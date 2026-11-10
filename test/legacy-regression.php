<?php
// Offline regression checks: php test/legacy-regression.php
require_once __DIR__.'/../interfaces/poller.php';
require_once __DIR__.'/../extractors/picoSolarLegacy.php';

class FixtureLegacy extends picoSolarLegacy {
    public function __construct($iphost, $user = '', $password = '', $options = []) {
        $this->url = $iphost;
    }
}

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['ON' => 1, 'OFF' => 0] as $state => $expected) {
    $poller = new FixtureLegacy(__DIR__.'/picoSolarLegacyData'.$state.'.html');
    check($poller->retrieve() === true, 'Fixture retrieval failed');
    check($poller->getData()['operating_status'] === $expected, 'Wrong operating status: '.$state);
}

$file = tempnam(sys_get_temp_dir(), 'legacy-test-');
try {
    $dom = new DOMDocument();
    @$dom->loadHTMLFile(__DIR__.'/picoSolarLegacyDataON.html');
    $cells = $dom->getElementsByTagName('td');
    foreach ([56 => '300', 65 => '2', 82 => '200', 91 => '1'] as $index => $value) {
        $cells->item($index)->nodeValue = $value;
    }
    file_put_contents($file, $dom->saveHTML());
    $poller = new FixtureLegacy($file);
    check($poller->retrieve() === true, 'Numeric fixture retrieval failed');
    check($poller->getData()['dc_output'] === 800.0, 'Both DC strings must contribute');

    file_put_contents($file, '');
    check($poller->retrieve() === false, 'Empty response must fail');
    check($poller->getData() === [], 'Failed retrieval must not expose old readings');

    file_put_contents($file, '<html><body>Service unavailable</body></html>');
    check($poller->retrieve() === false, 'Missing fields must fail');
    check($poller->getData() === [], 'Invalid response must not produce readings');

    // Simulate an HTTP/transport failure without contacting a device.
    $poller = new FixtureLegacy($file.'/missing');
    check(@$poller->retrieve() === false, 'Transport failure must fail cleanly');
} finally {
    unlink($file);
}
echo "Legacy regression checks passed\n";
