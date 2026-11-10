#!/usr/bin/env php
<?php
use techdada\phpMQTT;
require_once __DIR__.'/lib/Properties.php';
require_once __DIR__.'/lib/phpMQTT.php';
require_once __DIR__.'/lib/BridgeLog.php';
require_once __DIR__.'/interfaces/poller.php';

ini_set('display_errors', 'stderr');
ini_set('log_errors', '1');
ini_set('error_log', '/dev/stderr');
error_reporting(E_ALL);

$options = getopt('', ['loop', 'config:', 'interval:']);
$config = $options['config'] ?? (getenv('HOME').'/.config/phpMQTTbridge/config.properties');
if (!is_readable($config)) {
    BridgeLog::error('Configuration is missing or unreadable: '.$config);
    exit(1);
}
Properties::init($config);
BridgeLog::$debug = strtolower(Properties::get('log_level', 'info')) === 'debug';
$interval = filter_var($options['interval'] ?? Properties::get('poll_interval', '120'), FILTER_VALIDATE_INT);
if ($interval === false || $interval < 1) {
    BridgeLog::error('Polling interval must be a positive integer in seconds');
    exit(1);
}

// Do not unlink: concurrent processes must always lock the same inode.
$lock = fopen(getenv('POLLPUSH_LOCK') ?: '/tmp/pollpush.lock', 'c');
if ($lock === false) {
    BridgeLog::error('Cannot open process lock');
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
    BridgeLog::info($wouldBlock ? 'Another bridge process is running' : 'Cannot acquire process lock');
    exit($wouldBlock ? 0 : 1);
}

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) { $running = false; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

function pollCycle(&$running) {
    $tls = Properties::get('broker_tls');
    $cafile = $tls ? Properties::get('cafile', '/etc/ssl/certs/ca-bundle.crt') : null;
    $mqtt = new phpMQTT(Properties::get('broker'), Properties::get('broker_port'), uniqid('pollpush'), $cafile, $tls);
    $successful = 0;
    $failed = 0;
    try {
        if (!$mqtt->connect(true, null, Properties::get('broker_user'), Properties::get('broker_pass'))) {
            throw new RuntimeException('MQTT connection failed: '.$mqtt->lastError);
        }
        for ($i = 1; $running && ($extractor = Properties::get('extractor'.$i)); $i++) {
            $parts = array_pad(explode(',', $extractor, 6), 6, '');
            list($topic, $class, $host, $user, $pass, $extra) = $parts;
            try {
                if (!in_array($class, ['picoSolar', 'picoSolarLegacy', 'weishauptWP'], true)) {
                    throw new RuntimeException('Unknown extractor class');
                }
                require_once __DIR__.'/extractors/'.$class.'.php';
                if ($extra !== '') BridgeLog::warning('Extractor options are not supported: '.$class);
                $poller = new $class($host, $user, $pass);
                if (!$poller->retrieve()) throw new RuntimeException($poller->getError());
                $data = $poller->getData();
                if (!$data) throw new RuntimeException('No readings returned');
            } catch (Throwable $error) {
                BridgeLog::error($class.' at '.$host.': '.$error->getMessage());
                $failed++;
                continue;
            }
            foreach ($data as $key => $value) {
                if (!$running) break;
                $target = str_replace('#', $key, $topic);
                if (!$mqtt->publish($target, $value, 0, true)) {
                    throw new RuntimeException('MQTT publish failed for '.$target.': '.$mqtt->lastError);
                }
                BridgeLog::debug('Published '.$target.' = '.$value);
                if (!$mqtt->proc(false)) throw new RuntimeException('MQTT processing failed: '.$mqtt->lastError);
            }
            if (!$running) break;
            $target = explode('/', $topic);
            $target[0] = 'tele';
            $target = str_replace('#', 'STATUS', implode('/', $target));
            $data['time'] = time();
            $payload = json_encode($data, JSON_THROW_ON_ERROR);
            if (!$mqtt->publish($target, $payload)) {
                throw new RuntimeException('MQTT telemetry publish failed: '.$mqtt->lastError);
            }
            $successful++;
        }
        BridgeLog::info('Poll completed: '.$successful.' devices published, '.$failed.' failed');
        return $failed === 0;
    } finally {
        if (is_resource($mqtt->getsocket())) $mqtt->close();
    }
}

BridgeLog::info('Bridge started'.(isset($options['loop']) ? '; interval '.$interval.' seconds' : '; single run'));
$status = 0;
do {
    $started = microtime(true);
    try {
        $status = pollCycle($running) ? 0 : 1;
    } catch (Throwable $error) {
        BridgeLog::error($error->getMessage());
        $status = 1;
    }
    if (!isset($options['loop'])) break;
    // Sleep in small steps so SIGTERM can end the service promptly. Never spin
    // when a poll takes longer than the configured interval.
    $next = max($started + $interval, microtime(true) + 1);
    while ($running && microtime(true) < $next) usleep(200000);
} while ($running);
BridgeLog::info('Bridge stopped');
flock($lock, LOCK_UN);
fclose($lock);
exit($running ? $status : 0);
