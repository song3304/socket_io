<?php
use Workerman\Worker;
use App\SubNotify;

include __DIR__ . '/vendor/autoload.php';
include __DIR__ . '/autoload.php';

$server = new SubNotify();
$server->startServer();

$server1 = new SubNotify(2124,2125);
$server1->startServer();

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
