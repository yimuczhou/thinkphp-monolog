<?php
/**
 * Monolog.php
 *
 * @author YangHui<yanghui@y-sir.com>
 * @version 0.1
 * @createTime 2019/6/18 6:34 PM
 */

namespace think\log\driver;


use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use think\App;
use Monolog\Logger;

/**
 * Class Monolog
 * @package think\log\driver
 */
class Monolog
{
    protected $config = [
        'time_format' => 'Y-m-d H:i:s.u',
        'single' => false,
        'file_size' => 2097152,
        'path' => '',
        'apart_level' => [],
        'max_files' => 0,
        'json' => false,
        'log_name' => 'App',
        'file_name' => 'app.log',
        'sql_file_name' => 'sql.log',
        'log_format' => "[%datetime%] [#reqId#] [%level_name%] %message%\n",
        'ignore_tp_log' => true, //忽略tp框架日志
        'apart_sql' => true, //分开sql日志
    ];

    /**
     * @var array
     */
    protected static $loggerLevelMap = [];

    /**
     * @var App
     */
    protected $app;

    /**
     * @var \Monolog\Logger
     */
    protected $logger;

    /**
     * @var \Monolog\Logger
     */
    protected $sqlLogger;

    const LOG_SQL = 'SQL';
    const LOG_INFO = 'INFO';
    const LOG_REQUEST_ID = 'HTTP_REQUEST_ID';

    // 实例化并传入参数
    public function __construct(App $app, array $config = [])
    {
        $this->app = $app;

        if (is_array($config)) {
            $this->config = array_merge($this->config, $config);
        }

        if (empty($this->config['path'])) {
            $this->config['path'] = $this->app->getRuntimePath() . 'log' . DIRECTORY_SEPARATOR;
        } elseif (substr($this->config['path'], -1) != DIRECTORY_SEPARATOR) {
            $this->config['path'] .= DIRECTORY_SEPARATOR;
        }
        $this->init();
    }


    /**
     * @return Monolog
     */
    public function init(): Monolog
    {
        $fileName = $this->config['path'] . $this->config['file_name'];
        $this->logger = $this->initLogger($fileName);

        if ($this->isApartSqlLog()) {
            $sqlFileName = $this->config['path'] . $this->config['sql_file_name'];
            $this->sqlLogger = $this->initLogger($sqlFileName);
        } else {
            $this->sqlLogger = $this->logger;
        }

        $this->getLoggerLevelMap();

        return $this;
    }

    /**
     * 初始化日志handler
     *
     * @param string $fileName
     * @return Monolog
     */
    public function initLogger(string $fileName): \Monolog\Logger
    {
        $handler = new Logger($this->config['log_name']);
        $streamHandler = new RotatingFileHandler($fileName, 30, Logger::DEBUG, true, 0664);
        $formatter = new LineFormatter(str_replace("#reqId#", $this->getRequestId(), $this->config['log_format']),
            $this->config['time_format']);
        $streamHandler->setFormatter($formatter);
        $handler->pushHandler($streamHandler);
        return $handler;
    }

    /**
     * 是分开sql日志
     * @return bool
     */
    protected function isApartSqlLog(): bool
    {
        return $this->config['apart_sql'];
    }

    /**
     * 获取日志级别对照关系
     * @return $this
     */
    public function getLoggerLevelMap(): Monolog
    {
        static::$loggerLevelMap = Logger::getLevels();
        return $this;
    }

    /**
     * 封装monolog
     *
     * @param array $log
     * @param bool $append
     */
    public function save(array $log = [], $append = false)
    {
        $this->rotating();

        if ('cli' != PHP_SAPI) {
            $this->appendExtraInfo($log);
        }

        foreach ($log as $type => $val) {
            foreach ($val as $msg) {

                if (!is_string($msg)) {
                    $msg = var_export($msg, true);
                }

                //忽略不需要记录的内容
                if ($this->ignoreTPLogContent($msg)) {
                    continue;
                }

                $msg = PHP_SAPI == 'cli' ? '[CLI] ' . $msg : $msg;
                $type = strtoupper($type);
                $this->write($msg, $type);
            }
        }
    }

