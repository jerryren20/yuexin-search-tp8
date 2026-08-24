<?php
namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        // API 只返回稳定的业务错误，不把异常消息、路径或调用栈暴露给客户端。
        if ($request->isAjax() || $request->isJson() || strpos($request->url(), '/api/') !== false) {
            $statusCode = $e instanceof HttpException ? $e->getStatusCode() : 500;
            $message = '服务暂时不可用，请稍后重试';

            if ($e instanceof ValidateException) {
                $message = $e->getMessage() ?: '请求参数不正确';
                $statusCode = 422;
            } elseif ($e instanceof HttpException && $statusCode < 500) {
                $message = $e->getMessage() ?: '请求无法处理';
            }

            return Response::create([
                'code' => $statusCode,
                'message' => $message,
            ], 'json', $statusCode);
        }

        // 页面异常统一使用脱敏模板，即使 APP_DEBUG 被误设为 true 也不输出
        // 框架版本、文件路径、请求参数或调用栈。
        $statusCode = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $messages = [
            403 => '暂无权限访问',
            404 => '页面不存在',
            405 => '请求方法不支持',
            429 => '请求过于频繁',
            500 => '服务暂时不可用',
            503 => '服务暂时不可用',
        ];
        $statusCode = in_array($statusCode, [403, 404, 405, 429, 500, 503], true) ? $statusCode : 500;
        $statusMessage = $messages[$statusCode] ?? '服务暂时不可用';
        $template = $this->app->getRootPath() . 'app/error/public.php';

        ob_start();
        $statusCodeForTemplate = $statusCode;
        $message = $statusMessage;
        if (is_file($template)) {
            include $template;
        } else {
            echo '<!doctype html><meta name="robots" content="noindex,nofollow"><title>哈哈搜索</title><h1>' . htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') . '</h1>';
        }
        $content = ob_get_clean();

        return Response::create($content, 'html', $statusCode)
            ->header(['Cache-Control' => 'no-store, max-age=0']);
    }
}
