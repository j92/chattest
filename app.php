#!/usr/bin/env php
<?php
// app.php

use Chat\Commands\ServerStartCommand;
use Chat\DatabaseAdapter;
use React\EventLoop\Factory as LoopFactory;
use React\Socket\Server as ServerSocket;
use Symfony\Component\Console\Application;

//
require __DIR__.'/vendor/autoload.php';

date_default_timezone_set('Europe/Amsterdam');

$app = new Application();

try
{
    $pdo = new PDO('sqlite:db.sqlite');

    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}
catch (Exception $exception)
{
    echo 'Could not connect to the database';
    exit(1);
}

$dbAdapter = new DatabaseAdapter($pdo);
$loop = LoopFactory::create();
$server = new ServerSocket($loop);

$app->add(new ServerStartCommand($dbAdapter, $loop, $server));
$app->run();
