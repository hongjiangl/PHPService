<?php
error_reporting(E_ALL);
ini_set('display_errors', 'on');
ini_set('limit_memory', '256M');
date_default_timezone_set('Asia/Shanghai');

define('SERVER_BASE', realpath(__DIR__ . '/..') . '/');

require_once SERVER_BASE . 'core/events/interfaces.php';

class PHPServer
{
    // 支持的协议
    const PROTOCOL_TCP = 'tcp';

    // 服务的各种状态
    const STATUS_STARTING = 1;
    const STATUS_RUNNING = 2;
    const STATUS_SHUTDOWN = 4;
    const STATUS_RESTARTING_WORKERS = 8;

    /****配置相关*****/
    // 最大worker数
    const SERVER_MAX_WORKER_COUNT = 1000;

    // 单个进程打开文件数限制
    const MIN_SOFT_OPEN_FILES = 10000;
    const MIN_HARD_OPEN_FILES = 10000;

    // server的状态
    protected static $serverStatus = 1;

    // 运行worker进程所用的用户名
    protected static $workerUserName = '';

    // 使用事件轮询库的名称
    protected static $eventLoopName = 'Select';

    // worker_name最大长度
    protected static $maxWorkerNameLength = 1;

    // 发送停止命令多久后worker没退出则发送kill信号
    protected static $killWorkerTimeLong = 4;

    // server统计信息
    protected static $serverStatusInfo = array(
        'start_time' => 0,
        'err_info'=>array(),
    );

    // 事件轮询库实例
    protected static $event = null;

    // server监听端口的Socket数组，用来fork worker使用
    protected static $listenedSockets = array();

    // 所有子进程pid
    protected static $workerPids = array();

    // worker与master间通信通道
    protected static $channels = array();

    // 要重启的worker pid array('pid'=>'time_stamp', 'pid2'=>'time_stamp')
    protected static $workerToRestart = array();

    // worker从客户端接收数据超时默认时间 毫秒
    const WORKER_DEFAULT_RECV_TIMEOUT = 1000;

    // worker业务逻辑处理默认超时时间 毫秒
    const WORKER_DEFAULT_PROCESS_TIMEOUT = 30000;

    // worker发送数据到客户端默认超时时间 毫秒
    const WORKER_DEFAULT_SEND_TIMEOUT = 1000;

    /**
     * 初始化
     */
    public static function init()
    {
        $config_path = PHPServerConfig::instance()->fileName;
        self::setProcessTitle('PHPServer:master with-config:' . $config_path);
    }

    /**
     * 修改进程名称
     *
     * @param $title
     */
    protected static function setProcessTitle($title)
    {
        if (extension_loaded('proctitle') && function_exists('setproctitle'))
        {
            @setproctitle($title);
        } else if (version_compare(phpversion(), '5.5', 'ge') && function_exists('cli_set_process_title'))
        {
            @cli_set_process_title($title);
        }
    }

    /**
     * 启动
     */
    public static function run($worker_user_name = '')
    {
        self::notice("Server is starting ...", true);

        // 指定运行worker进程的用户名
        self::$workerUserName = trim($worker_user_name);

        // 标记server状态为启动中...
        self::$serverStatus = self::STATUS_STARTING;

        // 检查Server环境
        self::checkEnv();

        // 使之成为daemon进程
        self::daemonize();

        // master进程使用Select轮询
        self::$event = new Select();

        // 安装相关信号
        self::installSignal();

        // 创建监听进程
        self::createSocketsAndListen();

        // 创建workers，woker阻塞在这个方法上
        self::createWorkers();

        self::notice("Server start success ...", true);

        // 标记sever状态为运行中...
        self::$serverStatus = self::STATUS_RUNNING;

        // 非开发环境关闭标准输出
        self::resetStdFd();

        // 监控worker进程状态，worker执行master的命令的结果，监控文件更改
        self::loop();

        // 标记sever状态为关闭中...
        self::$serverStatus = self::STATUS_SHUTDOWN;

        return self::stop();
    }

