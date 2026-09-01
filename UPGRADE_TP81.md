# ThinkPHP 8.1 迁移说明

本目录是 `yuexin-search` 的并行迁移副本，原目录和原数据库不会被覆盖。当前依赖锁定到 ThinkPHP 8.1.4，运行环境为 PHP 8.3。

## 本地验证

1. 复制原项目的 `.env` 到本目录；如果其中存在 `[DATABASE]` 段，请逐项复制到 `config/database.php` 的 `connections.mysql` 后删除该段。`.env` 仅保存非数据库环境配置，不要提交到 Git。
   生产部署完成后不要把填写了密码的 `config/database.php` 加入 Git；可在部署机执行 `git update-index --skip-worktree config/database.php`，并通过备份或密钥管理保存该文件。
2. 安装依赖并清理缓存：

   ```bash
   composer install --no-dev --optimize-autoloader
   php think clear
   php think version
   ```

3. 确认 PHP-FPM/Nginx 的站点根目录指向 `public/`，再检查首页、`/s/<关键词>-1-0.html`、API、后台登录和转存流程。

## 上线步骤

1. 备份原目录、`data/` 和数据库；先复制数据库到测试实例。
2. 在新目录配置 `config/database.php` 的生产数据库连接，并确认 `.env` 中 `APP_DEBUG=false` 和网盘 Cookie；后台“基础设置 → 搜索设置 → CA证书路径”可填写项目根目录相对路径（如 `data/certs/cacert.pem`）或绝对路径，留空时使用 `.env` 中的 `NETDISK_CA_BUNDLE` 或系统 CA。
3. 运行 `composer install --no-dev --optimize-autoloader`、`php think clear`，重启 PHP-FPM 后做接口冒烟测试。
4. 通过站点根目录切换到新目录；确认站点的 `index` 优先级包含 `public/index.php`（不要让项目根目录的占位 `index.html` 抢先返回）；保留旧目录，出现问题时将根目录切回即可回滚。

## 兼容性注意

- 不要把旧 `vendor/` 复制回本目录；依赖版本由 `composer.lock` 管理。
- 迁移保持同步转存，不会自动启用 Redis 队列。
- `NetdiskCheckService` 默认开启 TLS 证书和主机名校验；可将证书放在 `data/certs/cacert.pem` 并填写该相对路径。CentOS 也可使用 `/etc/pki/tls/certs/ca-bundle.crt`，Windows 可填写 `C:/certs/cacert.pem`；无论哪种方式，都要确保 PHP-FPM 运行账号可以读取证书文件。
- 生产错误页只返回固定文案，不展示框架版本、路径、Cookie、请求参数或调用栈。
