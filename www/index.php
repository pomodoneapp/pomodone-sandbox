<?php

error_reporting(E_ALL & ~E_NOTICE);

require '../vendor/autoload.php';

use Symfony\Component\Debug\ErrorHandler;
use Symfony\Component\Debug\ExceptionHandler;

ErrorHandler::register();
ExceptionHandler::register();
/*Symfony\Component\Debug\Debug::enable();*/

use Pomodone\App;

$app = new App();
$app['debug'] = true;
$app->setupRouting();
$app->run();