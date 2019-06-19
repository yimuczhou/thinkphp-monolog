<?php
/**
 * Monolog.php
 *
 * @author YangHui<yanghui@y-sir.com>
 * @version 0.1
 * @createTime 2019/6/18 6:34 PM
 * @copyright Copyright (c) 云尚星. (http://www.yosar.com)
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
        'log_format' => "[%datetime%] #reqId# %level_name% %message%\n"
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

        $sqlFileName = $this->config['path'] . $this->config['sql_file_name'];
        $this->sqlLogger = $this->initLogger($sqlFileName);

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
        $logger = new Logger($this->config['log_name']);
        $streamHandler = new RotatingFileHandler($fileName, 30, Logger::DEBUG, true, 0664);
        $formatter = new LineFormatter(str_replace("#reqId#", $this->getRequestId(), $this->config['log_format']),
            $this->config['time_format']);
        $streamHandler->setFormatter($formatter);
        $logger->pushHandler($streamHandler);
        return $logger;
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
        foreach ($log as $type => $val) {
            foreach ($val as $msg) {
                if (!is_string($msg)) {
                    $msg = var_export($msg, true);
                }
                $type = strtoupper($type);
                if ($type == self::LOG_SQL) {
                    $this->sqlLogger->info($msg);
                } else {
                    if (isset(self::$loggerLevelMap[$type])) {
                        $this->logger->addRecord(self::$loggerLevelMap[$type], $msg);
                    } else {
                        $this->logger->debug($msg);
                    }
                }
            }
        }
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
            //todo 后续扩展从$_SERVER读取上游调用服务
            $requestId = md5(time() . rand(1, 1000));
        }
        return $requestId;
    }
}