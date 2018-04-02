<?php 

include 'websocket.php';
$s = new WebSocketServer();
$s->on_receive = function($cl,$f)use($s){
  echo 'client #'.$cl['id'].' says:';
  var_dump($f);
  echo "\n";
  $s->send($cl, 'Thank you!', 'text', false);
};
$s->on_connect = function($cl){
  echo 'client #'.$cl['id'].' connected to "'.$cl['url'].'"'."\n";
};
$s->on_error = function($cl,$err){
  echo 'client #'.$cl['id'].' error: '.$err."\n";
};
$s->start('localhost', 36000);

// цикл не обязателен - можно проверять довольно редко, с любыми интервалами
while (true)
{
    $s->check_messages();
    usleep(50000);
}
