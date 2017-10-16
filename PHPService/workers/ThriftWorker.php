<?php
/**
 * thrift Worker
 *
 * @author hongjiangl <hongjiangl@jumei.com>
 *
 */
require_once SERVER_BASE . '../Thrift/Lib/Thrift/Context.php';
require_once SERVER_BASE . '../Thrift/Lib/Thrift/ContextSerialize.php';
require_once SERVER_BASE . '../Thrift/Lib/Thrift/ContextReader.php';
require_once SERVER_BASE . '../Thrift/Lib/Thrift/ClassLoader/ThriftClassLoader.php';

use Thrift\ClassLoader\ThriftClassLoader;

$loader = new ThriftClassLoader();
$loader->registerNamespace('Thrift', SERVER_BASE . '../Thrift/Lib/');
$loader->register();

define('IN_THRIFT_WORKER', true);

class ThriftWorker extends PHPServerWorker
{

    /**
     * 存放thrift生成文件的目录
     */
    protected $providerDir = null;

    /**
     * 存放对thrift生成类的实现目录
     */
    protected $handlerDir = null;

    /**
     * thrift生成类实现的命名空间
     * @var string
     */
    protected $handlerNamespace = 'Services';

    /**
     * thrift生成类的命名空间
     * @var string
     */
    protected $providerNamespace = 'Services';

    /**
     * 服务名
     */
    public static $appName = 'ThriftWorker';

    /**
     * 进程启动时的一些初始化
     * @see PHPServerWorker::onServe()
     */
    public function onServe()
    {
        $bootstrap = PHPServerConfig::get('workers.' . $this->serviceName . '.bootstrap');

        if (is_file($bootstrap)) {
            require_once $bootstrap;
        }

        if($app_name = PHPServerConfig::get('workers.'.$this->serviceName.'.app_name'))
        {
            self::$appName = $app_name;
        }
        else
        {
            // 服务名
            self::$appName = $this->serviceName;
        }

        // 初始化thrift生成文件存放目录
        $provider_dir = PHPServerConfig::get('workers.'.$this->serviceName.'.provider');
        if ($provider_dir)
        {
            if ($this->providerDir = realpath($provider_dir))
            {
                if ($path_array = explode('/', $this->providerDir))
                {
                    $this->providerNamespace = $path_array[count($path_array) - 1];
                }
            }
            else
            {
                $this->providerDir = $provider_dir;
            }
        }

        // 初始化thrift生成类业务实现存放目录
        $handler_dir = PHPServerConfig::get('workers.'.$this->serviceName.'.handler');
        if ($handler_dir)
        {
            if($this->handlerDir = realpath($handler_dir))
            {
                if($path_array = explode('/', $this->handlerDir))
                {
                    $this->handlerNamespace = $path_array[count($path_array)-1];
                }
            }
            else
            {
                $this->handlerDir = $handler_dir;
            }
        }
        else
        {
            $this->handlerDir = $provider_dir;
        }
    }

    /**
     * 业务逻辑(non-PHPdoc)
     * @see Worker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        // 如果是文本协议
        if(strpos($recv_str, "\nRPC") === 1 || strpos($recv_str, "\nTEST") === 1 || strpos($recv_str, "\nPING") === 1)
        {
            return '';
        }
        // thrift协议
        else
        {
            return $this->dealThriftProcess($recv_str);
        }
    }

    /**
    * 业务处理(non-PHPdoc)
    * @see PHPServerWorker::dealProcess()
    */
    public function dealThriftProcess($recv_str) {

        // 服务名
        $serviceName = $this->serviceName;
        // 本地调用方法名
        $method_name = 'none';

        // 尝试读取上下文信息
        try{
            // 去掉TFrameTransport头
            $body_str = substr($recv_str, 4);
            // 再组合成TFrameTransport报文
            $recv_str = pack('N', strlen($body_str)).$body_str;
        }
        catch(Exception $e)
        {
            return;
        }

        // 尝试处理业务逻辑
        try {
            // 服务名为空
            if (!$serviceName){
                throw new \Exception('Context[serverName] empty', 400);
            }

            // 如果handler命名空间为provide
            $handlerClass = $this->handlerNamespace.'\\'.$serviceName.'\\' . $serviceName . 'Handler';

            // processor
            $processorClass = $this->providerNamespace . '\\' . $serviceName . '\\' . $serviceName . 'Processor';

            // 文件不存在尝试从磁盘上读取
            if(!class_exists($handlerClass, false))
            {
                clearstatcache();
                if(!class_exists($processorClass, false))
                {
                    require_once $this->providerDir.'/'.$serviceName.'/Types.php';
                    require_once $this->providerDir.'/'.$serviceName.'/'.$serviceName.'.php';
                }

                $handler_file = $this->handlerNamespace == 'Services' ? $this->handlerDir.'/'.$serviceName.'/'.$serviceName.'Handler.php' : $this->handlerDir.'/'.$serviceName.'.php';

                if(is_file($handler_file))
                {
                    require_once $handler_file;
                }

                if(!class_exists($handlerClass))
                {
                    throw new \Exception('Class ' . $handlerClass . ' not found', 404);
                }
            }

            // 运行thrift
            $handler = new $handlerClass();
            $processor = new $processorClass($handler);
            $thriftsocket = new \Thrift\Transport\TBufferSocket();
            $thriftsocket->setHandle($this->connections[$this->currentDealFd]);
            $thriftsocket->setBuffer($recv_str);
            $framedTrans = new \Thrift\Transport\TFramedTransport($thriftsocket, true, true);
            $protocol = new Thrift\Protocol\TBinaryProtocol($framedTrans, false, false);
            $protocol->setTransport($framedTrans);
            $processor->process($protocol, $protocol);
            return;
        }
        catch (Exception $e) {var_dump($e->getMessage());}
    }

    /**
     * 处理thrift包，判断包是否接收完整
     * 固定使用TFramedTransport，前四个字节是包体长度信息
     * @see PHPServerWorker::dealInput()
     */
    public function dealInput($recv_str) {
        // 不够4字节
        if(strlen($recv_str) < 4)
        {
            return 1;
        }

        return $this->dealThriftInput($recv_str);
    }

    /**
     * 处理thrift协议输入
     * @param unknown_type $recv_st
     */
    public function dealThriftInput($recv_str)
    {
        $val = unpack('N', $recv_str);
        $length = $val[1] + 4;
        if ($length <= Thrift\Factory\TStringFuncFactory::create()->strlen($recv_str)) {
            return 0;
        }
        return 1;
    }

}