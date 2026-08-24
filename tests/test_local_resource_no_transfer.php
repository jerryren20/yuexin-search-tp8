<?php

if (isset($argv[1]) && in_array($argv[1], ['--child-plain', '--child-encrypted', '--child-remote'], true)) {
    require __DIR__ . '/../vendor/autoload.php';

    class ControlledTransferProcessService extends \app\service\TransferProcessService
    {
        private $allowTransfer;

        public function __construct($allowTransfer = false)
        {
            $this->allowTransfer = $allowTransfer;
        }

        public function processUrl($value, &$numSuccess, &$datas, $type = false)
        {
            if ($this->allowTransfer) {
                return [
                    'code' => 200,
                    'data' => [
                        'title' => $value['title'],
                        'url' => 'https://pan.quark.cn/s/remotetransferresult',
                        'is_type' => 0,
                    ],
                ];
            }

            return [
                'code' => 599,
                'message' => 'Permanent local resource unexpectedly reached transfer processing',
            ];
        }
    }

    class PassthroughPanTreePreviewService extends \app\service\PanTreePreviewService
    {
        public function appendKeyForClient($data, $sourceUrl = '', $code = '', $stoken = '')
        {
            return is_object($data) ? $data->toArray() : $data;
        }
    }

    $app = new \think\App();
    $app->initialize();

    $isRemote = $argv[1] === '--child-remote';
    $sourceId = 0;
    $url = 'https://pan.quark.cn/s/'
        . ($isRemote ? 'remotetransfertest' : 'localdirecttest')
        . str_replace('.', '', uniqid('', true));
    register_shutdown_function(function () use (&$sourceId, $url) {
        if ($sourceId > 0) {
            \think\facade\Db::name('source')->where('source_id', $sourceId)->delete();
        }
        \think\facade\Cache::delete($url . 'ACAA');
        \think\facade\Cache::delete($url . 'ACAA_processing');
    });

    if (!$isRemote) {
        $sourceId = \think\facade\Db::name('source')->insertGetId([
            'title' => 'Local direct import regression test',
            'url' => $url,
            'is_type' => 0,
            'is_time' => 0,
            'status' => 1,
            'is_delete' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    $sourceModel = new \app\model\Source();
    if (!$isRemote) {
        $stored = (new \app\model\Source())
            ->where('status', 1)
            ->where('is_delete', 0)
            ->where('is_time', 0)
            ->where('url', $url)
            ->find();
        if (empty($stored)) {
            echo json_encode([
                'code' => 598,
                'message' => 'Test fixture was not visible through the Source model',
            ]);
            exit;
        }
    }

    $service = new \app\service\TransferResourceService(
        $sourceModel,
        new PassthroughPanTreePreviewService(),
        new ControlledTransferProcessService($isRemote)
    );

    $requestUrl = $argv[1] === '--child-encrypted' ? rawurlencode(encryptObject($url)) : $url;
    $service->saveUrl([
        'title' => 'Local direct import regression test',
        'url' => $requestUrl,
    ], [
        'enable' => '0',
    ]);

    exit(1);
}

foreach (['plain', 'encrypted', 'remote'] as $mode) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child-' . $mode . ' 2>&1';
    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);
    $output = implode("\n", $lines);

    if (!preg_match('/\{\"code\".*\}$/s', $output, $matches)) {
        fwrite(STDERR, "FAIL [{$mode}]: child process did not return JSON\n" . $output . "\n");
        exit(1);
    }

    $result = json_decode($matches[0], true);
    if (!is_array($result) || ($result['code'] ?? 0) !== 200) {
        fwrite(STDERR, "FAIL [{$mode}]: permanent local resource was not returned directly\n" . $output . "\n");
        exit(1);
    }

    $expectedUrlPrefix = $mode === 'remote'
        ? 'https://pan.quark.cn/s/remotetransferresult'
        : 'https://pan.quark.cn/s/localdirecttest';
    if (empty($result['data']['url']) || strpos($result['data']['url'], $expectedUrlPrefix) !== 0) {
        fwrite(STDERR, "FAIL [{$mode}]: response URL did not match the expected flow\n" . $output . "\n");
        exit(1);
    }
}

echo "PASS: local resources bypass transfer and remote resources still use transfer processing\n";
