<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

return [
    // 应用调试模式（控制F12控制台调试日志输出）
    // true = 显示所有调试日志（开发环境推荐）
    // false = 隐藏调试日志，仅显示错误（生产环境推荐）
    'debug'            => env('app.debug', false),
    
    // 应用地址
    'app_host'         => env('app.host', ''),
    // 应用的命名空间
    'app_namespace'    => '',
    // 是否启用路由
    'with_route'       => true,
    // 是否启用事件
    'with_event'       => true,
    // 默认应用
    'default_app'      => 'index',
    // 默认时区
    'default_timezone' => env('DEFAULT_TIMEZONE','Asia/Chongqing'),

    // 应用映射（自动多应用模式有效）
    'app_map'          => [],
    // 域名绑定（自动多应用模式有效）
    'domain_bind'      => [],
    // 禁止URL访问的应用列表（自动多应用模式有效）
    'deny_app_list'    => [],

    // 异常页面的模板文件
    'exception_tmpl'   => __DIR__ . "/../app/error/public.php",

    // HTTP 异常统一使用安全模板；ExceptionHandle 也会在渲染前再次脱敏。
    'http_exception_template' => [
        403 => __DIR__ . "/../app/error/public.php",
        404 => __DIR__ . "/../app/error/public.php",
        405 => __DIR__ . "/../app/error/public.php",
        429 => __DIR__ . "/../app/error/public.php",
        500 => __DIR__ . "/../app/error/public.php",
        503 => __DIR__ . "/../app/error/public.php",
    ],

    // 错误显示信息,非调试模式有效
    'error_message'    => '服务暂时不可用，请稍后重试。',
    // 显示错误信息
    'show_error_msg'   => false,
];