    /**
     * Server进程 主体循环
     * @return void
     */
    protected static function loop()
    {
        // 事件轮询
        self::$event->loop();
    }

    /**
     * 关闭标准输入输出
     * @return void
     */
    protected static function resetStdFd()
    {
        // 开发环境不关闭标准输出，用于调试
        if(PHPServerConfig::get('ENV') == 'dev')
        {
            ob_start();
            return;
        }
        global $STDOUT, $STDERR;
        @fclose(STDOUT);
        @fclose(STDERR);
        $STDOUT = fopen('/dev/null',"rw+");
        $STDERR = fopen('/dev/null',"rw+");
    }

    /**
     * 消息日志
     */
    protected static function notice($msg, $display = false)
    {
        if ($display)
        {
            if (self::$serverStatus == self::STATUS_STARTING && posix_ttyname(STDOUT))
            {
                echo $msg . "\n";
            }
        }
    }

    /**
     * 检查运行环境
     */
    protected static function checkEnv()
    {
        // 已经有进程pid可能server已启动
        if (@file_get_contents(PID_FILE))
        {
            exit("server already started\n");
        }

        // 检查指定的worker用户是否合法
        self::checkWorkerUserName();

        // 检查扩展支持情况
        self::checkExtension();

        // 检查函数禁用情况
        self::checkDisableFunction();

        // 检查配置和语法错误灯
        self::checkWorkersConfig();

        // 检查文件限制
        self::checkLimit();
    }

