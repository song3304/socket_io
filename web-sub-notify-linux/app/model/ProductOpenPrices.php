<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
namespace App\model;
/**
 * Description of ProductOpenPrice
 *
 * @author Xp
 */
class ProductOpenPrices {

    //数据库连接
    private $db = NULL;
    //所有数据记录
    private $records = [];
    //买卖
    private $type = [-1 => 'sell', 1 => 'buy', '-1'=>'sell', '1'=>'buy'];

    public function __construct($db) {
        $this->db = is_object($db) ? $db : NULL;
    }

    //根据品类和用户确定盘面，返回开盘价
    public function openPice($product_id, $type) {
        //判断type是否正确
        $type = in_array($type, ['sell', 'buy']) ? $type : NULL;
        $product_id = (string)$product_id;
        if ($type != NULL && isset($this->records[$product_id][$type]['open_price'])) {
            return $this->records[$product_id][$type]['open_price'];
        } else {
            return '-';
        }
    }
    
    //根据品类返回开盘价信息
    public function openPriceInfo($product_id) {
        $product_id = (string)$product_id;
        $sell = isset($this->records[$product_id]['sell']['open_price'])?$this->records[$product_id]['sell']['open_price']:'-';
        $buy = isset($this->records[$product_id]['buy']['open_price'])?$this->records[$product_id]['buy']['open_price']:'-';
        return ['sell_open_price'=>intval($sell),'buy_open_price'=>intval($buy)];
    }

    //将数据整理，后续查询可以加快速度
    private function _collectRecords($records) {
        if (empty($records)) {
            return [];
        } else {
            foreach ($records as $record) {
                $product_id = $record['product_id'];
                $type = $record['trade_type'];
                $type = isset($this->type[$type]) ? $this->type[$type] : NULL;
                if ($type === NULL) continue;
                $this->records[$product_id][$type] = $record;
            }
        }
    }

    //查询所有数据
    public function initTodayAllData() {
        $date = date('Y-m-d');
        $sql = "select * from en_product_open_price where date_time='$date' and delete_time is null";
        $records = $this->db->query($sql);
        $this->_collectRecords($records);
    }
    
    //清除开盘信息
    public function clearAllData() {
        $this->records = [];
    }
    //返回当前所有开盘价信息
    public function getRecords(){
        return $this->records;
    }
    //获取所有品种-撮合员ids--最新的
    public function getAllProductMatchIds(){
        $return_data = [];
        $sql = "select user_id,product_id from en_product_real_times where delete_time is null";
        $records = $this->db->query($sql);
        if(empty($records) || count($records)<1){
            $yesterday = date("Y-m-d",strtotime("-1 day"));
            $sql = "select user_id,product_id from en_product_real_times_logs";
            $records = $this->db->query($sql);
        }
        foreach ($records as $item){
            $return_data[$item['product_id']] = $item['user_id'];
        }
        return $return_data;
    }
}
