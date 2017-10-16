<?php
/**
 * select事件轮询库的封装
 * Worker类事件的轮询库
 *
 * @author hongjiangl
 */

class Select implements BaseEvent
{
    // select超时事件,支持只支持Select
    const EV_SELECT_TIMEOUT = 32;

    // select被系统调用打断
    const EV_SELECT_INTERRUPT = 64;

    // 记录所有事件处理函数及参数
    public $allEvents = array();

    // 记录所有信号处理函数及参数
    public $signalEvents = array();

    // 监听的读描述符
    public $readFds = array();

    // 监听的写描述符
    public $writeFds = array();

    // 搞个fd，避免 $readFds $writeFds 都为空时select失败
    public $channel = null;

    // 读超时 毫秒
    protected $readTimeout = 1000;
    // 写超时 毫秒
    protected $writeTimeout = 1000;
    // 超时触发的事件
    protected $selectTimeOutEvent = array();
    // 被系统调用打断触发的事件，一般是收到信号
    protected $selectInterruptEvent = array();

    /**
     * 创建一个管道，避免select空fd
     */
    public function __construct()
    {
        $this->channel = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($this->channel)
        {
            stream_set_blocking($this->channel[0], 0);
            $this->readFds[0] = $this->channel[0];
        }
        fclose($this->channel[0]);
    }

    /**
     * 设置读超时时间
     * @parma int $time_ms 毫秒
     */
    public function setReadTimeOut($time_ms)
    {
        if($time_ms >= 1)
        {
            $this->readTimeout = $time_ms;
        }
    }

    /**
     * 添加事件
     * @see BaseEvent::add()
     */
    public function add($fd, $flag, $func, $args = null, $read_timeout = 1000, $write_timeout = 1000)
    {
        $event_key = (int)$fd;

        // 设置超时
        if ($read_timeout && $read_timeout < $this->readTimeout)
        {
            $this->readTimeout = $read_timeout;
        }

        switch ($flag)
        {
            // 有链接事件
            case self::EV_ACCEPT:
            // 可读事件
            case self::EV_READ:
            // 文件监控描述符可读事件
            case self::EV_NOINOTIFY:
                $this->allEvents[$event_key][$flag] = array('args'=>$args, 'func'=>$func, 'fd'=>$fd);
                $this->readFds[$event_key] = $fd;
                break;
            // 写事件 目前没用到，未实现
            case self::EV_WRITE:
                $this->allEvents[$event_key][$flag] = array('args'=>$args, 'func'=>$func, 'fd'=>$fd);
                $this->writeFds[$event_key] = $fd;
                break;
            // 信号处理事件
            case self::EV_SIGNAL:
                $this->signalEvents[$event_key][$flag] = array('args'=>$args, 'func'=>$func, 'fd'=>$fd);
                pcntl_signal($fd, array($this, 'signalHandler'));
                break;
            // 超时事件
            case self::EV_SELECT_TIMEOUT:
                $this->selectTimeOutEvent = array('args'=>is_array($args) ? $args : array(), 'func'=>$func, 'fd'=>$fd);
                break;
            // 系统调用打断事件
            case self::EV_SELECT_INTERRUPT:
                $this->selectInterruptEvent = array('args'=>is_array($args) ? $args : array(), 'func'=>$func, 'fd'=>$fd);
                break;
        }

        return true;
    }

    /**
     * 回调信号处理函数
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        call_user_func_array($this->signalEvents[$signal][self::EV_SIGNAL]['func'], array(null, self::EV_SIGNAL, $signal));
    }

    /**
     * 删除某个fd的某个事件
     * @see BaseEvent::del()
     */
    public function del($fd, $flag)
    {
        $event_key = (int)$fd;
        switch ($flag)
        {
            // 有链接事件
            case self::EV_ACCEPT:
            // 可读事件
            case self::EV_READ:
            // 文件监控描述符可读事件
            case self::EV_NOINOTIFY:
                unset($this->allEvents[$event_key][$flag]);
                if(empty($this->allEvents[$event_key]))
                {
                    unset($this->readFds[$event_key], $this->allEvents[$event_key]);
                }
                break;
            // 可写事件
            case self::EV_WRITE:
                unset($this->allEvents[$event_key][$flag]);
                if(empty($this->allEvents[$event_key]))
                {
                    unset($this->allEvents[$event_key], $this->writeFds[$event_key]);
                }
                break;
            // 信号
            case self::EV_SIGNAL:
                unset($this->signalEvents[$event_key]);
                pcntl_signal($fd, SIG_IGN);
                break;

        }
        return true;
    }

    /**
     * 事件轮训库主循环
     */
    public function loop()
    {
        $e = null;
        while (1)
        {
            $read = $this->readFds;
            $write = $this->writeFds;
            // 触发信号处理函数
            pcntl_signal_dispatch();
            // stream_select(接收数据流数组并等待它们状态的改变) false：出错 0：超时
            if (!($ret = @stream_select($read, $write, $e, floor($this->readTimeout/1000), ($this->readTimeout*1000)%1000000)))
            {
                // 超时
                if ($ret === 0)
                {
                    if (!empty($this->selectTimeOutEvent))
                    {
                        call_user_func_array($this->selectTimeOutEvent['func'],  $this->selectTimeOutEvent['args']);
                    }
                }

                // 被系统调用或者信号打断
                if($ret === false)
                {
                    if($this->selectInterruptEvent)
                    {
                        call_user_func_array($this->selectInterruptEvent['func'],  $this->selectInterruptEvent['args']);
                    }
                }

                // 触发信号处理函数
                pcntl_signal_dispatch();
                continue;
            }
        }

        // 触发信号处理函数
        pcntl_signal_dispatch();

        // 检查所有可读描述符
        foreach ($read as $fd)
        {
            $event_key = (int)$fd;
            foreach ($read as $fd)
            {
                if (isset($this->allEvents[$event_key][self::EV_ACCEPT]))
                {
                    call_user_func_array($this->allEvents[$event_key][self::EV_ACCEPT]['func'], array($this->allEvents[$event_key][self::EV_ACCEPT]['fd'], self::EV_ACCEPT, $this->allEvents[$event_key][self::EV_ACCEPT]['args']));
                }
                if(isset($this->allEvents[$event_key][self::EV_NOINOTIFY]))
                {
                    call_user_func_array($this->allEvents[$event_key][self::EV_NOINOTIFY]['func'], array($this->allEvents[$event_key][self::EV_NOINOTIFY]['fd'], self::EV_NOINOTIFY,  $this->allEvents[$event_key][self::EV_NOINOTIFY]['args']));
                }
                if (isset($this->allEvents[$event_key][self::EV_READ]))
                {
                    $data = '';
                    while ($tmp = fread($fd, 10240))
                    {
                        $data .= $tmp;
                    }

                    call_user_func_array($this->allEvents[$event_key][self::EV_READ]['func'], array($this->allEvents[$event_key][self::EV_READ]['fd'], strlen($data), $data, $this->allEvents[$event_key][self::EV_READ]['args']));
                }
            }
        }

        // 可写描述符暂时没用
    }

    /**
     * 删除某个fd的所有监听事件
     * @see BaseEvent::delAll()
     */
    public function delAll($fd)
    {
        $event_key = (int)$fd;
        unset($this->writeFds[$event_key], $this->readFds[$event_key], $this->allEvents[$event_key]);
        return true;
    }
}