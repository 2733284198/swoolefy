<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

use Swoole\Server;
use Swoolefy\Core\Process\ProcessManager;

class EventCtrl implements \Swoolefy\Core\EventCtrlInterface {
	/**
	 * init start之前初始化
     * @return void
	 */
	public function init() {
        static::eachStartInfo();
        static::onInit();
		if(BaseServer::isEnableSysCollector()) {
			ProcessManager::getInstance()->addProcess('swoolefy_system_collector', \Swoolefy\Core\SysCollector\SysProcess::class);
		}
		if(BaseServer::isEnableReload()) {
            ProcessManager::getInstance()->addProcess('swoolefy_system_reload',\Swoolefy\AutoReload\ReloadProcess::class);
        }
	}

	/**
	 * onStart 
	 * @param Server $server
	 * @return void
	 */
	public function start($server) {
		static::onStart($server);
	}

	/**
	 * onManagerStart 
	 * @param Server $server
	 * @return void
	 */
	public function managerStart($server) {
		static::onManagerStart($server);
	}

	/**
	 * onWorkerStart
	 * @param Server $server
	 * @return void
	 */
	public function workerStart($server, $worker_id) {
        $this->buildComponentPools();
		static::onWorkerStart($server, $worker_id);
	}

	/**
	 * onWorkerStop
	 * @param Server $server
	 * @param int $worker_id
	 * @return void
	 */
	public function workerStop($server, $worker_id) {
		static::onWorkerStop($server, $worker_id);
	}

	/**
	 * workerError 
	 * @param  Server $server
	 * @param  int $worker_id
	 * @param  int $worker_pid
	 * @param  mixed $exit_code
	 * @param  boolean $signal
	 * @return void
	 */
	public function workerError($server, $worker_id, $worker_pid, $exit_code, $signal) {
		static::onWorkerError($server, $worker_id, $worker_pid, $exit_code, $signal);
	}

	/**
	 * workerExit 1.9.17+版本支持
	 * @param  Server $server
	 * @param  int $worker_id
	 * @return void
	 */
	public function workerExit($server, $worker_id) {
		static::onWorkerExit($server, $worker_id);
	}

	/**
	 * onManagerStop
	 * @param  Server $server
	 * @return void
	 */
	public function managerStop($server) {
		static::onManagerStop($server);
	}

    /**
     * 在workerStart可以创建一个协程池Channel
     * 'enable_component_pools' => [
        'redis' => [
            'pools_num'=>10,
            'push_timeout'=>1.5,
            'pop_timeout'=>1,
            'live_time'=>10 * 60
        ]
    ],
     *
     * @throws mixed
     */
	protected function buildComponentPools() {
        $app_conf = BaseServer::getAppConf();
        if(isset($app_conf['enable_component_pools']) && is_array($app_conf['enable_component_pools']) && !empty($app_conf['enable_component_pools'])) {
            $components = array_keys($app_conf['components']);
            foreach($app_conf['enable_component_pools'] as $pool_name =>$component_pool_config) {
                if(!in_array($pool_name, $components)) {
                    throw new \Exception("enable_component_pools of item={$pool_name},not set in components");
                }
                $callable = \Swoolefy\Core\Swfy::getAppConf()['components'][$pool_name];
                \Swoolefy\Core\Coroutine\CoroutinePools::getInstance()->addPool($pool_name, $component_pool_config, $callable);
            }
        }
    }

    /**
     * eachStartInfo
     */
	protected function eachStartInfo() {
        $protocol = BaseServer::getServiceProtocol();
        switch($protocol) {
            case SWOOLEFY_HTTP :
                $main_server = 'HttpServer';
                break;
            case SWOOLEFY_WEBSOCKET :
                $main_server = 'WebsockServer';
                break;
            case SWOOLEFY_TCP :
                $main_server = 'RpcServer';
                break;
            case SWOOLEFY_UDP :
                $main_server = 'UdpServer';
                break;
            default:
                $main_server = 'HttpServer';
        }
        $conf = Swfy::getConf();
        $daemonize = isset($conf['setting']['daemonize']) ? $conf['setting']['daemonize'] : false;
        $listen_host = isset($conf['host']) ? $conf['host'] : '127.0.0.1';
        $listen_port = isset($conf['port']) ? $conf['port'] : null;
        $worker_num  = isset($conf['setting']['worker_num']) ? $conf['setting']['worker_num'] : 1;
        $task_worker_num = isset($conf['setting']['task_worker_num']) ? $conf['setting']['task_worker_num'] : 0;
        $swoole_version = swoole_version();
        $php_version = phpversion();
        $swoolefy_version = SWOOLEFY_VERSION;
        $swoolefy_env = defined('SWOOLEFY_ENV') ? SWOOLEFY_ENV : null;
        $cpu_num = swoole_cpu_num();
        $ip_list = json_encode(swoole_get_local_ip());
        $this->each(str_repeat('-',50),'light_green');
        $this->each("
            main server         {$main_server}
            swoolefy envirment  {$swoolefy_env}
            daemonize           {$daemonize}
            listen address      {$listen_host}
            listen port         {$listen_port}
            worker num          {$worker_num}
            task worker num     {$task_worker_num}
            cpu num             {$cpu_num}
            swoole version      {$swoole_version}
            php version         {$php_version}
            swoolefy version    {$swoolefy_version}
            ip_list             {$ip_list}
            tips                执行 php swoolefy help 可以查看更多信息
",'light_green');
        $this->each(str_repeat('-',50)."\n",'light_green');

    }

    /**
     * _each
     * @param string $msg
     * @param string $foreground
     * @param string $background
     */
    protected function each(string $msg, string $foreground = "red", string $background = "black") {
	    _each($msg, $foreground, $background);
    }
} 