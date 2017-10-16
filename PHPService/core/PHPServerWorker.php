<?php
/**
 * Created by PhpStorm.
 * User: hongjiangl
 * Date: 16-10-13
 * Time: 下午2:48
 */
class PHPServerWorker
{
    // worker状态相关
    const STATUS_RUNNING = 2; // 运行中
    const STATUS_SHUTDOWN = 4; // 结束

    // 强制退出状态码
    const FORCE_EXIT_CODE = 117;

    // worker与master间通信通道
    protected $channel = null;

    // 事件轮询库的名称
    protected $eventLoopName = 'Select';

    // worker监听端口的Socket
    protected $mainSocket = null;

    // 从socket读数据超时时间,单位毫秒
    protected $recvTimeOut = 1000;

    // 发送数据超时时间
    protected $sendTimeOut = 1000;

    // 逻辑处理超时时间
    protected $processTimeout = 300000;

    // 该worker进程处理多少请求后退出，0表示不自动退出
    protected $maxRequests = 0;

    // 是否是长链接，(短连接每次请求后服务器主动断开，长连接一般是客户端主动断开)
    protected $isPersistentConnection = false;

    // 轮询库对象
    protected $event = null;

    // server的协议
    protected $protocol = "tcp";

    // worker的所有链接
    protected $connections = array();

    // worker的所有读buffer
    protected $recvBuffers = array();

    // worker的服务状态
    protected $workerStatus = 2;

    protected $serviceName = __CLASS__;

    // 当前处理的fd
    protected $currentDealFd = 0;

    // 进程意外退出状态码
    const EXIT_UNEXPECT_CODE = 118;


    // 统计信息
    protected $statusInfo = array(
        'start_time'      => 0,
        'total_request'   => 0,
        'recv_timeout'    => 0,
        'proc_timeout'    => 0,
        'packet_err'      => 0,
        'throw_exception' => 0,
        'thunder_herd'    => 0,
        'client_close'    => 0,
        'send_fail'       => 0,
    );

    public function __construct($socket = null, $recv_timeout = 1000, $process_timeout = 30000, $send_timeout = 1000, $persistent_connection = false, $max_requests = 0)
    {
        $this->mainSocket = $socket;
        $this->recvTimeOut = $recv_timeout >= 0 ? $recv_timeout : $this->recvTimeOut;
        $this->processTimeout = $process_timeout > 0 ? $process_timeout : $this->processTimeout;
        $this->sendTimeOut = $send_timeout > 0 ? $send_timeout : $this->sendTimeOut;
        $this->isPersistentConnection = (bool)$persistent_connection;
        $this->maxRequests = $max_requests <= 0 ? PHP_INT_MAX : $max_requests;

        if ($socket)
        {
            // 设置监听socket非阻塞
            stream_set_blocking($this->mainSocket, 0);

            // 获取协议
            $meta_data = stream_get_meta_data($socket);

            $this->protocol = substr($meta_data['stream_type'], 0 , 3);
        }

        // worker启动时间
        $this->statusInfo['start_time'] = time();

        // 进程退出时增加状态判断
        // register_shutdown_function(array($this, 'checkStatus'));
    }

    public function serve($is_daemon = true)
    {
        // 触发该worker进程onServe事件，该进程整个生命周期只触发一次
        if ($this->onServe())
        {
            return;
        }

        if ($is_daemon === true)
        {
            $this->installSignal();
        }

        $this->event = new $this->eventLoopName;

        if ($this->mainSocket)
        {
            if ($this->protocol == 'udp')
            {
                $this->event->add($this->mainSocket, BaseEvent::EV_ACCEPT, array($this, 'recvUdp'));
            } else {
                $this->event->add($this->mainSocket, BaseEvent::EV_ACCEPT, array($this, 'accept'));
            }
        }

        // 主体循环
        $this->event->loop();

        // 添加管道可读事件
        $this->event->add($this->channel,  BaseEvent::EV_READ, array($this, 'dealCmd'), null, 0, 0);

        exit(self::EXIT_UNEXPECT_CODE);
    }

