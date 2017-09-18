<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace App;

use Workerman\Worker;
use Workerman\Lib\Timer;
use App\ClientWorker;
use GatewayWorker\Lib\DbConnection;
use App\msg\QuoteClass;
/**
 * Description of TaskServer
 * 定期从数据库中读取当前数据，并实时上报给消息中心
 * 
 * @author xp
 */
class TaskServer extends Worker {

    //与服务通信客户端
    protected $client_worker = null;
    //数据库
    protected $db = null;
    //当前时间
    protected $timestamp = 0;
    //当前所有数据
    protected $records = [];

    protected function initClientWorker() {
        // 初始化与gateway连接服务
        $client_worker = new ClientWorker($this->conf['gateway_addr']);
        $this->client_worker = $client_worker;
        // 消息回调
        $this->client_worker->onMessage = array($this, 'onGatewayMessage');
    }

    protected function initDb() {
        $conf = $this->conf['database'];
        $db = new DbConnection($conf['host'], $conf['port'], $conf['user'], $conf['password'], $conf['dbname'], $conf['charset']);
        $this->db = $db;
    }

    protected function initTimer() {
        Timer::add(3, function () {
            //更新维护数据列表
            $this->updateRecords();
            //推送消息出去
            $this->emit();
        });
    }
    
    /*
     * 组装成发给服务中心的数据
     */
    private function msgData($record) {
        $roomId = SubNotifyRooms::roomId(0, $record['product_id'], $record['user_id']);
        $data = array(
            'id' => MsgIds::MESSAGE_GATEWAY_TO_GROUP,
            'room' => $roomId,
            'data' => $record,
        );
        return $data;
    }


    /*
     * 将数据推送出去
     */
    protected function emit() {
        foreach ($this->records as $record) {
            $json = $this->msgData($record);
            $this->client_worker->sendToGateway($json);
        }
    }

    /*
     * 将记录保存在内存中
     */

    protected function updateRecords($first_readdb = false /*首次读取数据*/) {
        //将当前时间保存下来
        $timestamp = $this->timestamp;
        $this->timestamp = time();
        
        $new_records = $first_readdb?$this->selectAllRecords():$this->selectAllRecordsAccordingTimestamp($timestamp);
        print_r($this->db->lastSQL());
        if (empty($new_records)) {
            //没有新数据
            return;
        }
        foreach ($new_records as $record) {
            if (!empty($record['delete_time']) && isset($this->records[$record['id']])) {
                //删除了
                unset($this->records[$record['id']]);
            } else {
                $this->records[$record['id']] = $record;
            }
        }
    }

    /*
     * 查找某一时刻之后更新的所有记录
     */

    private function selectAllRecordsAccordingTimestamp($timestamp) {
        //根据时间进行查询，仅仅查询比上次查询时间更晚的记录
        return $this->db->query("select * from en_product_offer where update_time>=$timestamp and delete_time>=$timestamp");
    }
    
    /*
     * 查找所有记录
     */

    private function selectAllRecords() {
        //根据时间进行查询，仅仅查询比上次查询时间更晚的记录
        return $this->db->query("select * from en_product_offer where ISNULL(delete_time)");
    }

    /*
     * 当中心发来消息的时候
     */

    public function onGatewayMessage($connection, $data) {
        //不做处理
    }

    public function workerStart() {
        $this->initClientWorker();
        $this->initDb();
        $this->updateRecords(TRUE);
        $this->initTimer();
    }

    /*
     * 启动server
     */

    public function startServer() {
        $this->onWorkerStart = array($this, 'workerStart');
    }

    public function __construct() {
        parent::__construct();
        //加载配置
        $conf = include __DIR__ . '/conf/gateway.php';
        $this->conf = $conf;
    }

}
