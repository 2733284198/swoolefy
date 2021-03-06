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

namespace Swoolefy\Core\Coroutine;

use Swoole\Coroutine\Channel;

class PoolsHandler {
    /**
     * @var Channel
     */
    protected $channel = null;

    /**
     * @var string
     */
	protected $poolName;

    /**
     * @var int
     */
	protected $poolsNum = 30;

    /**
     * @var int
     */
	protected $pushTimeout = 2;

    /**
     * @var int
     */
	protected $popTimeout = 1;

    /**
     * @var int
     */
	protected $callCount = 0;

    /**
     * @var int
     */
	protected $liveTime = 10;

    /**
     * @var null
     */
	protected $callable = null;

    /**
     * @param int $poolsNum
     */
	public function setPoolsNum(int $poolsNum = 50) {
		$this->poolsNum = $poolsNum;
	}

    /**
     * @return int
     */
	public function getPoolsNum() {
		return $this->poolsNum;
	}

    /**
     * @param float $pushTimeout
     */
	public function setPushTimeout(float $pushTimeout = 3) {
	    $this->pushTimeout = $pushTimeout;
    }

    /**
     * @return int
     */
    public function getPushTimeout() {
	    return $this->pushTimeout;
    }

    /**
     * @param float $popTimeout
     */
    public function setPopTimeout(float $popTimeout = 1) {
	    $this->popTimeout = $popTimeout;
    }

    /**
     * @return int
     */
    public function getPopTimeout() {
	    return $this->popTimeout;
    }

    /**
     * @param int $liveTime
     */
    public function setLiveTime(int $liveTime) {
	    $this->liveTime = $liveTime;
    }

    /**
     * @return int
     */
    public function getLiveTime() {
	    return $this->liveTime;
    }

    /**
     * @return string
     */
	public function getPoolName() {
		return $this->poolName;
	}

    /**
     * @return int
     */
	public function getCapacity() {
		return $this->channel->capacity;
	}

    /**
     * @return Channel
     */
	public function getChannel() {
		if(isset($this->channel)) {
			return $this->channel;
		}
	}

    /**
     * 实例创建执行体
     * @param callable $callback
     */
	public function setBuildCallable(callable $callback) {
	    $this->callable = $callback;
    }

    /**
     * @param string|null $poolName
     */
	public function registerPools(string $poolName = null) {
		if($poolName) {
			$this->poolName = trim($poolName);
			if(!isset($this->channel)) {
                $this->channel = new Channel($this->poolsNum);
        	}
		}
	}

	/**
	 * pushObj 使用完要重新push进channel
	 * @param  object $obj
	 * @return void
	 */
	public function pushObj($obj) {
		if(is_object($obj)) {
		    \Swoole\Coroutine::create(function() use($obj) {
                $isPush = true;
		        if(isset($obj->objExpireTime) && time() > $obj->objExpireTime) {
		            $isPush = false;
                }

                $length = $this->channel->length();
                if($length >= $this->poolsNum) {
                    $isPush = false;
                }

                if($isPush) {
                    $this->channel->push($obj, $this->pushTimeout);
                    $length = $this->channel->length();
                    // 矫正
                    if(($this->poolsNum - $length) == $this->callCount - 1) {
                        --$this->callCount;
                    }else {
                        $this->callCount = $this->poolsNum - $length;
                    }
                }else {
                    --$this->callCount;
                }

                if($this->callCount < 0) {
                	$this->callCount = 0;
                }
            });
		}
	}

    /**
     * @return mixed
     * @throws \Exception
     */
	public function fetchObj() {
		try {
			$obj = $this->getObj();
            is_object($obj) && $this->callCount++;
            return $obj;
		}catch(\Exception $exception) {
			throw $exception;
		}
	}

	/**
	 * getObj
	 * @return mixed
	 */
    protected function getObj() {
        // 第一次开始创建对象
        if($this->callCount == 0 && $this->channel->isEmpty()) {
            if($this->poolsNum) {
                $this->make($this->poolsNum);
            }
        }else {
            if($this->callCount >= $this->poolsNum || $this->channel->isEmpty()) {
                usleep(15 * 1000);
            }
        }
        if($this->channel->length() > 0) {
            return $this->pop();
        }
        return null;
    }

    /**
     * @param int $num
     * @param callable $callable
     * @throws Exception
     */
    protected function make(int $num = 1) {
        for($i=0; $i<$num; $i++) {
            $obj = call_user_func($this->callable, $this->poolName);
            if(!is_object($obj)) {
                throw new \Exception("Pools of {$this->poolName} build instance must return object");
            }
            $obj->objExpireTime = time() + ($this->liveTime) + rand(1,10);
            $this->channel->push($obj, $this->pushTimeout);
        }
    }

    /**
     * @return mixed|null
     */
    protected function pop() {
        $startTime = time();
        while($obj = $this->channel->pop($this->popTimeout)) {
            if(isset($obj->objExpireTime) && time() > $obj->objExpireTime) {
                //re build
                $this->make(1);
                if(time() - $startTime > 1) {
                    $isTimeOut = true;
                    break;
                }
            }else {
                break;
            }
        }

        if($obj === false || (isset($isTimeOut))) {
            unset($obj);
            $newObj = $this->channel->pop($this->popTimeout);
            return is_object($newObj) ? $newObj : null;
        }

        return $obj;
    }
}