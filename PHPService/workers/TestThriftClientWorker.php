<?php 

/**
 * 
 * web页面rpc测试客户端
 * http://ip:30304 例如:http://192.168.20.23:30304
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

class TestThriftClientWorker extends PHPServerWorker
{
    protected $thriftServiceArray = array();

    /**
     * 判断包是否都到达
     * @see PHPServerWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return HTTP::input($recv_str);
    }

    /**
     * 处理业务逻辑 查询log 查询统计信息
     * @see PHPServerWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        $time_start = microtime(true);
        HTTP::decode($recv_str);
        $rsp_data = '';
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
            global $reqText, $rspText;
            
            try{
                $rsp_data = call_user_func_array(array(ClientForTest::instance($class), $func), $param);
            }catch(Exception $e){
                $rsp_data = $e.'';
            }
            return $this->display($rsp_data, microtime(true)-$time_start, $services);
        }
        elseif(isset($_GET['ajax_get_service']))
        {
            $this->sendToClient(HTTP::encode($services));
        }
        
        $this->display('','','','',microtime(true)-$time_start);
    }
    
    protected function display($rsp_data = '', $cost='', $services = array())
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
        $this->sendToClient(HTTP::encode($display_data));
    }

    public function getService()
    {
        $this->thriftServiceArray = array();
        $client_config = array();

        // 查看thriftWorker配置了哪些服务和ip
        foreach (PHPServerConfig::get('workers') as $worker_name=>$config)
        {
            if((empty($config['worker_class']) || $config['worker_class'] != 'ThriftWorker') && $worker_name != 'ThriftWorker')
            {
                continue;
            }
            $ip = !empty($config['ip']) ? $config['ip'] : '127.0.0.1';
            $address = "$ip:{$config['port']}";
            
            $providerNamespace = 'Services';
            if($provider_dir = PHPServerConfig::get('workers.'.$worker_name.'.provider'))
            {
                if(($provider_dir = realpath($provider_dir)) && ($path_array = explode('/', $provider_dir = realpath($provider_dir))))
                {
                    $providerNamespace = $path_array[count($path_array)-1];
                }
            }
            $handlerNamespace = 'Services';
            if($handler_dir = PHPServerConfig::get('workers.'.$worker_name.'.handler'))
            {
                if(($handler_dir = realpath($handler_dir)) && ($path_array = explode('/', $handler_dir)))
                {
                    $handlerNamespace = $path_array[count($path_array)-1];
                }
            }

            $bootstrap = isset($config['bootstrap']) ? $config['bootstrap'] : '';
            if($bootstrap && is_file($bootstrap))
            {
                require_once $bootstrap;
            }

            if(!empty($provider_dir))
            {
                foreach (glob($provider_dir."/*", GLOB_ONLYDIR) as $dir)
                {
                    foreach(glob($dir."/*.php") as $php_file)
                    {
                        require_once $php_file;
                    }
                    $tmp_arr = explode("/", $dir);
                    $service_name = array_pop($tmp_arr);
                    if(empty($service_name))
                    {
                        continue;
                    }
            
                    if($handlerNamespace == 'Services')
                    {
                        $class_name = "\\$handlerNamespace\\$service_name\\{$service_name}Handler";
                        $handler_file = $handler_dir.'/'.$service_name.'/'.$service_name.'Handler.php';
                    }
                    else
                    {
                        $class_name = "\\$handlerNamespace\\{$service_name}";
                        $handler_file = $handler_dir.'/'.$service_name.'.php';
                    }
                    
                    if(is_file($handler_file))
                    {
                        require_once $handler_file;
                    }

                    if(class_exists($class_name))
                    {
                        $re = new ReflectionClass($class_name);
                        $method_array = $re->getMethods(ReflectionMethod::IS_PUBLIC);
                        $this->thriftServiceArray[$service_name] = array();
                        foreach($method_array as $m)
                        {
                            $params_arr = $m->getParameters();
                            $method_name = $m->name;
                            $params = array();
                            foreach($params_arr as $p)
                            {
                                $params[] = $p->name;
                            }
                            $this->thriftServiceArray[$service_name][$method_name] = $params;
                        }
                        $client_config[$service_name] = array(
                            'nodes' =>array(
                                $address
                            ),
                            'provider'  => $provider_dir,
                        );
                    }
                }
            }
        }
        ClientForTest::config($client_config);
        return $this->thriftServiceArray;
    }
    
}


/**
 * 保存所有故障节点的VAR
 * @var int
 */
