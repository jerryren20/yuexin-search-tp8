<?php
header("Content-type: text/html; charset=utf-8");
//开启session
session_start();
//配置信息
$config = include __DIR__ . '/config.php';
if(empty($config)){
	exit(get_tip_html('安装配置信息不存在，无法继续安装！'));
}
//安装环境验证，获取相应判断信息
define('INSTALLTYPE', 'HOST');
//本地
require __DIR__ . '/localhost.php';

//限制最大的执行时间
set_time_limit(1000);
//php版本
$phpversion = phpversion();
//数据库文件（始终基于安装脚本目录解析，避免 PHP-FPM 工作目录不同导致读取失败）
$sqlFilePath = __DIR__ . DIRECTORY_SEPARATOR . $config['sqlFileName'];
if(!is_file($sqlFilePath) || !is_readable($sqlFilePath)){
	exit(get_tip_html('数据库文件不存在，无法继续安装！'));
}
//写入数据库完成后处理的文件
if (!file_exists(__DIR__ . '/'.$config['handleFile'])) {
	exit(get_tip_html('处理文件不存在，无法继续安装！'));
}
//设置报错级别并返回当前级别。
error_reporting(E_ALL & ~E_NOTICE);

function install_create_mysqli($dbHost, $dbUser, $dbPwd, $dbName = '', $dbPort = 3306)
{
	if (!extension_loaded('mysqli')) {
		return null;
	}

	// PHP 8 默认可能将连接失败升级为 mysqli_sql_exception；安装器需要
	// 把它转换为可处理的连接错误，不能让异常污染页面或 AJAX JSON。
	$driver = new mysqli_driver();
	$previousReportMode = $driver->report_mode;
	mysqli_report(MYSQLI_REPORT_OFF);
	try {
		return @new mysqli($dbHost, $dbUser, $dbPwd, $dbName, (int) $dbPort);
	} catch (Throwable $e) {
		return null;
	} finally {
		mysqli_report($previousReportMode);
	}
}

function install_format_db_connect_error($mysqli)
{
	$error = '';
	if (class_exists('mysqli', false) && $mysqli instanceof mysqli) {
		$error = $mysqli->connect_error;
	}
	if ($error === '' && function_exists('mysqli_connect_error')) {
		$error = mysqli_connect_error();
	}
	if ($error === '') {
		$error = '未知错误';
	}
	error_log('[installer] database connection failed: ' . $error);

	if (
		stripos($error, 'caching_sha2_password') !== false
		|| stripos($error, 'authentication method unknown') !== false
		|| stripos($error, 'requested authentication method unknown') !== false
	) {
		return '数据库链接失败！当前 PHP mysqli/mysqlnd 可能不支持数据库用户的认证方式，请升级 PHP 或调整数据库用户认证方式后重试。';
	}

	return '数据库链接失败！请检查数据库地址、端口、用户名和密码。';
}

