<?php


class picoSolarLegacy implements poller {
	protected $iphost;
	protected $user;
	protected $password;
	protected $data = ['dxsEntries' => [] ];
	protected $publishto;
	protected $curlobj;
	protected $url;
	protected $ch;
	protected $protocol = 'http';

	protected $dxsEntries = array(
		14 => 'ac_output',
		56 => 'dc_output_1v',
		65 => 'dc_output_1a',
		82 => 'dc_output_2v',
		91 => 'dc_output_2a',
		26 => 'daily_yield',
		17 => 'total_yield',
		32 => 'operating_status',
	);

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
		$this->url = $this->protocol."://{$this->iphost}/index.fhtml?".date('U');
	}

	public function retrieve() {
	//connect to url

		$html = file_get_contents($this->url,false,$this->_authHeader());
		if ( !$html ) {
			$this->error = 'Could not load data';
		}
		echo "Connected to {$this->url}\n";
		$dom = new DOMDocument();
		@$dom->loadHTML($html);

		$elems = $dom->getElementsByTagName('td');
		//print_r($dom->getC);
		for ($i = 0 ; $i < sizeof($elems); $i++) {
			//echo $i.': '.$this->_filter($elems[$i]->nodeValue)."\n";
			if (isset($this->dxsEntries[$i])) {
				$this->data['dxsEntries'][$this->dxsEntries[$i]] = $this->_filter($elems[$i]->nodeValue);
			}
		}

		//distribute array to mqtt single topics
		//$this->publishto = $this->data['dxsEntries'];
		if (!isset($this->data['dxsEntries'])) {
			echo "no dxsEntries found\n";
			return;
		}
		$this->publishto['ac_output'] = $this->data['dxsEntries']['ac_output'];
		$this->publishto['dc_output'] = (
			floatval($this->data['dxsEntries']['dc_output_1v']) * floatval($this->data['dxsEntries']['dc_output_1a']) )
			+ ( floatval($this->data['dxsEntries']['dc_output_1v']) * floatval($this->data['dxsEntries']['dc_output_1a'])
		);
		$this->publishto['daily_yield'] = $this->data['dxsEntries']['daily_yield'];
		$this->publishto['total_yield'] = $this->data['dxsEntries']['total_yield'];
		$this->publishto['operating_status'] = $this->_numOpStat($this->data['dxsEntries']['operating_status']);
 		return true;

	}

	protected function _namefor($dxsId) {
		if (isset($this->dxsEntries[$dxsId])) return $this->dxsEntries[$dxsId];
		return "$dxsId unknown";
	}

	protected function _filter($value) {
		$clean = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
		$clean = str_replace('&nbsp',"",$clean);
		$clean = str_replace(["xxx","x x x","X X X", "XXX"],"0",$clean);
		return addslashes($clean);
	}

	protected function _numOpStat($value) {
		if ($value == 'Einspeisen') return 1;
		return 0;
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
