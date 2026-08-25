<?php

/**
 * Write an installer-generated file without exposing credentials in output.
 * Linux uses an atomic rename. Windows cannot overwrite an existing file with
 * rename() on all PHP builds, so it uses a backup/restore replacement path
 * that never discards the previous file when the replacement fails.
 */
function install_write_file($path, $content)
{
	$directory = dirname($path);
	if (!is_dir($directory) || !is_writable($directory)) {
		return false;
	}

	$tmp = tempnam($directory, '.install-');
	if ($tmp === false) {
		return false;
	}

	try {
		if (file_put_contents($tmp, $content, LOCK_EX) === false) {
			return false;
		}

		if (is_file($path)) {
			$permissions = @fileperms($path);
			if ($permissions !== false) {
				@chmod($tmp, $permissions & 0777);
			}
		}

		if (PHP_OS_FAMILY !== 'Windows' || !is_file($path)) {
			return @rename($tmp, $path);
		}

		$backup = tempnam($directory, '.install-backup-');
		if ($backup === false) {
			return false;
		}
		@unlink($backup);

		if (!@rename($path, $backup)) {
			return false;
		}

		if (@rename($tmp, $path)) {
			@unlink($backup);
			return true;
		}

		// Restore the previous configuration if the replacement failed.
		@rename($backup, $path);
		return false;
	} finally {
		if (is_file($tmp)) {
			@unlink($tmp);
		}
	}
}

/**
 * Build the static database configuration consumed by config/database.php.
 */
function install_database_config_content($dbHost, $dbName, $dbUser, $dbPwd, $dbPort, $dbPrefix)
{
	$schemaPathMarker = '__INSTALL_RUNTIME_SCHEMA_PATH__';
	$config = [
		'default' => 'mysql',
		'time_query_rule' => [],
		'auto_timestamp' => true,
		'datetime_format' => 'Y-m-d H:i:s',
		'connections' => [
			'mysql' => [
				'type' => 'mysql',
				'hostname' => (string) $dbHost,
				'database' => (string) $dbName,
				'username' => (string) $dbUser,
				'password' => (string) $dbPwd,
				'hostport' => (string) $dbPort,
				'params' => [],
				'charset' => 'utf8mb4',
				'prefix' => (string) $dbPrefix,
				'deploy' => 0,
				'rw_separate' => false,
				'master_num' => 1,
				'slave_no' => '',
				'fields_strict' => true,
				'break_reconnect' => false,
				'trigger_sql' => false,
				'fields_cache' => false,
				'schema_cache_path' => $schemaPathMarker,
			],
		],
	];

	$export = var_export($config, true);
	$export = str_replace(
		var_export($schemaPathMarker, true),
		"app()->getRuntimePath() . 'schema' . DIRECTORY_SEPARATOR",
		$export
	);

	return "<?php\n\nreturn " . $export . ";\n";
}

function install_env_content()
{
	// SYSTEM_SALT is a cryptographic secret, not a copy of the visible site name.
	try {
		$systemSalt = bin2hex(random_bytes(32));
	} catch (\Throwable $e) {
		error_log('[installer] generating system salt failed: ' . $e->getMessage());
		return false;
	}
	return "APP_DEBUG = false\n"
		. "SYSTEM_SALT = " . $systemSalt . "\n\n"
		. "[APP]\n"
		. "DEFAULT_TIMEZONE = Asia/Chongqing\n\n"
		. "[LANG]\n"
		. "default_lang = zh-cn\n\n"
		. "[NETDISK]\n"
		. "; Optional CA bundle path. Leave empty to use the operating system trust store.\n"
		. "CA_BUNDLE =\n";
}

// 调试信息：检查POST数据
if(empty($_POST['manager']) || empty($_POST['manager_pwd'])) {
	return array('status'=>0,'info'=>'管理员账号或密码不能为空！请检查表单数据。');
}

$username = trim((string) ($_POST['manager'] ?? ''));
$password = trim((string) ($_POST['manager_pwd'] ?? ''));
//网站名称
$site_name = trim((string) ($_POST['sitename'] ?? ''));

