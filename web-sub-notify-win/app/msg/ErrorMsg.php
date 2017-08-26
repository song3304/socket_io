<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
namespace App\msg;

/**
 * Description of errorMsg
 *
 * @author Xp
 */
class ErrorMsg {  
    // 消息格式错误
    const ERROR = -1;
    const ERROR_MSG = '消息格式错误';

    static public function output($code, $msg) {
        return json_encode(array('code'=>$code, 'msg'=>$msg));
    }
}
