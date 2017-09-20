<?php
use Workerman\Worker;
use App\TaskServer;

include __DIR__ . '/vendor/autoload.php';
include __DIR__ . '/autoload.php';

$server = new TaskServer();

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}