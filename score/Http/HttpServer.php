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

namespace Swoolefy\Http;

use Swoolefy\Core\Swfy;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\BaseServer;

abstract class HttpServer extends BaseServer {
	/**
	 * $setting
	 * @var array
	 */
	public static $setting = [
		'reactor_num' => 1,
		'worker_num' => 1,
		'max_request' => 1000,
		'task_worker_num' => 1,
		'task_tmpdir' => '/dev/shm',
		'daemonize' => 0,
		'log_file' => __DIR__.'/log/log.txt',
		'pid_file' => __DIR__.'/log/server.pid',
	];

	/**
	 * $webserver
	 * @var null
	 */
	public $webserver = null;

	/**
	 * $serverName server服务名称
	 * @var string
	 */
	const SERVER_NAME = SWOOLEFY_HTTP;

	/**
	 * __construct
	 * @param array $config
	 */
	public function __construct(array $config=[]) {
		// 刷新字节缓存
		self::clearCache();
		self::$config = array_merge(
			include(__DIR__.'/config.php'),
			$config
		);
		self::$config['setting'] = self::$setting = array_merge(self::$setting, self::$config['setting']);
		//设置进程模式和socket类型
		self::setSwooleSockType();
		self::setServerName(self::SERVER_NAME);
		self::$server = $this->webserver = new \Swoole\Http\Server(self::$config['host'], self::$config['port'], self::$swoole_process_mode, self::$swoole_socket_type);
		$this->webserver->set(self::$setting);
		parent::__construct();
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->webserver->on('Start', function(\Swoole\Http\Server $server) {
			// 重新设置进程名称
			self::setMasterProcessName(self::$config['master_process_name']);
			// 启动的初始化函数
            try{
                $this->startCtrl->start($server);
            }catch (\Exception $e) {
                self::catchException($e);
            }

		});

		/**
		 * managerstart回调
		 */
		$this->webserver->on('ManagerStart', function(\Swoole\Http\Server $server) {
			// 重新设置进程名称
			self::setManagerProcessName(self::$config['manager_process_name']);
			// 启动的初始化函数
			$this->startCtrl->managerStart($server);
		});

        /**
         * managerstop回调
         */
        $this->webserver->on('ManagerStop', function(\Swoole\Http\Server $server) {
            try{
                $this->startCtrl->managerStop($server);
            }catch (\Exception $e) {
                self::catchException($e);
            }
        });

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->webserver->on('WorkerStart', function(\Swoole\Http\Server $server, $worker_id) {
			// 记录主进程加载的公共files,worker重启不会在加载的
			self::getIncludeFiles($worker_id);
            self::registerShutdownFunction();
			// 重启worker时，刷新字节cache
			self::clearCache();
			// 重新设置进程名称
			self::setWorkerProcessName(self::$config['worker_process_name'], $worker_id, self::$setting['worker_num']);
			// 设置worker工作的进程组
			self::setWorkerUserGroup(self::$config['www_user']);
			// 启动时提前加载文件
			self::startInclude();
			// 记录worker的进程worker_pid与worker_id的映射
			self::setWorkersPid($worker_id, $server->worker_pid);
			// 启动动态运行时的Coroutine
			self::runtimeEnableCoroutine();
			// 超全局变量server
       		Swfy::$server = $this->webserver;
       		Swfy::$config = self::$config;
       		// 启动的初始化函数
			$this->startCtrl->workerStart($server, $worker_id);
			// 延迟绑定
			static::onWorkerStart($server, $worker_id);
			
		});

		/**
		 * worker进程停止回调函数
		 */
		$this->webserver->on('WorkerStop', function(\Swoole\Http\Server $server, $worker_id) {
			// worker停止的触发函数
			$this->startCtrl->workerStop($server,$worker_id);
			
		});

		/**
		 * 接受http请求
		 */
		$this->webserver->on('request', function(Request $request, Response $response) {
			try{
				parent::beforeHandler();
				static::onRequest($request, $response);
				return true;
			}catch(\Exception $e) {
				self::catchException($e);
			}
		});

		/**
		 * 异步任务
		 */
        if(parent::isTaskEnableCoroutine()) {
            $this->webserver->on('task', function(\Swoole\Http\Server $server, \Swoole\Server\Task $task) {
                try{
                    $from_worker_id = $task->worker_id;
                    $task_id = $task->id;
                    $data = $task->data;
                    $task_data = unserialize($data);
                    static::onTask($server, $task_id, $from_worker_id, $task_data, $task);
                }catch(\Exception $e) {
                    self::catchException($e);
                }
            });
        }else {
            $this->webserver->on('task', function(\Swoole\Http\Server $server, $task_id, $from_worker_id, $data) {
                try{
                    $task_data = unserialize($data);
                    static::onTask($server, $task_id, $from_worker_id, $task_data);
                }catch(\Exception $e) {
                    self::catchException($e);
                }

            });
        }

		/**
		 * 处理异步任务
		 */
		$this->webserver->on('finish', function(\Swoole\Http\Server $server, $task_id, $data) {
			try {
				static::onFinish($server, $task_id, $data);
				return true;
			}catch(\Exception $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * 处理pipeMessage
		 */
		$this->webserver->on('pipeMessage', function(\Swoole\Http\Server $server, $src_worker_id, $message) {
			try {
				static::onPipeMessage($server, $src_worker_id, $message);
				return true;
			}catch(\Exception $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * worker进程异常错误回调函数
		 */
		$this->webserver->on('WorkerError', function(\Swoole\Http\Server $server, $worker_id, $worker_pid, $exit_code, $signal) {
			try{
				// worker停止的触发函数
				$this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
			}catch(\Exception $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * worker进程退出回调函数，1.9.17+版本
		 */
        $this->webserver->on('WorkerExit', function(\Swoole\Http\Server $server, $worker_id) {
            try{
                $this->startCtrl->workerExit($server, $worker_id);
            }catch(\Exception $e) {
                self::catchException($e);
            }

        });

		$this->webserver->start();
	}

}