// 表前缀会出现在 SQL 标识符中，必须限制为安全的标识符字符。
$dbPrefix = trim((string) ($dbPrefix ?? ''));
if ($dbPrefix === '' || !preg_match('/^[A-Za-z0-9_]+$/', $dbPrefix)) {
	return array('status'=>0,'info'=>'数据库表前缀格式不正确，请仅使用字母、数字和下划线');
}

// 调试信息：检查数据库连接
if(!isset($mysqli) || !($mysqli instanceof mysqli) || $mysqli->connect_error) {
	$error = isset($mysqli) ? $mysqli->connect_error : '数据库连接对象不存在';
	error_log('[installer] database connection unavailable: ' . $error);
	return array('status'=>0,'info'=>'数据库连接不可用，请返回检查数据库参数');
}

//更新配置信息
$site_name_sql = $mysqli->real_escape_string($site_name);
if(!$mysqli->query("UPDATE `{$dbPrefix}conf` SET  `conf_value` = '$site_name_sql' WHERE conf_key='app_name'")){
	error_log('[installer] updating site name failed: ' . $mysqli->error);
	return array('status'=>0,'info'=>'更新网站名称配置失败，请检查数据库结构');
}

//插入管理员
//生成随机认证码
$salt = genRandomString(4);
$time = time();
$ip = get_client_ip();
$password = sha1($password . $salt . $password . $salt);
$adminTable = "`{$dbPrefix}admin`";
$adminSql = "INSERT INTO {$adminTable} (`admin_account`, `admin_password`, `admin_salt`, `admin_name`, `admin_idcard`, `admin_truename`, `admin_email`, `admin_money`, `admin_group`, `admin_ipreg`, `admin_status`, `admin_createtime`, `admin_updatetime`) VALUES (?, ?, ?, '超级管理员', '', '超级管理员', '', 0.00, 1, ?, 0, ?, ?)";
$adminStatement = $mysqli->prepare($adminSql);
if (!$adminStatement || !$adminStatement->bind_param('ssssii', $username, $password, $salt, $ip, $time, $time) || !$adminStatement->execute()) {
	$error = $adminStatement ? $adminStatement->error : $mysqli->error;
	error_log('[installer] administrator insert failed: ' . $error);
	if ($adminStatement) {
		$adminStatement->close();
	}
	return array('status'=>0,'info'=>'创建管理员账户失败，请检查数据库结构或管理员账号');
}
$adminStatement->close();

// 验证插入是否成功；账号值通过参数绑定，避免安装请求构造 SQL。
$checkStatement = $mysqli->prepare("SELECT COUNT(*) AS count FROM {$adminTable} WHERE admin_account = ?");
if (!$checkStatement || !$checkStatement->bind_param('s', $username) || !$checkStatement->execute()) {
	$error = $checkStatement ? $checkStatement->error : $mysqli->error;
	error_log('[installer] administrator verification failed: ' . $error);
	if ($checkStatement) {
		$checkStatement->close();
	}
	return array('status'=>0,'info'=>'管理员账户校验失败，请稍后重试');
}
$checkCount = 0;
$checkStatement->bind_result($checkCount);
$checkStatement->fetch();
$checkStatement->close();
if ((int)$checkCount === 0) {
	return array('status'=>0,'info'=>'管理员账户插入失败：数据未成功写入数据库');
}

if(INSTALLTYPE == 'HOST'){
	$projectRoot = dirname(__DIR__, 2);
	$databaseConfigPath = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
	$envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env';

	// 数据库连接信息只在数据库和管理员创建成功后写入，.env 仅保留非数据库配置。
	$databaseConfig = install_database_config_content($dbHost, $dbName, $dbUser, $dbPwd, $dbPort, $dbPrefix);
	if (!install_write_file($databaseConfigPath, $databaseConfig)) {
		return array('status'=>0,'info'=>'写入 config/database.php 失败，请检查 config 目录权限');
	}
	$envContent = install_env_content();
	if ($envContent === false || !install_write_file($envPath, $envContent)) {
		return array('status'=>0,'info'=>'写入 .env 失败，请检查项目根目录权限');
	}
}

$mysqli->close();
return array('status'=>2,'info'=>'成功添加管理员<br />成功写入配置文件<br>安装完成...');
