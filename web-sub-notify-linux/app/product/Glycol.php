<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace App\product;

use Workerman\Worker;
use Workerman\Lib\Timer;
use App\ClientWorker;
use GatewayWorker\Lib\DbConnection;
use App\MsgIds;
use App\StatisticClient;
use App\model\ProductOpenPrices;
use App\model\BrokerCompany;
use App\model\ProductClassify;
use App\product\Glycol\Calculate;
use App\Helper;
use App\model\GlycolModel;
/**
 * Description of TaskServer
 * 定期从数据库中读取当前数据，并实时上报给消息中心
 * 
 * @author xp
 */
class Glycol extends Worker {

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
    // 客户端关注的没有数据的个人盘
    protected $sub_products = [];
    // 客户端关注的没有数据的大盘
    protected $sub_summary_products = [];
    // 客户关注的没有数据的公司盘
    protected $sub_company_summary_products = [];
    // 保存2分钟~1分钟前的数据以防将来用到
    protected $retain_records = [];
    // 保存撮合公司关系
    protected $broker_company = NULL;
    // 该服务中服务的品类id
    protected $product_id = 0;
    // 品类
    protected $product_classify = NULL;

    protected function initClientWorker() {
        // 初始化与gateway连接服务
        $group_info = ['id' => MsgIds::MESSAGE_GATEWAY_BUSSINESS,'business_type' => 'JoinGroup','group' => 'TaskServer_11'];//标明分类是乙二醇
        $client_worker = new ClientWorker($this->conf['gateway_addr'], $group_info);
        $this->client_worker = $client_worker;
        // 消息回调
        $this->client_worker->onMessage = array($this, 'onGatewayMessage');
    }

    protected function initDb() {
        $conf = $this->conf['database'];
        $db = new DbConnection($conf['host'], $conf['port'], $conf['user'], $conf['password'], $conf['dbname'], $conf['charset']);
        $this->db = $db;
        //初始化开盘报价信息
        $this->product_open_price = new ProductOpenPrices($this->db);
        $this->product_open_price->initTodayAllData();
        //初始化撮合公司
        $this->broker_company = new BrokerCompany($this->db, $this->product_id);
        $this->broker_company->initCompanyInfo();
        //初始化品类
        $this->product_classify = new ProductClassify($this->db, $this->product_id);
        $this->product_classify->initProductIds();
    }

    private function reinit() {
        $this->timestamp = 0;
        $this->records = [];
        
        $timestamp = time();
        $hour = intval(date('H', $timestamp));
        if($hour>=22){//22点到0点
        //开盘价信息清除
            $this->product_open_price->clearAllData();
        }else{//0点到9点,时刻更新开盘价
            $this->product_open_price->initTodayAllData();
            $this->notify_open_price = TRUE;
            $product_open_prices = $this->product_open_price->getRecords();
            $product_match_ids = $this->product_open_price->getAllProductMatchIds();
            $open_pan_timestamp = strtotime(date("Y-m-d 09:00:00"));
            foreach ($product_open_prices as $product_id =>$item){
                $tmp = [];$tmp['buy'] = $tmp['sell']  = [];
                $buy_open_price = $this->product_open_price->openPice($product_id,'buy');
                $sell_open_price = $this->product_open_price->openPice($product_id,'sell');
                $tmp['buy_average'] = [$buy_open_price,$buy_open_price,$buy_open_price,$open_pan_timestamp];
                $tmp['sell_average'] = [$sell_open_price,$sell_open_price,$sell_open_price,$open_pan_timestamp];
                //推送大盘
                $user_id = 0;
                $data = array(
                    'id' => MsgIds::MESSAGE_GATEWAY_TO_GROUP,
                    'room' => Helper::roomId(0, $product_id, $user_id),
                    'data' => Calculate::QuoteOutput($product_id, $user_id, $tmp, TRUE),
                );
                $this->client_worker->sendToGateway($data);
                //推送个人盘
                if(isset($product_match_ids[$product_id])){
                    foreach ($product_match_ids[$product_id] as $match_id){
                        $data = array(
                            'id' => MsgIds::MESSAGE_GATEWAY_TO_GROUP,
                            'room' => Helper::roomId(0, $product_id, $match_id),
                            'data' => Calculate::QuoteOutput($product_id, $match_id, $tmp, TRUE),
                        );
                        $this->client_worker->sendToGateway($data);
                    }
                }
            }
        }
    }