define('RPC_BAD_ADDRESS_KEY2', 1);

/**
 * 保存配置的md5的VAR,用于判断文件配置是否已经更新
 * @var int
 */
define('RPC_CONFIG_MD5_KEY2', 2);

/**
 * 保存上次告警时间的VAR
 * @var int
 */
define('RPC_LAST_ALARM_TIME_KEY2', 3);


/**
 *
 * 通用客户端,支持故障ip自动踢出
 *
 * <b>使用示例:</b>
 * <pre>
 * <code>
 * CLientForTest::config(array(
 *                         'IRecommend' => array(
 *                             'nodes' => array(
 *                                   '10.0.20.10:9090',
 *                                   '10.0.20.11:9090',
 *                               ),
 *                           ),
 *                           'HelloWorldService' => array(
 *                               'nodes' => array(
 *                                   '127.0.0.1:9090'
 *                               ),
 *                           ),
 *                     )
 * );
 *
 * // 同步调用
 * $recommend_client = \ClientForTest::instance('IRecommend');
 * $ret = $recommend_client->recommendForUser(2000000437, "bj", 0, 3));
 *
 * // ===以下是异步调用===
 * // 异步调用之发送请求给服务器。提示：在方法前面加上asend_前缀即为异步发送请求
 * $recommend_client->asend_recommendForUser(2000000437, "bj", 0, 3);
 *
 * .................这里是你的其它业务逻辑...............
 *
 * // 异步调用之获取服务器返回。提示：在方法前面加上arecv_前缀即为异步接收服务器返回
 * $ret_async = arecv_recommendForUser(2000000437, "bj", 0, 3);
 *
 * <code>
 * </pre>
 *
 */
class ClientForTest
{
    /**
     * 存储RPC服务端节点共享内存的key
     * @var int
     */
    const BAD_ASSRESS_LIST_SHM_KEY = 0x90905700;

    /**
     * 当出现故障节点时，有多大的几率访问这个故障节点(默认万分之一)
     * @var float
     */
    const DETECTION_PROBABILITY = 0.0001;

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
     * 信号量
     * @var resource
     */
    private static $semFd = null;

    /**
     * 上次告警时间戳
     * @var int
     */
    private static $lastAlarmTime = 0;

    /**
     *
     */
    private static $alarmTimeKey = '';

    /**
     * 告警时间间隔 单位:秒
     * @var int
     */
    private static $alarmInterval = 300;

    /**
     * 排它锁文件handle
     * @var resource
     */
    private static $lockFileHandle = null;

    /**
     * klogger
     * @var klogger
     */
    public static $logger = null;

    /**
     * 设置/获取 配置
     *  array(
     *      'IRecommend' => array(
     *          'nodes' => array(
     *              '10.0.20.10:9090',
     *              '10.0.20.11:9090',
     *              '10.0.20.12:9090',
     *          ),
     *          'provider'      => 'yourdir/Provider',
     *      ),
     *      'HelloWorldService' => array(
     *          'nodes' => array(
     *              '127.0.0.1:9090'
     *          ),
     *          'provider'      => 'yourdir/Provider',
     *      ),
     *  )
     * @param array $config
     * @return array
     */
    public static function config(array $config=array())
    {
        if(!empty($config))
        {
            // 初始化配置
            self::$config = $config;
            // 检查现在配置md5与共享内存中md5是否匹配，用来判断配置是否有更新
            self::checkConfigMd5();
            // 从共享内存中获得故障节点列表
            self::getBadAddressList();
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
            $e = new \Exception('ServiceName can not be empty');
            throw $e;
        }

        if($newOne)
        {
            unset(self::$instance[$serviceName]);
        }

        if(!isset(self::$instance[$serviceName]))
        {
            self::$instance[$serviceName] = new ThriftInstanceForTest($serviceName);
        }

        return self::$instance[$serviceName];
    }

