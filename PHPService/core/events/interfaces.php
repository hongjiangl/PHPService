<?php
/**
 *
 * 事件轮询库的通用接口
 * 其它事件轮询库需要实现这些接口才能在这个server框架中使用
 * 目前 Select libevent libev libuv这些事件轮询库已经封装好这些接口可以直接使用
 *
 * @author hongjiangl
 */

interface BaseEvent
{
    // 获取链接事件
    const EV_ACCEPT = 1;

    // 数据可读事件
    const EV_READ = 2;

    // 数据可写事件
    const EV_WRITE = 4;

    // 信号事件
    const EV_SIGNAL = 8;

    // 文件更新事件
    const EV_NOINOTIFY = 16;

    // 添加事件
    public function add($fd, $flag, $func);

    // 删除事件
    public function del($fd, $flag);

    // 轮询事件
    public function loop();

    // 删除所有事件
    public function delAll($fd);
}