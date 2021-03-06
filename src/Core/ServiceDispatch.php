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

use Swoolefy\Core\Swfy;
use Swoolefy\Core\AppDispatch;
use Swoolefy\Core\Application;

class ServiceDispatch extends AppDispatch {
	/**
	 * $callable 远程调用函数对象类
	 * @var array
	 */
	protected $callable = [];

	/**
	 * $params 远程调用参数
	 * @var null
	 */
	protected $params = null;

	/**
	 * __construct 
	 */
	public function __construct($callable, $params, $rpc_pack_header = []) {
		// 执行父类
		parent::__construct();
		$this->callable = $callable;
		$this->params = $params;
		Application::getApp()->setMixedParams($params);
		Application::getApp()->setRpcPackHeader($rpc_pack_header);
	}

    /**
     * dispatch 路由调度
     * @return  mixed
     * @throws \Exception
     */
	public function dispatch() {
		list($class, $action) = $this->callable;
        $class = trim(str_replace('\\', '/', $class), '/');
		if(!isset(self::$routeCacheFileMap[$class])) {
			if(!$this->checkClass($class)) {
                $this->errorHandle($class, $action, 'return404');
                return false;
			}
		}
		
		$class = str_replace('/','\\', $class);
		/**@var \Swoolefy\Core\Task\TaskService $serviceInstance */
		$serviceInstance = new $class();
		$serviceInstance->setMixedParams($this->params);
		if(isset($this->from_worker_id) && isset($this->task_id)) {
            $serviceInstance->setFromWorkerId($this->from_worker_id);
            $serviceInstance->setTaskId($this->task_id);
            if(!empty($this->task)) {
                $serviceInstance->setTask($this->task);
            }
        }

		try{
			if(method_exists($serviceInstance, $action)) {
			    // before Call
                $isContinueAction = $serviceInstance->_beforeAction($action);
                if($isContinueAction === false) {
                    // end
                    return false;
                }
                // next Call
				$serviceInstance->$action($this->params);
                // after Call
                $serviceInstance->_afterAction($action);
			}else {
			    $this->errorHandle($class, $action, 'return500');
                return false;
			}
		}catch(\Throwable $t) {
			$errorMsg = $t->getMessage().' on '.$t->getFile().' on line '.$t->getLine().' ||| '.$class.'::'.$action.' ||| '.json_encode($this->params,JSON_UNESCAPED_UNICODE);
			if(Swfy::isWorkerProcess()) {
                $this->getErrorHandle()->returnError($errorMsg);
            }
            // 记录错误异常
            $exceptionClass = Application::getApp()->getExceptionClass();
            $exceptionClass::shutHalt($errorMsg);
            return false;
		}
		
	}

    /**
     * @param $class
     * @param $action
     * @param string $errorMethod
     * @return boolean
     * @throws \Exception
     */
	protected function errorHandle($class, $action, $errorMethod = 'return404') {
        if(Swfy::isWorkerProcess()) {
            $notFoundInstance = $this->getErrorHandle();
            $errorMsg = $notFoundInstance->{$errorMethod}($class, $action);
        }
        // 记录错误异常
        $msg = isset($errorMsg['msg']) ? $errorMsg['msg'] : "Call undefined function {$class}::{$action}";
        $exceptionClass = Application::getApp()->getExceptionClass();
        $exceptionClass::shutHalt($msg);
        return true;
    }

    /**
     * @return NotFound
     */
    protected function getErrorHandle() {
        $app_conf = Swfy::getAppConf();
        $notFoundInstance = new \Swoolefy\Core\NotFound();
        if(isset($app_conf['not_found_handler']) && is_string($app_conf['not_found_handler'])) {
            $handle = $app_conf['not_found_handler'];
            $notFoundInstance = new $handle;
        }
        return $notFoundInstance;
    }

    /**
     * @param int $from_worker_id
     * @param int $task_id
     */
	public function setFromWorkerIdAndTaskId(int $from_worker_id, int $task_id, $task = null) {
	    $this->from_worker_id = $from_worker_id;
	    $this->task_id = $task_id;
	    $this->task = $task;
    }

	/**
	 * checkClass 检查请求实例文件是否存在
	 * @param  string  $class
	 * @return boolean
	 */
	public function checkClass($class) {
        if(isset(self::$routeCacheFileMap[$class])) {
            return true;
        }
		$file = ROOT_PATH.DIRECTORY_SEPARATOR.$class.'.php';
		if(is_file($file)) {
			self::$routeCacheFileMap[$class] = true;
			return true;
		}
		return false;
	}

}