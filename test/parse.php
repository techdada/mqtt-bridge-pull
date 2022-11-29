<?php
$ch = curl_init();

curl_setopt_array($ch,array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_URL => 'http://Picoserver/index.fhtml',
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20
));
$html = curl_exec($this->ch);
if ( !$html ) {
    $this->error = curl_error($this->ch);
    curl_close($this->ch);
    return false;
}
echo "Connected to {$this->url}\n";
$dom = new DomDocument();
@$dom->loadHTMLFile("picoSolarLegacyDataON.html");
$dxsEntries = array(
    '33556736'=>'dc_input',
    '67109120'=>'ac_output',
    '251658754'=>'daily_yield',
    '251658753' => 'total_yield',
    '16780032'=>'operating_status'
);
$dxsEntries = array(
    14 => 'ac_output',
    56 => 'dc_output_1v',
    65 => 'dc_output_1a',
    82 => 'dc_output_2v',
    91 => 'dc_output_2a',
    26 => 'daily_yield',
    17 => 'total_yield',
    32 => 'operating_status',
);

$values = [];

$publis = [];

function filter($value) {
    $clean = str_replace(['&nbsp',"\n"," "],"",$value);
    if ($clean == 'xxx') return 0;
    return $clean;
}

function numOpStat($value) {
    if ($value == 'Einspeisen') return 1;
    return 0;
}


$elems = $dom->getElementsByTagName('td');
//print_r($dom->getC);
for ($i = 0 ; $i < sizeof($elems); $i++) {
    echo $i.': '.filter($elems[$i]->nodeValue)."\n";
    if (isset($dxsEntries[$i])) {
        $values[$dxsEntries[$i]] = filter($elems[$i]->nodeValue);
    }
}

$publis['ac_output'] = $values['ac_output'];
$publis['dc_output'] = ( $values['dc_output_1v']*$values['dc_output_1a'] ) + ( $values['dc_output_1v']*$values['dc_output_1a'] );
$publis['daily_yield'] = $values['daily_yield'];
$publis['totaly_yield'] = $values['total_yield'];
$publis['operating_status'] = numOpStat($values['operating_status']);

print_r($values);
echo "\n\n\nYXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXY\n\n\n";
print_r($publis);
echo "\n";
echo date("U");
