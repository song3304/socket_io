<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
namespace App\model;

use App\product\Glycol;

class GlycolModel
{
    //根据时间进行查询，仅仅查询比上次查询时间更晚的记录
    static function selectAllRecords($db) {
        $sell_buy = $db->query("select * from en_product_real_times where delete_time is null");
        $timestamp = strtotime(date('Y-m-d 09:00:00', time()));
        $order = $db->query("select id,user_id,product_id,number,price as trade_price,2 trade_type,create_time,update_time,delete_time from en_transactions where delete_time is null and UNIX_TIMESTAMP(create_time)>=$timestamp");
        foreach ($sell_buy as &$value) {
            $value['product']['name'] = $db->single("select name from en_products where id='" . $value['product_id'] . "'");
            $value['trader']['name'] = $db->single("select name from en_trader_company where id='" . $value['trader_id'] . "'");
            $value['stock']['name'] = $db->single("select name from en_storages where id='" . $value['stock_id'] . "'");
            
            $user = $db->row("select phone,qq,nickname,realname from en_users where id='".$value['user_id']."'");
            $value['phone'] = $user['phone'];
            $value['qq'] = $user['qq'];
            $value['mather_name'] = !empty($user['nickname'])?$user['nickname']:$user['realname'];
            
            $type_tag = '';
            switch ($value['trade_type']) {
                case Glycol::buy: $type_tag = "买";
                break;
                case Glycol::sell:$type_tag = "卖";
                break;
                case Glycol::deal:$type_tag = "成交";
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
            unset($value['note']);
        }
        unset($user);
        
        $arr = [];
        $arr = array_merge($arr, $sell_buy);
        $arr = array_merge($arr, $order);
        return $arr;
    }
    
    /*
     * 查找某一时刻之后更新的所有记录
     */
    static function selectAllRecordsAccordingTimestamp($db,$timestamp,$emit_interval) {
        //根据时间进行查询，仅仅查询比上次查询时间更晚的记录
        /*
         *   延迟5秒进行读取
         * 原因：数据通过nginx+php到数据库时间会滞后
         */
        $timestamp = $timestamp - $emit_interval;
        $sell_buy = $db->query("select * from en_product_real_times where "
            . "UNIX_TIMESTAMP(create_time)>=$timestamp or "
            . "UNIX_TIMESTAMP(delete_time)>=$timestamp");
        $order = $db->query("select id,user_id,product_id,number,price as trade_price,2 trade_type,create_time,update_time,delete_time from en_transactions where "
            . "UNIX_TIMESTAMP(create_time)>=$timestamp or "
            . "UNIX_TIMESTAMP(delete_time)>=$timestamp");
        
        foreach ($sell_buy as &$value) {
            $value['product']['name'] = $db->single("select name from en_products where id='" . $value['product_id'] . "'");
            $value['trader']['name'] = $db->single("select name from en_trader_company where id='" . $value['trader_id'] . "'");
            $value['stock']['name'] = $db->single("select name from en_storages where id='" . $value['stock_id'] . "'");
            
            $user = $db->row("select phone,qq,nickname,realname from en_users where id='".$value['user_id']."'");
            $value['phone'] = $user['phone'];
            $value['qq'] = $user['qq'];
            $value['mather_name'] = !empty($user['nickname'])?$user['nickname']:$user['realname'];
            
            $type_tag = '';
            switch ($value['trade_type']) {
                case Glycol::buy: $type_tag = "买";
                break;
                case Glycol::sell:$type_tag = "卖";
                break;
                case Glycol::deal:$type_tag = "成交";
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
            unset($value['note']);
        }
        unset($user);
        
        $arr = [];
        $arr = array_merge($arr, $sell_buy);
        $arr = array_merge($arr, $order);
        return $arr;
    }
}