    /**
     * 写入
     *
     * @param string $msg
     * @param string $type
     */
    protected function write(string $msg, string $type)
    {
        if (isset(self::$loggerLevelMap[$type])) {
            $this->logger->addRecord(self::$loggerLevelMap[$type], $msg);
        } else {
            $type == self::LOG_SQL ? $this->sqlLogger->info($msg) : $this->logger->debug($msg);
        }
    }


    /**
     *忽略tp框架一些日志
     * @param string $content
     * @return bool
     */
    protected function ignoreTPLogContent(string $content): bool
    {
        if (!$this->config['ignore_tp_log']) {
            return false;
        }

        $arr = [
            '[ LANG ]',
            '[ ROUTE ]',
            '[ PARAM ]',
            '[ DB ]'
        ];
        $ignore = false;
        foreach ($arr as $val) {
            if (strpos($content, $val) !== false) {
                $ignore = true;
                break;
            }
        }
        return $ignore;
    }

    /**
     * 旋转日志文件
     */
    protected function rotating()
    {
        $appLogHandler = $this->logger->getHandlers()[0];
        if ($appLogHandler) {
            $this->checkLogSize($appLogHandler->getUrl());
        }

        $sqlLogHandler = $this->sqlLogger->getHandlers()[0];
        if ($sqlLogHandler) {
            $this->checkLogSize($sqlLogHandler->getUrl());
        }
    }

    /**
     * 判断当天日志文件是否超过限制
     *
     * @param $destination
     */
    protected function checkLogSize($destination)
    {
        if (is_file($destination) && floor($this->config['file_size']) <= filesize($destination)) {
            try {
                rename($destination, $this->getCurrDateLogNewFileName($destination));
            } catch (\Exception $e) {
                error_log("日志重命名失败");
            }
        }
    }

    /**
     * 检查当天日志总数
     *
     * @param $destination
     * @return int
     */
    protected function checkCurrDateLogCount($destination)
    {
        return count(glob($destination . ".[0-9]*"));
    }

    /**
     * 获取当天新日志文件名称
     *
     * @param $destination
     * @return string
     */
    protected function getCurrDateLogNewFileName($destination)
    {
        $c = $this->checkCurrDateLogCount($destination) + 1;
        return $destination . "." . $c;
    }


    /**
     * 生成唯一请求ID
     * @return string
     */
    protected function getRequestId()
    {
        static $requestId;
        if (empty($requestId)) {
            $requestId = isset($_SERVER[self::LOG_REQUEST_ID]) && !empty($_SERVER[self::LOG_REQUEST_ID]) ? $_SERVER[self::LOG_REQUEST_ID] : md5(time() . rand(1,
                    1000));
        }
        return $requestId;
    }

    /**
     * 追加额外的信息
     *
     * @param $info
     * @return Monolog
     */
    protected function appendExtraInfo(&$info): Monolog
    {
        $this->appendDebugInfo($info)->appendHostInfo($info);
        return $this;
    }

    /**
     * 追加debug信息
     *
     * @param $info
     * @return $this
     */
    protected function appendDebugInfo(&$info): Monolog
    {
        if ($this->app->isDebug()) {

            $runtime = round(microtime(true) - $this->app->getBeginTime(), 10);
            $reqs = $runtime > 0 ? number_format(1 / $runtime, 2) : '∞';

            $memory_use = number_format((memory_get_usage() - $this->app->getBeginMem()) / 1024, 2);

            $time_str = '[运行时间：' . number_format($runtime, 6) . 's 吞吐率：' . $reqs . 'req/s';
            $memory_str = ' 内存消耗：' . $memory_use . 'kb';
            $file_load = ' 文件加载：' . count(get_included_files()) . ']';

            $data[self::LOG_INFO] = $time_str . $memory_str . $file_load;
            array_unshift($info, $data);
        }
        return $this;
    }

    /***
     * 添加请求日志信息
     *
     * @param $info
     * @return Monolog
     */
    protected function appendHostInfo(&$info): Monolog
    {
        $request = $this->app['request'];
        $data['info'] = sprintf("[IP:%s Host:%s Url:%s, Method:%s]", $request->ip(), $request->host(),
            $request->url(), $request->method());
        array_unshift($info, $data);
        return $this;
    }
}