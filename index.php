<?php

/*
 * This file is part of the overtrue/websocket.
 *
 * (c) overtrue <anzhengchao@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled.
 */

include __DIR__.'/vendor/autoload.php';

//
//use WebSocket\Client;
//
//$client = new Client("ws://echo.websocket.org/");
//$client->send("Hello WebSocket.org!");
//
//echo $client->receive(); // Will output 'Hello WebSocket.org!'

//--------------------------------

use Overtrue\WebSocket\Client;

$socket = new Client('ws://echo.websocket.org/');

$socket->send('Hello WebSocket.org!');
$socket->send('Hello world!');

var_dump($socket->receive());
