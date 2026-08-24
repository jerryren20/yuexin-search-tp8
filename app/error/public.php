<?php
/**
 * 生产环境统一错误页。
 *
 * 该模板只接受 ExceptionHandle 传入的状态码和固定文案，不渲染异常对象、
 * 请求参数、调用栈或服务器路径。即使 APP_DEBUG 被误设为 true，也不会把
 * ThinkPHP 的调试模板输出给访问者。
 *
 * @var int    $statusCode
 * @var string $message
 */
$statusCode = isset($statusCode) ? (int) $statusCode : 500;
$message    = isset($message) && is_string($message) ? $message : '服务暂时不可用，请稍后重试';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>哈哈搜索 - 页面暂时不可用</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, -apple-system, "Segoe UI", "PingFang SC", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #fafafa; color: #1f2937; }
        .card { width: min(92vw, 560px); padding: 48px 32px; text-align: center; background: #fff; border: 1px solid #e5e7eb; border-radius: 20px; box-shadow: 0 18px 48px rgba(15, 23, 42, .08); }
        .brand { margin: 0 0 28px; font-size: 34px; font-weight: 800; letter-spacing: .08em; }
        .code { margin: 0; color: #9ca3af; font-size: 16px; }
        h1 { margin: 12px 0; font-size: 30px; }
        .message { margin: 0 0 28px; color: #6b7280; line-height: 1.7; }
        a { display: inline-block; padding: 12px 24px; border-radius: 10px; background: #202124; color: #fff; text-decoration: none; }
        a:focus-visible, a:hover { background: #111827; }
    </style>
</head>
<body>
<main class="card" role="main">
    <p class="brand" aria-label="哈哈搜索"><span style="color:#111">哈</span><span style="color:#2675e8">哈</span><span style="color:#e11d2e">搜</span><span style="color:#0a9f55">索</span></p>
    <p class="code">错误代码 <?= htmlspecialchars((string) $statusCode, ENT_QUOTES, 'UTF-8') ?></p>
    <h1><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="message">我们已记录本次问题，请稍后重试。</p>
    <a href="/">返回首页</a>
</main>
</body>
</html>
