<?php
require_once __DIR__.'/misc.php';

if (!array_key_exists('path', $_POST)) {
    sendError('../gestionalePortafoglio.php.php', 'Richiesta senza path ricevuta. Non mandate pacchetti a caso pls.');
}
$selectedPortfolio = $_POST['portfolio'];
$path = $_POST['path'];

$php_error_msg = '';
$ops = $_POST['assets'] ?? [];
$new = $_POST['new'] ?? [];
$remove = $_POST['remove'] ?? [];
$changed = $_POST['changed'] ?? [];

//--- SET-UP ---
$xml = new DOMDocument();
$xml->preserveWhiteSpace=false;
$xml->formatOutput = true;
if (!$xml->load(__DIR__ . '/../data/' . $selectedPortfolio)) sendError('../gestionalePortafoglio.php', "Nodo $selectedPortfolio non trovato");
$xp = new DOMXPath($xml);
$root = $xp->query('/portafoglio/assets');
$node = $root;
if ($path !== '') {
    $pathElements = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
    foreach ($pathElements as $seg) {
        try {
            $lit = xpathLiteral($seg);
            echo "<!-- Looking for bucket with nome=$lit -->\n"; //DEBUG
            $node = $xp->query("./bucket[normalize-space(nome) = $lit]", $node->item(0));

        } catch(Exeption $e) {
            sendError('../gestionalePortafoglio.php', 'Il path: "' . $path . '" non può essere risolto. Caught exception:' . $e->getMessage());
        }
    }
} else {
    // --- PORTFOLIO INFO ---
    $infoNode = $xp->query('/portafoglio/informazioni')->item(0);
    $liqNode = $xp->query('/portafoglio/liquidita')->item(0);
    if (isset($changed['info']) && (!$infoNode || !$liqNode)) {
        sendError('../gestionalePortafoglio.php', 'Nodo /portafoglio/informazioni o /portafoglio/liquidita non trovato');;
    }
    foreach ($changed['info'] as $tagName => $isChanged) {
        switch ($tagName) {
            case 'liquidita':
                if ($isChanged) {
                    $val = $ops['info']['liquidita'];
                    $xp->query('totale', $liqNode)->item(0)->textContent = $val;
                }
                break;
            case 'liq-target':
                if ($isChanged) {
                    $val = $ops['info']['liq-target'];
                    $liqTargetNode = $xp->query('target', $liqNode);
                    if (!$liqTargetNode) {
                        $newNode = $xml->createElement('target', $val);
                        $liqNode->appendChild($newNode);
                    } else {
                        $liqTargetNode->item(0)->textContent = $val;
                    }
                }
                break;
            case 'commissione':
            case 'tolleranza':
                if ($isChanged) {
                    $val = $ops['info'][$tagName];
                    $infoNode->setAttribute($tagName, $val);
                }
                break;
            case 'valuta':
                $portfolio = $xp->query('/portafoglio')->item(0);
                if ($isChanged) {
                    $val = $ops['info']['valuta'];
                    $portfolio->setAttribute('valuta', $val);
                    $liqNode->setAttribute('valuta', $val);
                }
                break;
            default:
                $php_error_msg .= "Tag sconosciuto $tagName in /portafoglio/informazioni, modifica non effettuata.\n";
        }
    }
    unset($changed['info']);
}
$root = $node;

// --- REMOVE ITEM ---
foreach($remove as $id => $flag) {
    if (!$flag) continue;

    $lit = xpathLiteral($id);
    $asset = $xp->query("./azione[@ticker=$lit] | ./etf[@ticker=$lit] | ./obbligazione[@ticker=$lit] | ./bucket[normalize-space(nome)=$lit]", $root->item(0))->item(0);
    if (!!isset($asset)) {
        $asset->parentNode->removeChild($asset);
        unset($changed[$lit]);
    } else $php_error_msg .= "Impossibile rimuovere l'asset $id: item non trovato.\n";
}

