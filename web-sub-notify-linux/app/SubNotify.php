<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace App;

use Workerman\Worker;
//use Workerman\WebServer;
//use Workerman\Lib\Timer;
use PHPSocketIO\SocketIO;
use App\Helper;
// use App\msg\QuoteClass;
// use App\msg\ToClientClass;
use App\ClientWorker;
use App\MsgIds;
use App\StatisticClient;
use GatewayWorker\Lib\DbConnection;
use App\model\ProductCatalog;

/**
 * Description of subNotify
 * 订阅-通知服务
 *
 * @author Xp
 */
class SubNotify
{
    /*
     * 分组维度：品类+撮合
     * 1、房间由某类型客户端登陆后维护信息
     * 2、用户订阅要实时推送的房间内部的消息
     * 3、系统可查看当前在线客户等信息
     */

    // 数组保存uid在线数据
    public $uidConnectionMap = array();
    //当前在线uid数
    public $online_count_now = 0;
    //当前总共连接数
    public $online_page_count_now = 0;
    //上一秒在线uid数
    public $last_online_count = 0;
    //上一秒总共连接数
    public $last_online_page_count = 0;
    //系统状态
    public $system_status = TRUE;
    //socketio
    public $sender_io = null;
    //连接中心服务器的客户端
    public $client_worker = null;
    public $gateway_addr = '';
    //ssl
    public $ssl = [];

    public static $_product_sock = [
        '11'=>[],//示例 分类=》[产品id...]
        '23'=>[]
    ]; 
    
    
    public function findCatalog($proudct_id){
        if(empty(static::$_product_sock)) return 0;
        foreach (static::$_product_sock as $key=>$items){
            if(in_array($proudct_id, $items)) return $key;
        }
        return 0;
    }

    static private function classNameForLog() {
        $class = join('_', explode('\\', __CLASS__));
        return $class;
    }
    public function __construct($socket_port = 2120, $http_port = 2121)
    {
        //初始化数据
        $conf = include __DIR__.'/conf/gateway.php';
        $this->conf = $conf;
        $this->initData();
        //如果传参，则覆盖配置
        $this->socket_port = $socket_port;
//        $this->http_port = $http_port;
        $conf = $this->conf['database'];
        $db = new DbConnection($conf['host'], $conf['port'], $conf['user'], $conf['password'], $conf['dbname'], $conf['charset']);
        static::$_product_sock = (new ProductCatalog($db))->getProductList();
    }

    //加载配置
    private function initData()
    {
        $this->socket_port = $this->conf['socket_port'];
//        $this->http_port = $this->conf['http_port'];
        $this->system_status = boolval($this->conf['system_status']);
        $this->ssl_switch = $this->conf['ssl_switch'];
        if ($this->ssl_switch === 'on')
        {
            //ssl配置
            $this->ssl = $this->conf['ssl_conf'];
        }
        else
        {
            $this->ssl = [];
        }
        $this->gateway_addr = $this->conf['gateway_addr'];
    }

    //将配置写回文件
//     private function saveData()
//     {
//         $data = var_export($this->conf);
//         file_put_contents(__DIR__.'/conf/gateway', "return $data;");
//     }