    protected function initTimer() {
        Timer::add($this->conf['emit_interval'], function () {
            if (!Calculate::isActive()) {
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
                $this->emit_company_summary($flag);
                StatisticClient::report('TimerEmitInterval', 'emit_emit_summary', true, 0, '');
            }
        });
        Timer::add(1, function () {
            if (!Calculate::isActive()) {
                //重置系统记录
                $this->reinit();
                return;
            }
            //更新维护数据列表, 到整点都会推送一次
            if (date('s') === '59') {
                StatisticClient::tick("TimerEmit59", 'emit_emit_summary');
                //每一分钟59秒的时候需要强制更新了
                $this->updateRecords($this->timestamp === 0 ? TRUE : FALSE, TRUE);
                $this->emit();
                $this->emit_summary();
                $this->emit_company_summary();
                StatisticClient::report('TimerEmit59', 'emit_emit_summary', true, 0, '');
            }
            if (date('s') === '05') {
                StatisticClient::tick("TimerEmit05", 'emit_emit_summary');
                $this->updateRecords($this->timestamp === 0 ? TRUE : FALSE);
                $this->emit();
                $this->emit_summary();
                $this->emit_company_summary();
                StatisticClient::report('TimerEmit05', 'emit_emit_summary', true, 0, '');
            }
            if (date('H') === '09' && intval(date('i')) <= 20 && (date('s') === '00' || date('s') === '30')) {
                //9:00~9:05之间每隔30秒钟更新一次开盘价信息
                StatisticClient::tick("TimerInitOpenPrice", 'init');
                $this->product_open_price->initTodayAllData();
                $this->notify_open_price = TRUE;    //指示需要推送开盘价
                StatisticClient::report('TimerInitOpenPrice', 'init', true, 0, '');
            } else {
                $this->notify_open_price = TRUE;    //指示需要推送开盘价，即开盘价一直推送，只是在9:00~9:05之间需要查询数据库中数据进行更新
            }
            if (date('s') === '00') {
                //每分钟执行一次，更新撮合公司关系
                StatisticClient::tick("TimerInitComapyInfo", 'init');
                $this->broker_company->initCompanyInfo();
                StatisticClient::report('TimerInitComapyInfo', 'init', true, 0, '');
            }
        });
    }
    
    private function arraySummary(array $arr, $field = 'trade_price') {
        $max = 0;
        $min = 0;
        $average = 0;
        $sum = 0;
        $first = TRUE;
        $data = [];
        foreach ($arr as $value) {
            $field_value = isset($value[$field]) ? intval($value[$field]) : 0;
            if ($field === 0) 
                continue;
            if ($first) {
                $first = FALSE;
                $max = $min = $field_value;
                $data[] = $field_value;
                continue;
            } else {
                $max = $max > $field_value ? $max : $field_value;
                $min = $min < $field_value ? $min : $field_value;
                $data[] = $field_value;
            }
        }

        $average = !empty($data) ? pow(array_product($data),1/count($data)) : 0;
        if ($average === 0) {
            $max = $min = $average = '-';
        } else {
            $average = round($average);
            $max = intval($max);
            $min = intval($min);
        }
        return [$max, $min, $average, $this->timestamp];
    }

    //添加开盘信息
    private function addOpenPriceInfo(array &$arr, $product_id) {
        $open_price_info = $this->product_open_price->openPriceInfo($product_id);
        $arr = array_merge($arr, $open_price_info);
    }

    /*
     * 组装成发给服务中心的数据
     */

