<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

// 加载常量定义，根据自己项目实际路径记载
include_once START_DIR_ROOT.'/'.APP_NAME.'/Config/defines.php';
// 加载应用层协议,根据自己项目实际路径记载
$app_config = include_once START_DIR_ROOT.'/'.APP_NAME.'/Config/config-'.SWOOLEFY_ENV.'.php';

// tcpserver协议层配置
return [
    'app_conf' => $app_config, // 应用层配置，需要根据实际项目导入
	'application_service' => '',
	'event_handler' => \Swoolefy\Core\EventHandler::class,
    'exception_handler' => '',
	'master_process_name' => 'php-swoolefy-rpc-master',
	'manager_process_name' => 'php-swoolefy-rpc-manager',
	'worker_process_name' => 'php-swoolefy-rpc-worker',
	'www_user' => 'www',
	'host' => '0.0.0.0',
	'port' => '9504',
	'time_zone' => 'PRC',
    'runtime_enable_coroutine' => true,
	'setting' => [
		'reactor_num' => 1,
		'worker_num' => 3,
		'max_request' => 1000,
		'task_worker_num' => 2,
		'task_tmpdir' => '/dev/shm',
		'daemonize' => 0,
		// TCP使用固定的worker，使用2或4或7
		'dispatch_mode' => 2,
		'open_length_check'     => 1,
    	'package_length_type'   => 'N',
    	'package_length_offset' => 0,       //第N个字节是包长度的值
    	'package_body_offset'   => 34,       //第几个字节开始计算长度
    	'package_max_length'    => 2000000,  //协议最大长度

        'enable_coroutine' => 1,
        'task_enable_coroutine' => 1,

    	'log_file' => __DIR__.'/log/log.txt',
		'pid_file' => __DIR__.'/log/server.pid',

	],

	'enable_table_tick_task' => true,

    // 热更新
    //'reload_conf'=>[
    //    'enable_reload' => true,
    //    'after_seconds' => 3,
    //    'monitor_path' => APP_PATH,//开发者自己定义目录
    //    'reload_file_types' => ['.php','.html','.js'],
    //    'ignore_dirs' => [],
    //    'callback' => function() {
    //        var_dump("callback");
    //    }
    //],
];