// --- NEW ITEM ---
foreach ($new as $id => $flag) {
    if (!$flag) continue;

    $type = $ops[$id]['tipo'];
    $newAsset = $xml->createElement($type);
    switch ($type) {
        case 'obbligazione':
            if (empty($ops[$id]['ticker'])) {
                $php_error_msg .= "Impossibile creare l'asset $id senza un ticker";
                continue 2;
            }
            $newAsset->setAttribute('ticker', $ops[$id]['ticker']);
            $newAsset->setAttribute('tradeStep', '1000');
            if (!empty($ops[$id]['nome'])) $newAsset->appendChild($xml->createElement('nome', $ops[$id]['nome']));
            if (!empty($ops[$id]['target'])) $newAsset->appendChild($xml->createElement('target', $ops[$id]['target']));
            if (!empty($ops[$id]['cedola'])) $newAsset->appendChild($xml->createElement('cedola', $ops[$id]['cedola']));
            if (!empty($ops[$id]['fcedola'])) $newAsset->appendChild($xml->createElement('frequenza_cedola', $ops[$id]['fcedola']));
            if (!empty($ops[$id]['scadenza'])) $newAsset->appendChild($xml->createElement('scadenza', $ops[$id]['scadenza']));
            $newAsset->appendChild($xml->createElement('operazioni'));
            if (!empty($ops[$id]['valuta'])) $newAsset->setAttribute('valuta', $ops[$id]['valuta']);
            if (!empty($ops[$id]['tax-rate'])) $newAsset->setAttribute('tax-rate', $ops[$id]['tax-rate']);
            break;
        case 'etf':
        case 'azione':
            if (empty($ops[$id]['ticker'])) {
                $php_error_msg .= "Impossibile creare l'asset $id senza un ticker\n";
                continue 2;
            }
            $newAsset->setAttribute('ticker', $ops[$id]['ticker']);
            if (!empty($ops[$id]['nome'])) $newAsset->appendChild($xml->createElement('nome', $ops[$id]['nome']));
            if (!empty($ops[$id]['target'])) $newAsset->appendChild($xml->createElement('target', $ops[$id]['target']));
            $newAsset->appendChild($xml->createElement('operazioni'));
            if (!empty($ops[$id]['valuta'])) $newAsset->setAttribute('valuta', $ops[$id]['valuta']);
            if (!empty($ops[$id]['tax-rate'])) $newAsset->setAttribute('tax-rate', $ops[$id]['tax-rate']);
            break;
        case 'bucket':
            if (empty($ops[$id]['nome'])) {
                $php_error_msg .= "Impossibile creare il bucket $id senza un nome.\n";
                continue 2;
            }
            $newAsset->appendChild($xml->createElement('nome', sanitize_id($ops[$id]['nome'])));
            if (!empty($ops[$id]['target'])) $newAsset->appendChild($xml->createElement('target', $ops[$id]['target']));
            if (!empty($ops[$id]['valuta'])) $newAsset->setAttribute('valuta', $ops[$id]['valuta']);
            break;
        default:
            sendError('Tentativo di creazione di un tipo si asset sconosciuto, operazioni annullate');
    }
    $root->item(0)->appendChild($newAsset);
    echo '<pre>', htmlspecialchars($newAsset->C14N()), '</pre>'; //DEBUG
}


// --- CHANGE ITEM ---

$DTD_ORDER = [
    'azione'        => ['nome','target','operazioni'],
    'etf'           => ['nome','target','operazioni'],
    'obbligazione'  => ['nome','target','cedola','frequenza_cedola','scadenza','operazioni'],
    'bucket'        => ['nome','target'],
    'liquidita'     => ['totale','target'],
];

function insertChildInDTDOrder(DOMElement $parent, DOMElement $child, DOMXPath $xp, array $DTD_ORDER): DOMElement {
    $ptype = $parent->tagName;
    if (!isset($DTD_ORDER[$ptype])) {
        $parent->appendChild($child);
        return $child;
    }

    $order = $DTD_ORDER[$ptype];
    $idx   = array_search($child->tagName, $order, true);

    if ($idx === false) {
        $parent->appendChild($child);
        return $child;
    }

    for ($i = $idx + 1; $i < count($order); $i++) {
        $nextTag = $order[$i];
        $next = $xp->query("./$nextTag", $parent)->item(0);
        if ($next) {
            $parent->insertBefore($child, $next);
            return $child;
        }
    }

    $parent->appendChild($child);
    return $child;
}

foreach ($changed as $id => $flag) {
    if (!$flag) continue;

    $lit = xpathLiteral($id);
    $asset = $xp->query(
        "./azione[@ticker=$lit] | ./etf[@ticker=$lit] | ./obbligazione[@ticker=$lit] | ./bucket[normalize-space(nome)=$lit]",
        $root->item(0)
    )->item(0);

    if (!$asset) {
        $php_error_msg .= "Asset da modificare \"$id\" non trovato.\n";
        continue;
    }

    foreach ($ops[$id] as $tagName => $val) {

        if (in_array($tagName, ['valuta', 'taxRate', 'tradeStep'], true)) {
            $asset->setAttribute($tagName, $val);
            continue;
        }
        if ($tagName === 'ticker') {
            $asset->setAttribute('ticker', sanitize_id($val));
            continue;
        }

        if ($asset->tagName === 'informazioni') {
            $php_error_msg .= "Impossibile aggiungere elementi a <informazioni> (DTD EMPTY).\n";
            continue;
        }

        $tagNodeList = $xp->query("./$tagName", $asset);
        if ($tagNodeList->length === 0) {
            if ($tagName === 'nome' && $asset->tagName === 'bucket') {
                $val = sanitize_id($val);
            }
            $newNode = $xml->createElement($tagName, $val);
            insertChildInDTDOrder($asset, $newNode, $xp, $DTD_ORDER);
        } else {
            $node = $tagNodeList->item(0);
            if ($tagName === 'nome' && $asset->tagName === 'bucket') {
                $val = sanitize_id($val);
            }
            $node->textContent = $val;
        }
    }
}

$php_error_msg .= backupAndSave($xml, $selectedPortfolio);
header('Location: ../gestionalePortafoglio.php' . (empty($php_error_msg)? '' : '?err=') . rawurlencode($php_error_msg), true, 303);