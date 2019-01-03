<?php
use Workerman\Worker;
use App\TaskServer;

include __DIR__ . '/vendor/autoload.php';
include __DIR__ . '/autoload.php';

foreach(glob(__DIR__.'/app/product/*.php') as $start_file)
{
    require_once $start_file;
    $className = basename($start_file,".php");
    $fullClassName = "App\\product\\".$className;
    $productServer = new $fullClassName();
    $productServer->name = $className.'_task_server';
}

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}