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
use App\MsgIds;

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
        Timer::add($this->conf['emit_interval'], function () {
            //更新维护数据列表, 每60秒都会推送一次
            if ($this->updateRecords($this->timestamp === 0 ? TRUE : FALSE)) {
                //有更新，实时推送消息出去
                $this->emit();
            }
        });
        Timer::add(1, function () {
            //更新维护数据列表, 到整点都会推送一次
            if (date('s') === '00')
                $this->emit();
        });
    }

    /*
     * 根据字段，查找最大值、最小值、平均值、当前时间戳
     */

    private function arraySummary(array $arr, $field = 'trade_price') {
        $max = 0;
        $min = 0;
        $average = 0;
        $sum = 0;
        $first = TRUE;
        foreach ($arr as $value) {
            $field_value = isset($value[$field]) ? intval($value[$field]) : 0;
            if ($first) {
                $first = FALSE;
                $sum = $max = $min = $field_value;
                continue;
            } else {
                $max = $max > $field_value ? $max : $field_value;
                $min = $min < $field_value ? $min : $field_value;
                $sum += $field_value;
            }
        }
        $num = count($arr);
        $average = $num > 0 ? $sum / $num : 0;
        return [$max, $min, $average, $this->timestamp];
    }

    /*
     * 组装成发给服务中心的数据
     */

    private function msgData($product_id, $user_id, $record) {

        $roomId = SubNotifyRooms::roomId(0, $product_id, $user_id);
        //将array中的key去掉
        $tmp = [];
        foreach ($record as $key => $value) {
            $tmp[$key] = array_values($value);
            $tmp[$key . '_average'] = $this->arraySummary($value);
        }
        $msg = QuoteClass::output($product_id, $user_id, $tmp, TRUE);
        $data = array(
            'id' => MsgIds::MESSAGE_GATEWAY_TO_GROUP,
            'room' => $roomId,
            'data' => $msg,
        );
        return $data;
    }

    /*
     * 将数据推送出去
     */

    protected function emit() {
        foreach ($this->records as $product_id => $records) {
            // 结构：品类id->撮合id->类型->信息记录id
            foreach ($records as $user_id => $record) {
                //这一层是记录类型
                $product_id = explode('_', $product_id)[0];
                $user_id = explode('_', $user_id)[0];
                $json = $this->msgData($product_id, $user_id, $record);
                $this->client_worker->sendToGateway($json);
                //发送之后将成交记录删除，即成交记录一直只发最新的
                unset($this->records[$product_id][$user_id]['order']);
            }
        }
    }

    private function storeRecords($new_records) {
        foreach ($new_records as $record) {
            //保存  结构：品类id->撮合id->类型->信息记录id
            $product_id = $record['product_id'] . '_product';
            $user_id = $record['user_id'] . '_user';
            $trade_type = $record['trade_type'];
            $id = $record['id'];
            switch ($trade_type) {
                case -1:
                    $trade_type = 'sell';
                    break;
                case 1:
                    $trade_type = 'buy';
                    break;
                case 2:
                    $trade_type = 'order';
                    break;
                default:
                    break;
            }

            if (!empty($record['delete_time']) && isset($this->records[$product_id][$user_id][$trade_type][$id])) {
                //删除了
                unset($this->records[$product_id][$user_id][$trade_type][$id]);
            } else if (!in_array($trade_type, ['sell', 'buy', 'order'], TRUE)) {
                //不是买、卖、成交记录
                continue;
            } else {
                $this->records[$product_id][$user_id][$trade_type][$id] = $record;
            }
        }
    }

    /*
     * 将记录保存在内存中
     * @return TRUE:有新数据更新 FALSE:没有新数据
     */

    protected function updateRecords($first_readdb = false /* 首次读取数据 */) {
        //将当前时间保存下来
        $timestamp = $this->timestamp;
        $this->timestamp = time();

        $new_records = $first_readdb ? $this->selectAllRecords() : $this->selectAllRecordsAccordingTimestamp($timestamp);
        if (empty($new_records)) {
            //没有新数据
            return FALSE;
        } else {
            $this->storeRecords($new_records);
            return TRUE;
        }
    }

    /*
     * 查找某一时刻之后更新的所有记录
     */

    private function selectAllRecordsAccordingTimestamp($timestamp) {
        //根据时间进行查询，仅仅查询比上次查询时间更晚的记录
        /*
         *   延迟5秒进行读取
         * 原因：数据通过nginx+php到数据库时间会滞后
         */
        $timestamp -= 5;
        return $this->db->query("select * from en_product_offer where "
                . "UNIX_TIMESTAMP(update_time)>=$timestamp  or "
                . "UNIX_TIMESTAMP(delete_time)>=$timestamp");
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
        $this->initTimer();
    }

    /**
     * 构造函数
     *
     * @param string $socket_name
     * @param array  $context_option
     */
    public function __construct($socket_name = '', $context_option = array()) {
        parent::__construct($socket_name, $context_option);
        $backrace = debug_backtrace();
        $this->_autoloadRootPath = dirname($backrace[0]['file']);
        //加载配置
        $conf = include __DIR__ . '/conf/gateway.php';
        $this->conf = $conf;
    }

    /**
     * {@inheritdoc}
     */
    public function run() {
        $this->onWorkerStart = array($this, 'workerStart');
        parent::run();
    }

}
