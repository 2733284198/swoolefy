<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Library\Db;

use PDO;
use PDOStatement;

abstract class PDOConnection implements ConnectionInterface {

    const PARAM_FLOAT = 21;

    /**
     * 数据库连接参数配置
     * @var array
     */
    protected $config = [
        // 服务器地址
        'hostname'        => '',
        // 数据库名
        'database'        => '',
        // 用户名
        'username'        => '',
        // 密码
        'password'        => '',
        // 端口
        'hostport'        => '',
        // 连接dsn
        'dsn'             => '',
        // 数据库连接参数
        'params'          => [],
        // 数据库编码默认采用utf8
        'charset'         => 'utf8',
        // 数据库表前缀
        'prefix'          => '',
        // fetchType
        'fetch_type' => PDO::FETCH_ASSOC,
        // 是否需要断线重连
        'break_reconnect' => false,
        // 是否支持事务嵌套
        'support_savepoint' => false
    ];

    /**
     * @var \PDO
     */
    protected $PDOInstance;

    /**
     * PDO操作实例
     * @var \PDOStatement
     */
    protected $PDOStatement;

    /**
     * 当前SQL指令
     * @var string
     */
    protected $queryStr = '';

    /**
     * @var int
     */
    private $transTimes;

    /**
     * @var array
     */
    protected $logs = [];

    /**
     * @var int
     */
    private $reConnectTimes;

    /**
     * @var array
     */
    private $bind;

    /**
     * @var int
     */
    private $fetchType = PDO::FETCH_ASSOC;

    /**
     * @var int
     */
    private $attrCase = PDO::CASE_LOWER;

    /**
     * @var int
     */
    private $numRows;

    /**
     * 数据表字段信息
     * @var array
     */
    protected $tableFields = [];

    /**
     * @var array
     */
    protected $info = [];

    /**
     * PDO连接参数
     * @var array
     */
    protected $params = [
        PDO::ATTR_CASE              => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS      => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES  => false,
    ];

    /**
     * 参数绑定类型映射
     * @var array
     */
    protected $bindType = [
        'string'    => PDO::PARAM_STR,
        'str'       => PDO::PARAM_STR,
        'integer'   => PDO::PARAM_INT,
        'int'       => PDO::PARAM_INT,
        'boolean'   => PDO::PARAM_BOOL,
        'bool'      => PDO::PARAM_BOOL,
        'float'     => self::PARAM_FLOAT,
        'datetime'  => PDO::PARAM_STR,
        'timestamp' => PDO::PARAM_STR,
    ];

    /**
     * 服务器断线标识字符
     * @var array
     */
    protected $breakMatchStr = [
        'server has gone away',
        'no connection to the server',
        'Lost connection',
        'is dead or not enabled',
        'Error while sending',
        'decryption failed or bad record mac',
        'server closed the connection unexpectedly',
        'SSL connection has been closed unexpectedly',
        'Error writing data to the connection',
        'Resource deadlock avoided',
        'failed with errno',
    ];

    /**
     * @param array $config 数据库配置数组
     */
    public function __construct(array $config = []) {
        $this->config = array_merge($this->config, $config);
        $this->fetchType = $this->config['fetch_type'] ?: PDO::FETCH_ASSOC;
    }

    /**
     * 连接数据库方法
     * @param array $config
     * @param bool $autoConnection
     * @param bool $force
     * @return mixed|PDO
     */
    public function connect(array $config = [], bool $autoConnection = true, bool $force = false)
    {
        if(!$force) {
            if($this->PDOInstance) return $this->PDOInstance;
        }

        $this->config = array_merge($this->config, $config);
        if(isset($this->config['params']) && is_array($this->config['params'])) {
            $params = $this->config['params'] + $this->params;
        } else {
            $params = $this->params;
        }

        try {
            if(empty($this->config['dsn'])) {
                $this->config['dsn'] = $this->parseDsn();
            }
            $startTime = microtime(true);
            $this->PDOInstance = $this->createPdo($this->config['dsn'], $this->config['username'], $this->config['password'], $params);
            $endTime = microtime(true);
            $this->log(__FUNCTION__, 'Connect successful, Spend Time='.($endTime - $startTime));
            return $this->PDOInstance;
        } catch (\PDOException $e) {
            if($autoConnection) {
                $this->log(__FUNCTION__, 'Connect failed, errorMsg='.$e->getMessage());
                $this->log(__FUNCTION__, 'Start try connect once again');
                return $this->connect([], false, true);
            }else {
                throw $e;
            }
        }
    }

