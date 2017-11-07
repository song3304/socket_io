<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
namespace App;
/**
 * Description of subNotifyRooms
 *
 * @author Xp
 */
class SubNotifyRooms
{   
    /*
     * @param string product_id: 产品品类id
     * @param string match_id: 撮合人员id
     * @return string 根据品类和撮合id返回相关的room信息
     */
    static public function roomId($uid, $product_id, $match_id) {
        return "subNotify_"."product_id_$product_id".'_'."match_id_$match_id";
    }
}
