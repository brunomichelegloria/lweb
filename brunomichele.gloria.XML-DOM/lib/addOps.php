<?php
require __DIR__.'/misc.php';

$path  = $_POST['path']  ?? '';
$opType  = $_POST['type']  ?? '';
$qty   = (int)$_POST['qty'];
$price = (float)$_POST['price'];

$php_errormsg = '';
if (empty($path) || empty($opType)) {
    $php_errormsg = 'WUT questo non dovrebbe succedere.';
} elseif (!in_array($opType, ['buy', 'sell'])) {
    $php_errormsg = 'Dove sono le mie operazioni?';
} elseif (!is_numeric($qty) || $qty <= 0) {
    $php_errormsg = 'Quantità non valida.';
} elseif (!is_numeric($price) || $price < 0) {
    $php_errormsg = 'Prezzo non valido.';
} else {
    $xml = new DOMDocument();
    $xml->load('../data/portafoglio.xml');
    $xml->preserveWhiteSpace=false;
    $xml->formatOutput = true;

    $xpath = new DOMXPath($xml);
    $index = $xpath->query('/portafoglio/assets')->item(0);
    if (!$index) die('Nodo /portafoglio/assets non trovato');

    $pathElements = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
    if (count($pathElements) === 0) die('Path vuoto');

    $ticker = array_pop($pathElements);
    foreach ($pathElements as $segRaw) {
        $seg = trim($segRaw);
        $lit = xpathLiteral(sanitize_id($seg));
        $node = $xpath->query("./bucket[normalize-space(nome) = $lit]", $index)->item(0);
        if (!$node) die('Bucket non trovato nel path: ' . $lit);
        $index = $node;
    }

    $litT = xpathLiteral($ticker);
    $itemNode = $xpath->query("./azione[@ticker=$litT] | ./etf[@ticker=$litT] | ./obbligazione[@ticker=$litT]", $index)->item(0);
    if (!$itemNode) die('Asset non trovato nel bucket: ' . $ticker);

    $opNode = $xpath->query('operazioni', $itemNode)->item(0);

    $op = $xml->createElement('operazione');
    $price6 = number_format((float)$price, 6, '.', '');
    $day    = date('Ymd-H:i:s');

    $op->appendChild($xml->createElement('data', $day));
    if ($opType === 'buy') $op->appendChild($xml->createElement('quantita', (string)$qty));
    elseif ($opType === 'sell') $op->appendChild($xml->createElement('quantita', '-' . (string)$qty));
    $op->appendChild($xml->createElement('prezzo', $price6));

    $opNode->appendChild($op);
}

libxml_use_internal_errors(true);

if (!$xml->validate()) die('Errore validazione DOM');

$final = __DIR__ . '/../data/portafoglio.xml';
$tmp   = $final . '.tmp';
$bak   = __DIR__ . '/../data/portafoglio.' . date('Ymd-His') . '.bak';

if ($xml->save($tmp) === false) die('Errore salvataggio temp');

if (!savePretty($tmp)) {
    unlink($tmp);
    die('Errore rettifica');
}

$check = new DOMDocument();
if (!$check->load($tmp)) {
    unlink($tmp);
    die('Temp non ben formato');
}
if (!$check->validate()) {
    unlink($tmp);
    die('Temp non conforme DTD');
}

copy($final, $bak);
if (!rename($tmp, $final)) {
    unlink($tmp);
    die('Rename fallito');
}

header('Location: ../index.php');