    /**
     * 检查启动worker进程的的用户是否合法
     */
    protected static function checkWorkerUserName()
    {
        foreach (array(self::$workerUserName, PHPServerConfig::get('worker_user')) as $worker_user)
        {
            if ($worker_user) {
                $user_info = posix_getpwnam($worker_user);
                if (empty($user_info))
                {
                    exit("\033[31;40mCan not run worker processes as user $worker_user , User $worker_user not exists\033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
                }

                if (self::$workerUserName != $worker_user)
                {
                    self::$workerUserName = $worker_user;
                }
                break;
            }
        }
    }

    /**
     * 检查扩展
     */
    protected static function checkExtension()
    {
        // 扩展名=>是否是必须
        $need_map = array(
            'posix'     => true,
            'pcntl'     => true,
            'libevent'  => false,
            'ev'        => false,
            'uv'        => false,
            'proctitle' => false,
            'inotify'   => false,
        );

        // 检查每个扩展支持情况
        echo "----------------------EXTENSION--------------------\n";

        $pad_length = 26;
        foreach ($need_map as $ext_name => $must_required) {
            $suport = extension_loaded($ext_name);

            if ($must_required && !$suport)
            {
                exit($ext_name. " \033[31;40m [NOT SUPORT BUT REQUIRED] \033[0m\n\n\033[31;40mYou have to compile CLI version of PHP with --enable-{$ext_name} \033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
            }

            if (self::$eventLoopName == 'Select' && $suport)
            {
                if ($ext_name == 'libevent')
                {
                    self::$eventLoopName = 'Libevent';
                }
            }

            // 支持扩展
            if ($suport)
            {
                echo str_pad($ext_name, $pad_length),  "\033[32;40m [OK] \033[0m\n";
            } else
            {
                // ev uv inotify不是必须
                if('ev' == $ext_name || 'uv' == $ext_name || 'inotify' == $ext_name || 'proctitle' == $ext_name)
                {
                    continue;
                }
                echo str_pad($ext_name, $pad_length), "\033[33;40m [NOT SUPORT] \033[0m\n";
            }
        }
    }

    /**
     * 检查禁用函数
     */
    public static function checkDisableFunction()
    {
        // 可能禁用的函数
        $check_func_map = array(
            'stream_socket_client',
            'stream_socket_server'
        );

        if ($disable_func_string = ini_get('disable_functions'))
        {
            $disable_func_map = array_flip(explode(',', $disable_func_string));
        }

        // 遍历查看是否有禁用的函数
        foreach ($check_func_map as $func)
        {
            if (isset($disable_func_map[$func]))
            {
                exit("\n\033[31;40mFunction $func may be disabled\nPlease check disable_functions in php.ini\033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
            }
        }
    }

    public static function checkWorkersConfig()
    {
        $pad_length = 26;
        $total_worker_count = 0;

        // 检查worker 是否有语法错误
        echo "----------------------WORKERS--------------------\n";

        foreach (PHPServerConfig::get('workers') as $worker_name => $config)
        {
            // 端口、协议、进程数等信息
            if (empty($config['child_count']))
            {
                exit(str_pad($worker_name, $pad_length)."\033[31;40m [child_count not set]\033[0m\n\n\033[31;40mServer start fail\033[0m\n");
            }

            $total_worker_count += $config['child_count'];

            if (self::$maxWorkerNameLength < strlen($worker_name))
            {
                self::$maxWorkerNameLength = strlen($worker_name);
            }

            // 语法检查(暂时不做语法检查)
//            if (self::checkSyntaxError($worker_name) != 0)
//            {
//                unset(PHPServerConfig::instance()->config['workers'][$worker_name]);
//                echo str_pad($worker_name, $pad_length),"\033[31;40m [Fatal Err] \033[0m\n";
//                break;
//            }

            echo str_pad($worker_name, $pad_length),"\033[32;40m [OK] \033[0m\n";
        }

        if ($total_worker_count > self::SERVER_MAX_WORKER_COUNT)
        {
            exit("\n\033[31;40mNumber of worker processes can not be more than " . self::SERVER_MAX_WORKER_COUNT . ".\nPlease check child_count in " . SERVER_BASE . "config/main.php\033[0m\n\n\033[31;40mServer start fail\033[0m\n");
        }

        echo "-------------------------------------------------\n";
    }

    /**
     * 检查worker文件是否有语法错误
     */
    protected static function checkSyntaxError($worker_name)
    {
        $pid = pcntl_fork();
        // 父进程
        if ($pid > 0)
        {
            // 退出状态不为0说明可能有语法错误
            $pid = pcntl_wait($status);
            return $status;
        } else if ($pid == 0)
        {
            // 载入对应worker
            $class_name = PHPServerConfig::get('workers.'. $worker_name . '.worker_class');
            $class_name = $class_name ? $class_name :$worker_name;
            include_once SERVER_BASE . 'workers/' . $class_name . '.php';

            if (!class_exists($class_name))
            {
                throw new Exception("Class $class_name not exists");
            }
            exit(0);
        }
    }

    /*
     * 检查打开文件限制（文件打开句柄最大数，如果超过这个数将会打开失败）
     */
    public static function checkLimit()
    {
        if (PHPServerConfig::get('ENV') != 'dev' && $limit_info = posix_getrlimit())
        {
            if('unlimited' != $limit_info['soft openfiles'] && $limit_info['soft openfiles'] < self::MIN_SOFT_OPEN_FILES)
            {
                echo "Notice : Soft open files now is {$limit_info['soft openfiles']},  We recommend greater than " . self::MIN_SOFT_OPEN_FILES . "\n";
            }
            if('unlimited' != $limit_info['hard filesize'] && $limit_info['hard filesize'] < self::MIN_SOFT_OPEN_FILES)
            {
                echo "Notice : Hard open files now is {$limit_info['hard filesize']},  We recommend greater than " . self::MIN_HARD_OPEN_FILES . "\n";
            }
        }
    }

    /**
     * 使之脱离终端，变为守护进程
     */
    protected static function daemonize()
    {
        // 设置umask将文件权限掩码设为0
        umask(0);

        // fork一次
        $pid = pcntl_fork();
        if ($pid == -1)
        {
            // 出错退出
            exit("Daemonize fail ,can not fork");
        }
        else if ($pid > 0)
        {
            // 父进程，退出
            exit(0);
        }

        // 创建一个新的会话（session）子进程使之成为session leader
        //(1:让进程摆脱原会话的控制；2让进程摆脱原进程组的控制；3让进程摆脱原控制终端的控制),就是让调用进程完全独立出来，脱离所有其他进程的控制。
        if (posix_setsid() == -1)
        {
            // 出错退出
            exit("Daemonize fail ,setsid fail");
        }

        // 在fork一次
        $pid2 = pcntl_fork();
        if ($pid2 == -1)
        {
            // 出错退出
            exit("Daemonize fail ,can not fork");
        }
        else if ($pid2 !== 0)
        {
            // 结束第一子进程，用来禁止进程重新打开控制终端
            exit(0);
        }

        file_put_contents(PID_FILE, posix_getpid());
        chmod(PID_FILE, 0644);

        // 记录server启动时间
        self::$serverStatusInfo['start_time'] = time();
    }

    /**
     * 安装相关信号控制器
     */
    public static function installSignal()
    {
        // 设置终止信号处理函数
        self::$event->add(SIGINT, BaseEvent::EV_SIGNAL, array('PHPServer', 'signalHandler'), SIGINT);
        // 设置SIGUSR1信号处理函数，测试用
        self::$event->add(SIGUSR1, BaseEvent::EV_SIGNAL, array('PHPServer', 'signalHandler'), SIGUSR1);
        // 设置SIGUSR2信号处理函数,平滑重启Server
        self::$event->add(SIGUSR2, BaseEvent::EV_SIGNAL, array('PHPServer', 'signalHandler'), SIGUSR2);
        // 设置子进程退出信号处理函数
        self::$event->add(SIGCHLD, BaseEvent::EV_SIGNAL, array('PHPServer', 'signalHandler'), SIGCHLD);

        // 设置忽略信号
        /*因为并发服务器常常fork很多子进程，子进程终结之后需要
          服务器进程去wait清理资源。如果将此信号的处理方式设为
          忽略，可让内核把僵尸子进程转交给init进程去处理，省去了
          大量僵尸进程占用系统资源。*/
        pcntl_signal(SIGPIPE, SIG_IGN); // 管道破裂
        pcntl_signal(SIGHUP,  SIG_IGN); // 终止进程 终端线路挂断
        pcntl_signal(SIGTTIN, SIG_IGN); // 停止进程 后台进程读终端
        pcntl_signal(SIGTTOU, SIG_IGN); // 停止进程 后台进程写终端
        pcntl_signal(SIGQUIT, SIG_IGN); // SIGQUIT 和SIGINT类似, 但由QUIT字符(通常是Ctrl-)来控制. 进程在因收到SIGQUIT退出时会产生core文件, 在这个意义上类似于一个程序错误信号.
    }

    /**
     * 设置server信号处理函数
     */
    public static function signalHandler($null, $flag, $signal)
    {
        switch($signal)
        {
            // 停止server信号
            case SIGINT:
                self::notice("Server is shutting down");
                self::stop();
                break;
            // 展示server服务状态
            case SIGUSR1:
                break;
            // worker退出信号
            case SIGCHLD:
                // 不要在这里fork，fork出来的子进程无法收到信号
                break;
            // 平滑重启server信号
            case SIGUSR2:
                self::notice("Server reloading");
                self::addToRestartWorkers(array_keys(self::getPidWorkerNameMap()));
                self::restartWorkers();
                break;
        }
    }

    /**
     * 创建socket监听
     */
    protected static function createSocketsAndListen()
    {
        // 循环读取配置创建socket
        foreach (PHPServerConfig::get('workers') as $worker_name => $config)
        {
            if (!isset($config['protocol']) || !isset($config['port']))
            {
                continue;
            }

            $flags = $config['protocol'] == 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
            $ip = isset($config['ip']) ? $config['ip'] : "0.0.0.0";
            $error_no = 0;
            $error_msg = '';
            // 创建监听socket
            self::$listenedSockets[$worker_name] = stream_socket_server("{$config['protocol']}://{$ip}:{$config['port']}", $error_no, $error_msg, $flags);
            if (!self::$listenedSockets[$worker_name])
            {
                exit("\n\033[31;40mcan not create socket {$config['protocol']}://{$ip}:{$config['port']} info:{$error_no} {$error_msg}\033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
            }
        }
    }

    /**
     * 创建Workers
     */
    public static function createWorkers()
    {
        foreach (PHPServerConfig::get('workers') as $worer_name => $config)
        {
            if (empty(self::$workerPids[$worer_name]))
            {
                self::$workerPids[$worer_name] = array();
            }

            while (count(self::$workerPids[$worer_name]) < $config['child_count'])
            {
                $pid = self::forkOneWorker($worer_name);
                // 子进程退出
                if($pid == 0)
                {
                    exit("child exist err");
                }
            }
        }
    }

    /**
     * 复制一个worker进程
     *
     * @param $worker_name　worker名称
     *
     *
     */
    public static function forkOneWorker($worker_name)
    {
        if (!($channel = self::createChannel()))
        {
            self::notice('"Create channel fail\n"');
        }

        // 触发信号处理
        pcntl_signal_dispatch();
        $pid = pcntl_fork();

        // 父进程
        if ($pid > 0 )
        {
            fclose($channel[1]);
            self::$workerPids[$worker_name][$pid] = $pid;
            self::$channels[$pid] = $channel[0];
            unset($channel);
            self::$event->add(self::$channels[$pid], BaseEvent::EV_READ, array('PHPServer', 'dealCmdResult'), $pid, 0, 0);
            return $pid;
        }
        // 子进程
        else if ($pid === 0)
        {
            // 屏蔽alarm信号
            self::ignoreSignalAlarm();

            // 子进程关闭不用的监听socket
            foreach(self::$listenedSockets as $tmp_worker_name => $tmp_socket)
            {
                if ($tmp_worker_name != $worker_name)
                {
                    fclose($tmp_socket);
                }
            }

            // 关闭用不到的管道
            fclose($channel[0]);

            foreach (self::$channels as $ch)
            {
                self::$event->delAll((int)$ch);
                fclose($ch);
            }

            self::$channels = array();

            // 尝试以指定用户运行worker
            self::setWorkerUser();

            // 删除任务
            Task::delAll();

            // 开发环境打开标准输出，用于调试
            if(PHPServerConfig::get('ENV') == 'dev')
            {
                self::recoverStdFd();
            }
            else
            {
                self::resetStdFd();
            }

            if (isset(self::$listenedSockets[$worker_name]))
            {
                $sock_name = stream_socket_get_name(self::$listenedSockets[$worker_name], false);

                // 更改进程名称
                $mata_data = stream_get_meta_data(self::$listenedSockets[$worker_name]);
                $protocol = substr($mata_data['stream_type'], 0 , 3);
                self::setProcessTitle("PHPServer:worker $worker_name ".self::$eventLoopName." {$protocol}://$sock_name");
            }
            else
            {
                self::setProcessTitle("PHPServer:worker $worker_name ");
            }

            // 这里应该检查下需要生成服务的文件是否存在的，但项目结构还未定后期做
//            if(self::checkSyntaxError($worker_name) != 0)
//            {
//                self::notice("$worker_name has Fatal Err\n");
//                sleep(5);
//                exit(120);
//            }

            // 获取从客户端接收数据超时时间
            $recv_timeout = PHPServerConfig::get('workers.' . $worker_name . '.recv_timeout');
            if ($recv_timeout === null || intval($recv_timeout) < 0)
            {
                $recv_timeout = self::WORKER_DEFAULT_RECV_TIMEOUT;
            }

            // 获取业务逻辑处理默认超时时间
            $process_timeout = PHPServerConfig::get('workers.' . $worker_name . '.process_timeout');
            $process_timeout = (int)$process_timeout > 0 ? (int)$process_timeout : self::WORKER_DEFAULT_PROCESS_TIMEOUT;

            $send_timeout = PHPServerConfig::get('workers.' . $worker_name . '.send_timeout');
            $send_timeout = (int)$send_timeout > 0 ? (int)$send_timeout : self::WORKER_DEFAULT_SEND_TIMEOUT;

            // 是否开启长连接
            $persistent_connection = (bool)PHPServerConfig::get('workers.' . $worker_name . '.persistent_connection');
            $max_requests = (int)PHPServerConfig::get('workers.' . $worker_name . '.max_requests');

            // 类名（没验证出错了咋办）
            $class_name = PHPServerConfig::get('workers.' . $worker_name . '.worker_class');
            $class_name = $class_name ? $class_name : $worker_name;

            // 创建worker实例
            $worker = new $class_name(isset(self::$listenedSockets[$worker_name]) ? self::$listenedSockets[$worker_name] : null, $recv_timeout, $process_timeout, $send_timeout, $persistent_connection, $max_requests);

            // 设置服务名
            $worker->setServiceName($worker_name);

            // 设置通讯通道，worker读写channel[1]
            $worker->setChannel($channel[1]);

            // 设置worker事件轮询库的名称
            $worker->setEventLoopName(self::$eventLoopName);

            // 使worker开始服务
            $worker->serve();

            return 0;
        }
        // 失败
        else
        {
            self::notice("create worker fail worker_name:$worker_name detail:pcntl_fork fail");
            return $pid;
        }
    }

    /**
     * 处理命令结果
     * @param resource $channel
     * @param int $length
     * @param string $buffer
     * @param int $pid
     */
    public static function dealCmdResult($channel, $length, $buffer, $pid)
    {
        // 链接断开了，应该是对应的进程退出了
        if($length == 0)
        {
            return self::monitorWorkers();
        }
    }

    /**
     * 创建master和worker之间的通信通道
     *
     * @return array|bool
     */
    public static function createChannel()
    {
        // 建立进程间通信通道，目前是用unix域套接字
        $channel = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($channel === false)
        {
            return false;
        }
        // 父进程通道
        stream_set_blocking($channel[0], 0);
        // 子进程通道
        stream_set_blocking($channel[1], 0);

        return $channel;
    }

    /**
     * 屏蔽alarm信号
     */
    public static function ignoreSignalAlarm()
    {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_IGN);
        pcntl_signal_dispatch();
    }

    /**
     * 设置运行用户
     * @return void
     */
    public static function setWorkerUser()
    {
        if (!empty(self::$workerUserName))
        {
            $user_info = posix_getpwnam(self::$workerUserName);

            if (!posix_setgid($user_info['gid']) || !posix_setuid($user_info['uid']))
            {
                $notice = 'Notice : Can not run woker as '.self::$workerUserName." , You shuld be root\n";
                self::notice($notice);
            }
        }
    }

    /**
     * 恢复标准输出(开发环境用)
     */
    protected static function recoverStdFd()
    {
        if(PHPServerConfig::get('ENV') == 'dev')
        {
            @ob_end_clean();
        }
        if(!posix_ttyname(STDOUT))
        {
            global $STDOUT, $STDERR;
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen('/dev/null',"rw+");
            $STDERR = fopen('/dev/null',"rw+");
            return;
        }
    }

    /**
     * 停止server
     * @return void
     */
    public static function stop()
    {
        // 标记server开始关闭
        self::$serverStatus = self::STATUS_SHUTDOWN;

        // 停止所有worker
        self::stopAllWorker(true);

        // 如果没有子进程则直接退出
        $all_worker_pid = self::getPidWorkerNameMap();

        if(empty($all_worker_pid))
        {
            exit(0);
        }

        // killWorkerTimeLong 秒后如果还没停止则强制杀死所有进程
        Task::add(PHPServer::$killWorkerTimeLong, array('PHPServer', 'stopAllWorker'), array(true), false);

        // 停止所有worker
        self::stopAllWorker(true);

        return ;
    }

    /**
     * 停止所有worker
     * @param bool $force 是否强制退出
     * @return void
     */
    public static function stopAllWorker($force = false)
    {
        // 强行杀死？
        if ($force)
        {
            // 杀死所有子进程
            foreach (self::getPidWorkerNameMap() as $pid => $worker_name)
            {
                self::forceKillWorker($pid);
                if (self::$serverStatus != self::STATUS_SHUTDOWN)
                {
                    self::notice("Kill workers($worker_name) force!");
                }
            }
        }
        else
        {
            // 向所有worker发送停止服务命令
            self::sendCmdToAll(Cmd::CMD_STOP_SERVE);
        }
    }

    /**
     * 向所有worker发送命令
     * @param char $cmd
     * @return void
     */
    public static function sendCmdToAll($cmd)
    {
        $result = array();
        foreach(self::$channels as $pid => $channel)
        {
            self::sendCmdToWorker($cmd, $pid);
        }
    }

    /**
     * 强制杀死进程
     * @param int $pid
     */
    public static function forceKillWorker($pid)
    {
        if(posix_kill($pid, 0))
        {
            if(self::$serverStatus != self::STATUS_SHUTDOWN)
            {
                self::notice("Kill workers $pid force!");
            }
            posix_kill($pid, SIGKILL);
        }
    }

    /**
     * 向特定的worker发送命令
     * @param char $cmd
     * @param int $pid
     * @return boolean|string|mixed
     */
    protected static function sendCmdToWorker($cmd, $pid)
    {
        // 如果是ping心跳包，则计数
        if($cmd == Cmd::CMD_PING)
        {
            if(!isset(self::$pingInfo[$pid]))
            {
                self::$pingInfo[$pid] = 0;
            }
            self::$pingInfo[$pid]++;
        }
        // 写入命令
        if(!@fwrite(self::$channels[$pid], Cmd::encodeForMaster($cmd), 1))
        {
            self::notice("send cmd:$cmd to pid:$pid fail");
            self::monitorWorkers();
        }
    }

    /**
     * 获取pid 到 worker_name 的映射
     * @return array('pid1'=>'worker_name1','pid2'=>'worker_name2', ...)
     */
    public static function getPidWorkerNameMap()
    {
        $all_pid = array();
        foreach(self::$workerPids as $worker_name=>$pid_array)
        {
            foreach($pid_array as $pid)
            {
                $all_pid[$pid] = $worker_name;
            }
        }
        return $all_pid;
    }

    /**
     * 监控worker进程状态，退出重启
     * @param resource $channel
     * @param int $flag
     * @param int $pid 退出的进程id
     */
    public static function monitorWorkers($wait_pid = -1)
    {
        // 由于SIGCHLD信号可能重叠导致信号丢失，所以这里要循环获取所有退出的进程id
        while(($pid = pcntl_waitpid($wait_pid, $status, WUNTRACED | WNOHANG)) != 0)
        {
            // 如果是重启的进程，则继续重启进程
            if(isset(self::$workerToRestart[$pid]) && self::$serverStatus != self::STATUS_SHUTDOWN)
            {
                unset(self::$workerToRestart[$pid]);
                self::restartWorkers();
            }

            // 出错
            if($pid == -1)
            {
                // 没有子进程了,可能是出现Fatal Err 了
                if(pcntl_get_last_error() == 10)
                {
                    self::notice('Server has no workers now');
                }
                return -1;
            }

            // 查找子进程对应的woker_name
            $pid_workname_map = self::getPidWorkerNameMap();
            $worker_name = isset($pid_workname_map[$pid]) ? $pid_workname_map[$pid] : '';
            // 没找到worker_name说明出错了 哪里来的野孩子？
            if(empty($worker_name))
            {
                self::notice("child exist but not found worker_name pid:$pid");
                break;
            }

            // 进程退出状态不是0，说明有问题了
            if($status !== 0 && self::$serverStatus != self::STATUS_SHUTDOWN)
            {
                self::notice("worker exit status $status pid:$pid worker:$worker_name");
            }
            // 记录进程退出状态
            self::$serverStatusInfo['err_info'][$worker_name][$status] = isset(self::$serverStatusInfo['err_info'][$worker_name][$status]) ? self::$serverStatusInfo['err_info'][$worker_name][$status] + 1 : 1;

            // 清理这个进程的数据
            self::clearWorker($worker_name, $pid);

            // 如果服务不是关闭中
            if(self::$serverStatus != self::STATUS_SHUTDOWN)
            {
                // 重新创建worker
                self::createWorkers();
            }
            // 判断是否都重启完毕
            else
            {
                $all_worker_pid = self::getPidWorkerNameMap();
                if(empty($all_worker_pid))
                {
                    // 发送提示
                    self::notice("Server stoped");
                    // 删除pid文件
                    @unlink(PID_FILE);
                    exit(0);
                }
            }//end if
        }//end while
    }

    /**
     * 重启workers
     * @return void
     */
    public static function restartWorkers()
    {
        // 标记server状态
        if(self::$serverStatus != self::STATUS_RESTARTING_WORKERS)
        {
            self::$serverStatus = self::STATUS_RESTARTING_WORKERS;
        }

        // 没有要重启的进程了
        if(empty(self::$workerToRestart))
        {
            self::$serverStatus = self::STATUS_RUNNING;
            self::notice("\nWorker Restart Success");
            return true;
        }

        // 遍历要重启的进程 标记它们重启时间
        foreach(self::$workerToRestart as $pid => $stop_time)
        {
            if($stop_time == 0)
            {
                self::$workerToRestart[$pid] = time();
                self::sendCmdToWorker(Cmd::CMD_RESTART, $pid);
                Task::add(PHPServer::$killWorkerTimeLong, array('PHPServer', 'forceKillWorker'), array($pid), false);
                break;
            }
        }
    }

    /**
     * worker进程退出时，master进程的一些清理工作
     * @param string $worker_name
     * @param int $pid
     * @return void
     */
    protected static function clearWorker($worker_name, $pid)
    {
        // 删除事件监听
        self::$event->delAll(self::$channels[$pid]);
        // 释放一些不用了的数据
        unset(self::$channels[$pid], self::$workerToRestart[$pid], self::$workerPids[$worker_name][$pid], self::$pingInfo[$pid]);
        // 清除进程间通信缓冲区
        Cmd::clearPid($pid);
    }

    /**
     * 放入重启队列中
     * @param array $restart_pids
     * @return void
     */
    public static function addToRestartWorkers($restart_pids)
    {
        if(!is_array($restart_pids))
        {
            return false;
        }

        // 将pid放入重启队列
        foreach($restart_pids as $pid)
        {
            if(!isset(self::$workerToRestart[$pid]))
            {
                // 重启时间=0
                self::$workerToRestart[$pid] = 0;
            }
        }
    }

}

/**
 * Auto Loader
 */
$loadableModules = array('core', 'core/events', 'plugins', 'workers', 'protocols');

spl_autoload_register(function($name) {
    global $loadableModules;

    foreach ($loadableModules as $module) {
        $fileName = SERVER_BASE . $module . '/' . $name . '.php';
        if (file_exists($fileName)) {
            require_once $fileName;
        }
    }
});