    //系统开关控制
//     public function admin($status)
//     {
//         //通过status控制活动是否开关
//         $status = (int) $status > 0 ? TRUE : FALSE;
//         $this->system_status = $status;
//         $this->saveData();
//         $this->initData();

//         //通知在线的，服务端通知服务维护中
//         if (!$this->system_status)
//         {
//             if (!is_null($this->sender_io))
//             {
//                 $this->sender_io->emit('systemCare');
//             }
//         }
//         else
//         {
//             //通知在线的，服务端通知服务开始
//             if (!is_null($this->sender_io))
//             {
//                 $this->sender_io->emit('systemStart');
//             }
//         }
//     }
    /****************** 未启动 start ************/
        /*
     * 发给某一组的消息
     * @param client_id 转发给的客户端id
     * @param msg 转发的消息
     * @return 
     */
//     protected function sendToAll($msg)
//     {
//         $data = array(
//             'id' => MsgIds::MESSAGE_GATEWAY_TO_ALL,
//             'data' => $msg,
//         );
//         $this->client_worker->sendToGateway($data);
//     }
    
//     /*
//      * 发给某一组的消息
//      * @param client_id 转发给的客户端id
//      * @param msg 转发的消息
//      * @return 
//      */

//     protected function sendToGroup($room, $msg)
//     {
//         $data = array(
//             'id' => MsgIds::MESSAGE_GATEWAY_TO_GROUP,
//             'room' => $room,
//             'data' => $msg,
//         );
//         $this->client_worker->sendToGateway($data);
//     }
//     /*
//      * 发给某一客户端的消息
//      * @param client_id 转发给的客户端id
//      * @param msg 转发的消息
//      * @return 
//      */

//     protected function sendToClient($client_id, $to_client, $msg)
//     {
//         $data = array(
//             'id' => MsgIds::MESSAGE_GATEWAY_TO_CLIENT,
//             'client' => $client_id,
//             'to_client' => $to_client,
//             'data' => $msg,
//         );
//         $this->client_worker->sendToGateway($data);
//     }
    /******************* 未启动 end ************/
    /*
     * 请求数据
     */
    protected function firstLogin($product_id, $user_id, $client_id, $company_id = NULL) {
        $catalog_id = $this->findCatalog($product_id);
        $data = array(
            'id' => MsgIds::MESSAGE_GATEWAY_BUSSINESS,
            'business_type'=>'firstLogin',
            'client'=>$client_id,
            'product_id'=>$product_id,
            'catalog_id'=>$catalog_id,
            'user_id'=>$user_id,
            'company_id'=>$company_id
        );
        $this->client_worker->sendToGateway($data);
    }
    /*
     * gateway中心发来的消息，需要转给相应的client
     * @param json 格式的消息
     * @return 返回消息处理结果
     */
    protected function gatewayDispatch($msgType,$json)
    {
        StatisticClient::tick(self::classNameForLog(), __FUNCTION__);
        if (!isset($json->data))
        {
            StatisticClient::report(self::classNameForLog(), __METHOD__, FALSE, 0, 'data节点不存在');
            return;
        }
        
        if ($msgType == MsgIds::MESSAGE_GATEWAY_TO_GROUP && !isset($json->room))
        {
            StatisticClient::report(self::classNameForLog(), __FUNCTION__, FALSE, 0, 'room 节点不存在');
            return;
        }
        
        if ($msgType == MsgIds::MESSAGE_GATEWAY_TO_CLIENT && !isset($this->uidConnectionMap[$json->data->to_client])) {
            StatisticClient::report(self::classNameForLog(), __FUNCTION__, FALSE, 0, '客户端连接不存在');
            return;
        }
        $event_type = isset($json->data->event_type) ? (string)$json->data->event_type : '';
        if (empty($event_type)) {
            //消息类型为空
            StatisticClient::report(self::classNameForLog(), __FUNCTION__, FALSE, 0, '消息类型为空');
            return;
        }
        if($msgType == MsgIds::MESSAGE_GATEWAY_TO_ALL){
            $this->sender_io->emit($event_type, json_encode($json->data));
        }elseif($msgType == MsgIds::MESSAGE_GATEWAY_TO_GROUP){
            Worker::log("$json->room, $event_type");
            $this->sender_io->to($json->room)->emit($event_type, json_encode($json->data));
        }elseif($msgType == MsgIds::MESSAGE_GATEWAY_TO_CLIENT){
            unset($json->id);
            if(!empty($this->uidConnectionMap[$json->data->to_client])){
                $this->uidConnectionMap[$json->data->to_client]['connection']->emit($event_type, json_encode($json->data));
            }
        }
        StatisticClient::report(self::classNameForLog(), __FUNCTION__, true, 0, '');
    }
    
    
    /*
     * gateway中心发来的业务消息，比如说需要请求一些数据，可以去中心查询
     * @param json 格式的消息
     * @return 返回消息处理结果
     */

    protected function gatewayBussinessMsgHandle($json)
    {
        
    }

    /*
     * 当中心发来消息的时候
     */

    public function onGatewayMessage($connection, $data)
    {
        // 查看是否需要发给订阅了消息的客户端
        $json = json_decode($data);
        if (!$json || !isset($json->id))
        {
            // 消息错误
            return;
        }

        // 根据消息类型处理
        switch ($json->id)
        {
            case MsgIds::MESSAGE_GATEWAY_TO_ALL :
            case MsgIds::MESSAGE_GATEWAY_TO_GROUP :
            case MsgIds::MESSAGE_GATEWAY_TO_CLIENT :
                $this->gatewayDispatch($json->id,$json);
                break;
            case MsgIds::MESSAGE_GATEWAY_BUSSINESS :
                $this->gatewayBussinessMsgHandle($json);
                break;

            default :
                // 未知消息
                break;
        }
    }

    // 连接中心服务器客户端配置初始化
    public function clientWorkerInit()
    {
        $groupInfo = ['id' => MsgIds::MESSAGE_GATEWAY_BUSSINESS,'business_type'=>'JoinGroup','group'=>'SubNotify'];
        // 初始化与gateway连接服务
        $this->client_worker = new ClientWorker($this->gateway_addr, $groupInfo);;
        // 消息回调
        $this->client_worker->onMessage = array($this, 'onGatewayMessage');
    }

