<?php


class weishauptWP implements poller {
	protected $iphost;
	protected $user;
	protected $password;
	protected $data;
	protected $publishto;
	protected $curlobj;
	protected $url;
	protected $ch;
	protected $protocol = 'http';
		
	//get configuration
	public function __construct(
		$iphost,$user='',$password='',
		$options = array(
		)
	) {
		$this->iphost = $iphost;
		$this->user = $user;
		$this->password = $password;
		$this->url = $this->protocol."://{$this->iphost}/http/index/j_operating_custom.html";
	}
	
	protected function _normalizeDate(&$datum) {
		$date = explode(' ',$datum);
		$time = $date[1];
		$date = $date[0];
		
		$dmy = explode('.',$date);
		foreach ($dmy as &$d) {
		  $d = intval($d);
		  if ($d < 10)
			$d='0'.$d;
		}

		$hm = explode(':',$time);
		foreach ($hm as &$t) {
			$t = intval($t);
			if ($t < 10)
				$t = '0'.$t;
		}

		$date = join('.',$dmy);
		$time = join(':',$hm);
		$datum = $date.' '.$time;
		return mktime($hm[0],$hm[1],0,$dmy[1],$dmy[0],$dmy[2]);
	}
	
	protected function _normalizeName($name) {
		return strtolower(str_replace(' ','_',preg_replace('/[^a-zA-Z\ 0-9]/','',$name)));
	}
	
	protected function _authHeader() {
		$auth = base64_encode($this->user.':'.stripslashes($this->password));
		$opts = [
			'http'=> [
				'method'=> 'GET',
				'header'=> "Accept-Language: de-DE,de,en-US,en\r\n".
				"Authorization: Basic $auth\r\n"
				]
		];
		$sc = stream_context_create($opts);
		return $sc;
	}

	public function retrieve() {
		
	//connect to url
		$json = file_get_contents($this->url,false,$this->_authHeader());
		 
		$this->data = json_decode($json,true);
		$json = preg_replace('/<!--tagparser.*-->/','',$json);
		$this->data = @json_decode($json,true);
		var_dump($this->data);
		if (!is_array($this->data) && !is_object($this->data)) {
			//no valid data
			$this->error = 'Invalid JSON: '.$json;
			echo 'Letzter Fehler: ', $json_errors[json_last_error()], PHP_EOL, PHP_EOL;
			return false;
		}
		
		//distribute array to mqtt single topics
		//$this->publishto = $this->data['dxsEntries'];
		
 		foreach ($this->data as $key=>$value) {
			$key = $this->_normalizeName($key);
			$skip = false;
			
			switch ($key) {
				case 'date': $date = $value.' '; 
					break;
				case 'time': $date .= $value; 
					$this->publishto['timestamp'] = $this->_normalizeDate($date);
					break;
				case 'r1':
					$this->publishto['aussentemperatur'] = $value;
					break;
				case 'r2':
					$this->publishto['ruecklauf'] = $value;
					break;
				case 'r2_solltemperatur':
					$this->publishto['ruecklauf_solltemperatur'] = $value;
					break;
				case 'r3':
					$this->publishto['warmwasser'] = $value;
					break;
				case 'r3_solltemperatur':
					$this->publishto['warmwasser_solltemperatur'] = $value;
					break;
				case 'r9':
					$this->publishto['vorlauftemperatur2'] = $value;
					break;
				case 'r13':
					$this->publishto['vorratstank'] = $value;
					break;
				case 'heizunganforderung1':
					$this->publishto['heizunganforderung'] = $value;
					$skip = true;
					break;
				case 'm1':
					$this->publishto['verdichter'] = $value;
					break;
				case 'm2_m11':
					$this->publishto['luefter'] = $value;
					break;
				case 'e10':
					$this->publishto['zusatzheizung'] = $value;
					break;
				case 'm13':
					$this->publishto['heizungsumwaelzpumpe'] = $value;
					break;
				case 'm18':
					$this->publishto['warmwasserladepumpe'] = $value;
					break;
				case 'm16':
					$this->publishto['zusatzumwaelzpumpe'] = $value;
					break;
				case 'e9':
					$this->publishto['tauchheizkoerper'] = $value;
					break;
				case 'warmwasseranforderung': 
				case 'opmode':
				case 'leistungsstufe':
					//nothing
					break;
				default: 
					$skip = true;
				break;
			}
			if ($skip) continue;
			$this->publishto[$key] = $value;
		}
 		return true;
		
	}
	
	public function getData() {
	//return all data to pollpush
		if (!$this->publishto) {
			if (!$this->retrieve()) {
				echo $this->error."\n";
				return null;
			}			
		}
		return $this->publishto;
	}
	
	public function __destruct() {
		
	}
}