<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace App\model;

/**
 * Description of ProductClassify
 *
 * @author Xp
 */
class ProductClassify {
    //
    protected $product = 0;
    // 品类数据
    protected $product_ids = [];


    /*
     * 根据品类初始化撮合公司
     */
    public function __construct($db, $product_id) {
        $this->db = is_object($db) ? $db : NULL;
        $this->product = intval($product_id);
    }
    
    public function initProductIds() {
        $sql = "select DISTINCT id from en_products where catalog_id=$this->product and status=1 and delete_time IS NULL";
        $records = $this->db->query($sql);
        if (empty($records) || count($records) < 1) {
            //没有值
        } else {
            $this->product_ids = array_column($records, 'id');
        }
    }
    
    public function allProductIds() {
        return $this->product_ids;
    }
}
