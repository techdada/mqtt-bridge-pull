#!/usr/bin/env php
<?php
echo "load libraries\n";
// config
$path = dirname(realpath($argv[0]));
use techdada\phpMQTT;
require_once $path.'/lib/Properties.php';
require_once $path.'/lib/phpMQTT.php';
require_once $path.'/interfaces/poller.php';

$properties_file = $_SERVER['HOME'].'/.config/phpMQTTbridge/config.properties';
if (!file_exists($properties_file)) {
	file_put_contents($properties_file,"broker=broker.host.com\nbroker_tls=tlsv1.2\nbroker_port=8883\nbroker_user=buser\nbroker_pass=bpass\nextractor1=sensors/photovoltaics/inverter1/#,picoSolar,192.168.0.102");
	echo "No config file found!\nInitial file created at $properties_file\nPlease review and start again";
	exit;
} else {
	echo "load config from $properties_file\n";
}

Properties::init($properties_file);

if (file_exists('/tmp/pollpush.log')) {
  rename('/tmp/pollpush.log','/tmp/pollpush.log-'.date('ymdHi'));
}

if (file_exists('/tmp/pollpush.pid')) {
  $pid = file_get_contents('/tmp/pollpush.pid');
  if (posix_getpgid($pid)) {
    posix_kill($pid,SIGTERM);
  }
}
file_put_contents('/tmp/pollpush.pid',getmypid());


$tls = Properties::get('broker_tls');
if ($tls) {
	$cafile = Properties::get('cafile');
	if (!$cafile) $cafile = '/etc/ssl/certs/ca-bundle.crt';
}
$mqttclient = new phpMQTT(Properties::get('broker'), Properties::get('broker_port'), uniqid('pollpush'), $cafile, $tls);
$mqttclient->connect_auto(true,NULL,Properties::get('broker_user'),Properties::get('broker_pass'));

$extractors = array();
$p_count = 1;
while ($extractor = Properties::get('extractor'.$p_count)) {
	//$mqttclient->proc();
	@list($topic,$class,$host,$user,$pass,$options) = explode(',',$extractor);
	echo "processing $topic with $class at $host $user \n";
	require_once $path.'/extractors/'.$class.'.php';
	if ($options) echo "options not yet implemented\n";
	$obj = new $class($host,$user,$pass);
	$obj->retrieve();
	foreach ($obj->getData() as $key=>$value) {
		$t = str_replace('#',$key,$topic);
		echo "Publish $t = $value\n";
		$mqttclient->publish($t,$value,0,1);
		$mqttclient->proc();
	}
	// also publish to telemetry:
	$t = explode('/',$topic);
	$t[0] = 'tele';
	$t = join('/',$t);
	$t = str_replace('#','STATUS',$t);
	$data = $obj->getData();
	$data['time'] = time();
	echo "Published to ";
	$mqttclient->publish($t,json_encode($data));
	echo "$t \n";
	$p_count++;
}
echo "finished\n";
unlink('/tmp/pollpush.pid');
