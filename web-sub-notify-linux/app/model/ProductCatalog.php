<?php
namespace App\model;

class ProductCatalog {
    public function __construct($db) {
        $this->db = is_object($db) ? $db : NULL;
    }
    public function getProductList() {
        $sql = "select id,catalog_id from en_products where status=1 and delete_time IS NULL";
        $records = $this->db->query($sql);
        if (empty($records) || count($records) < 1) {
            return [];
        } else {
           $products = [];
           foreach ($records as $record){
               if(isset($products[$record['catalog_id']])){
                   $products[$record['catalog_id']][] = $record['id'];
               }else{
                   $products[$record['catalog_id']] = [$record['id']];
               }
           }
           return $products;
        }
    }
}
