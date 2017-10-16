<?php

return array(
    'workers' => array(
        'HelloWorld' => array(
            'protocol'              => 'tcp',                               // 固定tcp
            'port'                  => 9900,                                // 每组服务一个端口
            'child_count'           => 2,                                   // 启动多少个进程提供服务
            'persistent_connection' => true,                                // thrift默认使用长链接
            'provider'              => '/data/www/duocai_service/apps/crontab/Services',  // 这里是thrift生成文件所放目录,可以是绝对路径
            'handler'               => '/data/www/duocai_service/apps/crontab/Services',   // 这里是对thrift生成的Provider里的接口的实现
            'max_requests'          => 1000,                                // 进程接收多少请求后退出
            'worker_class'          => 'ThriftWorker',                      // 说明是Thrfit服务
            'bootstrap'             => '/data/www/duocai_service/public/index.php',// 进程初始化时调用一次，可以在这里做些全局的事情，例如设置autoload
        ),
        'DuoCai' => array(
            'protocol'              => 'tcp',                               // 固定tcp
            'port'                  => 9901,                                // 每组服务一个端口
            'child_count'           => 2,                                   // 启动多少个进程提供服务
            'persistent_connection' => true,                                // thrift默认使用长链接
            'provider'              => '/data/www/duocai_service/apps/crontab/Services',  // 这里是thrift生成文件所放目录,可以是绝对路径
            'handler'               => '/data/www/duocai_service/apps/crontab/Services',   // 这里是对thrift生成的Provider里的接口的实现
            'max_requests'          => 1000,                                // 进程接收多少请求后退出
            'worker_class'          => 'ThriftWorker',                      // 说明是Thrfit服务
            'bootstrap'             => '/data/www/duocai_service/public/index.php',// 进程初始化时调用一次，可以在这里做些全局的事情，例如设置autoload
        ),
        // [开发环境用，生产环境可以去掉该项]thrift rpc web测试工具
        'TestThriftClientWorker' => array(
            'protocol'              => 'tcp',
            'port'                  => 30305,
            'child_count'           => 1,
        ),
    ),
    
    'ENV'          => 'dev', // dev or production
    'worker_user'  => '', //运行worker的用户,正式环境应该用低权限用户运行worker进程

    // 数据签名用私匙
    'rpc_secret_key'    => '769af463a39f077a0340a189e9c1ec28',
);
