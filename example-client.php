<?php

include 'websocket.php';
$c = new WebSocketClient();
$c->connect('ws://localhost:36000/shit');
$c->send('some shit');
$r = $c->recv();
var_dump($r);
sleep(3);
