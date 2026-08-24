<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

// [ 应用入口文件 ]
namespace think;

// 检测是否是新安装
if(is_dir("./install") && !file_exists("./install/install.lock")){
	$path = dirname($_SERVER['SCRIPT_NAME']);
	if($path == '/' || $path == '\\'){
		$path = '';
	}
	// Use a relative redirect so an untrusted Host header cannot be reflected
	// into the Location header and HTTPS is never downgraded to HTTP.
	header('Location: ' . ($path ?: '') . '/install/index.php', true, 302);
	die;
}

require __DIR__ . '/../vendor/autoload.php';

// 执行HTTP应用并响应
$http = (new App())->http;

// $response = $http->run();

// 特殊路由
$_amain = 'index';
$_aother = 'admin|qfadmin|api'; // 这里是除了home以外的所有其他应用
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('/^\/(' . $_aother . ')(?:\/|$)/i', $requestPath)) {
    $response = $http->run();
} else {
    $response = $http->name($_amain)->run();
}

$response->send();
$http->end($response);
