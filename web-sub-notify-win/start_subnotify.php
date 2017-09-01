<?php
use Workerman\Worker;
use App\SubNotify;

include __DIR__ . '/vendor/autoload.php';
include __DIR__ . '/autoload.php';

$server = new SubNotify();
$server->startServer();

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