    /**
     * 获取一个可用节点
     * @param string $service_name
     * @throws \Exception
     * @return string
     */
    public static function getOneAddress($service_name)
    {

        // 配置中没有配置这个服务
        if(!isset(self::$config[$service_name]))
        {
            $e = new \Exception("Service[$service_name] is not exist!");
            throw $e;
        }

        // 总的节点列表
        $address_list = self::$config[$service_name]['nodes'];

        // 选择协议
        if(!empty(self::$config[$service_name]['protocol']))
        {
            \Thrift\Context::put('protocol', self::$config[$service_name]['protocol']);
        }
        // 超时时间
        if(!empty(self::$config[$service_name]['timeout']) && self::$config[$service_name]['timeout'] >= 1)
        {
            \Thrift\Context::put('timeout', self::$config[$service_name]['timeout']);
        }

        // 获取故障节点列表
        $bad_address_list = self::getBadAddressList(PHP_SAPI != 'cli');

        // 从节点列表中去掉故障节点列表
        if($bad_address_list)
        {
            $address_list = array_diff($address_list, $bad_address_list);
            // 一定的几率访问故障节点，用来探测故障节点是否已经存活
            if(empty($address_list) || rand(1, 1000000)/1000000 <= self::DETECTION_PROBABILITY)
            {
                $one_bad_address = $bad_address_list[array_rand($bad_address_list)];
                self::recoverAddress($one_bad_address);
                return $one_bad_address;
            }
        }
        // 如果没有可用的节点,尝试使用一个故障节点
        if (empty($address_list))
        {
            // 连故障节点都没有？
            if(empty($bad_address_list))
            {
                $e =  new \Exception("No avaliable server node! Service_name:$service_name allAddress:[".implode(',', self::$config[$service_name]['nodes']).'] badAddress:[' . implode(',', $bad_address_list).']');
                throw $e;
            }
            $address = $bad_address_list[array_rand($bad_address_list)];
            self::recoverAddress($address);
            $e =  new \Exception("No avaliable server node! Try to use a bad address:$address .Service_name:$service_name allAddress:[".implode(',', self::$config[$service_name]['nodes']).'] badAddress:[' . implode(',', $bad_address_list).']');
            return $address;
        }

        // 随机选择一个节点
        return $address_list[array_rand($address_list)];
    }

    /**
     * 获取故障节点共享内存的Fd
     * @return resource
     */
    public static function getShmFd()
    {
        if(!self::$badAddressShmFd)
        {
            self::$badAddressShmFd = shm_attach(self::BAD_ASSRESS_LIST_SHM_KEY);
        }
        return self::$badAddressShmFd;
    }

    /**
     * 获得信号量fd
     * @return null/resource
     */
    public static function getSemFd()
    {
        if(!self::$semFd && extension_loaded('sysvsem'))
        {
            self::$semFd = sem_get(self::BAD_ASSRESS_LIST_SHM_KEY);
        }
        return self::$semFd;
    }

