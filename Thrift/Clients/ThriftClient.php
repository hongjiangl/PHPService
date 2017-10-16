<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace ThriftClient;

define('THRIFT_CLIENT', realpath(__DIR__ ) );

require_once THRIFT_CLIENT . '/../Lib/Thrift/ClassLoader/ThriftClassLoader.php';
require_once THRIFT_CLIENT . '/AddressManager.php';

$loader = new \Thrift\ClassLoader\ThriftClassLoader();
$loader->registerNamespace('Thrift', THRIFT_CLIENT. '/../Lib');
$loader->register();

/**
 * 
 * 通用客户端,支持故障ip自动踢出及探测节点是否已经存活
 * 
 * <b>使用示例:</b>
 * <pre>
 * <code>
 // 引入客户端文件
    require_once 'yourdir/workerman/applications/ThriftRpc/Clients/ThriftClient.php';
    use ThriftClient;
    
    // 传入配置，一般在某统一入口文件中调用一次该配置接口即可
    ThriftClient::config(array(
                         'HelloWorld' => array(
                           'addresses' => array(
                               '127.0.0.1:9090',
                               '127.0.0.2:9191',
                             ),
                             'thrift_protocol' => 'TBinaryProtocol',//不配置默认是TBinaryProtocol，对应服务端HelloWorld.conf配置中的thrift_protocol
                             'thrift_transport' => 'TBufferedTransport',//不配置默认是TBufferedTransport，对应服务端HelloWorld.conf配置中的thrift_transport
                           ),
                           'UserInfo' => array(
                             'addresses' => array(
                               '127.0.0.1:9393'
                             ),
                           ),
                         )
                       );
    // =========  以上在WEB入口文件中调用一次即可  ===========
    
    
    // =========  以下是开发过程中的调用示例  ==========
    
    // 初始化一个HelloWorld的实例
    $client = ThriftClient::instance('HelloWorld');
    
    // --------同步调用实例----------
    var_export($client->sayHello("TOM"));
    
    // --------异步调用示例-----------
    // 异步调用 之 发送请求给服务端（注意：异步发送请求格式统一为 asend_XXX($arg),既在原有方法名前面增加'asend_'前缀）
    $client->asend_sayHello("JERRY");
    $client->asend_sayHello("KID");
    
    // 这里是其它业务逻辑
    sleep(1);
    
    // 异步调用 之 接收服务端的回应（注意：异步接收请求格式统一为 arecv_XXX($arg),既在原有方法名前面增加'arecv_'前缀）
    var_export($client->arecv_sayHello("KID"));
    var_export($client->arecv_sayHello("JERRY"));
 * 
 * <code>
 * </pre>
 * 
 *
 */
class ThriftClient 
{
    /**
     * 客户端实例
     * @var array
     */
    private static $instance = array();
    
    /**
     * 配置
     * @var array
     */
    private static $config = null;
    
    /**
     * 故障节点共享内存fd
     * @var resource
     */
    private static $badAddressShmFd = null;
    
    /**
     * 故障的节点列表
     * @var array
     */
    private static $badAddressList = null;
    
    /**
     * 设置/获取 配置
     *  array(  
     *      'HelloWorld' => array(
     *          'addresses' => array(
     *              '127.0.0.1:9090',
     *              '127.0.0.2:9090',
     *              '127.0.0.3:9090',
     *          ),
     *      ),
     *      'UserInfo' => array(
     *          'addresses' => array(
     *              '127.0.0.1:9090'
     *          ),
     *      ),
     *  )
     * @param array $config
     * @return array
     */
    public static function config(array $config = array())
    {
        if(!empty($config))
        {
            // 赋值
            self::$config = $config;
            
            // 注册address到AddressManager
            $address_map = array();
            foreach(self::$config as $key => $item)
            {
                $address_map[$key] = $item['addresses'];
            }
            AddressManager::config($address_map);
        }
        return self::$config;
    }
    
    /**
     * 获取实例
     * @param string $serviceName 服务名称
     * @param bool $newOne 是否强制获取一个新的实例
     * @return object/Exception
     */
    public static function instance($serviceName, $newOne = false)
    {
        if (empty($serviceName))
        {
            throw new \Exception('ServiceName can not be empty');
        }
        
        if($newOne)
        {
            unset(self::$instance[$serviceName]);
        }
        
        if(!isset(self::$instance[$serviceName]))
        {
            self::$instance[$serviceName] = new ThriftInstance($serviceName);
        }
        
        return self::$instance[$serviceName];
    }
    
