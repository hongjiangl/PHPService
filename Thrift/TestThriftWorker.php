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
use Workerman\Worker;

require_once THRIFT_ROOT . '/Lib/Thrift/ClassLoader/ThriftClassLoader.php';

use Thrift\ClassLoader\ThriftClassLoader;
use Workerman\Protocols\HttpCache;
use Workerman\Protocols\Http;

$loader = new ThriftClassLoader();
$loader->registerNamespace('Thrift', THRIFT_ROOT.'/Lib');
$loader->registerNamespace('Service', THRIFT_ROOT);
$loader->register();

class TestThriftWorker extends Worker
{

    /**
     * Mime.
     * @var string
     */
    protected static $defaultMimeType = 'text/html; charset=utf-8';

    /**
     * Virtual host to path mapping.
     * @var array ['workerman.net'=>'/home', 'www.workerman.net'=>'home/www']
     */
    protected $serverRoot = array();

    /**
     * Mime mapping.
     * @var array
     */
    protected static $mimeTypeMap = array();


    /**
     * Used to save user OnWorkerStart callback settings.
     * @var callback
     */
    protected $_onWorkerStart = null;

    /**
     * Add virtual host.
     * @param string $domain
     * @param string $root_path
     * @return void
     */
    public  function addRoot($domain, $root_path)
    {
        $this->serverRoot[$domain] = $root_path;
    }

    /**
     * Construct.
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($socket_name, $context_option = array())
    {
        list($scheme, $address) = explode(':', $socket_name, 2);
        parent::__construct('http:'.$address, $context_option);
        $this->name = 'WebServer';
    }

    /**
     * Run webserver instance.
     * @see Workerman.Worker::run()
     */
    public function run()
    {
        $this->_onWorkerStart = $this->onWorkerStart;
        $this->onWorkerStart = array($this, 'onWorkerStart');
        $this->onMessage = array($this, 'onMessage');
        parent::run();
    }

    /**
     * Emit when process start.
     * @throws \Exception
     */
    public function onWorkerStart()
    {
        if(empty($this->serverRoot))
        {
            throw new \Exception('server root not set, please use WebServer::addRoot($domain, $root_path) to set server root path');
        }
        // Init HttpCache.
        HttpCache::init();
        // Init mimeMap.
        $this->initMimeTypeMap();

        // Try to emit onWorkerStart callback.
        if($this->_onWorkerStart)
        {
            try
            {
                call_user_func($this->_onWorkerStart, $this);
            }
            catch(\Exception $e)
            {
                echo $e;
                exit(250);
            }
        }
    }

    /**
     * Init mime map.
     * @return void
     */
    public function initMimeTypeMap()
    {
        $mime_file = Http::getMimeTypesFile();
        if(!is_file($mime_file))
        {
            $this->notice("$mime_file mime.type file not fond");
            return;
        }
        $items = file($mime_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if(!is_array($items))
        {
            $this->log("get $mime_file mime.type content fail");
            return;
        }
        foreach($items as $content)
        {
            if(preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match))
            {
                $mime_type = $match[1];
                $workerman_file_extension_var = $match[2];
                $workerman_file_extension_array = explode(' ', substr($workerman_file_extension_var, 0, -1));
                foreach($workerman_file_extension_array as $workerman_file_extension)
                {
                    self::$mimeTypeMap[$workerman_file_extension] = $mime_type;
                }
            }
        }
    }

    /**
     * Emit when http message coming.
     * @param TcpConnection $connection
     * @param mixed $data
     * @return void
     */
    public function onMessage($connection)
    {
        // REQUEST_URI.
        $workerman_url_info = parse_url($_SERVER['REQUEST_URI']);

        if(!$workerman_url_info)
        {
            Http::header('HTTP/1.1 400 Bad Request');
            return $connection->close('<h1>400 Bad Request</h1>');
        }
        return $connection->close($this->dealProcess());

    }

    protected $thriftServiceArray = array();