    /**
     * 检查配置文件的md5值是否正确,
     * 用来判断配置是否有更改
     * 有更改清空badAddressList
     * @return bool
     */
    public static function checkConfigMd5()
    {
        // 没有加载扩展
        if(!extension_loaded('sysvshm'))
        {
            return false;
        }

        // 获取shm_fd
        if(!self::getShmFd())
        {
            return false;
        }

        // 尝试读取md5，可能其它进程已经写入了
        $config_md5 = @shm_get_var(self::$badAddressShmFd, RPC_CONFIG_MD5_KEY2);
        $config_md5_now = md5(serialize(self::$config));

        // 有md5值，则判断是否与当前md5值相等
        if($config_md5 === $config_md5_now)
        {
            return true;
        }

        self::$badAddressList = array();

        // 清空badAddressList
        self::getMutex();
        $ret = shm_put_var(self::$badAddressShmFd, RPC_BAD_ADDRESS_KEY2, array());
        self::releaseMutex();
        if($ret)
        {
            // 写入md5值
            self::getMutex();
            $ret = shm_put_var(self::$badAddressShmFd, RPC_CONFIG_MD5_KEY2, $config_md5_now);
            self::releaseMutex();
            return $ret;
        }
        return false;
    }

    /**
     * 获取故障节点列表
     * @return array
     */
    public static function getBadAddressList($use_cache = true)
    {
        // 没有加载扩展
        if(!extension_loaded('sysvshm'))
        {
            self::$badAddressList = array();
            return false;
        }

        // 还没有初始化故障节点
        if(null === self::$badAddressList || !$use_cache)
        {
            // 是否有故障节点
            if(!shm_has_var(self::getShmFd(), RPC_BAD_ADDRESS_KEY2))
            {
                self::$badAddressList = array();
            }
            else
            {
                // 获取故障节点
                $bad_address_list = shm_get_var(self::getShmFd(), RPC_BAD_ADDRESS_KEY2);
                if(false === $bad_address_list || !is_array($bad_address_list))
                {
                    // 出现错误，可能是共享内存写坏了，删除共享内存
                    $ret = shm_remove(self::getShmFd());
                    self::$badAddressShmFd = shm_attach(self::BAD_ASSRESS_LIST_SHM_KEY);
                    self::$badAddressList = array();
                    // 这个不要再加锁了
                    self::checkConfigMd5();
                }
                else
                {
                    self::$badAddressList = $bad_address_list;
                }
            }
        }

        return self::$badAddressList;
    }

    /**
     * 获取上次告警时间
     */
    public static function getLastAlarmTime()
    {
        // 没有加载扩展
        if(!extension_loaded('sysvshm'))
        {
            return false;
        }
        // 是否有保存上次告警时间
        if(!shm_has_var(self::getShmFd(), RPC_LAST_ALARM_TIME_KEY2))
        {
            $time_now = time();
            self::setLastAlarmTime($time_now);
            return $time_now;
        }
        return shm_get_var(self::getShmFd(), RPC_LAST_ALARM_TIME_KEY2);
    }

    /**
     * 设置上次告警时间
     * @param int $timestamp
     */
    public static function setLastAlarmTime($timestamp)
    {
        // 没有加载扩展
        if(!extension_loaded('sysvshm'))
        {
            return false;
        }
        self::getMutex();
        $ret = shm_put_var(self::getShmFd(), RPC_LAST_ALARM_TIME_KEY2, $timestamp);
        self::releaseMutex();
        return $ret;
    }

    /**
     * 获得本机ip
     */
    public static function getLocalIp()
    {
        if (isset($_SERVER['SERVER_ADDR']))
        {
            $ip = $_SERVER['SERVER_ADDR'];
        }
        else
        {
            $ip = gethostbyname(trim(`hostname`));
        }
        return $ip;
    }

    /**
     * 保存故障节点
     * @param string $address
     * @bool
     */
    public static function kickAddress($address)
    {
        $bad_address_list = self::getBadAddressList(false);
        $bad_address_list[] = $address;
        $bad_address_list = array_unique($bad_address_list);
        self::$badAddressList = $bad_address_list;
        self::getMutex();
        $ret = shm_put_var(self::getShmFd(), RPC_BAD_ADDRESS_KEY2, $bad_address_list);
        self::releaseMutex();
        return $ret;
    }

