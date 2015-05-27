<?php

class StreamClient {
	// 20 ошибок записи в сокет подряд - кикбан
	const BUF_WRITE_ERR_MAXCOUNT = 20;

	protected $peer;
	protected $socket;
	protected $stream;
	protected $finished = false; // выставляется в true когда отключается клиент
	protected $err_counter;
	protected $buffer = array();

	public function __construct($peer, $socket) {
		$this->peer = $peer;
		$this->socket = $socket;
		stream_set_blocking($this->socket, 0);
		stream_set_timeout($this->socket, 0, 20000);
		#stream_set_write_buffer($this->socket, 128000); // хер помогает
	}
	public function getBuffersCount() {
		return count($this->buffer);
	}

	public function getIp() {
		return implode('', array_slice(explode(':', $this->peer), 0, 1));
	}

	public function getName() {
		return $this->peer;
	}
	public function isFinished() {
		return $this->finished;
	}
	public function isActiveStream() {
		return $this->stream and $this->stream->isActive();
	}

	// вызывается при регистрации клиента в потоке, они связываются перекрестными ссылками друг на друга
	public function associateStream(StreamUnit $stream) {
		$this->stream = $stream;
	}

	public function accept() {
		$headers = 'HTTP/1.0 200 OK' . "\r\n" . 'Connection: keep-alive' . "\r\n\r\n";
		return $this->put($headers);
	}

	public function copy($src_res, $buf) {
		return stream_copy_to_stream ($src_res, $this->socket, $buf);
	}
	public function put($data) {
		if (!$this->socket) {
			throw new Exception('inactive client socket', 10);
		}

		// сразу в буфер, на случай если выйдем по неготовности сокета
		if ($data) {
			$this->buffer[] = $data;
		}
		// будем кикать тех, у кого буфер слишком вырос. не знаю как еще опрделить, что клиент мертв
		// TODO проблема в том, что на клиенте это держать дороже - сколько клиентов, столько копий буферов в памяти
		if (count($this->buffer) > 500) {
			// 300 буферов HD дискавери это около 45Mb
			$this->close();
			throw new Exception('buffer kickban ' . $this->getName());
		}

		// пробуем использовать stream_select()
		// а проблема в том, что XBMC набрал себе буфера секунд 5-8, и больше не лезет, 
		// а ace транслирует и читать это приходится, разве что излишки в памяти хранить
		$write = array($this->socket);
		$mod_fd = stream_select($_r = NULL, $write, $_e = NULL, 0, 20000);
		if ($mod_fd === FALSE) {
			return false;
		}
		// когда клиент тупо вырубается (по питанию, инет упал и т.д.) - он застревает тут
		if (!$write) {
			$cnt = count($this->buffer);
			if ($cnt % 10 == 0) {
				#error_log('write socket not ready2. ' . $cnt . ' buffered');
			}
			return null;
		}
		$sock = reset($write);
		

		// передаем буфер. тут он заполняется снизу, а расходуется сверху
		$b = 0;
		while ($tmp = array_shift($this->buffer)) {
			$b = @fwrite($this->socket, $tmp); // @ чтоб ошибки в лог не сыпались
			$this->checkForWriteError();
			// если сокет полон и дальше не лезет - выходим
			if ($b != strlen($tmp)) {
				#error_log(count($this->buffer) . ' buffers left');
				// видимо в сокет уже не лезет, вернем в буфер что осталось и выходим
				array_unshift($this->buffer, substr($tmp, $b));
				break;
			}
		}

		// fwrite отличается тем, что не врет, что записал весь буфер в неактивный сокет
		// но с ней другая проблема, картинка периодически разваливается, затем снова восстанавливается
		// можно юзать .._sendto, а ошибки мониторить через error_get_last, 
		// к тому же реальное число записанных байт не пригодилось
		#$res = @fwrite($this->socket, $data); // @ чтоб ошибки в лог не сыпались
		#$res = @stream_socket_sendto($this->socket, $data);

		// если запись не удалась, надо бы как то попытаться еще раз.. может в буфер себе сохранить
		// вот еще по ошибке 11
		// http://stackoverflow.com/questions/14370489/what-can-cause-a-resource-temporarily-unavailable-on-sock-send-command

		// поскольку грубо выключенный комп не успевает правильно закрыть сокет, трансляция пишет "вникуда"
		// будем считать число ошибок записи в сокет подряд (при успешной записи счетчик в 0)
		// по достижении порога ошибок - кикаем сами себя

#if ($res != strlen($data) or $match) {
#	error_log($res . ' of ' . strlen($data) . ' bytes with ' . $err['message']);
	// если запись не удалась (только для fwrite), или записалось не все - кладем разницу в буфер
	// в след.подход попробуем еще раз
	// $res байт записалось, сохраняем часть с res и до конца
#	$this->buffer[] = substr($data, $res);
#}

		return $b;
	}
	protected function checkForWriteError() {
		$err = error_get_last();
		@trigger_error(""); // "очистить" ошибку, по другому хз как
		// можно и просто по fwrite смотреть
		$match = ($err['message'] and strpos($err['message'], 'Resource temporarily unavailable'));
		if (!$match) {
			$this->err_counter = 0;
		}
		else {
			$this->err_counter++;
		}

		if ($this->err_counter > self::BUF_WRITE_ERR_MAXCOUNT) {
			$this->close();
			throw new Exception('error kickban ' . $this->getName());
		}
	}