//安装步骤
$steps = array(
	'1' => '安装许可协议',
	'2' => '运行环境检测',
	'3' => '安装参数设置',
	'4' => '安装详细过程',
	'5' => '安装完成',
);
$step = isset($_GET['step']) ? $_GET['step'] : 1;
//当前安装步骤
$step_html = '';
foreach ($steps as $key => $value) {
	$current = $key == $step? 'current':'';
	$step_html .= '<li class="'.$current.'"><em>'.$key.'</em>'.$value.'</li>';
}
//安装页面
switch ($step) {
	//安装许可协议
	case '1':
		$license = file_get_contents(__DIR__ . '/license.txt');
		include (__DIR__ . "/templates/1.php");
		break;
	//运行环境检测	
	case '2':
		$server = array(
			//操作系统
			'os' => php_uname(),
			//PHP版本
			'php' => $phpversion,
		);
		$error = 0;
		//php版本
		if ($phpversion>=$config['php']) {
			$server['php'] = '<span class="correct_span">&radic;</span> 支持';
		} else {
			$server['php'] = '<span class="correct_span error_span">&radic;</span> '.$phpversion;
			$error++;
		}
		//上传限制
		if (ini_get('file_uploads')) {
			$server['uploadSize'] = '<span class="correct_span">&radic;</span> ' . ini_get('upload_max_filesize');
		} else {
			$server['uploadSize'] = '<span class="correct_span error_span">&radic;</span>禁止上传';
		}
		//session
		if (function_exists('session_start')) {
			$server['session'] = '<span class="correct_span">&radic;</span> 支持';
		} else {
			$server['session'] = '<span class="correct_span error_span">&radic;</span> 不支持';
			$error++;
		}


		//需要读写权限的目录
		$folder = $config['dirAccess'];
		// PHP-FPM 的工作目录不一定是安装目录；权限检查必须基于脚本位置。
		$site_path = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/') . '/';
		include (__DIR__ . "/templates/2.php");
		$_SESSION['INSTALLSTATUS'] = $error == 0?'SUCCESS':$error;
		break;
	//安装参数设置
	case '3':
		verify(3);
		//测试数据库链接
		if (isset($_GET['testdbpwd'])) {
			empty($_POST['dbhost'])?alert(0,'数据库服务器地址不能为空！','dbhost'):'';
			empty($_POST['dbuser'])?alert(0,'数据库用户名不能为空！','dbuser'):'';
			empty($_POST['dbname'])?alert(0,'数据库名不能为空！','dbname'):'';
			empty($_POST['dbport'])?alert(0,'数据库端口不能为空！','dbport'):'';
			$dbHost = trim((string) $_POST['dbhost']);
			$dbPort = (int) $_POST['dbport'];
			if (!install_is_port($dbPort)) {
				alert(0, '数据库端口格式不正确，请填写 1-65535 之间的数字', 'dbport');
			}
			$mysqli = install_create_mysqli($dbHost,  $_POST['dbuser'], (string) ($_POST['dbpw'] ?? ''), '', $dbPort);
			// 改进错误检查机制
			if(!$mysqli || $mysqli->connect_error)  {
				alert(0, install_format_db_connect_error($mysqli), 'dbpw');
			}else{
				// 测试数据库版本
				if ($mysqli->server_info < 5.0) {
					alert(0,'MySQL版本过低，请升级到5.0以上！当前版本：' . $mysqli->server_info,'dbpw');
				}
				alert(1,'数据库链接成功！MySQL版本：' . $mysqli->server_info,'dbpw');
			}
			$mysqli->close();
		}
		//域名+路径
		$domain = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
		if ($domain === '') {
			$domain = 'localhost';
		}
		$serverPort = (int) ($_SERVER['SERVER_PORT'] ?? 80);
		if ($serverPort !== 80 && $serverPort !== 443 && strpos($domain, ':') === false) {
			$domain .= ":" . $serverPort;
		}
		$scriptName = (string) ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'] ?? '');
		$rootpath = preg_replace("/\/(I|i)nstall\/index\.php(.*)$/", "", $scriptName);
		$domain = $domain . $rootpath;
		include (__DIR__ . "/templates/3.php");
		break;
	//安装详细过程
	case '4':
		if (!isset($_GET['install'])){
			switch (INSTALLTYPE){
				case 'SAE':
					// 服务器地址
					$_POST['dbhost'] = SAE_MYSQL_HOST_M;
					// 端口
					$_POST['dbport'] = SAE_MYSQL_PORT;
					// 数据库名
					$_POST['dbname'] = SAE_MYSQL_DB;
					// 用户名
					$_POST['dbuser'] = SAE_MYSQL_USER;
					// 密码
					$_POST['dbpw'] = SAE_MYSQL_PASS;
					break;
				case 'BAE':
					// 服务器地址
					$_POST['dbhost'] = HTTP_BAE_ENV_ADDR_SQL_IP;
					// 端口
					$_POST['dbport'] = HTTP_BAE_ENV_ADDR_SQL_PORT;
					// 用户名
					$_POST['dbuser'] = HTTP_BAE_ENV_SK;
					// 密码
					$_POST['dbpw'] = SAE_MYSQL_PASS;
					break;
			}
		}
		verify(4);
		// 第一次进入第 4 步时，表单会提交到 step=4，但不会带 install 参数。
		// 使用默认值，避免 PHP 8 将未定义数组键报告为 Warning。
		$install = isset($_GET['install']) ? (int) $_GET['install'] : 0;
		if ($install > 0) {
			dataVerify();
			//关闭特殊字符提交处理到数据库
			//设置时区
			date_default_timezone_set('PRC');
			
			// 设置脚本执行时间和内存限制，防止安装过程超时
			set_time_limit(300); // 5分钟超时
			ini_set('memory_limit', '256M'); // 增加内存限制
			
			// 输出缓冲区设置，确保实时输出
			ob_implicit_flush(true);
			ob_end_flush();
			//当前进行的数据库操作
			$n = isset($_GET['n']) ? max(0, (int) $_GET['n']) : 0;
			$arr = array();
			//数据库服务器地址
			$dbHost = trim((string) ($_POST['dbhost'] ?? ''));
			//数据库端口
			$dbPort = trim((string) ($_POST['dbport'] ?? ''));
			if (!install_is_port($dbPort)) {
				alert(0, '数据库端口格式不正确，请填写 1-65535 之间的数字');
			}
			//数据库名
			$dbName = trim((string) ($_POST['dbname'] ?? ''));
			//数据库用户名
			$dbUser = trim((string) ($_POST['dbuser'] ?? ''));
			//数据库密码
			// 密码必须保留用户输入的首尾空格，不能使用 trim() 改变真实凭据。
			$dbPwd = (string) ($_POST['dbpw'] ?? '');
			//表前缀
			$dbPrefix = empty($_POST['dbprefix'])
				? trim((string) ($config['dbPrefix'] ?? 'qf_'))
				: trim((string) $_POST['dbprefix']);
			if (!install_is_identifier($dbName)) {
				alert(0, '数据库名格式不正确，请仅使用字母、数字和下划线');
			}
			if (!install_is_identifier($dbPrefix)) {
				alert(0, '数据库表前缀格式不正确，请仅使用字母、数字和下划线');
			}
			//链接数据库
			$mysqli = install_create_mysqli($dbHost, $dbUser, $dbPwd, '', (int) $dbPort);
			// 改进数据库连接错误检查
			if (!$mysqli || $mysqli->connect_error) {
				alert(0, install_format_db_connect_error($mysqli));
			}
			// PHP 8 默认可能把 SQL 错误转换成异常；安装器需要通过返回值
			// 统一处理每条语句，避免异常文本破坏 AJAX JSON 响应。
			mysqli_report(MYSQLI_REPORT_OFF);
			
			// 设置字符集
			if(!$mysqli->query("SET NAMES 'utf8mb4'")){
				error_log('[installer] setting database charset failed: ' . $mysqli->error);
				alert(0,'设置数据库字符集失败，请检查数据库权限和版本');
			}
			
			// 检查MySQL版本
			if ($mysqli->server_info < 5.0) {
				alert(0,'MySQL版本过低，请升级到5.0以上！当前版本：' . $mysqli->server_info);
			}
			
			// 创建数据库并选中
			if(!$mysqli->select_db($dbName)){
				$create_sql='CREATE DATABASE IF NOT EXISTS `'.$dbName.'` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;';
				if(!$mysqli->query($create_sql)){
					error_log('[installer] creating database failed: ' . $mysqli->error);
					alert(0,'创建数据库失败，请检查数据库用户权限');
				}
				if(!$mysqli->select_db($dbName)){
					error_log('[installer] selecting database failed: ' . $mysqli->error);
					alert(0,'选择数据库失败，请检查数据库名称和权限');
				}
			}
			
			// 导入sql数据并创建表
			$sqldata = @file_get_contents($sqlFilePath);
			if($sqldata === false || trim($sqldata) === ''){
				alert(0,'数据库文件不能为空！');
			}
			
			// 按 SQL 语句分割，忽略注释并避免拆分字符串中的分号。
			$sql_array = sql_split($sqldata, $dbPrefix, $config['dbPrefix']);
			if (empty($sql_array)) {
				alert(0, '数据库文件中没有可执行的 SQL 语句！');
			}
			$counts = count($sql_array);
			$created_tables = array(); // 记录创建的表
			
			for ($i = $n; $i < $counts; $i++) {
				$sql = trim($sql_array[$i]);
				if ($sql === '') {
					continue;
				}

				$isCreateTable = preg_match('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', $sql, $matches) === 1;
				$createdTableName = $isCreateTable ? (string) ($matches[1] ?? '') : '';
				$fallbackUsed = false;
				$executed = $mysqli->query($sql);
				if (!$executed && $isCreateTable && stripos($mysqli->error, 'ngram') !== false) {
					// 部分 MySQL/MariaDB 未启用 ngram 时，移除该索引解析器后重试。
					$newSql = preg_replace('/\s+WITH\s+PARSER\s+ngram\b/i', '', $sql);
					if ($newSql !== null) {
						$executed = $mysqli->query($newSql);
						if ($executed) {
							$fallbackUsed = true;
							$created_tables[] = ($createdTableName !== '' ? $createdTableName : 'unknown') . ' (Fallback: No ngram)';
						}
					}
				}
				if (!$executed) {
					error_log('[installer] SQL statement ' . ($i + 1) . ' failed: ' . $mysqli->error);
					alert(0, $isCreateTable ? '创建数据表失败，请检查数据库版本和权限' : '执行初始化 SQL 失败，请检查数据库权限和版本');
				}
				if ($isCreateTable && !$fallbackUsed && $createdTableName !== '' && !in_array($createdTableName, $created_tables, true)) {
					$created_tables[] = $createdTableName;
				}
			}
			
			// 所有表创建完成后，生成成功信息
			$info = '';
			foreach($created_tables as $table_name){
				$info .= '<li><span class="correct_span">&radic;</span>创建数据表' . $table_name . '，完成！<span style="float: right;">'.date('Y-m-d H:i:s').'</span></li> ';
			}
			
			//处理管理员账号创建和配置文件生成
			$data = include __DIR__ . '/'.$config['handleFile'];
			$_SESSION['INSTALLOK'] = $data['status']?1:0;
			
			// 合并所有安装信息
			if($data['status'] == 2) {
				$info .= '<li><span class="correct_span">&radic;</span>' . $data['info'] . '<span style="float: right;">'.date('Y-m-d H:i:s').'</span></li>';
				// 安装完成，返回特殊的type标识
				if (!headers_sent()) {
					header('Content-Type: application/json; charset=utf-8');
				}
				exit(json_encode(array('status'=>2,'info'=>$info,'type'=>'install_complete'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			} else {
				$info .= '<li><span class="error_span">×</span>' . $data['info'] . '<span style="float: right;">'.date('Y-m-d H:i:s').'</span></li>';
				alert(0, $info);
			}
		}
		include (__DIR__ . "/templates/4.php");
		break;
	//安装完成
	case '5':
		verify(5);
		include (__DIR__ . "/templates/5.php");
		//安装完成,生成.lock文件
		if(isset($_SESSION['INSTALLOK']) && $_SESSION['INSTALLOK'] == 1){
			if(!filewrite(__DIR__ . '/install.lock')){
				// 锁文件创建失败，记录错误但不阻止安装完成
				echo '<script>console.error("警告：安装锁文件创建失败，请手动创建 install.lock 文件以防止重复安装");</script>';
			}
		}
		unset($_SESSION);
		break;
}	

/**
 * 错误提示html
 */
function get_tip_html($info){
	return '<div>'.$info.'</div>';
}
//返回提示信息
function alert($status,$info,$type = 0){
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
	}
	exit(json_encode(array('status'=>$status,'info'=>$info,'type'=>$type), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function verify($step = 3){
	if($step >= 3){
		//未运行环境检测，跳转到安装许可协议页面
		if(!isset($_SESSION['INSTALLSTATUS'])){
			header('location:./index.php');
			exit();
		}
		//运行环境检测存在错误，返回运行环境检测
		if($_SESSION['INSTALLSTATUS'] != 'SUCCESS'){
			header('location:./index.php?step=2');
			exit();
		}
	}
	if($step == 4){
		//未提交数据
		if(empty($_POST)){
			header('location:./index.php?step=3');
			exit();
		}
	}
	if($step >= 5){
		//数据库未写入完成
		if(!isset($_SESSION['INSTALLOK'])){
			header('location:./index.php?step=4');
			exit();
		}
	}
}
function dataVerify(){
	empty($_POST['dbhost'])?alert(0,'数据库服务器不能为空！'):'';
	empty($_POST['dbport'])?alert(0,'数据库端口不能为空！'):'';
	empty($_POST['dbuser'])?alert(0,'数据库用户名不能为空！'):'';
	empty($_POST['dbname'])?alert(0,'数据库名不能为空！'):'';
	empty($_POST['dbprefix'])?alert(0,'数据库表前缀不能为空！'):'';
	empty($_POST['manager'])?alert(0,'管理员帐号不能为空！'):'';
	empty($_POST['manager_pwd'])?alert(0,'管理员密码不能为空！'):'';
}
/**
 * Validate values that will be used as MySQL identifiers.
 */
function install_is_identifier($value) {
	return preg_match('/^[A-Za-z0-9_]+$/D', (string) $value) === 1;
}
/**
 * Validate a TCP port accepted by mysqli.
 */
function install_is_port($value) {
	if (!is_int($value) && !ctype_digit((string) $value)) {
		return false;
	}
	$port = (int) $value;
	return $port >= 1 && $port <= 65535;
}
/**
 * 判断目录是否可写
 */
function testwrite($d) {
	$tfile = "_test.txt";
	$fp = fopen($d . "/" . $tfile, "w");
	if (!$fp) {
		return false;
	}
	fclose($fp);
	$rs = unlink($d . "/" . $tfile);
	if ($rs) {
		return true;
	}
	return false;
}
/**
 * 创建目录
 */
function dir_create($path, $mode = 0777) {
	if (is_dir($path)) {
		return TRUE;
	}
	mkdir($path, $mode, true);
	chmod($path, $mode);
}
/**
 * 数据库语句解析
 * @param $sql 数据库
 * @param $newTablePre 新的前缀
 * @param $oldTablePre 旧的前缀
 */
function sql_split($sql, $newTablePre, $oldTablePre) {
	if (!is_string($sql) || $sql === '') {
		return array();
	}

	// 前缀替换。安装 SQL 使用 qf_，允许安装时改成其他安全前缀。
	if ((string) $newTablePre !== (string) $oldTablePre) {
		$sql = str_replace((string) $oldTablePre, (string) $newTablePre, $sql);
	}
	$sql = preg_replace("/TYPE=(InnoDB|MyISAM|MEMORY)( DEFAULT CHARSET=[^; ]+)?/i", "ENGINE=\\1 DEFAULT CHARSET=utf8", $sql);
	$sql = str_replace(array("\r\n", "\r"), "\n", (string) $sql);

	$queries = array();
	$buffer = '';
	$length = strlen($sql);
	$quote = null;

	for ($i = 0; $i < $length; $i++) {
		$char = $sql[$i];

		if ($quote !== null) {
			$buffer .= $char;
			if ($char === '\\' && $i + 1 < $length) {
				$buffer .= $sql[++$i];
				continue;
			}
			if ($char === $quote) {
				// SQL 允许用两个相同引号表示一个引号字符。
				if ($i + 1 < $length && $sql[$i + 1] === $quote) {
					$buffer .= $sql[++$i];
					continue;
				}
				$quote = null;
			}
			continue;
		}

		if ($char === "'" || $char === '"' || $char === '`') {
			$quote = $char;
			$buffer .= $char;
			continue;
		}

		// 跳过普通块注释、# 注释和 -- 注释，避免注释中的分号干扰分割。
		if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
			$i += 2;
			while ($i < $length - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
				$i++;
			}
			$i = min($length - 1, $i + 1);
			continue;
		}
		if ($char === '#') {
			while ($i + 1 < $length && $sql[$i + 1] !== "\n") {
				$i++;
			}
			continue;
		}
		if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
			$i += 2;
			while ($i + 1 < $length && $sql[$i + 1] !== "\n") {
				$i++;
			}
			continue;
		}

		if ($char === ';') {
			$query = trim($buffer);
			if ($query !== '') {
				$queries[] = $query;
			}
			$buffer = '';
			continue;
		}
		$buffer .= $char;
	}

	$query = trim($buffer);
	if ($query !== '') {
		$queries[] = $query;
	}
	return $queries;
}
/**
 * 产生随机字符串
* 产生一个指定长度的随机字符串,并返回给用户
* @access public
* @param int $len 产生字符串的位数
* @return string
*/
function genRandomString($len = 6) {
	$chars = array(
			"a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k",
			"l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v",
			"w", "x", "y", "z", "A", "B", "C", "D", "E", "F", "G",
			"H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R",
			"S", "T", "U", "V", "W", "X", "Y", "Z", "0", "1", "2",
			"3", "4", "5", "6", "7", "8", "9", '!', '@', '#', '$',
			'%', '^', '&', '*', '(', ')'
	);
	$charsLen = count($chars) - 1;
	shuffle($chars);	// 将数组打乱
	$output = "";
	for ($i = 0; $i < $len; $i++) {
		$output .= $chars[mt_rand(0, $charsLen)];
	}
	return $output;
}
/**
 * 获取客户端IP地址
 * @param integer $type 返回类型 0 返回IP地址 1 返回IPV4地址数字
 * @return mixed
 */
 function get_client_ip($type = 0) {
	$type	   =  $type ? 1 : 0;
	static $ip  =   NULL;
	if ($ip !== NULL) return $ip[$type];
	if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$arr	=   explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
		$pos	=   array_search('unknown',$arr);
		if(false !== $pos) unset($arr[$pos]);
		$ip	 =   trim($arr[0]);
	}elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
		$ip	 =   $_SERVER['HTTP_CLIENT_IP'];
	}elseif (isset($_SERVER['REMOTE_ADDR'])) {
		$ip	 =   $_SERVER['REMOTE_ADDR'];
	}
	// IP地址合法验证
	$long = sprintf("%u",ip2long($ip));
	$ip   = $long ? array($ip, $long) : array('0.0.0.0', 0);
	return $ip[$type];
 }
/**
  * 写入文件
  */
 function filewrite($file, $content = '') {
 	$fp = fopen($file, 'w');
 	if ($fp) {
 		fwrite($fp, $content);
 		fclose($fp);
 		return true;
 	}
 	return false;
 }
 ?>