    /**
     * 恢复一个节点
     * @param string $address
     * @bool
     */
    public static function recoverAddress($address)
    {
        $bad_address_list = self::getBadAddressList(false);
        if(empty($bad_address_list) || !in_array($address, $bad_address_list))
        {
            return true;
        }
        $bad_address_list_flip = array_flip($bad_address_list);
        unset($bad_address_list_flip[$address]);
        $bad_address_list = array_keys($bad_address_list_flip);
        self::$badAddressList = $bad_address_list;
        self::getMutex();
        $ret = shm_put_var(self::getShmFd(), RPC_BAD_ADDRESS_KEY2, $bad_address_list);
        self::releaseMutex();
        return $ret;
    }

    /**
     * 获取写锁(睡眠锁)
     * @return true
     */
    public static function getMutex()
    {
        self::getSemFd() && sem_acquire(self::getSemFd());
        return true;
    }

    /**
     * 释放写锁（睡眠锁）
     * @return true
     */
    public static function releaseMutex()
    {
        self::getSemFd() && sem_release(self::getSemFd());
        return true;
    }

    /**
     * 获取排它锁
     */
    public static function getLock()
    {
        self::$lockFileHandle = fopen("/tmp/RPC_CLIENT_SEND_MSM_ALARM.lock", "w");
        return self::$lockFileHandle && flock(self::$lockFileHandle, LOCK_EX | LOCK_NB);
    }

    /**
     * 释放排它锁
     */
    public static function releaseLock()
    {
        return self::$lockFileHandle && flock(self::$lockFileHandle, LOCK_UN);
    }
}

/**
 *
 * thrift异步客户端实例
 * @author liangl
 *
 */