    public function startServer()
    {
        // PHPSocketIO服务
        $this->sender_io = new SocketIO($this->socket_port, $this->ssl);
        // 客户端发起连接事件时，设置连接socket的各种事件回调
        $this->sender_io->on('connection', function($socket) {
            // 当客户端登录验证
            $socket->on('login', function ($uid)use($socket) {
                //todo：验证登陆是否合法
                if (!$this->system_status)
                {
                    //系统维护中
                    $socket->emit('systemCare');
                }

                // 更新对应uid的在线数据
                $uid = (string) $socket->id;     //重写uid，使用socket的唯一标识
                //合法之后存入uid，这是登陆成功的标记
                $socket->uid = $uid;
                if (!isset($this->uidConnectionMap[$uid]))
                {
                    $this->uidConnectionMap[$uid]['count'] = 0;
                    $this->uidConnectionMap[$uid]['connection'] = $socket;
                }
                // 这个uid有++$uidConnectionMap[$uid]个socket连接
                ++$this->uidConnectionMap[$uid]['count'];

                // 通知登陆成功了
                $socket->emit('login_success', "login");
                Worker::log("$uid login");
            });

            // 用户注册自己订阅的服务
            $socket->on('register', function ($uid, $product_id, $match_id, $company_id = NULL)use($socket) {
                $uid = $socket->uid;
                if (!isset($socket->uid))
                {
                    return;
                }
                // 将这个连接加入到uid分组，方便针对uid推送数据
                $roomId = Helper::roomId($uid, $product_id, $match_id, $company_id);
                // 进入房间名单
                Worker::log("$uid join $roomId");
                $socket->join($roomId);
                // 通知进入房间了
                $this->sender_io->to($roomId)->emit('member_enter', $uid, $product_id, $match_id);
                $this->firstLogin($product_id, $match_id, $socket->uid, $company_id);
            });
            
            // 当客户端断开连接时触发（一般是关闭网页或者跳转刷新导致）
            $socket->on('disconnect', function () use($socket) {
                if (!isset($socket->uid)) return;
                // 将uid的在线socket数减一
                if (isset($this->uidConnectionMap[$socket->uid]['count']) &&
                    --$this->uidConnectionMap[$socket->uid]['count'] <= 0)
                {
                    unset($this->uidConnectionMap[$socket->uid]);
                    Worker::log("$socket->uid disconnect");
                }
            });
        });

        // 当$sender_io启动后监听一个http端口，通过这个端口可以给任意uid或者所有uid推送数据
        $this->sender_io->on('workerStart', function() {
            $this->clientWorkerInit();
//             // 监听一个http端口
//             $inner_http_worker = new Worker('http://127.0.0.1:' . $this->http_port);
//             // 当http客户端发来数据时触发
//             $inner_http_worker->onMessage = function($http_connection, $data) {
//                 global $uidConnectionMap;
//                 $_POST = $_POST ? $_POST : $_GET;
//                 // 推送数据的url格式 type=publish&to=uid&content=xxxx
//                 switch (@$_POST['type'])
//                 {
//                     case 'publish':
//                         global $sender_io;
//                         $to = @$_POST['to'];
//                         $_POST['content'] = htmlspecialchars(@$_POST['content']);
//                         // 有指定uid则向uid所在socket组发送数据
//                         if ($to)
//                         {
//                             $this->sender_io->to($to)->emit('new_msg', $_POST['content']);
//                             // 否则向所有uid推送数据
//                         }
//                         else
//                         {
//                             $this->sender_io->emit('new_msg', @$_POST['content']);
//                         }
//                         // http接口返回，如果用户离线socket返回fail
//                         if ($to && !isset($this->uidConnectionMap[$to]))
//                         {
//                             return $http_connection->send('offline');
//                         }
//                         else
//                         {
//                             return $http_connection->send('ok');
//                         }
//                     case 'admin':
//                         //status:0停止服务 1开启服务
//                         //url格式：http://'http://127.0.0.1:'.$this->http_port?type=admin&status=1
//                         //后台更新数据库
//                         $status = isset($_POST['status']) ? (int) $_POST['status'] : 0;
//                         $this->admin($status);
//                         return $http_connection->send('ok');
//                         break;
//                 }
//                 return $http_connection->send('fail');
//             };
//             // 执行监听
//             $inner_http_worker->listen();

            // 一个定时器，定时向所有uid推送当前uid在线数及在线页面数
//             Timer::add(1, function() {
//                 $this->online_count_now = count($this->uidConnectionMap);
//                 $this->online_page_count_now = array_sum($this->uidConnectionMap);
//                 // 只有在客户端在线数变化了才广播，减少不必要的客户端通讯
//                 if ($this->last_online_count != $this->online_count_now || $this->last_online_page_count != $this->online_page_count_now)
//                 {
//                     $this->sender_io->emit('update_online_count', "$this->online_count_now", "$this->online_page_count_now");
//                     $this->last_online_count = $this->online_count_now;
//                     $this->last_online_page_count = $this->online_page_count_now;
//                 }
//             });
        });
    }

}

