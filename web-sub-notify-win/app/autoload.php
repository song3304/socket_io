<?php

$classMap = require(__DIR__ . '/classes.php');
spl_autoload_register(function($name) use ($classMap) {
    if (!empty($classMap[$name])) {
        require_once($classMap[$name]);
        return true;
    }
    
    $path = str_replace('\\', DIRECTORY_SEPARATOR ,$name);
    $path = str_replace('App', 'app', $path);
    if(is_file($class_file = __DIR__ . "/$path.php"))
    {
        require_once($class_file);
        if(class_exists($name, false))
        {
            return true;
        }
    }
    return false;
});