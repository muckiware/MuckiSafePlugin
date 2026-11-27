<?php declare(strict_types=1);
$loader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
$loader->addPsr4('MuckiSafePlugin\\', dirname(__DIR__) . '/src');
$loader->addPsr4('MuckiSafePlugin\\tests\\', __DIR__);
return $loader;
