<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
return array(
    'gateway_addr' => '127.0.0.1:8282',
    'socket_port' => 2120,
    'http_port' => 2121,
    'system_status' => TRUE,
    'ssl_switch' => 'off',
    'ssl_conf' => array(
        'local_cert' => '', //cert
        'local_pk' => '', //pk
    ),
    'database' => [
        'host' => '192.168.0.53',
        'port' => 3306,
        'user' => 'root',
        'password' => '123456',
        'dbname' => 'energy',
        'charset' => 'utf8mb4',
    ],
    'emit_interval'=>3,
);
