<?php
/**
 * libevent事件轮询库的封装
 * Worker类事件的轮询库
 *
 * @author hongjiangl
 */

class Libevent implements BaseEvent
{
    // eventBase实例
    public $eventBase = null;

    // 记录信号回调函数
    public $eventSignal = array();

    // 读数据eventBuffer实例
    public $eventBuffer = array();

    // 记录所有监听事件
    public $allEvents = array();

    /**
     * 初始化
     */
    public function __construct()
    {
        $this->eventBase = event_base_new();
    }

    public function add($fd, $flag, $func, $args = null, $read_timeout = 1000, $write_timeout = 1000)
    {
        $event_key = intval($fd);

        $real_flag = EV_READ | EV_PERSIST;

        switch ($flag)
        {
            // 链接事件
            case self::EV_ACCEPT:
                break;
            // 数据可读事件
            case self::EV_READ:
                $this->eventBuffer[$event_key] = event_buffer_new($fd, array($this, 'bufferCallBack'), NULL, array($this, 'bufferCallBackErr'), array('args' => $args, 'func' => $func, 'fd' => $fd));
                event_buffer_base_set($this->eventBuffer[$event_key], $this->eventBase);
                event_buffer_timeout_set($this->eventBuffer[$event_key], ceil($read_timeout/1000), ceil($write_timeout/1000));
                event_buffer_watermark_set($this->eventBuffer[$event_key], EV_READ, 0, 0xffffff);
                event_buffer_enable($this->eventBuffer[$event_key], EV_READ | EV_PERSIST);
                return true;
            // 数据可写事件
            case self::EV_WRITE;
                $real_flag = EV_WRITE | EV_PERSIST;
                break;
            // 信号监听事件
            case self::EV_SIGNAL:
                $real_flag = EV_SIGNAL | EV_PERSIST;

                //　创建一个用户监听的event
                $this->eventSignal[$event_key] = event_new();

                // 设置监听处理函数
                if (!event_set($this->eventSignal[$event_key], $fd, $real_flag, $func, $args))
                {
                    return false;
                }

                if (event_base_set($this->eventSignal[$event_key], $this->eventBase))
                {
                    return false;
                }

                // 添加事件
                if (!event_add($this->eventSignal[$event_key]))
                {
                    return false;
                }

                return true;
                break;
            // 监控文件描述符可读事件
            case self::EV_NOINOTIFY;
                $real_flag = EV_READ | EV_PERSIST;
                break;
        }

        // 创建一个用户监听的event
        $this->allEvents[$event_key][$flag] = event_new();

        // 设置监听处理函数
        if (!event_set($this->allEvents[$event_key][$flag], $fd, $real_flag, $func, $args))
        {
            return false;
        }

        if (!event_base_set($this->allEvents[$event_key][$flag], $this->eventBase))
        {
            return false;
        }

        if (!event_add($this->allEvents[$event_key][$flag]))
        {
            return false;
        }

        return true;
    }

    public function bufferCallBack($buffer, $args)
    {
        $data = '';
        while ($tmp = event_buffer_read($buffer, 10240))
        {
            $data .= $tmp;
        }
        return call_user_func_array($args['func'], array($args['fd'], strlen($data), $data, $args['args']));
    }

    /**
     * 描述符异常时触发的函数
     */
    public function bufferCallBackErr($buffer, $error, $args)
    {
        return call_user_func_array($args['func'], array($args['fd'], 0, '', $args['args']));
    }

    /**
     * 删除fd的某个事件
     * @see BaseEvent::del()
     */
    public function del($fd ,$flag)
    {
        $event_key = (int)$fd;
        // 读事件
        if($flag == BaseEvent::EV_READ)
        {
            if(isset($this->eventBuffer[$event_key]))
            {
                event_buffer_disable($this->eventBuffer[$event_key], EV_READ | EV_WRITE);
                event_buffer_free($this->eventBuffer[$event_key]);
            }
            unset($this->eventBuffer[$event_key]);
        }
        // 链接监听等事件
        if($flag == BaseEvent::EV_ACCEPT || $flag == BaseEvent::EV_NOINOTIFY || $flag == self::EV_WRITE)
        {
            if(isset($this->allEvents[$event_key][$flag]))
            {
                event_del($this->allEvents[$event_key][$flag]);
            }
            unset($this->allEvents[$event_key][$flag]);
        }
        // 信号
        if($flag == BaseEvent::EV_SIGNAL)
        {
            if(isset($this->eventSignal[$event_key]))
            {
                event_del($this->eventSignal[$event_key]);
            }
            unset($this->eventSignal[$event_key]);
        }

        return true;
    }

    public function loop()
    {
        event_base_loop($this->eventBase);
    }

    /**
     * 删除某个fd的所有事件
     * @see BaseEvent::delAll()
     */
    public function delAll($fd)
    {
        $event_key = (int)$fd;
        if(!empty($this->allEvents[$event_key]))
        {
            foreach($this->allEvents[$event_key] as $flag=>$event)
            {
                event_del($event);
            }
        }

        if(isset($this->eventBuffer[$event_key]))
        {
            event_buffer_disable($this->eventBuffer[$event_key], EV_READ | EV_WRITE);
            event_buffer_free($this->eventBuffer[$event_key]);
        }

        unset($this->allEvents[$event_key], $this->eventBuffer[$event_key]);

        return true;
    }
}