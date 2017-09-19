<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace App;

use Workerman\Worker;
use Workerman\WebServer;
use Workerman\Lib\Timer;
use PHPSocketIO\SocketIO;
use App\SubNotifyRooms;
use App\msg\QuoteClass;
use App\msg\ToClientClass;
use App\ClientWorker;
use App\MsgIds;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;

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

    // 消息类型，表示中心发过来需要发送给所有人
    const MESSAGE_GATEWAY_TO_ALL = 10000;
    // 消息类型，表示中心发过来需要发送给某个组的消息
    const MESSAGE_GATEWAY_TO_GROUP = 10001;
    // 消息类型，表示中心发过来需要发送给某个人的消息
    const MESSAGE_GATEWAY_TO_CLIENT = 10002;
    // 消息类型，表示中心发过来的业务消息
    const MESSAGE_GATEWAY_BUSSINESS = 10003;

    public function __construct($socket_port = 2120, $http_port = 2121)
    {
        //初始化数据
        $conf = include __DIR__.'/conf/gateway.php';
        $this->conf = $conf;
        $this->initData();
        
        //如果传参，则覆盖配置
        $this->socket_port = $socket_port;
        $this->http_port = $http_port;
    }

    //加载配置
    private function initData()
    {
        $this->socket_port = $this->conf['socket_port'];
        $this->http_port = $this->conf['http_port'];
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
    private function saveData()
    {
        $data = var_export($this->conf);
        file_put_contents(__DIR__.'/conf/gateway', "return $data;");
    }

    //系统开关控制
    public function admin($status)
    {
        //通过status控制活动是否开关
        echo $status;
        $status = (int) $status > 0 ? TRUE : FALSE;
        $this->system_status = $status;
        $this->saveData();
        $this->initData();

        //通知在线的，服务端通知服务维护中
        if (!$this->system_status)
        {
            if (!is_null($this->sender_io))
            {
                $this->sender_io->emit('systemCare');
            }
        }
        else
        {
            //通知在线的，服务端通知服务开始
            if (!is_null($this->sender_io))
            {
                $this->sender_io->emit('systemStart');
            }
        }
    }

    
        /*
     * 发给某一组的消息
     * @param client_id 转发给的客户端id
     * @param msg 转发的消息
     * @return 
     */

    protected function sendToAll($msg)
    {
        $data = array(
            'id' => MsgIds::MESSAGE_GATEWAY_TO_ALL,
            'data' => $msg,
        );
        $this->client_worker->sendToGateway($data);
    }
    
    /*
     * 发给某一组的消息
     * @param client_id 转发给的客户端id
     * @param msg 转发的消息
     * @return 
     */

    protected function sendToGroup($room, $msg)
    {
        $data = array(
            'id' => MsgIds::MESSAGE_GATEWAY_TO_GROUP,
            'room' => $room,
            'data' => $msg,
        );
        $this->client_worker->sendToGateway($data);
    }
    
    /*
     * 发给某一客户端的消息
     * @param client_id 转发给的客户端id
     * @param msg 转发的消息
     * @return 
     */

    protected function sendToClient($client_id, $to_client, $msg)
    {
        $data = array(
            'id' => MsgIds::MESSAGE_GATEWAY_TO_CLIENT,
            'client' => $client_id,
            'to_client' => $to_client,
            'data' => $msg,
        );
        $this->client_worker->sendToGateway($data);
    }
    
    /*
     * gateway中心发来的消息，需要转给所有客户端
     * @param json 格式的消息
     * @return 返回消息处理结果
     */

    protected function gatewayToAllHandle($json)
    {
        if (!isset($json->data))
        {
            //错误信息
            return;
        }
        // 将消息发给所有人
        $data = json_decode($json->data);
        $event_type = isset($data->event_type) ? (string)$data->event_type : '';
        if (empty($event_type)) {
            //消息类型为空
            return;
        }
        $this->sender_io->emit($event_type, json_encode($json->data));
    }

    /*
     * gateway中心发来的消息，需要转给相应的group
     * @param json 格式的消息
     * @return 返回消息处理结果
     */

    protected function gatewayToGroupHandle($json)
    {
        if (!isset($json->room) || !isset($json->data))
        {
            //错误信息
            return;
        }
        // 将消息发给相应的组
        $data = json_decode($json->data);
        
        $event_type = isset($data->event_type) ? (string)$data->event_type : '';
        if (empty($event_type)) {
            //消息类型为空
            return;
        }
        $this->sender_io->to($json->room)->emit($event_type, json_encode($json->data));
    }

    /*
     * gateway中心发来的消息，需要转给相应的client
     * @param json 格式的消息
     * @return 返回消息处理结果
     */

    protected function gatewayToClientHandle($json)
    {
        if (!isset($json->client) || 
            !isset($json->to_client) || 
            !isset($json->data) || 
            !isset($this->uidConnectionMap[$json->to_client]))
        {
            return;
        }
        // 将消息发给相应的客户端
        unset($json->id);
        $data = json_decode($json->data);
        $event_type = isset($data->event_type) ? (string)$data->event_type : '';
        if (empty($event_type)) {
            //消息类型为空
            return;
        }
        $this->uidConnectionMap[$json->to_client]->emit($event_type, json_encode($json));
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
                $this->gatewayToAllHandle($json);
                break;
            case MsgIds::MESSAGE_GATEWAY_TO_GROUP :
                $this->gatewayToGroupHandle($json);
                break;
            case MsgIds::MESSAGE_GATEWAY_TO_CLIENT :
                $this->gatewayToClientHandle($json);
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
        // 初始化与gateway连接服务
        $client_worker = new ClientWorker($this->gateway_addr);
        $this->client_worker = $client_worker;
        // 消息回调
        $this->onMessage = array($this, 'onGatewayMessage');
        $this->client_worker->onMessage = $this->onMessage;
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
                $uid = (string) $uid;
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
            $socket->on('register', function ($uid, $product_id, $match_id)use($socket) {
                if (!isset($socket->uid))
                {
                    return;
                }
                // 将这个连接加入到uid分组，方便针对uid推送数据
                $roomId = SubNotifyRooms::roomId($uid, $product_id, $match_id);
                // 进入房间名单
                $socket->join($roomId);
                // 通知进入房间了
                $this->sender_io->to($roomId)->emit('member_enter', $uid, $product_id, $match_id);
            });

            // 当客户端请求更新报价数据
            $socket->on('quote', function ($product_id, $match_id, $data) use($socket) {
                if (!isset($socket->uid))
                {
                    return;
                }
                //通知关注的客户端
                $room = subNotifyRooms::roomId($socket->uid, $product_id, $match_id);
                $msg = QuoteClass::output($product_id, $match_id, $data);
                //通知该组的客户端
                $this->sendToGroup($room, $msg);
                Worker::log($msg);
            });
            
            // 当客户端要发给其他客户端的时候
            $socket->on('send_to_client', function ($to_uid, $data) use($socket) {
                if (!isset($socket->uid))
                {
                    return;
                }
                //通知关注的客户端
                $msg = ToClientClass::output($socket->uid, $to_uid, $data);
                //通知该组的客户端
                $this->sendToClient($socket->uid, $to_uid, $msg);
            });

            // 当客户端断开连接时触发（一般是关闭网页或者跳转刷新导致）
            $socket->on('disconnect', function () use($socket) {
                if (!isset($socket->uid))
                {
                    return;
                }

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
            // 监听一个http端口
            $inner_http_worker = new Worker('http://127.0.0.1:' . $this->http_port);
            // 当http客户端发来数据时触发
            $inner_http_worker->onMessage = function($http_connection, $data) {
                global $uidConnectionMap;
                $_POST = $_POST ? $_POST : $_GET;
                // 推送数据的url格式 type=publish&to=uid&content=xxxx
                switch (@$_POST['type'])
                {
                    case 'publish':
                        global $sender_io;
                        $to = @$_POST['to'];
                        $_POST['content'] = htmlspecialchars(@$_POST['content']);
                        // 有指定uid则向uid所在socket组发送数据
                        if ($to)
                        {
                            $this->sender_io->to($to)->emit('new_msg', $_POST['content']);
                            // 否则向所有uid推送数据
                        }
                        else
                        {
                            $this->sender_io->emit('new_msg', @$_POST['content']);
                        }
                        // http接口返回，如果用户离线socket返回fail
                        if ($to && !isset($this->uidConnectionMap[$to]))
                        {
                            return $http_connection->send('offline');
                        }
                        else
                        {
                            return $http_connection->send('ok');
                        }
                    case 'admin':
                        //status:0停止服务 1开启服务
                        //url格式：http://'http://127.0.0.1:'.$this->http_port?type=admin&status=1
                        //后台更新数据库
                        $status = isset($_POST['status']) ? (int) $_POST['status'] : 0;
                        $this->admin($status);
                        return $http_connection->send('ok');
                        break;
                }
                return $http_connection->send('fail');
            };
            // 执行监听
            $inner_http_worker->listen();

            // 一个定时器，定时向所有uid推送当前uid在线数及在线页面数
            Timer::add(1, function() {
                $this->online_count_now = count($this->uidConnectionMap);
                $this->online_page_count_now = array_sum($this->uidConnectionMap);
                // 只有在客户端在线数变化了才广播，减少不必要的客户端通讯
                if ($this->last_online_count != $this->online_count_now || $this->last_online_page_count != $this->online_page_count_now)
                {
                    $this->sender_io->emit('update_online_count', "$this->online_count_now", "$this->online_page_count_now");
                    $this->last_online_count = $this->online_count_now;
                    $this->last_online_page_count = $this->online_page_count_now;
                }
            });
        });
    }

}

