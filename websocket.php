<?php
/*	WebSocket-клиент без зависимостей.
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
	
*/



class WebSocketException extends Exception {}

class WebSocketClient {

	// интервал по-умолчанию между проверками сетевого буфера входящих данных, в секундах
	public $timeout = 0.05;

	// заголовки, полученные от сервера при рукопожатии (строка)
	public $handshake;
	
	protected $socket;
	protected $magic_string = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
	protected $initial_opcode = 'text';
	
	
    public function __construct($url)
	{$this->connect($url);}
	
	/*	Соединиться с заданным URL.
			$url - адрес, например 'ws://some-shit/to/hell'
	*/
	public function connect($url)
	{
		$this->closed = false;
		extract(parse_url($url));
		$this->socket = stream_socket_client('tcp://'.$host.':'.$port, $this->errno, $this->errstr, 10);
		if ($this->errno) throw new WebSocketException('#'.$this->errno.', '.$this->errstr);
		stream_set_timeout($this->socket, 10);
		$key = range('a', 'z');
		shuffle($key);
		$key = implode($key);
		$head = "GET ".$path." HTTP/1.1"."\r\n".
				"Host: ".$this->host."\r\n".
				"Upgrade: WebSocket"."\r\n".
				"Connection: Upgrade"."\r\n".
				"Sec-WebSocket-Key: ".$key."\r\n".
				"Origin: http://localhost"."\r\n".
				"Sec-WebSocket-Version: 13"."\r\n".
				"\r\n";
		$x = fwrite($this->socket, $head);
		if (!$x) throw new WebSocketException('#'.$this->errno.', '.$this->errstr);
		$this->handshake = '';
		do {
			$x = fgets($this->socket);
			$this->handshake .= $x;
		} while (!in_array($x, ["\r\n", "\n"]));
		$server_key = base64_encode(sha1($key.$this->magic_string, true));
		if (strpos($this->handshake, $server_key)===FALSE)
		{throw new WebSocketException('Invalid handshake response from server');}
		$this->set_timeout($this->timeout);
	}
	
	/*	Задать интервал проверок буфера при получении фреймов, в секундах.
		Чем он выше, тем больше вероятность что входящие данные будут периодически слегка запаздывать.
	*/
	public function set_timeout($timeout)
	{
		$this->timeout = $timeout;
		stream_set_timeout($this->socket, floor($timeout), 1000000*($timeout-floor($timeout)));
	}

	/*	Отправить фрейм (строка).
		Можно задать опкод.
	*/
    public function send($data, $opcode = 'text')
	{
		$data = $this->hybi10Encode($data, $opcode);
		for ($wr=0;$wr<strlen($data);$wr+=$x)
		{
			$x = fwrite($this->socket, substr($data, $wr));
			if ($x===FALSE)
			{throw new WebSocketException('Can\'t write to socket');}
		}
    }
	
	/*	Получить свежие фреймы.
			$blocking - если true, то блокирует и ждёт пока не получит хотя бы один.
				если false, то лишь проверяет наличие данных и сразу же возвращает пустой массив, если ни одного фрейма пока нет.
		Вернет массив фреймов, возможно пустой.
		Фрейм это ассоциативный массив, имеет вид:
			'payload' - данные (строка)
			'opcode' - тип. Возможные значения: 'text', 'continue', 'binary', 'close', 'ping', 'pong'
			'masked' - (bool) был ли фрейм маскирован
			'fragmented' - (bool) был ли фрейм фрагментирован
	*/
	public function recv($blocking = true)
	{
		$res = [];
		while (!feof($this->socket))
		{
			// ждёт данных время, равное таймауту. задать можно через set_timeout().
			$x = fread($this->socket, 64*1024);
			// цикл нужен чтобы забрать все готовые фреймы из буфера
			while ($data = $this->hybi10Decode($x))
			{
				$x = '';
				try {
					if ($data['opcode']=='ping')
					{$this->send(substr($data['payload'],0,125), 'pong');}
					elseif ($data['opcode']=='close' && !$this->closed)
					{
						$this->send('', 'close');
						$this->close();
					}
				}
				catch (Exception $e) {}
				$res[] = $data;
			}
			// есть один или несколько целых фреймов (и возможно один неполный, его данные были сохранены - их впитала функция hybi10Decode())
			if ($res) return $res;
			// фрейм всего один и он пока неполный, его данные были сохранены - их впитала функция hybi10Decode()
			if (!$blocking) return [];
			// если не было получено ни одного целого фрейма и включен блокирующий режим, то отправляем дальше ждать неявно через fread()
		}
		// соединение было закрыто.
		// но об этом может быть неизвестно, если при этом были получены данные.
		$this->close();
		return [];
	}
	
	// закрыть соединение
    public function close()
	{
		$this->closed = true;
		$this->prev_data = '';
		fclose($this->socket);
	}

