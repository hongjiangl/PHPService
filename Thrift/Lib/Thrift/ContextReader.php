<?php
namespace Thrift;
/**
 * 上下文解析类
 * @author hongjiangl
 *
 */
class ContextReader
{
    public static function read(&$buf)
    {
        $context_str_len = self::readContextSerialize($buf);

        if($context_str_len)
        {
            $buf = \Thrift\Factory\TStringFuncFactory::create()->substr($buf, $context_str_len);
        }
    }

    public static function readContextSerialize($buf){
        // 判断是否是TBinaryProtocol
        $sz = unpack('N', $buf);
        if((int)($sz[1] & \Thrift\Protocol\TBinaryProtocol::VERSION_MASK) != (int)\Thrift\Protocol\TBinaryProtocol::VERSION_1)
        {
            return 0;
        }
        $totallength=\Thrift\Factory\TStringFuncFactory::create()->strlen($buf);
        global $context;
        $obj = new \Thrift\ContextSeralize($context);
        $transObj = new \Thrift\Transport\TMemoryBuffer($buf);
        $protocol = new \Thrift\Protocol\TBinaryProtocol($transObj);
        $flag = $obj->read($protocol);
        if ($flag){
            return $totallength - \Thrift\Factory\TStringFuncFactory::create()->strlen($transObj->getBuffer());
        }else{
            return 0;
        }
    }
}
