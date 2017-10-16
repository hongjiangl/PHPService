<?php
/**
 * 
 * master 与 worker进程间通讯命令相关
 * 
 * @author hongjiangl
 *
 */
class Cmd 
{
    protected static $recvBuffers = array();
    
    const CMD_HEAD_LEN = 5;
    
    // master发来的命令相关
    const CMD_REPORT_INCLUDE_FILE = 2;
    const CMD_STOP_SERVE = 3;
    const CMD_RESTART = 4;
    const CMD_CLOSE_CHANNEL = 5;
    const CMD_REPORT_STATUS_FOR_MASTER = 6;
    const CMD_REPORT_WORKER_STATUS = 7;
    const CMD_PING = 8;
    const CMD_PONG = 9;
    
    const CMD_UNKNOW = 255;
    
    
    /**
     * 处理命令字输入
     * @param string $recv_str
     * @return int
     */
    public static function dealCmdResultInput($recv_str)
    {
        $recv_len = strlen($recv_str);
        // 包的长度不够包头长度需要继续收包
        if($recv_len < self::CMD_HEAD_LEN)
        {
            return self::CMD_HEAD_LEN - $recv_len;
        }
        else
        {
            // 协议 [一个字节的命令字+4个字节的包长+包体]
            $unpack_data = unpack("Ccmd/Ipack_len", $recv_str);
            if($unpack_data['pack_len'] < self::CMD_HEAD_LEN)
            {
                return false;
            }
            // 包收完了
            if($recv_len >= $unpack_data['pack_len'])
            {
                return 0;
            }
            // 包体还有些数据没到
            else
            {
                return $unpack_data['pack_len'] - $recv_len;
            }
        }
    }
    
    /**
     * 处理命令结果
     * @param resource $channel
     * @param 标记 $flag
     * @param worker pid $pid
     * @return void
     */
    public static function getCmdResult($channel, $length, $buffer, $pid)
    {
        // 长度为0，说明worker可能退出了
        if($length == 0)
        {
            return false;
        }
        
        if(!isset(self::$recvBuffers[$pid]))
        {
            self::$recvBuffers[$pid] = '';
        }
        
        self::$recvBuffers[$pid] .= $buffer; 
            
        // 处理命令字返回，注意有可能多个返回结果堆积在一起
        $remain_len = self::dealCmdResultInput(self::$recvBuffers[$pid]);
        if(false === $remain_len)
        {
            // 出错了，buffer里面的数据都要丢掉
            self::$recvBuffers[$pid] = '';
            return false;
        }

        // 还有数据没收完
        if($remain_len !== 0)
        {
            return null;
        }
        else
        {
            $ret = self::decodeForMaster(self::$recvBuffers[$pid], $pid);
            self::$recvBuffers[$pid] = '';
            return $ret;
        }
    
    }
    
    /**
     * cmd encode
     * @param char $cmd
     * @return string
     */
    public static function encodeForMaster($cmd)
    {
        return pack("C", $cmd);
    }
    
    /**
     * decodeForMaster
     * @param string $buffer
     * @return array
     */
    public static function decodeForMaster($buffer, $pid)
    {
        $unpack_data = unpack("Ccmd/Ipack_len", $buffer);
        
        // 包体的数据长度
        if($unpack_data['pack_len'] == self::CMD_HEAD_LEN)
        {
            $result = '';
        }
        else
        {
            $result = unserialize(substr(self::$recvBuffers[$pid], self::CMD_HEAD_LEN, $unpack_data['pack_len']));
        }
        
        return array('cmd'=>$unpack_data['cmd'], 'result'=>$result, 'pid'=>$pid);
    }
    
    /**
     * encodeForWorker
     * @param char $cmd
     * @param mix $result
     * @return string
     */
    public static function encodeForWorker($cmd, $result)
    {
        $result = serialize($result);
        $pack_len = strlen($result) + 1 + 4;
        return pack("CI", $cmd, $pack_len) . $result;
    }
    
    /**
     * decodeForWorker
     * @param string $buffer
     * @return char
     */
    public static function decodeForWorker($buffer)
    {
        $cmd_data = unpack('Ccmd', $buffer);
        return $cmd_data['cmd'];
    }
    
    /**
     * 清理pid对应的缓冲
     * @param int $pid
     */
    public static function clearPid($pid)
    {
        unset(self::$recvBuffers[$pid]);
    }
    
}
