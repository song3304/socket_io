<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
namespace App\msg;

use App\msg\ErrorMsg;
/**
 * Description of ToClientClass
 * 发送给某客户端的消息
 *
 * @author Xp
 */
class ToClientClass {
    
    static public function output($product_id, $match_id, $client_id,$data) {
//        if (!$json = json_decode($data)) {
//            return ErrorMsg::output(ErrorMsg::ERROR, ErrorMsg::ERROR_MSG);
//        }
        $json = $data;
        
        //todo: 根据业务需要检测相关数据
        
        //组装返回
        return array(
            'code' => 0,
            'product_id' => $product_id,
            'match_id' => $match_id,
            'to_client' => $client_id,
            'data'=>$json,
            'event_type'=>'quoteUpdate',    //推客户端的事件类型
        );
    }
}
