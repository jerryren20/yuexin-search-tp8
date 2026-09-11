<?php

namespace app\service;

use think\facade\Log;

class SearchApiLineParser
{
    public function parse($line, $title)
    {
        $type = intval($line['search_pantype'] ?? ($line['pantype'] ?? 0));
        $maxCount = intval($line['count'] ?? 0);

        $panType = [
            0 => 'quark',
            2 => 'baidu',
            3 => 'uc',
            4 => 'xunlei',
        ];

        if (!isset($panType[$type]) || $maxCount <= 0) {
            return [];
        }

        $url = $line['url'] ?? '';
        $method = strtoupper($line['method'] ?? 'GET');
        $headers = json_decode($line['headers'] ?? '', true);
        $headers = $this->normalizeHeaders($headers, $url);
        $params = json_decode($line['fixed_params'] ?? '', true);
        $params = is_array($params) ? $params : [];
        $isPanSou = strtolower(trim((string)($line['type'] ?? ''))) === 'pansou';
        $imageProxy = '';
        if ($isPanSou && !empty($params['image_proxy'])) {
            $imageProxy = trim((string)$params['image_proxy']);
            unset($params['image_proxy']);
        }

        $params = $this->replaceKeywordRecursive($params, $title);
        if ($isPanSou) {
            $params['cloud_types'] = [$panType[$type]];
        }

        $headerArr = $this->buildCurlHeaderArray($headers);
        $contentType = $this->detectContentType($headers);
        if ($method === 'POST' && $contentType === '') {
            $contentType = 'application/x-www-form-urlencoded';
            $headerArr[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $fieldMap = json_decode($line['field_map'] ?? '', true);
        $fieldMap = is_array($fieldMap) ? $fieldMap : [];

        $firstRequest = $this->requestAndExtractList(
            $url,
            $method,
            $params,
            $headerArr,
            $contentType,
            $fieldMap,
            $type
        );

        $list = $firstRequest['list'];

        // 仅当 PanSou 正常返回 data.total=0 时刷新重试一次
        if (
            $isPanSou
            && $firstRequest['total'] === 0
            && !$this->isRefreshEnabled($params)
        ) {
            $retryParams = $params;
            $retryParams['refresh'] = true;

            Log::info('[PanSou] data.total=0，执行 refresh 重试: keyword=' . $title
                . ' line=' . ($line['name'] ?? ''));

            $retryRequest = $this->requestAndExtractList(
                $url,
                $method,
                $retryParams,
                $headerArr,
                $contentType,
                $fieldMap,
                $type
            );

            $list = $retryRequest['list'];

            Log::info('[PanSou] refresh 重试完成: keyword=' . $title
                . ' total=' . ($retryRequest['total'] ?? 'unknown')
                . ' result_count=' . count($list));
        }

        if ($imageProxy !== '') {
            $list = $this->applyImageProxyToList($list, $imageProxy);
        }

        return array_slice($list, 0, $maxCount);
    }

    private function requestAndExtractList(
        $url,
        $method,
        array $params,
        array $headerArr,
        $contentType,
        array $fieldMap,
        $type
    ) {
        $queryParams = $method === 'GET' ? $params : [];

        if ($method === 'POST' && !empty($params)) {
            $postData = strpos($contentType, 'application/json') !== false
                ? json_encode($params, JSON_UNESCAPED_UNICODE)
                : http_build_query($params);

            $result = curlHelper($url, $method, $postData, $headerArr, $queryParams);
        } else {
            $result = curlHelper(
                $url,
                $method,
                $method === 'GET' ? null : $params,
                $headerArr,
                $queryParams
            );
        }

        // 请求错误、空响应时不触发 total=0 重试
        if (!is_array($result) || !empty($result['error']) || empty($result['body'])) {
            return [
                'total' => null,
                'list' => [],
            ];
        }

        $response = json_decode($result['body'], true);

        // JSON 异常时不触发 total=0 重试
        if (!is_array($response)) {
            return [
                'total' => null,
                'list' => [],
            ];
        }

        return [
            'total' => $this->extractResponseTotal($response),
            'list' => $this->extractList($response, $fieldMap, $type),
        ];
    }

    private function extractResponseTotal(array $response)
    {
        if (
            !isset($response['data'])
            || !is_array($response['data'])
            || !array_key_exists('total', $response['data'])
        ) {
            return null;
        }

        $total = $response['data']['total'];

        if (is_int($total) || is_float($total)) {
            return (int)$total;
        }

        if (is_string($total) && is_numeric(trim($total))) {
            return (int)$total;
        }

        return null;
    }

    private function isRefreshEnabled(array $params)
    {
        if (!array_key_exists('refresh', $params)) {
            return false;
        }

        $value = $params['refresh'];

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }

        return in_array(
            strtolower(trim((string)$value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    private function normalizeHeaders($headers, $url = '')
    {
        if (!is_array($headers)) {
            $headers = [];
        }

        $normalized = [];
        $hasUserAgent = false;
        $hasAccept = false;
        foreach ($headers as $key => $value) {
            $headerName = trim((string)$key);
            $headerValue = trim((string)$value);
            if ($headerName === '' || $headerValue === '') {
                continue;
            }

            $lowerName = strtolower($headerName);
            if ($lowerName === 'user-agent') {
                $hasUserAgent = true;
                if ($headerValue === '...' || strtolower($headerValue) === 'ua') {
                    $headerValue = $this->defaultBrowserUserAgent();
                }
            }
            if ($lowerName === 'accept') {
                $hasAccept = true;
            }

            $normalized[$headerName] = $headerValue;
        }

        if (!$hasUserAgent) {
            $normalized['User-Agent'] = $this->defaultBrowserUserAgent();
        }
        if (!$hasAccept) {
            $normalized['Accept'] = 'application/json, text/plain, */*';
        }

        return $normalized;
    }

    private function buildCurlHeaderArray($headers)
    {
        $headerArr = [];
        if (!is_array($headers)) {
            return $headerArr;
        }
        foreach ($headers as $k => $v) {
            $headerArr[] = trim((string)$k) . ': ' . trim((string)$v);
        }
        return $headerArr;
    }

    private function detectContentType($headers)
    {
        foreach ($headers as $k => $v) {
            if (strtolower(trim((string)$k)) === 'content-type') {
                return strtolower(trim((string)$v));
            }
        }
        return '';
    }

    private function replaceKeywordRecursive($value, $title)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replaceKeywordRecursive($item, $title);
            }
            return $value;
        }
        if (is_string($value)) {
            return str_replace('{keyword}', $title, $value);
        }
        return $value;
    }

    private function defaultBrowserUserAgent()
    {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    }

    private function extractList($response, $fieldMap, $type)
    {
        $listData = $this->resolveListData($response, $fieldMap, $type);
        if (!is_array($listData)) {
            return [];
        }

        $fields = $fieldMap['fields'] ?? [];
        if (!is_array($fields)) {
            return [];
        }

        $result = [];
        foreach ($listData as $item) {
            $row = [];
            foreach ($fields as $targetKey => $sourcePath) {
                $value = $this->getValueByPath($item, (string)$sourcePath);
                if ($targetKey === 'images' && is_array($value) && !empty($value)) {
                    $row['image'] = $value[0];
                    $row[$targetKey] = $value;
                } else {
                    $row[$targetKey] = $value;
                }

                if ($targetKey === 'url') {
                    $row['url'] = $this->extractPanUrl($value, $type);
                }
            }

            if (!empty($row['url'])) {
                $result[] = $row;
            }
        }

        return $result;
    }

    private function applyImageProxyToList(array $list, $proxy)
    {
        foreach ($list as &$item) {
            if (!empty($item['image'])) {
                $item['image'] = $this->applyImageProxy($item['image'], $proxy);
            }
            if (!empty($item['images']) && is_array($item['images'])) {
                foreach ($item['images'] as &$image) {
                    $image = $this->applyImageProxy($image, $proxy);
                }
                unset($image);
            }
        }
        unset($item);
        return $list;
    }

    private function applyImageProxy($url, $proxy)
    {
        $url = trim((string)$url);
        $proxy = trim((string)$proxy);
        if ($url === '' || $proxy === '') {
            return $url;
        }
        if (!preg_match('#^https?://#i', $url) || strpos($url, $proxy) === 0) {
            return $url;
        }
        return rtrim($proxy, '/') . '/' . $url;
    }

    private function getValueByPath($item, $path)
    {
        $value = $item;
        foreach (explode('.', $path) as $p) {
            if (is_array($value) && array_key_exists($p, $value)) {
                $value = $value[$p];
            } else {
                return null;
            }
        }
        return $value;
    }

    private function extractPanUrl($value, $type)
    {
        if (is_array($value)) {
            $stringValue = json_encode($value, JSON_UNESCAPED_UNICODE);
            $stringValue = str_replace('\/', '/', $stringValue);
        } else {
            $stringValue = (string)$value;
        }

        if ($type === 0 && preg_match('/https:\/\/(?:pan|drive)\.quark\.cn\/s\/[a-zA-Z0-9_-]+(?:\?[^"\'\s]*)?/', $stringValue, $urlMatch)) {
            return trim($urlMatch[0]);
        }
        if ($type === 3 && preg_match('/https:\/\/(?:drive|www)\.uc\.cn\/s\/[a-zA-Z0-9_-]+(?:\?[^"\'\s]*)?/', $stringValue, $urlMatch)) {
            return trim($urlMatch[0]);
        }
        if ($type === 2 && preg_match('/https:\/\/pan\.baidu\.com\/s\/[a-zA-Z0-9_-]+(?:\?[^"\'\s]*)?/', $stringValue, $urlMatch)) {
            $url = trim($urlMatch[0]);
            if (strpos($url, '?pwd=') === false && preg_match('/["\'](pwd|code)["\']\s*:\s*["\']([^"\']+)["\']/', $stringValue, $pwdMatches)) {
                $url .= '?pwd=' . $pwdMatches[2];
            }
            return $url;
        }
        if ($type === 4 && preg_match('/https:\/\/pan\.xunlei\.com\/s\/[a-zA-Z0-9_-]+(?:\?[^"\'\s]*)?/', $stringValue, $urlMatch)) {
            $url = trim($urlMatch[0]);
            if (strpos($url, '?pwd=') === false && preg_match('/["\'](pwd|code)["\']\s*:\s*["\']([^"\']+)["\']/', $stringValue, $pwdMatches)) {
                $url .= '?pwd=' . $pwdMatches[2];
            }
            return $url;
        }

        return '';
    }

    private function resolveListData($response, $fieldMap, $type)
    {
        $listPath = trim((string)($fieldMap['list_path'] ?? ''));
        if ($listPath !== '') {
            $listData = $response;
            $pathKeys = array_filter(explode('.', $listPath), function ($v) {
                return $v !== '';
            });
            $found = true;
            foreach ($pathKeys as $key) {
                if (is_array($listData) && array_key_exists($key, $listData)) {
                    $listData = $listData[$key];
                } else {
                    $found = false;
                    break;
                }
            }
            if ($found && is_array($listData)) {
                return $listData;
            }
        }

        $mergedByType = $response['data']['merged_by_type'] ?? null;
        if (is_array($mergedByType)) {
            $typeKeyAliases = [
                0 => ['quark'],
                2 => ['baidu'],
                3 => ['uc', 'ucdrive', 'uc_pan'],
                4 => ['xunlei', 'thunder', 'xl'],
            ];
            $aliases = $typeKeyAliases[$type] ?? [];
            foreach ($aliases as $alias) {
                if (isset($mergedByType[$alias]) && is_array($mergedByType[$alias])) {
                    return $mergedByType[$alias];
                }
            }
        }

        $candidates = [
            $response['data']['list'] ?? null,
            $response['data']['items'] ?? null,
            $response['data']['results'] ?? null,
            $response['data']['data'] ?? null,
            $response['list'] ?? null,
            $response['items'] ?? null,
            $response['results'] ?? null,
            $response['data'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }
}