    /**
     * 处理业务逻辑
     */
    public function dealProcess()
    {
        $time_start = microtime(true);
        $services = json_encode($this->getService());
        if(!empty($_POST))
        {
            $class = isset($_POST['class']) ? $_POST['class'] : '';
            $func = isset($_POST['func']) ? $_POST['func'] : '';
            $param = isset($_POST['value']) ? $_POST['value'] : array();
            if(get_magic_quotes_gpc() && !empty($_POST['value']) && is_array($_POST['value']))
            {
                foreach($_POST['value'] as $index=>$value)
                {
                    $_POST['value'][$index] = stripslashes(trim($value));
                }
            }
            if($param)
            {
                foreach($param as $index=>$value)
                {
                    if(stripos($value, 'array') === 0 || $value === 'true' || $value === 'false' || $value == 'null' || stripos($value, 'object') === 0)
                    {
                        eval('$param['.$index.']='.$value.';');
                    }
                }
            }

            try{
                $rsp_data = call_user_func_array(array(ThriftClient::instance($class), $func), $param);
            }catch(Exception $e){
                $rsp_data = $e.'';
            }

            return $this->display($rsp_data, microtime(true)-$time_start, $services);
        }
        elseif(isset($_GET['ajax_get_service']))
        {
            return $services;
        }

        return $this->display('','','','',microtime(true)-$time_start);
    }

