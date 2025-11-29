<?php
require __DIR__.'/misc.php';

$selectedPortfolio = $_POST['portfolio'];
$opType  = $_POST['type']  ?? '';
$path  = $_POST['path']  ?? '';
$qty   = (int)$_POST['qty'];
$price = (float)($_POST['price'] ?? '');

$php_errormsg = '';
$xml = new DOMDocument();
$xml->load(__DIR__ . '/../data/' . $selectedPortfolio) ?? sendError('../gestionalePortafoglio.php', "Nodo $selectedPortfolio non trovato");
$xml->preserveWhiteSpace=false;
$xml->formatOutput = true;
$xpath = new DOMXPath($xml);

if ($opType === 'cumulate') {
    
    $assets = $xpath->query('/portafoglio/assets')->item(0);
    if (!$assets) sendError('../gestionalePortafoglio.php', 'Nodo /portafoglio/assets non trovato');

    foreach($xpath->query('.//*[@ticker]', $assets) as $asset) {
        [$price, $qty] = getWac($asset, $xpath);
        if ($price < 0 || $qty < 0) sendError('../gestionalePortafoglio.php', 'Lista operazioni contiene dati incorretti');

        foreach ($xpath->query('operazioni/operazione', $asset) as $op) {
            $op->parentNode->removeChild($op);
        }

        $op = $xml->createElement('operazione');
        $day    = date('Ymd-H:i:s');
        $price6 = number_format((float)$price, 6, '.', '');

        if ($qty !== 0.0) {
            $op->appendChild($xml->createElement('data', $day));
            $op->appendChild($xml->createElement('quantita', (string)$qty));
            $op->appendChild($xml->createElement('prezzo', $price6));

            $xpath->query('operazioni', $asset)->item(0)->appendChild($op);
        }
    }

    $php_errormsg .= backupAndSave($xml);

} elseif (empty($path) || empty($opType)) {
    $php_errormsg .= 'WUT questo non dovrebbe succedere.';
} elseif (!is_numeric($qty) || $qty <= 0) {
    $php_errormsg .= 'Quantità non valida.';
} elseif (!is_numeric($price) || $price < 0) {
    $php_errormsg .= 'Prezzo non valido.';
} elseif (in_array($opType, ['buy', 'sell'])) {

    $index = $xpath->query('/portafoglio/assets')->item(0);
    if (!$index) sendError('../gestionalePortafoglio.php', 'Nodo /portafoglio/assets non trovato');

    $pathElements = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
    if (count($pathElements) === 0) sendError('../gestionalePortafoglio.php', 'Path vuoto');

    $ticker = array_pop($pathElements);
    foreach ($pathElements as $segRaw) {
        $seg = trim($segRaw);
        $lit = xpathLiteral(sanitize_id($seg));
        $node = $xpath->query("./bucket[normalize-space(nome) = $lit]", $index)->item(0);
        if (!$node) sendError('../gestionalePortafoglio.php', 'Bucket non trovato nel path: ' . $lit);
        $index = $node;
    }

    $litT = xpathLiteral($ticker);
    $itemNode = $xpath->query("./azione[@ticker=$litT] | ./etf[@ticker=$litT] | ./obbligazione[@ticker=$litT]", $index)->item(0);
    if (!$itemNode) sendError('../gestionalePortafoglio.php', 'Asset non trovato nel bucket: ' . $ticker);

    $opNode = $xpath->query('operazioni', $itemNode)->item(0);

    if($itemNode->nodeName === 'obbligazione') $qty = $qty % 1000 ? ($qty < 1000 ? $qty*1000 : 0) : $qty;
    if (!$qty) sendError('../gestionalePortafoglio.php', 'Se hai veramente più di un milione in bond hai sbagliato app.');

    $op = $xml->createElement('operazione');
    $price6 = number_format((float)$price, 6, '.', '');
    $day    = date('Ymd-H:i:s');

    $op->appendChild($xml->createElement('data', $day));
    if ($opType === 'buy') $op->appendChild($xml->createElement('quantita', (string)$qty));
    elseif ($opType === 'sell') {
        [$wac, $storedQty] = getWac($itemNode, $xpath);
        if ($storedQty < $qty) {
            sendError('../gestionalePortafoglio.php', 'Non puoi vendere ciò che non hai.');
            exit;
        }
        $op->appendChild($xml->createElement('quantita', '-' . (string)$qty));
    }
    $op->appendChild($xml->createElement('prezzo', $price6));

    $opNode->appendChild($op);

    $php_errormsg .= backupAndSave($xml, $selectedPortfolio);
}

header('Location: ../gestionalePortafoglio.php' . (empty($php_errormsg)? '' : '?err=') . rawurlencode($php_errormsg), true, 303);