class ThriftInstanceForTest
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
            $e = new \Exception('serviceName can not be empty', 500);
            throw $e;
        }
        $this->serviceName = $serviceName;
    }

    /**
     * 方法调用
     * @param string $name
     * @param array $arguments
     * @return mix
     */
    public function __call($method_name, $arguments)
    {
        $time_start = microtime(true);

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
            $arguments_key = var_export($arguments,true);
            $method_name_key = $method_name . $arguments_key;
            // 判断是否已经有这个方法的异步发送请求
            if(isset($this->thriftAsyncInstances[$method_name_key]))
            {
                // 如果有这个方法发的异步请求，则删除
                $this->thriftAsyncInstances[$method_name_key] = null;
                unset($this->thriftAsyncInstances[$method_name_key]);
                $e = new \Exception($this->serviceName."->$method_name(".implode(',',$arguments).") already has been called, you can't call again before you call ".self::ASYNC_RECV_PREFIX.$real_method_name, 500);
            }
            try{
                $instance = $this->__instance();
                $callback = array($instance, 'send_'.$real_method_name);
                if(!is_callable($callback))
                {
                    throw new \Exception($this->serviceName.'->'.$method_name. ' not callable', 1400);
                }
                $ret = call_user_func_array($callback, $arguments);
            }
            catch (\Exception $e)
            {
                $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 0, $e->getCode());
                throw $e;
            }
            // 保存实例
            $this->thriftAsyncInstances[$method_name_key] = $instance;
            $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 1);
            return $ret;
        }
        // 异步接收
        if(0 === strpos($method_name, self::ASYNC_RECV_PREFIX))
        {
            $real_method_name = substr($method_name, strlen(self::ASYNC_RECV_PREFIX));
            $send_method_name = self::ASYNC_SEND_PREFIX.$real_method_name;
            $arguments_key = var_export($arguments,true);
            $method_name_key = $send_method_name . $arguments_key;
            // 判断是否有发送过这个方法的异步请求
            if(!isset($this->thriftAsyncInstances[$method_name_key]))
            {
                $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 0, 1500);
                $e = new \Exception($this->serviceName."->$send_method_name(".implode(',',$arguments).") have not previously been called, call " . $this->serviceName."->".self::ASYNC_SEND_PREFIX.$real_method_name."(".implode(',',$arguments).") first", 1500);
                throw $e;
            }

            // 创建个副本
            $instance = $this->thriftAsyncInstances[$method_name_key];
            // 删除原实例，避免异常时没清除
            $this->thriftAsyncInstances[$method_name_key] = null;
            unset($this->thriftAsyncInstances[$method_name_key]);

            try{
                $callback = array($instance, 'recv_'.$real_method_name);
                if(!is_callable($callback))
                {
                    throw new \Exception($this->serviceName.'->'.$method_name. ' not callable', 1400);
                }
                // 接收请求
                $ret = call_user_func_array($callback, array());
            }catch (\Exception $e)
            {
                $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 0, $e->getCode());
                throw $e;
            }
            $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 1);

            return $ret;
        }

        $success = true;
        try {
            // 每次都重新初始化一个实例
            $this->thriftInstance = $this->__instance();
            $callback = array($this->thriftInstance, $method_name);
            if(!is_callable($callback))
            {
                throw new \Exception($this->serviceName.'->'.$method_name. ' not callable', 1400);
            }
            $arguments = array_merge($auth, $arguments);
            $ret = call_user_func_array($callback, $arguments);
            $this->thriftInstance = null;
        }
        catch(\Exception $e)
        {
            $this->thriftInstance = null;
            $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 0, $e->getCode());
            throw $e;
        }

        $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 1);
        // 统一日志监控 MNLogger END
        return $ret;
    }

    /**
     * 统一日志
     * @param string $service_name
     * @param string $method_name
     * @param integer $count
     * @param float $cost_time
     * @param integer $success
     * @param integer $code
     * @return void
     */
    protected function mnlog($service_name, $method_name, $count, $cost_time, $success, $code = 0)
    {
    }

    /**
     * 获取一个实例
     * @return instance
     */
    protected function __instance()
    {
        if (\Thrift\Context::get('serverName') != $this->serviceName){
            \Thrift\Context::put('serverName', $this->serviceName);
        }
        $address = CLientForTest::getOneAddress($this->serviceName);
        list($ip, $port) = explode(':', $address);
        $socket = new \Thrift\Transport\TSocket($ip, $port);
        // 接收超时
        if(($timeout = \Thrift\Context::get('timeout')) && $timeout >= 1)
        {
            $socket->setRecvTimeout($timeout*1000);
        }
        else
        {
            // 默认30秒
            $socket->setRecvTimeout(30000);
        }
        $transport = new \Thrift\Transport\TFramedTransport($socket);
        $pname = \Thrift\Context::get('protocol') ? \Thrift\Context::get('protocol') : 'binary';
        $protocolName = self::getProtocol($pname);
        $protocol = new $protocolName($transport);

        $classname = '\Services\\' . $this->serviceName . "\\" . $this->serviceName . "Client";
        if(!class_exists($classname))
        {
            $this->includeFile($classname);
        }
        try
        {
            $transport->open();
        }
        catch(\Exception $e)
        {
            CLientForTest::kickAddress($address);
            throw $e;
        }

        return new $classname($protocol);
    }

    /**
     * 载入provider文件
     * @return void
     */
    protected function includeFile($classname)
    {
        $config = ClientForTest::config();
        $provider_dir = $config[$this->serviceName]['provider'];
        $include_file_array = glob($provider_dir.'/'.$this->serviceName.'/*.php');

        foreach($include_file_array as $file)
        {
            include_once $file;
        }
        if(!class_exists($classname))
        {
            $e = new \Exception("Can not find class $classname in directory $provider_dir/".$this->serviceName.'/');
            throw $e;
        }
    }

    /**
     * getProtocol
     * @param string $key
     * @return string
     */
    private static function getProtocol($key=null)
    {
        $protocolArr = array(
                        'binary' =>'Thrift\Protocol\TBinaryProtocol',
                        'compact'=>'Thrift\Protocol\TCompactProtocol',
                        'json'   =>'Thrift\Protocol\TJSONProtocol',
        );
        return isset($protocolArr[$key]) ? $protocolArr[$key] : $protocolArr['binary'];
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

