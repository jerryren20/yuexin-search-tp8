# ThinkPHP 8.1 迁移说明

本目录是 `yuexin-search` 的并行迁移副本，原目录和原数据库不会被覆盖。当前依赖锁定到 ThinkPHP 8.1.4，运行环境为 PHP 8.3。

## 本地验证

1. 复制原项目的 `.env` 到本目录，并把数据库改为测试库；不要把 `.env` 提交到 Git。
2. 安装依赖并清理缓存：

   ```bash
   composer install --no-dev --optimize-autoloader
   php think clear
   php think version
   ```

3. 确认 PHP-FPM/Nginx 的站点根目录指向 `public/`，再检查首页、`/s/<关键词>-1-0.html`、API、后台登录和转存流程。

## 上线步骤

1. 备份原目录、`data/` 和数据库；先复制数据库到测试实例。
2. 在新目录配置生产 `.env`，至少确认 `DATABASE`、`APP_DEBUG=false`、网盘 Cookie 和 `NETDISK_CA_BUNDLE`（留空时使用系统 CA）。
3. 运行 `composer install --no-dev --optimize-autoloader`、`php think clear`，重启 PHP-FPM 后做接口冒烟测试。
4. 通过站点根目录切换到新目录；确认站点的 `index` 优先级包含 `public/index.php`（不要让项目根目录的占位 `index.html` 抢先返回）；保留旧目录，出现问题时将根目录切回即可回滚。

## 兼容性注意

- 不要把旧 `vendor/` 复制回本目录；依赖版本由 `composer.lock` 管理。
- 迁移保持同步转存，不会自动启用 Redis 队列。
- `NetdiskCheckService` 默认开启 TLS 证书和主机名校验；CentOS 可使用 `/etc/pki/tls/certs/ca-bundle.crt`，需配置到 `NETDISK_CA_BUNDLE`。
- 生产错误页只返回固定文案，不展示框架版本、路径、Cookie、请求参数或调用栈。