    public function recvUdp()
    {

    }

    public function accept($socket, $null_one = null, $null_two = null)
    {
        $connection = @stream_socket_accept($socket, 0);

        // 可能是惊群效应
        if ($connection === false)
        {
            return false;
        }

        // 接收请求数加１
        $this->statusInfo['total_request'] ++;

        // 链接的fd序号
        $fd = intval($connection);

        stream_set_blocking($connection, 0);
        $this->event->add($connection, BaseEvent::EV_READ, array($this, 'dealInputBase'), $fd, $this->recvTimeOut);

        $this->connections[$fd] = $connection;
    }

    public function dealInputBase($connection, $length, $buffer, $fd = null)
    {
        $this->currentDealFd = $fd;

        // 出错了
        if($length == 0)
        {
            if(feof($connection))
            {
                // 客户端提前断开链接
                $this->statusInfo['client_close']++;
            }
            else
            {
                // 超时了
                $this->statusInfo['recv_timeout']++;
            }
            $this->closeClient($fd);
            if($this->workerStatus == self::STATUS_SHUTDOWN)
            {
                $this->stopServe();
            }
            return;
        }

        if(isset($this->recvBuffers[$fd]))
        {
            $buffer = $this->recvBuffers[$fd] . $buffer;
        }

        $remain_len = $this->dealInput($buffer);

        // 包接收完毕
        if(0 === $remain_len)
        {
            // 逻辑超时处理，逻辑只能执行xxs,xxs后超时放弃当前请求处理下一个请求
            pcntl_alarm(ceil($this->processTimeout/1000));

            // 执行处理
            try{
                declare(ticks=1);
                // 业务处理
                $this->dealProcess($buffer);
                // 关闭闹钟
                pcntl_alarm(0);
            }
            catch(Exception $e)
            {
                // 关闭闹钟
                pcntl_alarm(0);
                if($e->getCode() != self::CODE_PROCESS_TIMEOUT)
                {
                    $this->notice($e->getMessage().":\n".$e->getTraceAsString());
                    $this->statusInfo['throw_exception'] ++;
                    $this->sendToClient($e->getMessage());
                }
            }

            // 是否是长连接
            if($this->isPersistentConnection)
            {
                // 清空缓冲buffer
                unset($this->recvBuffers[$fd]);
            }
            else
            {
                // 关闭链接
                $this->closeClient($fd);
            }

        }
        // 出错
        else if(false === $remain_len)
        {
            // 出错
            $this->statusInfo['packet_err']++;
            $this->sendToClient('packet_err:'.$buffer);
            $this->notice('packet_err:'.$buffer);
            $this->closeClient($fd);
        }
        // 还有数据没收完，则保存收到的数据，等待其它数据
        else
        {
            $this->recvBuffers[$fd] = $buffer;
        }

        // 检查是否到达请求上限或者服务是否是关闭状态
        if($this->statusInfo['total_request'] >= $this->maxRequests || $this->workerStatus == self::STATUS_SHUTDOWN)
        {
            // 停止服务
            $this->stopServe();
            // 5秒后退出进程
            pcntl_alarm(self::EXIT_WAIT_TIME);
        }
    }

    /**
     * 用户worker继承此worker类必须实现该方法，根据具体协议和当前收到的数据决定是否继续收包
     * @param string $recv_str 收到的数据包
     * @return int/false 返回0表示接收完毕/>0表示还有多少字节没有接收到/false出错
     */
    public function dealInput($recv_str)
    {
        return 0;
    }

    /**
     * 用户worker继承此worker类必须实现该方法，根据包中的数据处理逻辑
     * 逻辑处理
     * @param string $recv_str 收到的数据包
     * @return void
     */
    public function dealProcess($recv_str)
    {
    }

