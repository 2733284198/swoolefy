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

namespace Swoolefy\Core\Memory;

class ChannelManager {

    use \Swoolefy\Core\SingletonTrait;

    private $list = [];

    /**
     * 创建一个数据Channel
     * @param  string  $name
     * @param  int   $capacity
     * @throws mixed
     */
    public function addChannel(string $name, int $capacity = null) {
        if(!isset($this->list[$name])) {
            if($capacity) {
                $channel = new \Swoole\Coroutine\Channel($capacity);
            }else {
                $channel = new \Swoole\Coroutine\Channel();
            }
            $this->list[$name] = $channel;
        }
        return $this;
    }

    /**
     * getChannel
     * @param    string   $name
     * @return   mixed
     */
    public function getChannel(string $name) {
        return $this->list[$name] ?? null;
    }
}