    /**
     * getProtocol
     * @param string $service_name
     * @return string
     */
    public static function getProtocol($service_name)
    {
        $config = self::config();
        $protocol = 'TBinaryProtocol';
        if(!empty($config[$service_name]['thrift_protocol']))
        {
            $protocol = $config[$service_name]['thrift_protocol'];
        }
        return "\\Thrift\\Protocol\\".$protocol;
    }
    
    /**
     * getTransport
     * @param string $service_name
     * @return string
     */
    public static function getTransport($service_name)
    {
        $config = self::config();
        $transport= 'TBufferedTransport';
        if(!empty($config[$service_name]['thrift_transport']))
        {
            $transport = $config[$service_name]['thrift_transport'];
        }
        return "\\Thrift\\Transport\\".$transport;
    }
    
    /**
     * 获得服务目录，用来查找thrift生成的客户端文件
     * @param string $service_name
     * @return string
     */
    public static function getServiceDir($service_name)
    {
        $config = self::config();
        if(!empty($config[$service_name]['service_dir']))
        {
            $service_dir = $config[$service_name]['service_dir']."/$service_name";
        }
        else
        {
            $service_dir = THRIFT_CLIENT . "/../Services/$service_name";
        }
        return $service_dir;
    }
}

/**
 * 
 * thrift异步客户端实例
 * @author liangl
 *
 */
class ThriftInstance
{
    /**
     * 异步发送前缀
     * @var string
     */
    const ASYNC_SEND_PREFIX = 'asend_';
    
    /**
     * 异步接收后缀
     * @var string
     */
    const ASYNC_RECV_PREFIX = 'arecv_';
    
    /**
     * 服务名
     * @var string
     */
    public $serviceName = '';
    
    /**
     * thrift实例
     * @var array
     */
    protected $thriftInstance = null;
    
    /**
     * thrift异步实例['asend_method1'=>thriftInstance1, 'asend_method2'=>thriftInstance2, ..]
     * @var array
     */
    protected $thriftAsyncInstances = array();
    
    /**
     * 初始化工作
     * @return void
     */
    public function __construct($serviceName)
    {
        if(empty($serviceName))
        {
            throw new \Exception('serviceName can not be empty', 500);
        }
        $this->serviceName = $serviceName;
        $this->includeFile();
    }
    
