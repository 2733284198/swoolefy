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

interface AppInterface {
    /**
     * bootstrap  完成一些必要的程序引导和设置
     * @param mixed $args
     * @return mixed
     */
    public static function bootstrap($args);

}