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
use App\msg\ToClientClass;
use App\MsgIds;
use App\StatisticClient;
use App\model\ProductOpenPrices;

/**
 * Description of TaskServer
 * 定期从数据库中读取当前数据，并实时上报给消息中心
 * 
 * @author xp
 */
class TaskServer extends Worker {

    const buy = 1;
    const sell = -1;
    const deal = 2;

    //与服务通信客户端
    protected $client_worker = null;
    //数据库
    protected $db = null;
    //当前时间
    protected $timestamp = 0;
    //当前所有数据
    protected $records = [];
    //开盘价
    protected $product_open_price = NULL;
    //是否需要添加开盘报价
    protected $notify_open_price = FALSE;

    protected function initClientWorker() {
        // 初始化与gateway连接服务
        $client_worker = new ClientWorker($this->conf['gateway_addr'], $this->groupIno());
        $this->client_worker = $client_worker;
        // 消息回调
        $this->client_worker->onMessage = array($this, 'onGatewayMessage');
    }

    protected function initDb() {
        $conf = $this->conf['database'];
        $db = new DbConnection($conf['host'], $conf['port'], $conf['user'], $conf['password'], $conf['dbname'], $conf['charset']);
        $this->db = $db;
        //初始化开盘报价信息
        $this->product_open_price = new ProductOpenPrices($db);
        $this->product_open_price->initTodayAllData();
    }
    
    protected function groupIno() {
        $data = array(
            'id' => MsgIds::MESSAGE_GATEWAY_BUSSINESS,
            'business_type'=>'JoinGroup',
            'group'=>'TaskServer',
        );
        return $data;
    }

    /*
     * 9~18点之间服务，之后不推送实时
     */

    private function isActive() {
        $timestamp = time();
        $hour = intval(date('H', $timestamp));
        return $hour >= 9 && $hour < 18;
    }

    private function reinit() {
        $this->timestamp = 0;
        $this->records = [];
        //开盘价信息清除
        $this->product_open_price->clearAllData();
    }

