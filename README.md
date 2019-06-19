# 基本信息
该项目主要是根据thinkphp日志文件驱动封装的Monolog驱动

# 基本用法

将thinkphp框架的日志配置文件的驱动改成monolog即可。

```php
'type' => 'monolog',
```

其他支持参数
```php
'log_name' => 'App',
'file_name' => 'app.log', //应用日志文件
'sql_file_name' => 'sql.log', //SQL日志文件
'log_format' => "[%datetime%] #reqId# %level_name% %message%\n" //日志输出格式，同monolog格式
```