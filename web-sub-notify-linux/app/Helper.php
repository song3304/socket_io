<?php 
namespace App;

class Helper
{
    /*
     * @param string product_id: 产品品类id
     * @param string match_id: 撮合人员id
     * @return string 根据品类和撮合id返回相关的room信息
     */
    static public function roomId($uid, $product_id, $match_id, $company_id = NULL) {
        if (!empty($company_id)) {
            //关注公司盘，忽略撮合员id
            return "subNotify_"."$product_id".'_'."company_$company_id";
        } else {
            return "subNotify_"."$product_id".'_'."$match_id";
        }
    }
}