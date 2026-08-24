<?php

declare(strict_types=1);

namespace app\index\controller;

use think\exception\HttpException;

/**
 * 前台未匹配路由的兜底控制器。
 *
 * 旧文件只有被注释掉的源码文本，在 PHP 中会被直接输出；这里显式抛出
 * 404，由统一异常处理器返回脱敏错误页。
 */
class Error
{
    public function __call($method, $args)
    {
        throw new HttpException(404, 'Not Found');
    }

    public function index()
    {
        throw new HttpException(404, 'Not Found');
    }
}
