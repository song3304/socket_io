<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
namespace App;
/**
 * Description of MsgIds
 *
 * @author Xp
 */
class MsgIds
{
    /*
     * 消息返回code
     */
    //消息格式错误
    const MSG_FORMAT_ERROR = -100;
    
    // 消息类型，表示中心发过来需要发送给某个组的消息
    const MESSAGE_GATEWAY_TO_ALL = 10000;
    // 消息类型，表示中心发过来需要发送给某个组的消息
    const MESSAGE_GATEWAY_TO_GROUP = 10001;
    // 消息类型，表示中心发过来需要发送给某个人的消息
    const MESSAGE_GATEWAY_TO_CLIENT = 10002;
    // 消息类型，表示中心发过来的业务消息
    const MESSAGE_GATEWAY_BUSSINESS = 10003;
}
