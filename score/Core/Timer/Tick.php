<?php
namespace Swoolefy\Core\Timer;

use Swoolefy\Core\Swfy;

class Tick {
	/**
     * $_tick_tasks
     * @var array
     */
	protected static $_tick_tasks = [];

    /**
     * $_after_tasks
     * @var array
     */
    protected static $_after_tasks = [];

    /**
     * $_tasks_instances 任务对象类
     * @var array
     */
    protected static $_tasks_instances = [];

    /**
     * tickTimer  循环定时器
     * @param   int      $time_interval
     * @param   callable $func         
     * @param   array    $params       
     * @return  int              
     */
	public static function tickTimer($time_interval, $func, $params = null) {
		if($time_interval <= 0 || $time_interval > 86400000) {
            throw new \Exception(get_called_class()."::tickTimer() the first params 'time_interval' is requested 0-86400000 ms");
            return false;
        }

        if(!is_callable($func)) {
            throw new \Exception(get_called_class()."::tickTimer() the seconed params 'func' is not callable");
            return false;
        }

        $timer_id = self::tick($time_interval, $func, $params);

        return $timer_id;
	}

    /**
     * tick  循环定时器执行
     * @param   int       $time_interval
     * @param   callable  $func         
     * @param   array     $user_params  
     * @return  boolean              
     */
    public static function tick($time_interval, $func, $user_params = null) {
        $tid = swoole_timer_tick($time_interval, function($timer_id, $user_params) use($func) {
            $params = [];
            if($user_params) {
                $params = [$user_params, $timer_id];
            }else {
                $params = [$timer_id];
            }

            if(is_array($func)) {
                list($class, $action) = $func;
                $task_key = $class.'\\'.$action;
                if(self::$_tasks_instances[$task_key]) {
                   $tickTaskInstance = swoole_unpack(self::$_tasks_instances[$task_key]);
                }else {
                    $tickTaskInstance = new $class;
                    self::$_tasks_instances[$task_key] = swoole_pack($tickTaskInstance);
                }
            }
            call_user_func_array([$tickTaskInstance, $action], $params);
            unset($tickTaskInstance, $class, $action, $user_params, $params, $func);
        }, $user_params);

        if($tid) {
            self::$_tick_tasks[$tid] = array('callback'=>$func, 'params'=>$user_params, 'time_interval'=>$time_interval, 'timer_id'=>$tid, 'start_time'=>date('Y-m-d H:i:s',strtotime('now')));
            if(isset(Swfy::$config['table_tick_task']) && Swfy::$config['table_tick_task'] == true) {
                Swfy::$server->table_ticker->set('tick_timer_task',['tick_tasks'=>json_encode(self::$_tick_tasks)]);
            }
            
        }

        return $tid ? $tid : false;
    }

    /**
     * delTicker 删除循环定时器
     * @param    int  $timer_id
     * @return   boolean         
     */
    public static function delTicker($timer_id) {
        $result = swoole_timer_clear($timer_id);
        if($result) {
            foreach(self::$_tick_tasks as $tid=>$value) {
                if($tid == $timer_id) {
                    unset(self::$_tick_tasks[$tid]); 
                }
            }
            if(isset(Swfy::$config['table_tick_task']) && Swfy::$config['table_tick_task'] == true) {
                Swfy::$server->table_ticker->set('tick_timer_task',['tick_tasks'=>json_encode(self::$_tick_tasks)]);
            }
            return true;
        }

        return false;
    }

    /**
     * afterTimer 一次性定时器
     * @param    int       $time_interval
     * @param    callable  $func         
     * @param    array     $params       
     * @return   int              
     */
    public static function afterTimer($time_interval, $func, $params = null) {
        if($time_interval <= 0 || $time_interval > 86400000) {
            throw new \Exception(get_called_class()."::afterTimer() the first params 'time_interval' is requested 0-86400000 ms");
            return false;
        }

        if(!is_callable($func)) {
            throw new \Exception(get_called_class()."::afterTimer() the seconed params 'func' is not callable");
            return false;
        }

        $timer_id = self::after($time_interval, $func, $params);

        return $timer_id;
    }

    /**
     * after 一次性定时器执行
     * @return  boolean
     */
    public static function after($time_interval, $func, $user_params = null) {
        $tid = swoole_timer_after($time_interval, function($user_params) use($func) {
            $params = [];
            if($user_params) {
                $params = [$user_params];
            }
            if(is_array($func)) {
                list($class, $action) = $func;
                $tickTaskInstance = new $class;
            }
            call_user_func_array([$tickTaskInstance, $action], $params);
            // 执行完之后,更新目前的一次性任务项
            self::updateRunAfterTick();
            unset($tickTaskInstance, $class, $action, $user_params, $params, $func);
        }, $user_params);

        if($tid) {
            self::$_after_tasks[$tid] = array('callback'=>$func, 'params'=>$user_params, 'time_interval'=>$time_interval, 'timer_id'=>$tid, 'start_time'=>date('Y-m-d H:i:s',strtotime('now')));
            if(isset(Swfy::$config['table_tick_task']) && Swfy::$config['table_tick_task'] == true) {
                Swfy::$server->table_after->set('after_timer_task',['after_tasks'=>json_encode(self::$_after_tasks)]);
            }
        }

        return $tid ? $tid : false;
    }

    /**
     * updateRunAfterTick 更新一次定时器
     * @return  array
     */
    public static function updateRunAfterTick() {
        if(self::$_after_tasks) {
            // 加上1000,提前1s
            $now = strtotime('now') * 1000 + 1000;
            foreach(self::$_after_tasks as $key=>$value) {
                $end_time = $value['time_interval'] + strtotime($value['start_time']) * 1000;
                if($now >= $end_time) {
                    unset(self::$_after_tasks[$key]);    
                }
            }
            if(isset(Swfy::$config['table_tick_task']) && Swfy::$config['table_tick_task'] == true) {
                Swfy::$server->table_after->set('after_timer_task',['after_tasks'=>json_encode(self::$_after_tasks)]);
            }
        }
        return ;
    }


}