    public function display($rsp_data = '', $cost='', $services = array())
    {
        $value_data = '';
        $class = isset($_POST['class']) ? $_POST['class'] : '';
        $func = isset($_POST['func']) ? $_POST['func'] : '';
        $rsp_data = !is_scalar($rsp_data) ? var_export($rsp_data, true) : $rsp_data;
        $cost = $cost ? round($cost, 5) : '';

        // 默认给个测试参数
        if(empty($_POST))
        {
            $class = "";
            $func = "";
            $_POST['value'][] = '';
        }

        if(isset($_POST['value']))
        {
            foreach($_POST['value'] as $value)
            {
                $value_data .= '<tr><td>参数</td><td><input type="text" name="value[]" style="width:480px;" value=\''.htmlspecialchars($value, ENT_QUOTES).'\' autocomplete="off" disableautocomplete/> <a href="javascript:void(0)" onclick="delParam(this)">删除本行</a></td></tr>';
            }
        }
        else
        {
            $value_data = '<tr><td>参数</td><td><input type="text" name="value[]" style="width:480px;" value="" /> <a href="javascript:void(0)" onclick="delParam(this)">删除本行</a></td></tr>';
        }
        $services = json_encode($services);
        $display_data = <<<HHH
<html>
    <head>
        <meta charset=utf-8>
        <title>Thrift Rpc test tool</title>
    </head>
    <body>
        <b style="color:red"></b>
        </br>
        <b>数组使用array(..)格式,bool直接使用true/false,null直接写null</b>
        </br>
        <form action="" method="post">
            <table>
                <tr>
                    <td>类</td>
                    <td><input id='service_class' type="text" name="class" style="width:480px;" value="$class" autocomplete="off" disableautocomplete/></td>
                </tr>
                <tr>
                    <td>方法</td>
                    <td><input id='service_method' type="text" name="func" style="width:480px;" value="$func"  autocomplete="off" disableautocomplete/></td>
                </tr>
                <tbody id="parames">
                   $value_data
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2"><a href="javascript:void(0)" onclick="addParam()">添加参数</a></td>
                    </tr>
                      <tr>
                        <td colspan="2" align="center">
                        <input style="padding:5px 20px;" type="submit" value="submit" />
                        <br>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </form>
        <b>Return Data: </b><pre>$rsp_data</pre><br>
        <br><br>
	<table>
<tr>
<td>

</td>
<td width=24px>
</td>
<td>

</td>
</tr>
    </table>

        <b>耗时:</b>{$cost}秒
        <div id='service' style="position:absolute;left:200;display:none"></div>
        <script type="text/javascript" src="http://libs.baidu.com/jquery/1.9.0/jquery.js"></script>
        <script type="text/javascript">
            var services = $services;
            function addParam(value , auto ) {
                var style = auto ? 'width:480px;color:#BBBBBB' : 'width:480px;';
                var auto_flag = auto ? 'auto="true"' : '';
                value = value ? value : '';
                $('#parames').append('<tr><td>参数</td><td><input class="service_param" type="text" name="value[]" style="'+style+'" value="'+value+'" '+auto_flag+'/> <a href="javascript:void(0)" onclick="delParam(this)">删除本行</a></td></tr>');
            }

            function delParam(obj) {
                $(obj).parent('td').parent('tr').remove();
            }
            var last_input_id = '';
            $(document).click(
                function(event)
                {
                    var div = $("#service");
                    var e = $(event.target);
                    // 处理类
                    if(e.attr('id') && e.attr('id') == 'service_class')
                    {
                          $.ajax({
                              type: "get",
                              dataType: "json",
                              url: "/?ajax_get_service",
                              async : false,
                              complete :function(){},
                              success: function(msg){
                                 services = msg;
                              }
                          });

                          div.empty();
                          $.each(services, function(key,value){div.append('<a class="list_class_item" href="#" onclick="return false">'+key+'</a>')});
                          div.css("top",$('#service_class').offset().top+$('#service_class').height()+7);
                          div.css("left",$('#service_class').offset().left);
                          div.css("width",$('#service_class').width()+2);
                          div.fadeIn();
                          last_input_id = 'service_class';
                          return;
                    }
                    // 处理方法
                    if(e.attr('id') && e.attr('id') == 'service_method')
                    {
                          div.empty();
                          if($("#service_class").attr('value') && services[$("#service_class").attr('value')])
                          {
                              $.each(services[$("#service_class").attr('value')], function(key, value){
                                  div.append('<a class="list_method_item" href="#" onclick="return false">'+key+'</a>');
                              });
                              div.css("top",$('#service_method').offset().top + $('#service_class').height()+7);
                              div.css("left",$('#service_method').offset().left);
                              div.css("width",$('#service_method').width()+2);
                              div.fadeIn();
                          }
                          last_input_id = 'service_method';
                          return;
                    }
                    // 处理点击类提示浮层
                    if(e.attr('class') && e.attr('class') == 'list_class_item')
                    {
                        $('#service_class').attr('value',e.html());
                        $("#service").fadeOut();
                    }
                    // 处理点击方法提示浮层
                    if(e.attr('class') && e.attr('class') == 'list_method_item')
                    {
                        $('#service_method').attr('value',e.html());
                        // 创建参数栏
                        $("#parames").empty();
                        if($("#service_class").attr('value') && services[$("#service_class").attr('value')] && services[$("#service_class").attr('value')][$('#service_method').attr('value')])
                        {
                            $.each(services[$("#service_class").attr('value')][$('#service_method').attr('value')], function(i,v){
                               addParam(v, true);
                            });
                        }
                        $("#service").fadeOut();
                    }
                    // 处理参数input
                    if(e.attr('class') && e.attr('class') == 'service_param')
                    {
                        if(e.attr('auto'))
                        {
                            e.removeAttr('auto');
                            e.attr('value', '');
                            e.css('color','#333333');
                        }
                    }
                    // 让浮层淡出
                    if(!e.attr('id') || e.attr('id') != last_input_id)
                    {
                        $("#service").fadeOut();
                    }
                }
            );
        </script>
        <style type="text/css">
            #service {background:#EEEEEE}
            .list_class_item, .list_method_item {border-bottom: 1px solid #AAAAAA;display:block;padding:3px 10px;color:#333333;}
            A:link {color:#333333; text-decoration:none}
            A:hover {text-decoration:none;background:#DDDDDD}
        </style>

    </body>
</html>
HHH;
        return $display_data;
    }

    public function getService()
    {
        $this->thriftServiceArray = array();
        $config = require __DIR__.'/Config/config.php';
        $config = $config->toArray();
        ThriftClient::config($config);
        // 查看thriftWorker配置了哪些服务
        foreach ($config as $service_name=>$info)
        {
            $class_name = "\\Services\\$service_name\\{$service_name}Handler";
            // 载入该服务下的所有文件
            $service_dir = ThriftClient::getServiceDir($service_name);
            foreach(glob($service_dir.'/*.php') as $php_file)
            {
                require_once $php_file;
            }

            if(class_exists($class_name))
            {
                $re = new ReflectionClass($class_name);
                $method_array = $re->getMethods(ReflectionMethod::IS_PUBLIC);
                $this->thriftServiceArray[$service_name] = array();
                foreach($method_array as $m)
                {
                    if ('\\'.$m->class == $class_name) {
                        $params_arr = $m->getParameters();
                        $method_name = $m->name;
                        $params = array();
                        foreach($params_arr as $p)
                        {
                            $params[] = $p->name;
                        }
                        $this->thriftServiceArray[$service_name][$method_name] = $params;
                    }
                }
            }
        }
        return $this->thriftServiceArray;
    }

}

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
                $address_map[$key] = $item['Client']['addresses'];
            }
            \ThriftClient\AddressManager::config($address_map);
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
        if(!empty($config[$service_name]['Client']['thrift_protocol']))
        {
            $protocol = $config[$service_name]['Client']['thrift_protocol'];
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
        if(!empty($config[$service_name]['Client']['thrift_transport']))
        {
            $transport = $config[$service_name]['Client']['thrift_transport'];
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
        if(!empty($config[$service_name]['Client']['service_dir']))
        {
            $service_dir = $config[$service_name]['Client']['service_dir']."/$service_name";
        }
        else
        {
            $service_dir = THRIFT_CLIENT . "/../../../../crontab/Services/$service_name";
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
        $uid = $arguments[0];

        if (!ctype_digit((string)$uid)) {
            throw new Exception('第一个参数必须为UID,若无UID填写0!');
        }

        unset($arguments[0]);

        $auth = $this->setAuth($uid);

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
        $arguments = array_merge($auth, $arguments);
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
        $address = \ThriftClient\AddressManager::getOneAddress($this->serviceName);
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
            \ThriftClient\AddressManager::kickAddress($address);
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

    /**
     * 设置auth.
     */
    public function setAuth($uid = 0)
    {
         $secret = array(
             'HelloWorld'     => '{test}',
             'IpadPSR'        => '{B29D1D63-DA13-8414-7EC0-11690E9AAF85}',
             'IpadTeamLeader' => '{5DA82F12-82BA-B494-0B95-BD33543F402D}',
             'Api'            => '{72664DC0-959F-3B0C-0489-1F8C7046A9F3}',
             'BackendH5Api'   => '{92468999-718F-0AAE-9473-0DA0A98C74E8}',
             'BackendPcApi'   => '{E832DB19-F390-B87F-C7D8-3A0E901F6359}',
             'H5Api'          => '{6967BCA9-E466-DE63-1628-F2DBFF76CDA5}',
             'Pc360'          => '{69515C56-502D-1661-B180-B429330AAE31}',
             'PcApi'          => '{39A90250-4177-2F5C-CF9C-9565C7CBCF49}',
             'CCV2'           => '{48E38283-2311-9E8F-II0D-7283J8CLCX78}',
             'DuoCai'         => '{70B8F1EB-E2AF-AFA9-791C-36B8700B1D1B}',
        );

        $auth = "\Services\\" . $this->serviceName . "\Auth";

        if (!class_exists($auth)) {
            return false;
        }

        $authData['uid'] = $uid;
        $authData['accessToken'] = \Dc\Lib\Session::authcode($uid, 'ENCODE', $secret[$this->serviceName], 86400 * 30);
        $authData['AppId'] = 'test';
        $authData['AppSecret'] = $secret[$this->serviceName];

        return array(new $auth($authData));
    }

}

?>