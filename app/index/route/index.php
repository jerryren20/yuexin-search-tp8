<?php


use think\facade\Route;


// 首页
// 应用已经绑定到 index，因此路由目标只写 controller/action。
// 继续使用三段式目标会被 ThinkPHP 8 解析成
// app\index\controller\index\index，不再是原有的 Index 控制器。
Route::get('/', 'index/index');

// 前端页面路由
Route::get('s/<name>-<page?>-<cate?>', 'index/list')->pattern(['name' => '[^-]+', 'id' => '\d+', 'cate' => '\d+']);
Route::get('d/:id','index/detail');
Route::get('sitemap.xml', 'sitemap/index');

// API路由组
Route::group('api', function () {
    // 其他API路由
    Route::post('other/save_url', 'api/other/save_url');
    Route::post('other/get_display_url', 'api/other/get_display_url');
    Route::post('other/search', 'api/other/search');
    Route::post('other/delete_search', 'api/other/delete_search');
    
    // 密码验证相关API路由
    Route::post('verify_password', 'api/other/verify_password');
    Route::get('check_password_required', 'api/other/check_password_required');
    Route::get('get_resource_url', 'api/other/get_resource_url');
    
    // 其他工具API
    Route::get('tool/ranking', 'api/tool/ranking');
});



 