    private function msgData($product_id, $user_id, $record) {

        $roomId = Helper::roomId(0, $product_id, $user_id);
        //将array中的key去掉
        $tmp = [];
        if(empty($record)){
            $open_price_info = $this->product_open_price->openPriceInfo($product_id);
            $tmp['buy'] = $tmp['sell']  = [];
            $push_buy_price = $open_price_info['buy_open_price'];//date("H:i",$this->timestamp) == "09:00"?:'-';
            $push_sell_price = $open_price_info['sell_open_price'];//date("H:i",$this->timestamp) == "09:00"?:'-';
//            $tmp['buy_average'] = [$push_buy_price,$push_buy_price,$push_buy_price,$this->timestamp];
//            $tmp['sell_average'] = [$push_sell_price,$push_sell_price,$push_sell_price,$this->timestamp];
            $tmp['buy_average'] = ['-','-','-',$this->timestamp];
            $tmp['sell_average'] = ['-','-','-',$this->timestamp];
        }else{
            foreach ($record as $key => $value) {
                $tmp[$key] = array_values($value);
                $tmp[$key . '_average'] = $this->arraySummary($value);
            }
        }
        //增加开盘信息
        if ($this->notify_open_price) {
            $this->addOpenPriceInfo($tmp, $product_id);
        }

        $msg = Calculate::QuoteOutput($product_id, $user_id, $tmp, TRUE);
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

        $roomId = Helper::roomId(0, $product_id, $user_id);
        //将array中的key去掉
        $tmp = [];
        if(empty($record)){
            $open_price_info = $this->product_open_price->openPriceInfo($product_id);
            $tmp['buy'] = $tmp['sell']  = [];
            $push_buy_price = $open_price_info['buy_open_price'];//date("H:i",$this->timestamp) == "09:00"?:'-';
            $push_sell_price = $open_price_info['sell_open_price'];//date("H:i",$this->timestamp) == "09:00"?:'-';
            $tmp['buy_average'] = ['-','-','-',$this->timestamp];
            $tmp['sell_average'] = ['-','-','-',$this->timestamp];
        }else{
            foreach ($record as $key => $value) {
                $tmp[$key] = array_values($value);
                $tmp[$key . '_average'] = $this->arraySummary($value);
            }
        }
        //增加开盘信息
        if ($this->notify_open_price) {
            $this->addOpenPriceInfo($tmp, $product_id);
        }

        $msg = Calculate::ClientOutput($product_id, $user_id, $client, $tmp);
        $data = array(
            'id' => MsgIds::MESSAGE_GATEWAY_TO_CLIENT,
            'room' => $roomId,
            'data' => $msg,
        );
        return $data;
    }

    //过滤掉开盘价正负10%以外的数据
    private function filterPrice($product_id, $type, &$value) {
        $open_price = intval($this->product_open_price->openPice($product_id, $type));
        if ($open_price > 0) {
            foreach ($value as $k => $v) {
                $price = intval($v['trade_price']);
                if ($price >= $open_price * 1.1 || $price <= $open_price * 0.9) {
                    unset($value[$k]);
                }
            }
        }
    }