	public function track4new() {
		$read = array($this->socket);
		$mod_fd = stream_select($read, $_w = NULL, $_e = NULL, 0, 20000);
		if ($mod_fd === FALSE) {
			return false;
		}
		if (!$read) {
			return null;
		}
		$sock = reset($read);

		$sock_data = stream_socket_recvfrom($sock, 1024);
		if (strlen($sock_data) === 0) { // connection closed, works
			throw new Exception('Disconnect', 1);
		} else if ($sock_data === FALSE) {
			throw new Exception('Something bad happened', 2);
		}

		// в этой ветке можем читать запрос клиента на запуск канала
		// http://sci-smart.ru:8000/pid/43b12325cd848b7513c42bd265a62e89f635ab08/Russia24
		// закрывать коннект не надо
		// error_log(date('H:i:s') . " Client sent: " . $sock_data);

		// РЕФАКТОРИТЬ! эта часть должна быть представлена каким-то объектом-обработчиком запросов
		if (preg_match('~^HEAD.*HTTP~smU', $sock_data, $m)) {
			throw new Exception('HEAD request not supported', 3);
		}
		else if (preg_match('~Range: bytes=0\-0~smU', $sock_data, $m)) {
			throw new Exception('Skip empty range request', 4);
		}

		// start by PID
		if (preg_match('~GET\s/pid/([^/]*)/(.*)\sHTTP~smU', $sock_data, $m)) {
			$pid = $m[1];
			$name = urldecode($m[2]);
			return array('pid' => $pid, 'name' => $name, 'type' => 'pid');
		}
		// start by translation ID (http://torrent-tv.ru/torrent-online.php?translation=?)
		else if (preg_match('~GET\s/trid/(\d+)/(.*)\sHTTP~smU', $sock_data, $m)) {
			$id = $m[1];
			$name = urldecode($m[2]);
			return array('pid' => $id, 'name' => $name, 'type' => 'trid');
		}
		// start by channel name (how?)
		// {}
		// response with m3u tv playlist
		else if (preg_match('~GET\s/playlist~smU', $sock_data, $m)) {
			$headers = 
				'HTTP/1.0 200 OK' . "\r\n" . 
				'Content-type: text/plain' . "\r\n" . 
				'Connection: close' . "\r\n\r\n";
			$this->put($headers);

			$this->put('#EXTM3U
#EXTINF:-1,2x2
http://sci-smart.ru:8000/pid/0d2137fc5d44fa9283b6820973f4c0e017898a09/2x2
#EXTINF:-1,24 Техно
http://sci-smart.ru:8000/pid/11e61b71cf55801a7e5c23671006caa379fc1e35/24 Техно
');
		}
		// выдать HTML-страницу, аналогичную содержанию ncurses-ui
		else if (preg_match('~GET\s/\sHTTP~smU', $sock_data, $m)) {
		}

		// если запрос неясен - закрываем коннект
		// по идее клиент после запроса потока больше ничего не шлет
		$this->close();
	}

	// новая фича, пробуем уведомить XBMC-клиента об ошибке (popup уведомление)
	// работает! :)
	// notify all уведомляет всех клиентов, хз чем фича мб полезна
	//	{"id":2,"jsonrpc":"2.0","method":"JSONRPC.NotifyAll","params":{"sender":"me","message":"he","data":"testdata"}}
	//  тут же получаю уведомление 
	//	{"jsonrpc":"2.0","method":"Other.he","params":{"data":"testdata","sender":"me"}}
	//  и отчет о выполнении команды {"id":2,"jsonrpc":"2.0","result":"OK"}
	public function notify($note, $type = 'info') {
		$ip = $this->getIp();
		error_log('NOTE on ' . $ip . ':' . $note);

		$conn = @stream_socket_client('tcp://' . $ip . ':9090', $e, $e, 0.01, STREAM_CLIENT_CONNECT);
		if ($conn) {
			switch ($type) {
				case 'info':
					$dtime = 1500;
					break;
				case 'warning':
					$dtime = 3000;
					break;
				default:
					$dtime = 4000;
			}

			$json = array(
					'jsonrpc' => '2.0',
					'id' => 1,
					'method' => 'GUI.ShowNotification',
					'params' => array(
						'title' => 'AcePHP ' . $type,
						'message' => $note,
						'image' => 'http://kodi.wiki/images/c/c9/Logo.png',
						'displaytime' => $dtime
					)
			);
			$json = json_encode($json);
			$res = @stream_socket_sendto($conn, $json);
			fclose($conn);
		}
	}

	public function close() {
		if (!empty($this->stream)) {
			$this->stream->unregisterClientByName($this->getName());
			#error_log('unregister');
			unset($this->stream); // без этого лишняя ссылка оставалась в памяти и объект потока не уничтожался
		}
		is_resource($this->socket) and fclose($this->socket);
		//unset($this->socket);
		$this->finished = true;
	}

	public function __destruct() {
	}
}

