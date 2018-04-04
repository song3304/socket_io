<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace App\model;

/**
 * Description of BrokerCompany
 *
 * @author Xp
 */
class BrokerCompany {

    //数据库连接
    private $db = NULL;
    //品类
    private $product = NULL;
    //所有数据记录
    private $company = [];
    //所有数据记录
    private $user_org = [];

    /*
     * 根据品类初始化撮合公司
     */
    public function __construct($db, $product_id) {
        $this->db = is_object($db) ? $db : NULL;
        $this->product = intval($product_id);
    }
    
    /*
     * 定时任务
     */
    public function initCompanyInfo() {
        $this->cacheActiveCompanyInfo();
    }

    /*
     * 定期缓存所有该品类的公司列表
     * 结构如下：[
     *              [company][[user],[user]],
     *              [company][[user],[user]],
     *          ]
     * 
     */

    private function cacheActiveCompanyInfo() {
        $tmp = [];
        $sql = 'select DISTINCT org_id,en_users.id as user_id from en_users  '
                . 'LEFT JOIN en_user_products on en_users.id = en_user_products.user_id '
                . 'where is_valid=1 and system_type=2 and en_users.delete_time IS NULL and en_user_products.delete_time IS NULL '
                . 'and catalog_id='.$this->product;
        $records = $this->db->query($sql);
        if (empty($records) || count($records) < 1) {
            //没有值
        } else {
            $org_ids = array_unique(array_column($records, 'org_id'));
            foreach ($org_ids as $org_id) {
                foreach ($records as $record) {
                    //将该公司所有人的id集中在一起，user_id是主键，不需要去重
                    if ($record['org_id'] === $org_id) {
                        $tmp[$org_id][$record['user_id']] = TRUE;   //将user_id保存成key_index，方便后面判断
                    }
                }
            }
        }
        $this->company = $tmp;

        //保存一份用户与公司对应
        $this->user_org = array_column($records, 'org_id', 'user_id');
    }

    //根据用户id查找出所有公司id
    public function companyIdsFromUserids(Array $user_ids) {
        $tmp = [];
        foreach ($user_ids as $user_id) {
            if (isset($this->user_org[$user_id])) {
                $org_id = $this->user_org[$user_id];
                $tmp[$org_id][] = $user_id;
            }
        }
        return $tmp;
    }
    
    /*
     * 查找该公司下所有用户
     */
    public function userIds($company_id) {
        return isset($this->company[$company_id])?array_keys($this->company[$company_id]):[];
    }
    
    /*
     * 返回所有撮合公司人员对应关系
     */
    public function companyUserIds() {
        return $this->company;
    }
    
    /*
     * 返回所有撮合公司id
     */
    public function companyIds() {
        return empty($this->company)?[]:array_keys($this->company);
    }

}