    /**
     * 根据fd关闭链接
     * @param int $fd
     * @return void
     */
    protected function closeClient($fd)
    {
        // udp忽略
        if($this->protocol != 'udp')
        {
            $this->event->delAll($this->connections[$fd]);
            fclose($this->connections[$fd]);

            unset($this->connections[$fd], $this->recvBuffers[$fd]);
        }
    }

    /**
     * 安装信号处理
     */
    protected function installSignal()
    {
        pcntl_signal(SIGALRM, array($this, 'signalHandler'), false); // 来自alarm的计时器到时信号
        // 设置忽略信号
        pcntl_signal(SIGHUP,  SIG_IGN); // 终端的挂断或进程死亡
        pcntl_signal(SIGTTIN, SIG_IGN); // 后台进程读终端
        pcntl_signal(SIGTTOU, SIG_IGN); // 后台进程写终端
        pcntl_signal(SIGQUIT, SIG_IGN); // 来自键盘的离开信号
        pcntl_signal(SIGPIPE, SIG_IGN); // 管道损坏：向一个没有读进程的管道写数据
        pcntl_signal(SIGTERM, SIG_IGN); // 终止
        pcntl_signal(SIGUSR1, SIG_IGN); // 用户自定义信号1
        pcntl_signal(SIGUSR2, SIG_IGN); // 用户自定义信号2
        pcntl_signal(SIGCHLD, SIG_IGN); // 子进程停止或终止
    }

    /**
     * 设置server信号处理函数
     */
    public function signalHandler($signal)
    {
        switch($signal)
        {
            // 时钟处理函数
            case SIGALRM:
                // 停止服务后EXIT_WAIT_TIME秒还没退出则强制退出
                if ($this->workerStatus == self::STATUS_SHUTDOWN)
                {
                    exit(self::FORCE_EXIT_CODE);
                }

                // 处理逻辑超时
                $this->statusInfo['proc_timeout']++;

                throw new Exception('process_timeout', 504);
                break;
        }
    }

    /**
     * 该worker进程开始服务的时候会触发一次，可以在这里做一些全局的事情
     * @return bool
     */
    protected function onServe()
    {
        return false;
    }

    /**
     * 设置服务名
     * @param string $service_name
     * @return void
     */
    public function setServiceName($service_name)
    {
        $this->serviceName = $service_name;
    }

    /**
     * 设置master与worker间通信通道
     * @param resource $channel
     * @return void
     */
    public function setChannel($channel)
    {
        $this->channel = $channel;
        stream_set_blocking($this->channel, 0);
    }

    /**
     * 设置worker的事件轮询库的名称
     * @param string
     * @return void
     */
    public function setEventLoopName($event_loop_name)
    {
        $this->eventLoopName = $event_loop_name;
    }

    /**
     * 停止服务
     * @param bool $exit 是否退出
     * @return void
     */
    public function stopServe($exit = true)
    {
        // 触发该worker进程onStopServe事件
        if($this->onStopServe())
        {
            return;
        }

        // 标记这个worker开始停止服务
        if($this->workerStatus != self::STATUS_SHUTDOWN)
        {
            $this->workerStatus = self::STATUS_SHUTDOWN;
        }

        // 停止接收连接
        if($this->mainSocket)
        {
            $this->event->del($this->mainSocket, BaseEvent::EV_ACCEPT);
            @fclose($this->mainSocket);
        }

        // 当前任务都完成了
        if($this->allTaskHasDone())
        {
            if($exit)
            {
                exit(0);
            }
        }
    }

    /**
     * 该worker进程停止服务的时候会触发一次，可以在这里做一些全局的事情
     * @return bool
     */
    protected function onStopServe()
    {
        return false;
    }

    /**
     * 该进程收到的任务是否都已经完成，重启进程时需要判断
     * @return bool
     */
    protected function allTaskHasDone()
    {
        return empty($this->connections);
    }