    protected function initTimer() {
        Timer::add($this->conf['emit_interval'], function () {
            if (!$this->isActive()) {
                //重置系统记录
                $this->reinit();
                return;
            }

            //更新维护数据列表, 每60秒都会推送一次
            if (((int) date('s') > 59 - $this->conf['emit_interval']) || ((int) date('s') >= 5 - $this->conf['emit_interval'] && (int) date('s') <= 5)) {
                //整点会推送，所以这次不做推送了
                return;
            }
            $flag = $this->updateRecords($this->timestamp === 0 ? TRUE : FALSE);
            if (!empty($flag)) {
                StatisticClient::tick("TimerEmitInterval", 'emit_emit_summary');
                //有更新，实时推送消息出去
                $this->emit($flag);
                $this->emit_summary();
                StatisticClient::report('TimerEmitInterval', 'emit_emit_summary', true, 0, '');
            }
        });
        Timer::add(1, function () {
            if (!$this->isActive()) {
                //重置系统记录
                $this->reinit();
                return;
            }
            //更新维护数据列表, 到整点都会推送一次
            if (date('s') === '59') {
                StatisticClient::tick("TimerEmit59", 'emit_emit_summary');
                $this->updateRecords($this->timestamp === 0 ? TRUE : FALSE);
                $this->emit();
                $this->emit_summary();
                StatisticClient::report('TimerEmit59', 'emit_emit_summary', true, 0, '');
            }
            if (date('s') === '05') {
                StatisticClient::tick("TimerEmit05", 'emit_emit_summary');
                $this->updateRecords($this->timestamp === 0 ? TRUE : FALSE);
                $this->emit();
                $this->emit_summary();
                StatisticClient::report('TimerEmit05', 'emit_emit_summary', true, 0, '');
            }
//            if (date('H') === '09' && intval(date('i')) <= 5 && date('s') === '30') {
                //9:00~9:05之间每隔30秒钟更新一次开盘价信息
                StatisticClient::tick("TimerInitOpenPrice", 'init');
                $this->product_open_price->initTodayAllData();
                $this->notify_open_price = TRUE;    //指示需要推送开盘价
                StatisticClient::report('TimerInitOpenPrice', 'init', true, 0, '');
//            } else {
//                $this->notify_open_price = FALSE;    //不需要推送开盘价
//            }
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
            $field_value = isset($value[$field]) ? round(floatval($value[$field]), 2) : 0;
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
        if ($average === 0) {
            $max = $min = $average = '-';
        } else {
            $average = round(floatval($average), 2);
            $max = round(floatval($max), 2);
            $min = round(floatval($min), 2);
        }
        return [$max, $min, $average, $this->timestamp];
    }

    //添加开盘信息
    private function addOpenPriceInfo(array &$arr, $product_id) {
        StatisticClient::tick("AddOpenPrice", 'add');
        $open_price_info = $this->product_open_price->openPriceInfo($product_id);
        $arr = array_merge($arr, $open_price_info);
        StatisticClient::report('AddOpenPrice', 'add', true, 0, '');
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
        //增加开盘信息
        if ($this->notify_open_price) {
            $this->addOpenPriceInfo($tmp, $product_id);
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
     * 客户端请求初次数据
     */

    private function msgDataToClient($product_id, $user_id, $record, $client) {

        $roomId = SubNotifyRooms::roomId(0, $product_id, $user_id);
        //将array中的key去掉
        $tmp = [];
        foreach ($record as $key => $value) {
            $tmp[$key] = array_values($value);
            $tmp[$key . '_average'] = $this->arraySummary($value);
        }
        //增加开盘信息
        if ($this->notify_open_price) {
            $this->addOpenPriceInfo($tmp, $product_id);
        }

        $msg = ToClientClass::output($product_id, $user_id, $client, $tmp);
        $data = array(
            'id' => MsgIds::MESSAGE_GATEWAY_TO_CLIENT,
            'room' => $roomId,
            'data' => $msg,
        );
        return $data;
    }

    private function msgDataAllToClient($product_id, $user_id, $record, $client) {
        $roomId = SubNotifyRooms::roomId(0, $product_id, $user_id);
        //将array中的key去掉
        $tmp = [];
        foreach ($record as $key => $value) {
            $tmp[$key] = array_values($value);
            $tmp[$key . '_average'] = $this->arraySummary($value);
        }
        //增加开盘信息
        if ($this->notify_open_price) {
            $this->addOpenPriceInfo($tmp, $product_id);
        }

        $msg = ToClientClass::output($product_id, $user_id, $client, $tmp);
        $data = array(
            'id' => MsgIds::MESSAGE_GATEWAY_TO_CLIENT,
            'room' => $roomId,
            'data' => $msg,
        );
        return $data;
    }

    private function toolsSummary($array) {
        $tmp = [0, 0, 0, $this->timestamp];
        $count = 0;
        foreach ($array as $value) {
            if ($count === 0) {
                //第一次赋值给最小值
                $tmp[1] = $value['data'][1];
            }
            $count += $value['count'];
            $tmp[0] = $value['data'][0] > $tmp[0] ? $value['data'][0] : $tmp[0];
            $tmp[1] = $value['data'][1] < $tmp[1] ? $value['data'][1] : $tmp[1];
            $tmp[2] += round(floatval($value['data'][2] * $value['count'] / $count), 2);
        }
        return $tmp;
    }

    private function msgDataAll($product_id, $user_id, $record) {
        $roomId = SubNotifyRooms::roomId(0, $product_id, $user_id);
        //将array中的key去掉
        $tmp = [];
        foreach ($record as $key => $value) {
            $tmp[$key] = array_values($value);
            $tmp[$key . '_average'] = $this->arraySummary($value);
        }
        //增加开盘信息
        if ($this->notify_open_price) {
            $this->addOpenPriceInfo($tmp, $product_id);
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
     * 每一个品类有一个大盘数据，实时推出去
     */

    protected function emit_summary() {
        foreach ($this->records as $product_id => $records) {
            $tmp = ['sell' => [], 'buy' => [], 'order' => []];
            foreach ($records as $record) {
                if (isset($record['sell'])) {
                    $tmp['sell'] = array_merge($tmp['sell'], $record['sell']);
                }
                if (isset($record['buy'])) {
                    $tmp['buy'] = array_merge($tmp['buy'], $record['buy']);
                }
                if (isset($record['order'])) {
                    $tmp['order'] = array_merge($tmp['order'], $record['order']);
                }
            }
            // 结构：品类id->撮合id->类型->信息记录id
            $product_id = explode('_', $product_id)[0];
            $user_id = 0;
            $json = $this->msgDataAll($product_id, $user_id, $tmp);
            $this->client_worker->sendToGateway($json);
        }
    }

    /*
     * 将数据推送出去
     */

    protected function emit($except = array()) {
        foreach ($this->records as $product_id => $records) {
            // 结构：品类id->撮合id->类型->信息记录id
            foreach ($records as $user_id => $record) {
                if (!empty($except) && !in_array($product_id . '_' . $user_id, $except)) {
                    //不需要推送
                    continue;
                }
                //这一层是记录类型
                $product_id = explode('_', $product_id)[0];
                $user_id = explode('_', $user_id)[0];
                $tmp = ['sell' => [], 'buy' => [], 'order' => []];
                if (isset($record['sell'])) {
                    $tmp['sell'] = array_merge($tmp['sell'], $record['sell']);
                }
                if (isset($record['buy'])) {
                    $tmp['buy'] = array_merge($tmp['buy'], $record['buy']);
                }
                if (isset($record['order'])) {
                    $tmp['order'] = array_merge($tmp['order'], $record['order']);
                }
                $json = $this->msgData($product_id, $user_id, $tmp);
                $this->client_worker->sendToGateway($json);
                //发送之后将成交记录删除，即成交记录一直只发最新的
                //unset($this->records[$product_id][$user_id]['order']);
            }
        }
    }

    //返回有更新的信息数组
    private function storeRecords($new_records) {
        $update_arr = [];
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
            //有新数据，则将原来的60秒之前的数据
            if (isset($this->records[$product_id][$user_id][$trade_type]) && is_array($this->records[$product_id][$user_id][$trade_type])) {
                foreach ($this->records[$product_id][$user_id][$trade_type] as $key => $r) {
                    if (strtotime($r['update_time']) < $this->timestamp - 60 || !empty($r['delete_time'])) {
                        unset($this->records[$product_id][$user_id][$trade_type][$key]);
                    }
                }
            }
            //直接将新数据复制过来
            if (!in_array($trade_type, ['sell', 'buy', 'order'], TRUE)) {
                //不是买、卖、成交记录
                continue;
            } else {
                $this->records[$product_id][$user_id][$trade_type][$id] = $record;
                $update_arr[] = $product_id . '_' . $user_id;
            }
        }
        return array_unique($update_arr);
    }

    /*
     * 将记录保存在内存中
     * @return TRUE:有新数据更新 FALSE:没有新数据
     */

    protected function updateRecords($first_readdb = false/* 首次读取数据 */) {
        //将当前时间保存下来
        $timestamp = $this->timestamp;
        $this->timestamp = time();

        $new_records = $first_readdb ? $this->selectAllRecords() : $this->selectAllRecordsAccordingTimestamp($timestamp);

        if (empty($new_records)) {
            //没有新数据
            return FALSE;
        } else {
            return $this->storeRecords($new_records);
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
        $timestamp = $timestamp - $this->conf['emit_interval'];
        $sell_buy = $this->db->query("select * from en_product_real_times where "
                . "UNIX_TIMESTAMP(create_time)>=$timestamp or "
                . "UNIX_TIMESTAMP(delete_time)>=$timestamp");
        $order = $this->db->query("select * from en_transactions where "
                . "UNIX_TIMESTAMP(create_time)>=$timestamp or "
                . "UNIX_TIMESTAMP(delete_time)>=$timestamp");

        foreach ($sell_buy as &$value) {
            $value['product']['name'] = $this->db->single("select name from en_products where id='" . $value['product_id'] . "'");
            $value['trader']['name'] = $this->db->single("select name from en_trader_company where id='" . $value['trader_id'] . "'");
            $value['stock']['name'] = $this->db->single("select name from en_storages where id='" . $value['stock_id'] . "'");

            $type_tag = '';
            switch ($value['trade_type']) {
                case static::buy: $type_tag = "买";
                    break;
                case static::sell:$type_tag = "卖";
                    break;
                case static::deal:$type_tag = "成交";
                    break;
                default: $type_tag = "未知";
            }
            $value['trader_type_tag'] = $type_tag;

            $delivery_tag = '';
            switch ($value['delivery_type']) {
                case 0:
                    $delivery_tag = '先货后款';
                    break;
                case 1:
                    $delivery_tag = '先款后货';
                    break;
                default:
                    $delivery_tag = '未知';
            }
            $value['delivery_tag'] = $delivery_tag;

            $withdraw_type_tag = '';
            switch ($value['withdraw_type']) {
                case 0:
                    $withdraw_type_tag = '电汇';
                    break;
                case 1:
                    $withdraw_type_tag = '票汇';
                    break;
                case 2:
                    $withdraw_type_tag = '信汇';
                    break;
                default:
                    $withdraw_type_tag = '未知';
            }
            $value['withdraw_tag'] = $withdraw_type_tag;
        }
        foreach ($order as &$value) {
            $value['trade_type'] = 2;   //表明是成交记录
            $value['trade_price'] = $value['price'];    //为了和报价一致，字段名修改一下
        }
        $arr = [];
        $arr = array_merge($arr, $sell_buy);
        $arr = array_merge($arr, $order);
        return $arr;
    }

    /*
     * 查找所有记录
     */

    private function selectAllRecords() {
        //根据时间进行查询，仅仅查询比上次查询时间更晚的记录
        $sell_buy = $this->db->query("select * from en_product_real_times where delete_time is null");
        $order = $this->db->query("select * from en_transactions where delete_time is null");
        foreach ($sell_buy as &$value) {
            $value['product']['name'] = $this->db->single("select name from en_products where id='" . $value['product_id'] . "'");
            $value['trader']['name'] = $this->db->single("select name from en_trader_company where id='" . $value['trader_id'] . "'");
            $value['stock']['name'] = $this->db->single("select name from en_storages where id='" . $value['stock_id'] . "'");

            $type_tag = '';
            switch ($value['trade_type']) {
                case static::buy: $type_tag = "买";
                    break;
                case static::sell:$type_tag = "卖";
                    break;
                case static::deal:$type_tag = "成交";
                    break;
                default: $type_tag = "未知";
            }
            $value['trader_type_tag'] = $type_tag;

            $delivery_tag = '';
            switch ($value['delivery_type']) {
                case 0:
                    $delivery_tag = '先货后款';
                    break;
                case 1:
                    $delivery_tag = '先款后货';
                    break;
                default:
                    $delivery_tag = '未知';
            }
            $value['delivery_tag'] = $delivery_tag;

            $withdraw_type_tag = '';
            switch ($value['withdraw_type']) {
                case 0:
                    $withdraw_type_tag = '电汇';
                    break;
                case 1:
                    $withdraw_type_tag = '票汇';
                    break;
                case 2:
                    $withdraw_type_tag = '信汇';
                    break;
                default:
                    $withdraw_type_tag = '未知';
            }
            $value['withdraw_tag'] = $withdraw_type_tag;
        }
        foreach ($order as &$value) {
            $value['trade_type'] = 2;   //表明是成交记录
            $value['trade_price'] = $value['price'];    //为了和报价一致，字段名修改一下
        }
        $arr = [];
        $arr = array_merge($arr, $sell_buy);
        $arr = array_merge($arr, $order);
        return $arr;
    }

    /*
     * 首次登陆请求数据
     */

    private function firstLoginDataNotify($product_id, $user_id, $client) {
        if (empty($user_id)) {
            //请求大盘数据
            $this->sendSummaryMsgToClient($product_id, 0, $client);
        } else {
            //请求某一个人的小盘
            $this->sendMsgToClient($product_id, $user_id, $client);
        }
    }

    /*
     * 用户登录后请求数据，非整体数据
     */

    private function sendMsgToClient($product_id, $user_id, $client) {
        $product_id_tmp = $product_id . '_product';
        $user_id_tmp = $user_id . '_user';
        $record = isset($this->records[$product_id_tmp][$user_id_tmp]) ? $this->records[$product_id_tmp][$user_id_tmp] : [];
        if (!empty($record)) {
            $tmp = ['sell' => [], 'buy' => [], 'order' => []];
            if (isset($record['sell'])) {
                $tmp['sell'] = array_merge($tmp['sell'], $record['sell']);
            }
            if (isset($record['buy'])) {
                $tmp['buy'] = array_merge($tmp['buy'], $record['buy']);
            }
            if (isset($record['order'])) {
                $tmp['order'] = array_merge($tmp['order'], $record['order']);
            }
            $json = $this->msgDataToClient($product_id, $user_id, $tmp, $client);
            $this->client_worker->sendToGateway($json);
        } else {
            //没有数据，返回false
            return;
        }
    }

    /*
     * 用户登录后请求数据，大盘数据
     */

    private function sendSummaryMsgToClient($product_id, $user_id, $client) {
        $product_id_tmp = $product_id . '_product';
        $user_id_tmp = $user_id . '_user';
        $records = isset($this->records[$product_id_tmp]) ? $this->records[$product_id_tmp] : [];
        if (!empty($records)) {
            $tmp = ['sell' => [], 'buy' => [], 'order' => []];
            foreach ($records as $record) {
                if (isset($record['sell'])) {
                    $tmp['sell'] = array_merge($tmp['sell'], $record['sell']);
                }
                if (isset($record['buy'])) {
                    $tmp['buy'] = array_merge($tmp['buy'], $record['buy']);
                }
                if (isset($record['order'])) {
                    $tmp['order'] = array_merge($tmp['order'], $record['order']);
                }
            }
            // 结构：品类id->撮合id->类型->信息记录id
            $product_id = explode('_', $product_id)[0];
            $user_id = 0;
            $json = $this->msgDataAllToClient($product_id, $user_id, $tmp, $client);
            $this->client_worker->sendToGateway($json);
        } else {
            //没有数据，返回false
            return;
        }
    }

    /*
     * 当中心发来消息的时候
     */

    public function onGatewayMessage($connection, $data) {
        //
        $json = json_decode($data);
        if (empty($json)) {
            return;
        } else if (isset($json->id) && $json->id == MsgIds::MESSAGE_GATEWAY_BUSSINESS) {
            if (isset($json->business_type) && $json->business_type == 'firstLogin' && isset($json->client) && !empty($json->client) && isset($json->product_id) && !empty($json->product_id) && isset($json->user_id)) {
                //用户来获取第一次登录后的实时信息了
                $json = $this->firstLoginDataNotify($json->product_id, $json->user_id, $json->client);
            }
        }
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