    /**
     * @param bool $force
     */
    protected function initConnect(bool $force = false): void
    {
        $this->connect($this->config, true, $force);
    }

    /**
     * 创建PDO实例
     * @param $dsn
     * @param $username
     * @param $password
     * @param $params
     * @return PDO
     */
    protected function createPdo($dsn, $username, $password, $params)
    {
        return new PDO($dsn, $username, $password, $params);
    }

    /**
     * @param string $sql
     * @param array $bind
     * @param bool $procedure
     * @return PDOStatement
     * @throws PDOException
     * @throws Exception
     * @throws Throwable
     */
    public function PDOStatementHandle(string $sql, array $bind = []): PDOStatement
    {
        $this->initConnect();

        // 记录SQL语句
        $this->queryStr = $sql;

        $this->bind = $bind;

        try {
            $queryStartTime = microtime(true);
            $this->log('Execute sql start','bindParams='.json_encode($bind, JSON_UNESCAPED_UNICODE));
            // 预处理
            $this->PDOStatement = $this->PDOInstance->prepare($sql);
            // 参数绑定
            $this->bindValue($bind);
            // 执行查询
            $this->PDOStatement->execute();
            $this->reConnectTimes = 0;
            $queryEndTime = microtime(true);
            $this->log('Execute sql end','Execute successful, Execute time='.($queryEndTime - $queryStartTime));
            return $this->PDOStatement;
        } catch (\Throwable | \Exception $e) {
            if($this->reConnectTimes < 4 && $this->isBreak($e)) {
                ++$this->reConnectTimes;
                return $this->close()->PDOStatementHandle($sql, $bind);
            }
            throw $e;
        }
    }

    /**
     * @return PDOStatement|null
     */
    public function getPDOStatement(): PDOStatement
    {
        return $this->PDOStatement ?? null;
    }

    /**
     * 获取PDO对象
     * @access public
     * @return \PDO|false
     */
    public function getPdo(): PDo
    {
        if(!$this->PDOInstance) {
            return false;
        }
        return $this->PDOInstance;
    }

