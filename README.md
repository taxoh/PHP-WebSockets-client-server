# PHP-webSocketsClient
WebSockets Client written in PHP 7

WebSocket-клиент без зависимостей

	Реализует версию протокола #13 (RFC 6455).
	Обрабатывает хендшейки, маскирование, фрагментацию, control-фреймы (пинги, close'ы).
	Не накладывает каких-либо ограничений на маскировку фреймов.
	Поддерживает почти все возможности протокола, можно еще SSL прикрутить при желании.
	
Пример:
	
	$client = new WebSocketClient("ws://localhost:1122", 10);
	$client->send('some shit');
	$arr = $client->recv();
	foreach ($arr as $a)
	{echo $a['payload'].'==========='."\n";}
	
Ссылки:
	
	https://tools.ietf.org/html/rfc6455
	https://developer.mozilla.org/en-US/docs/Web/API/WebSockets_API/Writing_WebSocket_servers	
