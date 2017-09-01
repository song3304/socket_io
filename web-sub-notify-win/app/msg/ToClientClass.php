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
    
    static public function output($uid,$to_uid, $data) {
//        if (!$json = json_decode($data)) {
//            return errorMsg::output(ErrorMsg::ERROR, ErrorMsg::ERROR_MSG);
//        }
        $json = $data;
        
        //todo: 根据业务需要检测相关数据
        
        //组装返回
        return json_encode(array(
            'code' => 0,
            'client'=>$uid,
            'to_client'=>$to_uid,
            'data'=>$json,
        ));
    }
}
