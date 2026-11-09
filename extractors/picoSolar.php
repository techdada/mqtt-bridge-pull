<?php


class picoSolar implements poller {
	protected $iphost;
	protected $user;
	protected $password;
	protected $data;
	protected $publishto;
	protected $curlobj;
	protected $url;
	protected $ch;
	protected $protocol = 'http';
	
	protected $dxsEntries = array(
		'33556736'=>'dc_input',
		'67109120'=>'ac_output',
		'251658754'=>'daily_yield',
		'251658753' => 'total_yield',
		'16780032'=>'operating_status'
	);
	
	
	//get configuration
	public function __construct(
		$iphost,$user='',$password='',
		$options = array(
		)
	) {
		$this->iphost = $iphost;
		$this->user = $user;
		$this->password = $password;
		if ( isset($options['dxsEntries'])) $this->dxsEntries = $options['dsxEntries'];
		if ( isset($options['protocol'])) $this->protocol = $options['protocol'];
		$this->url = $this->protocol."://{$this->iphost}/api/dxs.json?dxsEntries=";
		$this->url.= join('&dxsEntries=',array_keys($this->dxsEntries));
	}

	public function retrieve() {
	//connect to url
		$this->ch = curl_init();
		
		curl_setopt_array($this->ch,array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_URL => $this->url,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 20
		));
		$json = curl_exec($this->ch);
		if ( !$json ) {
			$this->error = curl_error($this->ch);
			curl_close($this->ch);
			return false;
		}
		echo "Connected to {$this->url}\n";
		
		// Errordefinitions
		$constants = get_defined_constants(true);
		$json_errors = array();
		foreach ($constants["json"] as $name => $value) {
			if (!strncmp($name, "JSON_ERROR_", 11)) {
				$json_errors[$value] = $name;
			}
		}

		echo "curl close\n";
		curl_close($this->ch);
	
		 
		// $json = file_get_contents($this->url);
		$this->data = json_decode($json,true);
	
		if (!is_array($this->data)) {
			$this->error = 'Invalid JSON: '.$json;
			echo 'Letzter Fehler: ', $json_errors[json_last_error()], PHP_EOL, PHP_EOL;
			return false;
		}

		//distribute array to mqtt single topics
		//$this->publishto = $this->data['dxsEntries'];
		if (!isset($this->data['dxsEntries'])) {
			echo "no dxsEntries found\n";
			return;
		}
 		foreach ($this->data['dxsEntries'] as $line) {
			//var_dump($line);
			$this->publishto[$this->_namefor($line['dxsId'])] = $line['value'];
		}
 		return true;
		
	}
	
	protected function _namefor($dxsId) {
		if (isset($this->dxsEntries[$dxsId])) return $this->dxsEntries[$dxsId];
		return "$dxsId unknown";
	}
	
	public function getData() {
	//return all data to pollpush
		if (!$this->publishto) {
			if (!$this->retrieve()) {
				echo $this->error."\n";
				return [];
			}			
		}
		return $this->publishto;
	}
	
	public function __destruct() {
		
	}
}