    /**
     * 参数绑定
     * 支持 [':name'=>'value',':id'=>123] 对应命名占位符
     * 或者 ['value',123] 对应问号占位符
     * @access public
     * @param array $bind 要绑定的参数列表
     * @return void
     * @throws \Exception
     */
    protected function bindValue(array $bind = []): void
    {
        foreach ($bind as $key => $val) {
            // 占位符
            $param = is_numeric($key) ? $key + 1 : $key;

            if (is_array($val)) {
                if (PDO::PARAM_INT == $val[1] && '' === $val[0]) {
                    $val[0] = 0;
                } elseif (self::PARAM_FLOAT == $val[1]) {
                    $val[0] = is_string($val[0]) ? (float) $val[0] : $val[0];
                    $val[1] = PDO::PARAM_STR;
                }
                $result = $this->PDOStatement->bindValue($param, $val[0], $val[1]);
            } else {
                $result = $this->PDOStatement->bindValue($param, $val);
            }

            if(!$result) {
                throw new \Exception("Error occurred  when binding parameters '{$param}',lastSql=".$this->getRealSql($this->queryStr, $bind));
            }
        }
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @return array
     */
    public function query(string $sql, array $bindParams = []): array
    {
        $this->initConnect();
        $this->PDOStatementHandle($sql, $bindParams);
        return $this->getResult();
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @return int
     */
    public function execute(string $sql, array $bindParams = []): int
    {
        $this->initConnect();
        $this->PDOStatementHandle($sql, $bindParams);
        $this->numRows = $this->PDOStatement->rowCount();
        return $this->numRows;
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @return int
     */
    public function insert(string $sql, array $bindParams = []): int
    {
        $this->execute($sql, $bindParams);
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @return int
     */
    public function update(string $sql, array $bindParams = []): int
    {
        $this->execute($sql, $bindParams);
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @return int
     * @throws \Throwable
     */
    public function delete(string $sql, array $bindParams = []): int
    {
        $this->execute($sql, $bindParams);
    }

    /**
     * 获得数据集数组
     * @return array
     */
    protected function getResult(): array
    {
        $result = $this->PDOStatement->fetchAll($this->fetchType);

        $this->numRows = count($result);

        return $result;
    }

    /**
     * 解析pdo连接的dsn信息
     * @param array $config 连接信息
     * @return string
     */
    abstract protected function parseDsn();

    /**
     * 取得数据表的字段信息
     * @param string $tableName 数据表名称
     * @return array
     */
    abstract public function getFields(string $tableName);

    /**
     * 取得数据库的表信息
     * @param string $dbName 数据库名称
     * @return array
     */
    abstract public function getTables(string $dbName);

    /**
     * 获取数据库的配置参数
     * @param string $name
     * @return array|mixed|string
     */
    public function getConfig(string $name = '')
    {
        if($name) {
            return $this->config[$name] ?? '';
        }

        return $this->config;
    }

    /**
     * 获取数据表信息
     * @param string  $tableName 数据表名
     * @param string $fetch     获取信息类型 值包括 fields type bind pk
     * @return mixed
     */
    public function getTableInfo(string $tableName, string $fetch = '')
    {

        $info = $this->getSchemaInfo($tableName);

        return $fetch ? $info[$fetch] : $info;
    }

    /**
     * 获取数据表的自增主键
     * @param mixed $tableName 数据表名
     * @return string
     */
    public function getAutoInc($tableName)
    {
        return $this->getTableInfo($tableName, 'autoinc');
    }

    /**
     * @param string $tableName 数据表名称
     * @param bool $force 强制从数据库获取
     * @return array
     */
    public function getSchemaInfo(string $tableName, bool $force = false)
    {
        if (!strpos($tableName, '.')) {
            $schema = $this->getConfig('database') . '.' . $tableName;
        } else {
            $schema = $tableName;
        }

        if (!isset($this->info[$schema]) || $force) {
            $info = $this->getTableFieldsInfo($tableName);
            $pk      = $info['_pk'] ?? null;
            $autoinc = $info['_autoinc'] ?? null;
            unset($info['_pk'], $info['_autoinc']);

            $bind = [];
            foreach ($info as $name => $val) {
                $bind[$name] = $this->getFieldBindType($val);
            }

            $this->info[$schema] = [
                'fields'  => array_keys($info),
                'type'    => $info,
                'bind'    => $bind,
                'pk'      => $pk,
                'autoinc' => $autoinc,
            ];
        }

        return $this->info[$schema];
    }

    /**
     * 获取数据表的字段信息
     * @param string $tableName 数据表名
     * @return array
     */
    public function getTableFieldsInfo(string $tableName): array
    {
        $fields = $this->getFields($tableName);
        $info   = [];

        foreach ($fields as $key => $val) {
            // 记录字段类型
            $info[$key] = $this->getFieldType($val['type']);

            if (!empty($val['primary'])) {
                $pk[] = $key;
            }

            if (!empty($val['autoinc'])) {
                $autoinc = $key;
            }
        }

        if (isset($pk)) {
            // 设置主键
            $pk          = count($pk) > 1 ? $pk : $pk[0];
            $info['_pk'] = $pk;
        }

        if (isset($autoinc)) {
            $info['_autoinc'] = $autoinc;
        }

        return $info;
    }

    /**
     * 获取字段类型
     * @param string $type 字段类型
     * @return string
     */
    protected function getFieldType(string $type): string
    {
        if (0 === strpos($type, 'set') || 0 === strpos($type, 'enum')) {
            $result = 'string';
        } elseif (preg_match('/(double|float|decimal|real|numeric)/is', $type)) {
            $result = 'float';
        } elseif (preg_match('/(int|serial|bit)/is', $type)) {
            $result = 'int';
        } elseif (preg_match('/bool/is', $type)) {
            $result = 'bool';
        } elseif (0 === strpos($type, 'timestamp')) {
            $result = 'timestamp';
        } elseif (0 === strpos($type, 'datetime')) {
            $result = 'datetime';
        } elseif (0 === strpos($type, 'date')) {
            $result = 'date';
        } else {
            $result = 'string';
        }

        return $result;
    }

    /**
     * 获取字段绑定类型
     * @param string $type 字段类型
     * @return integer
     */
    public function getFieldBindType(string $type): int
    {
        if (in_array($type, ['integer', 'string', 'float', 'boolean', 'bool', 'int', 'str'])) {
            $bind = $this->bindType[$type];
        } elseif (0 === strpos($type, 'set') || 0 === strpos($type, 'enum')) {
            $bind = PDO::PARAM_STR;
        } elseif (preg_match('/(double|float|decimal|real|numeric)/is', $type)) {
            $bind = self::PARAM_FLOAT;
        } elseif (preg_match('/(int|serial|bit)/is', $type)) {
            $bind = PDO::PARAM_INT;
        } elseif (preg_match('/bool/is', $type)) {
            $bind = PDO::PARAM_BOOL;
        } else {
            $bind = PDO::PARAM_STR;
        }

        return $bind;
    }


    /**
     * 对返数据表字段信息进行大小写转换出来
     * @param array $info 字段信息
     * @return array
     */
    public function fieldCase(array $info): array
    {
        // 字段大小写转换
        switch ($this->attrCase) {
            case PDO::CASE_LOWER:
                $info = array_change_key_case($info);
                break;
            case PDO::CASE_UPPER:
                $info = array_change_key_case($info, CASE_UPPER);
                break;
            case PDO::CASE_NATURAL:
            default:
                // 不做转换
        }

        return $info;
    }

    /**
     * 启动事务
     * @return void
     * @throws \Throwable
     */
    public function beginTransaction()
    {
        $this->initConnect();
        $this->PDOInstance->beginTransaction();
        ++$this->transTimes;

        try {
            if (1 == $this->transTimes) {
                $this->PDOInstance->beginTransaction();
            } elseif ($this->transTimes > 1 && $this->supportSavepoint()) {
                $this->PDOInstance->exec(
                    $this->parseSavepoint('trans' . $this->transTimes)
                );
            }
            $this->reConnectTimes = 0;
        } catch (\Throwable $e) {
            if ($this->reConnectTimes < 4 && $this->isBreak($e)) {
                --$this->transTimes;
                ++$this->reConnectTimes;
                $this->close()->beginTransaction();
            }
            throw $e;
        }

    }

    /**
     * 是否支持事务嵌套
     * @return bool
     */
    protected function supportSavepoint(): bool
    {
        return $this->config['support_savepoint'] ?? false;
    }

    /**
     * 生成定义保存点的SQL
     * @param string $name 标识
     * @return string
     */
    protected function parseSavepoint(string $name): string
    {
        return 'SAVEPOINT ' . $name;
    }

    /**
     * 生成回滚到保存点的SQL
     * @return string
     */
    protected function parseSavepointRollBack(string $name): string
    {
        return 'ROLLBACK TO SAVEPOINT ' . $name;
    }

    /**
     * 用于非自动提交状态下面的查询提交
     * @return void
     * @throws \Exception
     */
    public function commit()
    {
        $this->initConnect();
        if (1 == $this->transTimes) {
            $this->PDOInstance->commit();
        }
        --$this->transTimes;
    }


    /**
     * 事务回滚
     * @return void
     * @throws \Exception
     */
    public function rollback()
    {
        $this->initConnect();
        if (1 == $this->transTimes) {
            $this->PDOInstance->rollBack();
        } elseif ($this->transTimes > 1 && $this->supportSavepoint()) {
            $this->PDOInstance->exec(
                $this->parseSavepointRollBack('trans' . $this->transTimes)
            );
        }

        $this->transTimes = max(0, $this->transTimes - 1);
    }

    /**
     * 启动XA事务
     * @param  string $xid XA事务id
     * @return void
     */
    public function startTransXa(string $xid) {}

    /**
     * 预编译XA事务
     * @param  string $xid XA事务id
     * @return void
     */
    public function prepareXa(string $xid) {}

    /**
     * 提交XA事务
     * @param  string $xid XA事务id
     * @return void
     */
    public function commitXa(string $xid) {}

    /**
     * 回滚XA事务
     * @param  string $xid XA事务id
     * @return void
     */
    public function rollbackXa(string $xid) {}

    /**
     * 关闭数据库（或者重新连接）
     * @return $this
     */
    public function close()
    {
        $this->logs = [];
        $this->free();
        return $this;
    }

    /**
     * 释放查询结果
     * @access public
     */
    public function free(): void
    {
        $this->PDOStatement = null;
    }

    /**
     * 是否断线
     * @param \PDOException|\Exception $e 异常对象
     * @return bool
     */
    protected function isBreak($e): bool
    {
        if (!$this->config['break_reconnect']) {
            return false;
        }

        $error = $e->getMessage();

        foreach ($this->breakMatchStr as $msg) {
            if (false !== stripos($error, $msg)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 根据参数绑定组装最终的SQL语句 便于调试
     * @param string $sql  带参数绑定的sql语句
     * @param array  $bind 参数绑定列表
     * @return string
     */
    public function getRealSql(string $sql, array $bind = []): string
    {
        foreach ($bind as $key => $val) {
            $value = is_array($val) ? $val[0] : $val;
            $type  = is_array($val) ? $val[1] : PDO::PARAM_STR;

            if ((self::PARAM_FLOAT == $type || PDO::PARAM_STR == $type) && is_string($value)) {
                $value = '\'' . addslashes($value) . '\'';
            } elseif (PDO::PARAM_INT == $type && '' === $value) {
                $value = 0;
            }

            // 判断占位符
            $sql = is_numeric($key) ?
                substr_replace($sql, $value, strpos($sql, '?'), 1) :
                substr_replace($sql, $value, strpos($sql, ':' . $key), strlen(':' . $key));
        }

        return rtrim($sql);
    }

    /**
     * 获取最近插入的ID
     * @param string  $sequence 自增序列名
     * @return mixed
     */
    public function getLastInsID($sequence = null)
    {
        try {
            $insertId = $this->PDOInstance->lastInsertId($sequence);
        } catch (\Exception $e) {
            $insertId = '';
        }
        if(is_numeric($insertId)) {
            $insertId = (int)$insertId;
        }

        return $insertId;
    }

    /**
     * 获取最近一次查询的sql语句
     * @access public
     * @return string
     */
    public function getLastSql(): string
    {
        return $this->getRealSql($this->queryStr, $this->bind);
    }

    /**
     * 获取最近的错误信息
     * @access public
     * @return string
     */
    public function getLastError(): string
    {
        if ($this->PDOStatement) {
            $error = $this->PDOStatement->errorInfo();
            $error = $error[1] . ':' . $error[2];
        } else {
            $error = '';
        }

        if ('' != $this->queryStr) {
            $error .= "\n [ SQL语句 ] : " . $this->getLastsql();
        }

        return $error;
    }

    /**
     * @param $action
     * @param string $msg
     */
    protected function log($action, $msg = '') {
        array_push($this->logs, [$action, $msg]);
    }

    /**
     *
     */
    public function getLog() {
        return $this->logs;
    }

}