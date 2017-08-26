<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit500fb98e7a488b3ade70d7ab64cd44ff
{
    public static $prefixLengthsPsr4 = array (
        'W' => 
        array (
            'Workerman\\' => 10,
        ),
        'P' => 
        array (
            'PHPSocketIO\\' => 12,
        ),
        'C' => 
        array (
            'Channel\\' => 8,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Workerman\\' => 
        array (
            0 => __DIR__ . '/..' . '/workerman/workerman',
        ),
        'PHPSocketIO\\' => 
        array (
            0 => __DIR__ . '/..' . '/workerman/phpsocket.io/src',
        ),
        'Channel\\' => 
        array (
            0 => __DIR__ . '/..' . '/workerman/channel/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit500fb98e7a488b3ade70d7ab64cd44ff::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit500fb98e7a488b3ade70d7ab64cd44ff::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