	/*	Закодировать фрейм.
			$data - данные (строка)
			$opcode - тип фрейма. Возможные значения:
				'text' - стандартный тип фрейма
				'continue' - используется в связке с $fin, см. ниже
				'binary' - бинарный (используется по согласованию!)
				'close' - закрывающий соединение фрейм. Получив такой фрейм сторона должна закрыть соединение.
				'ping' - пингующий фрейм. Может посылаться как сервером, так и клиентом. Согласно протоколу противоположная сторона должна ответить на него как можно быстрее фреймом с опкодом 'pong' и с теми же данными, но размер данных при этом не может быть больше 125.
				'pong' - см. выше
			$masked - маскировать ли фрейм. Обычно true.
			$fin - последний ли это фрейм. Обычно true. Используется когда размер данных неизвестен и тогда засылается серия фреймов, первый из которых имеет опкод 'text' либо 'binary', а остальные "досылаются" с опкодом 'continue'. Всем фреймам в этой цепочке (кроме последнего) ставится $fin == false. Но опять же, это используется только для различного рода стриминга, т.е. случай нестандартный.
	*/
	protected function hybi10Encode($data, $opcode = 'text', $masked = true, $fin = true)
	{
		$opcodes = ['continue' => 0x00, 'text' => 0x01, 'binary' => 0x02, 'close' => 0x08, 'ping' => 0x09, 'pong' => 0x0A];
		$sz = strlen($data);
		$mask = $extended_sz = '';
		if ($sz > 0xFFFF)
		{
			$extended_sz = pack('J', $sz);
			$sz = 127;
		}
		elseif ($sz > 125)
		{
			$extended_sz = pack('n', $sz);
			$sz = 126;
		}
		if ($masked)
		{
			$mask = pack('N', mt_rand(0, 0xffffffff));
			$x = strlen($data);
			for ($i=0;$i<$x;$i++)
			{$data{$i} = $data{$i} ^ $mask{$i % 4};}
			$sz = (0x80 | $sz);
		}
		$opcode = $opcodes[$opcode];
		if ($fin) $opcode = ($opcode | 0x80);
		$frame = 
			chr($opcode).
			chr($sz).
			$extended_sz.
			$mask.
			$data
		;
		return $frame;
	}
	
	/*	Декодировать фрейм.
		Обрезает из переданных данных ровно один фрейм, декодирует его и возвращает.
		Если данных не хватает, то вернет false.
		Необработанная часть неявно запоминается и будет добавлена перед декодированием в начало следующих данных. Т.е. данных может иметься больше, скажем несколько фреймов - будет возвращен лишь первый, а остальные можно будет получить вызывая эту функцию с пустым параметром $data.
	*/
	protected function hybi10Decode($data)
	{
		$data = $this->prev_data.$data;
		// данных недостаточно
		if (strlen($data) < 2)
		{
			$this->prev_data = $data;
			return false;
		}
		$opcodes = [0x00 => 'continue', 0x01 => 'text', 0x02 => 'binary', 0x08 => 'close', 0x09 => 'ping', 0x0A => 'pong'];
		$opcode = ord($data[0]) & 0x0F;
		$is_fin = ord($data[0]) & 0x80;
		$res = [];
		$res['opcode'] = $opcodes[$opcode];
		$res['masked'] = ord($data[1]) & 0x80;
		$res['fragmented'] = false;
		$p_len = ord($data[1]) & 0x7F;
		if ($p_len==126)
		{
		   $p_offset = 8;
		   $p_len = max(0, unpack('nshit/npay', $data)['pay']);
		}
		elseif ($p_len==127)
		{
			$p_offset = 14;
			$p_len = max(0, unpack('nshit/Jpay', $data)['pay']);
		}
			else
		{$p_offset = 6;}
		if (!$res['masked']) $p_offset -= 4;
		// данных меньше, чем должно быть в пакете
		if (strlen($data) < $p_offset + $p_len)
		{
			$this->prev_data = $data;
			return false;
		}
		if ($res['masked'])
		{
			$mask = substr($data, $p_offset-4, 4);
			$j = 0;
			$x = $p_offset + $p_len;
			for ($i=$p_offset;$i<$x;$i++)
			{$data{$i} = $data{$i} ^ $mask[$j++ % 4];}
		}
		$res['payload'] = substr($data, $p_offset, $p_len);
		$this->prev_data = substr($data, $p_offset + $p_len);
		if ($res['opcode']=='continue')
		{
			if (in_array($this->initial_opcode, ['text', 'binary']))
			{
				$this->buf .= $res['payload'];
				if (!$is_fin) return false;
				$res['opcode'] = $this->initial_opcode;
				$res['fragmented'] = true;
				$res['payload'] = $this->buf;
			}
				else
			{
				// получен фрейм-продолжение для опкода, в котором это запрещено.
				// мысленно ставим серверу Warning.
			}
		}
			else
		{
			if (in_array($res['opcode'], ['text', 'binary']))
			{$this->buf = '';}
			$this->initial_opcode = $res['opcode'];
		}
		return $res;
	}
}
