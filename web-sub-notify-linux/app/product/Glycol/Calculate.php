<?php 
namespace App\product\Glycol;

use App\MsgIds;

class Calculate
{
    //分组信息
    static function groupIno() {
        $data = array(
            'id' => MsgIds::MESSAGE_GATEWAY_BUSSINESS,
            'business_type' => 'JoinGroup',
            'catalog_id'=>11 //乙二醇分类id
        );
        return $data;
    }
    //是否在有效时间内
    static function isActive() {
        $timestamp = time();
        $hour = intval(date('H', $timestamp));
        return $hour >= 9 && $hour < 22;
    }
    // 几何平均数
    static function arraySummary(array $arr, $field = 'trade_price') {
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
        return [$max, $min, $average];
    }
    //输出
    static public function QuoteOutput($product_id, $match_id, $data, $passback = false, $company_id = NULL) {
        $json = $data;
        //组装返回
        if (!$passback) {
            return array(
                'code' => 0,
                'product_id' => $product_id,
                'match_id' => $match_id,
                'company_id' => empty($company_id)?0:$company_id,
                'data' => $json,
                'event_type' => 'quoteUpdate', //推客户端的事件类型
            );
        } else {
            return array(
                'code' => 0,
                'product_id' => $product_id,
                'match_id' => $match_id,
                'company_id' => empty($company_id)?0:$company_id,
                'data' => $json,
                'event_type' => 'quoteUpdate', //推客户端的事件类型
                'passback' => '1',
            );
        }
    }
    //
    static public function ClientOutput($product_id, $match_id, $client_id, $data,$company_id = NULL) {
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
            'company_id' => empty($company_id)?0:$company_id,
            'data'=>$json,
            'event_type'=>'quoteUpdate',    //推客户端的事件类型
        );
    }
    
    /*
     * 移除过期数据
     */
    static function removeExpireData(&$records, $product_id, $user_id, $trade_type) {
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
}