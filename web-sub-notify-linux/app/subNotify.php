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

/**
 * Description of subNotify
 * 订阅-通知服务
 *
 * @author Xp
 */
class SubNotify {
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
    public $systemStatus = TRUE;

    public function __construct($socketPort = 2120, $httpPort = 2121, $conf = 'conf.json') {
        //初始化数据
        $this->socketPort = $socketPort;
        $this->httpPort = $httpPort;
        $this->conf = $conf;
        $this->ssl = array();
    }

    private function initData() {
        $json = @file_get_contents($this->conf);
        if (!$json)
            return;
        $json_obj = json_decode($json);

        if ($json_obj) {
            //初始化数据
            $this->socketPort = $json_obj->socketPort;
            $this->httpPort = $json_obj->httpPort;
            $this->systemStatus = $json_obj->systemStatus;
            $this->ssl_switch = $json_obj->ssl_switch;
            //是否启用ssl
            if ($json_obj->ssl_switch === 'on') {
                $this->ssl['local_cert'] = $json_obj->ssl['local_cert'];
                $this->ssl['local_pk'] = $json_obj->ssl['local_pk'];
            }
        }
    }

    private function saveData() {
        $json_array = array(
            'socketPort' => $this->socketPort,
            'httpPort' => $this->httpPort,
            'systemStatus' => $this->systemStatus,
            'ssl_switch' => $this->ssl_switch,
            'ssl' => $this->ssl,
        );
        $json = json_encode($json_array);
        file_put_contents($this->conf, $json);
    }

    //系统开关控制
    public function admin($status) {
        //通过status控制活动是否开关
        echo $status;
        $status = (int)$status > 0 ? TRUE : FALSE;
        $this->systemStatus = $status;
        $this->saveData();
        $this->initData();
        echo $this->systemStatus;

        //通知在线的，服务端通知服务维护中
        if (!$this->systemStatus) {
            if (!is_null($this->sender_io))
                $this->sender_io->emit('systemCare');
        } else {
            //通知在线的，服务端通知服务开始
            if (!is_null($this->sender_io))
                $this->sender_io->emit('systemStart');
        }
    }

    public function startServer() {
        $this->initData();

        // PHPSocketIO服务
        $this->sender_io = new SocketIO($this->socketPort, $this->ssl);
        // 客户端发起连接事件时，设置连接socket的各种事件回调
        $this->sender_io->on('connection', function($socket) {
            // 当客户端登录验证
            $socket->on('login', function ($uid)use($socket) {
                //todo：验证登陆是否合法
                
                if (!$this->systemStatus) {
                    //系统维护中
                    $socket->emit('systemCare');
                }

                // 更新对应uid的在线数据
                $uid = (string) $uid;
                //合法之后存入uid，这是登陆成功的标记
                $socket->uid = $uid;
                if (!isset($this->uidConnectionMap[$uid])) {
                    $this->uidConnectionMap[$uid] = 0;
                }
                // 这个uid有++$uidConnectionMap[$uid]个socket连接
                ++$this->uidConnectionMap[$uid];

                // 通知登陆成功了
                $socket->emit('login_success', "login");
                Worker::log("$uid login");
            });

            // 用户注册自己订阅的服务
            $socket->on('register', function ($uid, $product_id, $match_id)use($socket) {
                if (!isset($socket->uid)) {
                    return;
                }
                // 将这个连接加入到uid分组，方便针对uid推送数据
                $roomId = SubNotifyRooms::roomId($uid, $product_id, $match_id);
                // 进入房间名单
                $socket->join($roomId);
                // 通知进入房间了
                $this->sender_io->to($roomId)->emit('member_enter', $uid);
            });

            // 当客户端请求更新报价数据
            $socket->on('quote', function ($product_id, $match_id, $data) use($socket) {
                if (!isset($socket->uid)) {
                    return;
                }
                //通知关注的客户端
                $this->sender_io->to(subNotifyRooms::roomId($socket->uid, $product_id, $match_id))
                        ->emit('quoteUpdate', QuoteClass::output($product_id, $match_id, $data));
                Worker::log(QuoteClass::output($product_id, $match_id, $data));
            });

            // 当客户端断开连接时触发（一般是关闭网页或者跳转刷新导致）
            $socket->on('disconnect', function () use($socket) {
                if (!isset($socket->uid)) {
                    return;
                }
                
                // 将uid的在线socket数减一
                if (isset($this->uidConnectionMap[$socket->uid]) && --$this->uidConnectionMap[$socket->uid] <= 0) {
                    unset($this->uidConnectionMap[$socket->uid]);
                    Worker::log("$socket->uid disconnect");
                }
            });
        });

        // 当$sender_io启动后监听一个http端口，通过这个端口可以给任意uid或者所有uid推送数据
        $this->sender_io->on('workerStart', function() {
            // 监听一个http端口
            $inner_http_worker = new Worker('http://127.0.0.1:' . $this->httpPort);
            // 当http客户端发来数据时触发
            $inner_http_worker->onMessage = function($http_connection, $data) {
                global $uidConnectionMap;
                $_POST = $_POST ? $_POST : $_GET;
                // 推送数据的url格式 type=publish&to=uid&content=xxxx
                switch (@$_POST['type']) {
                    case 'publish':
                        global $sender_io;
                        $to = @$_POST['to'];
                        $_POST['content'] = htmlspecialchars(@$_POST['content']);
                        // 有指定uid则向uid所在socket组发送数据
                        if ($to) {
                            $this->sender_io->to($to)->emit('new_msg', $_POST['content']);
                            // 否则向所有uid推送数据
                        } else {
                            $this->sender_io->emit('new_msg', @$_POST['content']);
                        }
                        // http接口返回，如果用户离线socket返回fail
                        if ($to && !isset($this->uidConnectionMap[$to])) {
                            return $http_connection->send('offline');
                        } else {
                            return $http_connection->send('ok');
                        }
                    case 'admin':
                        //status:0停止服务 1开启服务
                        //url格式：http://'http://127.0.0.1:'.$this->httpPort?type=admin&status=1
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
                if ($this->last_online_count != $this->online_count_now || $this->last_online_page_count != $this->online_page_count_now) {
                    $this->sender_io->emit('update_online_count', "$this->online_count_now", "$this->online_page_count_now");
                    $this->last_online_count = $this->online_count_now;
                    $this->last_online_page_count = $this->online_page_count_now;
                }
            });
        });
    }

}