    /**
     * 方法调用
     * @param string $name
     * @param array $arguments
     * @return mix
     */
    public function __call($method_name, $arguments)
    {
        // 异步发送
        if(0 === strpos($method_name ,self::ASYNC_SEND_PREFIX))
        {
            $real_method_name = substr($method_name, strlen(self::ASYNC_SEND_PREFIX));
            $arguments_key = serialize($arguments);
            $method_name_key = $method_name . $arguments_key;
            // 判断是否已经有这个方法的异步发送请求
            if(isset($this->thriftAsyncInstances[$method_name_key]))
            {
                // 删除实例，避免在daemon环境下一直出错
                unset($this->thriftAsyncInstances[$method_name_key]);
                throw new \Exception($this->serviceName."->$method_name(".implode(',',$arguments).") already has been called, you can't call again before you call ".self::ASYNC_RECV_PREFIX.$real_method_name, 500);
            }
           
            // 创建实例发送请求
            $instance = $this->__instance();
            $callback = array($instance, 'send_'.$real_method_name);
            if(!is_callable($callback))
            {
                throw new \Exception($this->serviceName.'->'.$method_name. ' not callable', 400);
            }
            $ret = call_user_func_array($callback, $arguments);
            // 保存客户单实例
            $this->thriftAsyncInstances[$method_name_key] = $instance;
            return $ret;
        }
        // 异步接收
        if(0 === strpos($method_name, self::ASYNC_RECV_PREFIX))
        {
            $real_method_name = substr($method_name, strlen(self::ASYNC_RECV_PREFIX));
            $send_method_name = self::ASYNC_SEND_PREFIX.$real_method_name;
            $arguments_key = serialize($arguments);
            $method_name_key = $send_method_name . $arguments_key;
            // 判断是否有发送过这个方法的异步请求
            if(!isset($this->thriftAsyncInstances[$method_name_key]))
            {
                throw new \Exception($this->serviceName."->$send_method_name(".implode(',',$arguments).") have not previously been called", 500);
            }
            
            $instance = $this->thriftAsyncInstances[$method_name_key];
            // 先删除客户端实例
            unset($this->thriftAsyncInstances[$method_name_key]);
            $callback = array($instance, 'recv_'.$real_method_name);
            if(!is_callable($callback))
            {
                throw new \Exception($this->serviceName.'->'.$method_name. ' not callable', 400);
            }
            // 接收请求
            $ret = call_user_func_array($callback, array());
             
            return $ret;
        }
        
        // 同步调用
        $success = true;
        // 每次都重新创建一个实例
        $this->thriftInstance = $this->__instance();
        
        $callback = array($this->thriftInstance, $method_name);
        if(!is_callable($callback))
        {
            throw new \Exception($this->serviceName.'->'.$method_name. ' not callable', 1400);
        }
        // 调用客户端方法
        $ret = call_user_func_array($callback, $arguments);
        // 每次都销毁实例
        $this->thriftInstance = null;
        
        return $ret;
    }
    
    
    /**
     * 获取一个实例
     * @return instance
     */
    protected function __instance()
    {
        // 获取一个服务端节点地址
        $address = AddressManager::getOneAddress($this->serviceName);
        list($ip, $port) = explode(':', $address);
        
        // Transport
        $socket = new \Thrift\Transport\TSocket($ip, $port);
        $transport_name = ThriftClient::getTransport($this->serviceName);
        $transport = new $transport_name($socket);
        // Protocol
        $protocol_name = ThriftClient::getProtocol($this->serviceName);
        $protocol = new $protocol_name($transport);
        try 
        {
            $transport->open();
        }
        catch(\Exception $e)
        {
            // 无法连上，则踢掉这个地址
            AddressManager::kickAddress($address);
            throw $e;
        }

        // 客户端类名称
        $class_name = "\\Services\\" . $this->serviceName . "\\" . $this->serviceName . "Client";
        // 类不存在则尝试加载
        if(!class_exists($class_name))
        {
            $service_dir = $this->includeFile();
            if(!class_exists($class_name))
            {
                throw new \Exception("Class $class_name not found in directory $service_dir");
            }
        }
        
        // 初始化一个实例
        return new $class_name($protocol);
    }
    
    /**
     * 载入thrift生成的客户端文件
     * @throws \Exception
     * @return void
     */
    protected function includeFile()
    {
        // 载入该服务下的所有文件
        $service_dir = ThriftClient::getServiceDir($this->serviceName);
        foreach(glob($service_dir.'/*.php') as $php_file)
        {
            require_once $php_file;
        }
        return $service_dir;
    }
}


/***********以下是测试代码***********/
if(PHP_SAPI == 'cli' && isset($argv[0]) && $argv[0] == basename(__FILE__))
{
    ThriftClient::config(array(
                         'HelloWorld' => array(
                             'addresses' => array(
                                   '127.0.0.1:9090',
                                   //'127.0.0.2:9191', //设置的一个故障地址，用来测试客户端故障节点踢出功能
                               ),
                               'thrift_protocol'  => 'TBinaryProtocol',        // 不设置默认为TBinaryProtocol
                               'thrift_transport' => 'TBufferedTransport',  // 不设置默认为TBufferedTransport
                               'service_dir'         => __DIR__.'/../Services/'   // 不设置默认是__DIR__.'/../Services/',即上一级目录下的Services目录
                           ),
                           'UserInfo' => array(
                               'addresses' => array(
                                   '127.0.0.1:9090'
                               ),
                           ),
                     )
             );
    $client = ThriftClient::instance('HelloWorld');
    
    // 同步
    echo "sync send and recv sayHello(\"TOM\")\n";
    var_export($client->sayHello("TOM"));
    
    // 异步
    echo "\nasync send request asend_sayHello(\"JERRY\") asend_sayHello(\"KID\")\n";
    $client->asend_sayHello("JERRY");
    $client->asend_sayHello("KID");
    
    // 这里是其它业务逻辑
    echo "sleep 1 second now\n";
    sleep(1);
    
    echo "\nasync recv response arecv_sayHello(\"KID\") arecv_sayHello(\"JERRY\")\n";
    var_export($client->arecv_sayHello("KID"));
    var_export($client->arecv_sayHello("JERRY"));
    echo "\n";
}