    private function msgDataAllToClient($product_id, $user_id, $record, $client, $company_id = NULL) {
        $roomId = Helper::roomId(0, $product_id, $user_id, $company_id);
        //将array中的key去掉
        $tmp = [];
        foreach ($record as $key => $value) {
            $this->filterPrice($product_id, $key, $value);
            $tmp[$key] = array_values($value);
            $tmp[$key . '_average'] = $this->arraySummary($value);
        }
        if(empty($record)){
            $open_price_info = $this->product_open_price->openPriceInfo($product_id);
            $tmp['buy'] = $tmp['sell']  = [];
            $push_buy_price = $open_price_info['buy_open_price'];//date("H:i",$this->timestamp) == "09:00"?:'-';
            $push_sell_price = $open_price_info['sell_open_price'];//date("H:i",$this->timestamp) == "09:00"?:'-';
            $tmp['buy_average'] = ['-','-','-',$this->timestamp];
            $tmp['sell_average'] = ['-','-','-',$this->timestamp];
        }
        //增加开盘信息
        if ($this->notify_open_price) {
            $this->addOpenPriceInfo($tmp, $product_id);
        }

        $msg = Calculate::ClientOutput($product_id, $user_id, $client, $tmp, $company_id);
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
            $tmp[2] += intval($value['data'][2] * $value['count'] / $count);
        }
        return $tmp;
    }

    private function msgDataAll($product_id, $user_id, $record, $company_id = NULL) {
        $roomId = Helper::roomId(0, $product_id, $user_id, $company_id);
        //将array中的key去掉
        $tmp = [];
        
        
        foreach ($record as $key => $value) {
            //过滤掉开盘价正负10%以外的数据
            $this->filterPrice($product_id, $key, $value);
            $tmp[$key] = array_values($value);
            $tmp[$key . '_average'] = $this->arraySummary($value);
        }
        if(empty($record)){
            $open_price_info = $this->product_open_price->openPriceInfo($product_id);
            $tmp['buy'] = $tmp['sell']  = [];
            $push_buy_price = $open_price_info['buy_open_price'];//date("H:i",$this->timestamp) == "09:00"?:'-';
            $push_sell_price = $open_price_info['sell_open_price'];//date("H:i",$this->timestamp) == "09:00"?:'-';
            $tmp['buy_average'] = ['-','-','-',$this->timestamp];
            $tmp['sell_average'] = ['-','-','-',$this->timestamp];
        }
        //增加开盘信息
        if ($this->notify_open_price) {
            $this->addOpenPriceInfo($tmp, $product_id);
        }
        $msg = Calculate::QuoteOutput($product_id, $user_id, $tmp, TRUE, $company_id);
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
        $user_id = 0;
        foreach ($this->records as $product_id => $records) {
            $tmp = ['sell' => [], 'buy' => [], 'order' => []];
            foreach ($records as $record) {
                if (isset($record['sell']) && is_array($record['sell'])) {
                    $tmp['sell'] = array_merge($tmp['sell'], $record['sell']);
                }
                if (isset($record['buy']) && is_array($record['buy'])) {
                    $tmp['buy'] = array_merge($tmp['buy'], $record['buy']);
                }
                if (isset($record['order']) && is_array($record['order'])) {
                    $tmp['order'] = array_merge($tmp['order'], $record['order']);
                }
            }
            // 结构：品类id->撮合id->类型->信息记录id
            $product_id = explode('_', $product_id)[0];
            
            $json = $this->msgDataAll($product_id, $user_id, $tmp);
            $this->client_worker->sendToGateway($json);
			$this->reduceSubProducts($product_id, $user_id);
        }
        //推送没有数据的盘面数据为开盘价
        foreach ($this->sub_summary_products as $product_id => $value) {
            $json = $this->msgDataAll($product_id, $user_id, []);
            $this->client_worker->sendToGateway($json);
        }
    }
    
    private function _emit_according_companyids(Array $company_ids, Array $product_ids) {
        foreach ($product_ids as $product_id) {
            foreach ($company_ids as $company_id) {
                $records = $this->filterRecordsAccordingCompany($product_id, $company_id);
                $tmp = ['sell' => [], 'buy' => [], 'order' => []];
                foreach ($records as $record) {
                    if (isset($record['sell']) && is_array($record['sell'])) {
                        $tmp['sell'] = array_merge($tmp['sell'], $record['sell']);
                    }
                    if (isset($record['buy']) && is_array($record['buy'])) {
                        $tmp['buy'] = array_merge($tmp['buy'], $record['buy']);
                    }
                    if (isset($record['order']) && is_array($record['order'])) {
                        $tmp['order'] = array_merge($tmp['order'], $record['order']);
                    }
                }
                $json = $this->msgDataAll($product_id, 0, $tmp, $company_id);
                $this->client_worker->sendToGateway($json);
                $this->reduceSubProducts($product_id, 0, $company_id);
            }
        }
        
    }
    
    /*
     * 每一个品类有一个公司大盘数据，实时推出去
     * $update_arr[] = $product_id . '_' . $user_id;
     */

    protected function emit_company_summary($update_arr = false/*需要更新的数组信息*/) {
        if (!empty($update_arr)) {
            //根据更新的信息推送需要更新的公司大盘
            $user_ids = [];
            $product_ids = [];
            foreach($update_arr as $value) {
                $tmp = explode('_', $value);
                $product_ids[] = $tmp[0];
                $user_ids[] = $tmp[2];
            }
            $company_ids = array_keys($this->broker_company->companyIdsFromUserids($user_ids));
        } else {
            $product_ids = $this->product_classify->allProductIds();
            $company_ids = $this->broker_company->companyIds();
        }
        $this->_emit_according_companyids($company_ids, $product_ids);
        //推送没有数据的盘面数据为开盘价
        foreach ($this->sub_company_summary_products as $company_id => $product) {
            foreach ($product as $product_id) {
                $json = $this->msgDataAll($product_id, 0, [], $company_id);
                $this->client_worker->sendToGateway($json);
                $this->reduceSubProducts($product_id, 0, $company_id);
            }
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
                $this->reduceSubProducts($product_id, $user_id);
            }
        }
        //推送没有数据的盘面数据为开盘价
        foreach ($this->sub_products as $product_id => $user_id) {
            $json = $this->msgData($product_id, $user_id, []);
            $this->client_worker->sendToGateway($json);
        }
    }
    
    
    /*
     * 只保留一分钟内的数据，执行时间点为每一分钟最后一次推送，即59秒的时候
     */
    private function updateExpireRecords() {
        foreach ($this->records as $product_id => &$records) {
            foreach($records as $user_id=>&$rrs){
                foreach($rrs as $trade_type=>&$values) {
                    $max_time = 0;
                    $loop = 0;
                    foreach ($values as $key => $r) {
                        $uptime = strtotime($r['update_time']);
                        if ($loop++ == 0) {
                            $max_time = $uptime;
                        } else if ($uptime > $max_time) {
                            $max_time = $uptime;
                        }
                    }
                    foreach ($values as $key => $r) {
                        if (strtotime($r['update_time']) < $max_time - $max_time%60) {
                            unset($values[$key]);
                        }
                    }
                }
            }
        }
    }

    /*
     * 移除过期数据
     */

    private function removeExpireData(&$records, $product_id, $user_id, $trade_type) {
        //先将删除数据移除
        foreach ($records as $key => $r) {
            if (!empty($r['delete_time'])) {
                unset($records[$key]);
            }
        }
        //如果没有数据了，则需要沿用上次报的数据
//        if (empty($records)) {
//            //将之前的数据还原
//            $records = isset($this->retain_records[$product_id][$user_id][$trade_type])?$this->retain_records[$product_id][$user_id][$trade_type]:[];
//        }
        
        //查找最后的时间
        $max_time = 0;
        $loop = 0;
        foreach ($records as $key => $r) {
            $uptime = strtotime($r['update_time']);
            if ($loop++ == 0) {
                $max_time = $uptime;
            } else if ($uptime > $max_time) {
                $max_time = $uptime;
            }
        }

        //将该时间60秒之前数据清空
        foreach ($records as $key => $r) {
            if (strtotime($r['update_time']) < $max_time - 60) {
                unset($records[$key]);
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

            //直接将新数据复制过来
            if (!in_array($trade_type, ['sell', 'buy', 'order'], TRUE)) {
                //不是买、卖、成交记录
                continue;
            } else {
                $this->records[$product_id][$user_id][$trade_type][$id] = $record;
                $update_arr[] = $product_id . '_' . $user_id;
            }

            //清理过期数据
            $this->removeExpireData($this->records[$product_id][$user_id][$trade_type], $product_id, $user_id, $trade_type);
        }
        return array_unique($update_arr);
    }

    /*
     * 将记录保存在内存中
     * @return TRUE:有新数据更新 FALSE:没有新数据
     */

    protected function updateRecords($first_readdb = false/* 首次读取数据 */, $update_expiredata = false) {
        //将当前时间保存下来
        $timestamp = $this->timestamp;
        $this->timestamp = time();
        
        //需要更新过期数据了,每一分钟59秒的时候需要强制更新了
        if ($update_expiredata) {
            $this->updateExpireRecords();
        }

        $new_records = $first_readdb ? GlycolModel::selectAllRecords($this->db) : GlycolModel::selectAllRecordsAccordingTimestamp($this->db,$timestamp,$this->conf['emit_interval']);

        if (empty($new_records)) {
            //没有新数据
            return FALSE;
        } else {
            return $this->storeRecords($new_records);
        }
    }
    
    private function addSubProducts($product_id, $user_id, $company_id = NULL) {
        if (!empty($company_id)) {
            //加入公司大盘关注
            $this->sub_company_summary_products[$company_id][$product_id] = 1;
        } else if (empty($user_id)) {
            //加入关注列表
            $this->sub_summary_products[$product_id] = 1;
        } else {
            //加入关注列表
            $this->sub_products[$product_id] = $user_id;
        }
    }
    
    private function reduceSubProducts($product_id, $user_id, $company_id = NULL) {
        if (!empty($company_id)) {
            //有数据了，移除公司大盘关注
            unset($this->sub_company_summary_products[$company_id][$product_id]);
        } else if (empty($user_id)) {
            //有数据了，移除关注列表
            unset($this->sub_summary_products[$product_id]);
        } else if (isset ($this->sub_products[$product_id]) && $user_id == $this->sub_products[$product_id]){
            //有数据了，移除关注列表
            unset($this->sub_products[$product_id]);
        }
    }

    /************处理首次登录数据 start*******************/  
    /*
     * 首次登陆请求数据
     */

    private function firstLoginDataNotify($product_id, $user_id, $client, $notify_fd, $company_id = NULL/*公司id*/) {
        if (!empty($company_id)) {
            StatisticClient::tick("FirstLogin", 'CompanySummaryMsgToClient');
            //请求大盘数据
            $this->sendCompanySummaryMsgToClient($product_id, $company_id, $client,$notify_fd);
            StatisticClient::report('FirstLogin', 'CompanySummaryMsgToClient', true, 0, '');
        }
        else if (empty($user_id)) {
            StatisticClient::tick("FirstLogin", 'SummaryMsgToClient');
            //请求大盘数据
            $this->sendSummaryMsgToClient($product_id, 0, $client,$notify_fd);
            StatisticClient::report('FirstLogin', 'SummaryMsgToClient', true, 0, '');
        } else {
            StatisticClient::tick("FirstLogin", 'MsgToClient');
            //请求某一个人的小盘
            $this->sendMsgToClient($product_id, $user_id, $client,$notify_fd);
            StatisticClient::report('FirstLogin', 'MsgToClient', true, 0, '');
        }
        $this->addSubProducts($product_id, $user_id, $company_id);
    }

    /*
     * 用户登录后请求数据，非整体数据
     */

    private function sendMsgToClient($product_id, $user_id, $client, $notify_fd) {
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
            $this->client_worker->sendToGateway($json+['stype'=>'firstLogin','notify_fd'=>$notify_fd]);
        } else {
            //没有数据，返回false
            $json = $this->msgDataToClient($product_id, $user_id, [], $client);
            $this->client_worker->sendToGateway($json+['stype'=>'firstLogin','notify_fd'=>$notify_fd]);
            return;
        }
    }

    /*
     * 用户登录后请求数据，大盘数据
     */

    private function sendSummaryMsgToClient($product_id, $user_id, $client, $notify_fd ) {
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
            $this->client_worker->sendToGateway($json+['stype'=>'firstLogin','notify_fd'=>$notify_fd]);
        } else {
            //没有数据，返回false
            $json = $this->msgDataAllToClient($product_id, $user_id, [], $client);
            $this->client_worker->sendToGateway($json+['stype'=>'firstLogin','notify_fd'=>$notify_fd]);
            return;
        }
    }
    
    /*
     * 从记录中只找出该公司的记录
     */
    private function filterRecordsAccordingCompany($product_id, $company_id) {
        $product_id_tmp = $product_id . '_product';
        $records = isset($this->records[$product_id_tmp]) ? $this->records[$product_id_tmp] : [];
        $user_ids = $this->broker_company->userIds($company_id);
        $records_tmp = [];
        foreach ($user_ids as $user_id) {
            $user_id_tmp = $user_id . '_user';
            $records_tmp[$user_id_tmp] = isset($records[$user_id_tmp]) ? $records[$user_id_tmp] : [];
        }
        return $records_tmp;
    }
    
    /*
     * 用户登录后请求数据，公司大盘数据
     */

    private function sendCompanySummaryMsgToClient($product_id, $company_id, $client, $notify_fd) {
        //找出指定公司的记录
        $records = $this->filterRecordsAccordingCompany($product_id, $company_id);
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
            $json = $this->msgDataAllToClient($product_id, 0, $tmp, $client, $company_id);
            $this->client_worker->sendToGateway($json+['stype'=>'firstLogin','notify_fd'=>$notify_fd]);
        } else {
            //没有数据，返回false
            $json = $this->msgDataAllToClient($product_id, 0, [], $client, $company_id);
            $this->client_worker->sendToGateway($json+['stype'=>'firstLogin','notify_fd'=>$notify_fd]);
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
                if (isset($json->company_id) && !empty($json->company_id)){
                    $json = $this->firstLoginDataNotify($json->product_id, $json->user_id, $json->client, $json->notify_client, $json->company_id);
                } else {
                    $json = $this->firstLoginDataNotify($json->product_id, $json->user_id, $json->client, $json->notify_client);
                }
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
        $conf = include __DIR__ . '/../conf/gateway.php';
        $this->conf = $conf;
        //初始化品类
        $this->product_id = $this->conf['product_id'];
    }

    /**
     * {@inheritdoc}
     */
    public function run() {
        $this->onWorkerStart = array($this, 'workerStart');
        parent::run();
    }

}