    /**
     * 向master发送对应命令字的结果
     * 协议如下char<cmd>/unsigned int<pack_len>/string<serialize(result)>
     * @param char $cmd
     * @param mix $result
     * @return false/int
     */
    protected function reportToMaster($cmd, $result)
    {
        $pack_data = Cmd::encodeForWorker($cmd, $result);
        return $this->blockWrite($this->channel, $pack_data);
    }

    /**
     * 向fd写数据，如果socket缓冲区满了，则改用阻塞模式写数据
     * @param resource $fd
     * @param string $str_to_write
     * @param int $time_out 单位毫秒
     */
    protected function blockWrite($fd, $str_to_write, $timeout_ms = 500)
    {
        $send_len = @fwrite($fd, $str_to_write);
        if($send_len == strlen($str_to_write))
        {
            return true;
        }

        // 客户端关闭
        if(feof($fd))
        {
            $this->notice("blockWrite client close");
            return false;
        }

        // 设置阻塞
        stream_set_blocking($fd, 1);
        // 设置超时
        $timeout_sec = floor($timeout_ms/1000);
        $timeout_ms = $timeout_ms%1000;
        stream_set_timeout($fd, $timeout_sec, $timeout_ms*1000);
        $send_len += @fwrite($fd, substr($str_to_write, $send_len));
        // 改回非阻塞
        stream_set_blocking($fd, 0);

        return $send_len == strlen($str_to_write);
    }

    /**
     * master进程挂掉会触发该方法
     * @return void
     */
    protected function onMasterDead()
    {
    }

    /**
     * 处理master发过来的命令
     * @param resource $socket
     * @param int $flag
     */
    public function dealCmd($channel, $length, $buffer)
    {
        // 主进程挂了，完蛋了
        if($length == 0)
        {
            $this->event->delAll($this->channel);
            $this->notice("!!!!!!!!!!!!!!!!!!!!Master has gone !!!!!!!!!!!!!!!!!");
            $this->onMasterDead();
            return false;
        }
        // master发过来的命令字只有一个字节
        $cmd = Cmd::decodeForWorker($buffer);
        // 判断是哪个命令字
        switch($cmd)
        {
            // 获取该worker进程包含的文件
            case Cmd::CMD_REPORT_INCLUDE_FILE:
                $files = get_included_files();
                $this->reportToMaster($cmd, $files);
                break;
            // 命令该worker停止服务
            case Cmd::CMD_STOP_SERVE:
                $this->reportToMaster($cmd, 1);
                $this->stopServe();
                break;
            // 命令重启服务
            case Cmd::CMD_RESTART:
                $this->reportToMaster($cmd, 1);
                $this->stopServe();
                break;
            // 命令关闭通信管道
            case Cmd::CMD_CLOSE_CHANNEL:
                break;
            // 命令上报worker状态信息给master
            case Cmd::CMD_REPORT_STATUS_FOR_MASTER:
                $this->reportToMaster($cmd, array_merge($this->statusInfo, array('memory'=>memory_get_usage(true))));
                break;
            // 未知命令
            default :
                $this->reportToMaster(Cmd::CMD_UNKNOW, 'CMD UNKONW!!');
        }
    }

    /**
     * 发送数据到客户端
     * @return bool
     */
    public function sendToClient($str_to_send)
    {
        // udp 直接发送，要求数据包不能超过65515
        if($this->protocol == 'udp')
        {
            $len = stream_socket_sendto($this->mainSocket, $str_to_send, 0, $this->currentClientAddress);
            return $len == strlen($str_to_send);
        }

        // tcp 如果一次没写完（一般是缓冲区满的情况），则阻塞写
        if(!$this->blockWrite($this->connections[$this->currentDealFd], $str_to_send, $this->sendTimeOut))
        {
            $this->notice('sendToClient fail ,Data length = ' . strlen($str_to_send));
            $this->statusInfo['send_fail']++;
        }
        return true;
    }

}