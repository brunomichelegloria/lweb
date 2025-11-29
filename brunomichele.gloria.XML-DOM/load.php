<?php

if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
require_once __DIR__.'/lib/fetchPrice.php';
require_once __DIR__.'/lib/fetchBondInvesting.php';
require_once __DIR__.'/lib/fetchBondBI.php';
require_once __DIR__.'/lib/rebalance.php';
require_once __DIR__.'/lib/misc.php';
date_default_timezone_set('Europe/Riga');

ini_set('display_errors', '0');                  // niente errori in pagina
ini_set('log_errors', '1');                      // abilita logging
ini_set('error_log', __DIR__ . '/php-error.log'); // file di log nel progetto
error_reporting(E_ALL);

function renderChildren(DOMElement $parent, DOMXPath $xp, callable &$getPrice, float $liquidita, string $parentPath, string $defaultCurrency): array {
    $children = $xp->query('azione | etf | obbligazione | bucket', $parent);
    $items = [];
    $sum = 0.0;


    foreach ($children as $child) {
        $type = strtolower($child->nodeName);
        $nameNode   = $xp->query('nome',   $child)->item(0);
        $targetNode = $xp->query('target', $child)->item(0);
        $name      = $nameNode   ? trim($nameNode->textContent)   : 'Bucket';
        $targetRaw = $targetNode ? trim($targetNode->textContent) : '';
        $included = $targetRaw === ''? false : true;
		$currency  = $child->hasAttribute('valuta') ? $child->getAttribute('valuta') : $defaultCurrency;

        if ($type === 'bucket') {
            // ========= BUCKET =========
            $childPath = $parentPath . '/' . sanitize_id($name);
            $colore = (substr_count($childPath, '/') % 2 === 0) ? 'bucket-details-even' : 'bucket-details-odd';
            [$innerHtml, $innerSum] = renderChildren($child, $xp, $getPrice, 0.00, $childPath, $currency);
            if ($included) $sum += $innerSum;

			if (isset($_SESSION['rebalanceReport']['ops'])) {
				$includedInRebalance = false;

				foreach ($_SESSION['rebalanceReport']['ops'] as $rebalancePath => $rebalanceData) {
					if (str_contains($rebalancePath, $childPath . '/')) {
						$includedInRebalance = true;
						break;
					}
				}

				if ($includedInRebalance) {
					$deltaText = "❌\nCorrezioni necessarie.";
				} else {
					$deltaText = '✅';
				}
			} else {
				$deltaText = '-';
			}

            $items[] = [
                'type' => 'bucket',
                'value' => $innerSum,
                'included' => $included,
                'rowOpen' => '<tr class="bucket-row" data-type="bucket" '
                        .  ' data-nome="' . htmlspecialchars($name) . '"'
						            .  ' data-valuta="' . htmlspecialchars($currency) . '"'
                        .  ' data-target-raw="' . htmlspecialchars($targetRaw) . '" ' 
                        .  ' data-path="' . $childPath . '">'
                        .    '<td class="edit-cell"><button type="button" id="' . sanitize_id(htmlspecialchars($childPath)) . '-button" class="edit-button" data-open-assets>🛠️</button></td>' //ID potenzialmente NON univoco
                                                                //SOLUZIONE TEMPORANEA ⬆️
                        .    '<td class="tipo">bucket</td>'
                        .    '<td class="nome">' . htmlspecialchars($name) . '</td>'
                        .    '<td class="quantita">-</td>'
                        .    '<td class="prezzo">-</td>'
                        .    '<td class="valore">' . ($innerSum > 0 ? htmlspecialchars(number_format($innerSum, 2, ',', '.')) : '-') . '</td>'
                        .    '<td class="attuale">',
                'rowClose' => '</td>'
                        .    '<td class="target">' . ($targetRaw === '' ? '-' : htmlspecialchars($targetRaw)) . '</td>'
                        .    '<td class="delta-qty">' . htmlspecialchars($deltaText) . '</td>'
                        .    '<td class="toggle-details-cell"><button type="button" class="toggle-details-button">&#9664;</button></td>'
                        .  '</tr>'
                        . '<tr class="bucket-details ' . $colore . '">'
                        . '<td colspan="9"><table class="bucket-table"><tbody>'
                        .   $innerHtml
                        . '</tbody></table></td>' 
                        . '</tr>'
            ];
        
        } else if (in_array($type, ['azione', 'etf', 'obbligazione'])) {
            // ========= ASSET =========
            $ticker     = $child->getAttribute('ticker');
            $childPath = $parentPath . '/' . $ticker;


            // WAC, qty corrente
            [$avgCost, $qty] = getWAC($child, $xp);
            
            $quoted = $getPrice($ticker, $type);
            $unitPrice = (is_numeric($quoted) ? (float)$quoted : 0.0);

            $value = ($type === 'obbligazione') ? $qty * $unitPrice / 100 : $qty * $unitPrice;
            if ($included) $sum += $value;

            $taxRateRow = $child->hasAttribute('taxRate') ? $child->getAttribute('taxRate') : '26';
            $tradeStep  = $child->hasAttribute('tradeStep') ? (int)$child->getAttribute('tradeStep') : 0;


            $obbData = '';
            if ($type === 'obbligazione') {
				$cedolaNode = $xp->query('cedola',   $child)->item(0);
				$fCedolaNode = $xp->query('frequenza_cedola',   $child)->item(0);
				$scadenzaNode = $xp->query('scadenza',   $child)->item(0);
				$cedola = $cedolaNode ? $cedolaNode->textContent : '';
				$fCedola = $fCedolaNode ? $fCedolaNode->textContent : '';
				$scadenza = $scadenzaNode ? $scadenzaNode->textContent : '';
				$obbData = ' data-cedola="' . htmlspecialchars($cedola) . '" data-fcedola="' . htmlspecialchars($fCedola) . '" data-scadenza="' . htmlspecialchars($scadenza) . '"';
            }

			if (isset($_SESSION['rebalanceReport']['ops'])) {
				[$qtyDelta, $note] = $_SESSION['rebalanceReport']['ops'][$childPath] ?? [0, ''];
				$qtyDelta = (int)$qtyDelta;
				if ($qtyDelta > 0) {
					$deltaText = "❌BUY: " . number_format($qtyDelta, 0, '.', '');
				} elseif ($qtyDelta < 0) {
					$deltaText = "❌SELL: " . number_format(-$qtyDelta, 0, '.', '');
				} else {
					$deltaText = '✅';
				}
				$deltaText .= $note ? "\n(" . trim($note) . ")" : '';
			} else {
				$deltaText = '-';
			}

            $items[] = [
                'type' => $type,
                'value' => $value,
                'included' => $included,
                'rowOpen' => '<tr class="asset-row" data-type="' . htmlspecialchars($type) . '"'
                        .  ' data-path="' . htmlspecialchars($childPath) . '"'
                        .  ' data-nome="' . htmlspecialchars($name) . '"'
                        .  ' data-ticker="' . htmlspecialchars($ticker) . '"'
                        .  ' data-quantita="' . htmlspecialchars($qty) . '"'
                        .  ' data-valuta="' . htmlspecialchars($currency) . '"'
                        .  ' data-costo="' . htmlspecialchars(number_format($avgCost, 6, '.', '')) . '"'
                        .  ' data-tax-rate="' . htmlspecialchars($taxRateRow) . '"'
                        .  ' data-trade-step="' . htmlspecialchars($tradeStep) . '"'
                        .  ' data-prezzo="' . htmlspecialchars(number_format($unitPrice, 6, '.', '')) . '"'
                        .  ' data-target-raw="' . htmlspecialchars($targetRaw) . '"'
						. ' data-deltatext="' . htmlspecialchars($deltaText) . '"'
                        . $obbData . '>'
                            .   '<td class="edit-cell"><button type="button" id="' . sanitize_id(htmlspecialchars($childPath)) . '-button" class="edit-button" data-role="ops-gear">⚙️</button></td>' //ID potenzialmente NON univoco
                                                                //SOLUZIONE TEMPORANEA ⬆️
                            .    '<td class="tipo">' . htmlspecialchars($type) . '</td>'
                            .    '<td class="nome">' . htmlspecialchars($name) . '</td>'
                            .    '<td class="quantita">' . htmlspecialchars($qty) . '</td>'
                            .    '<td class="prezzo">' . ($unitPrice > 0 ? htmlspecialchars(number_format($unitPrice, 2, ',', '.')) : '-') . '</td>'
                            .    '<td class="valore">' . ($value > 0 ? htmlspecialchars(number_format($value, 2, ',', '.')) : '-') . '</td>'
                            .    '<td class="attuale">',
                'rowClose' =>    '</td>'
                            .    '<td class="target">' . ($targetRaw === '' ? '-' : htmlspecialchars($targetRaw)) . '</td>'
                            .    '<td class="delta-qty">' . htmlspecialchars($deltaText) . '</td>'
                        .  '</tr>'
            ];

        }
    }

    //=== costruzione html ===
    $html = '';
    $denom = $sum + $liquidita;
    foreach ($items as $it) {
        $att = ($it['included'] && $denom > 0 && $it['value'] > 0) ? number_format($it['value'] / $denom * 100, 2, ',', '.') : '-';
        $html .= $it['rowOpen'] . $att . $it['rowClose'];
    }
    return [$html, $denom];
}

$xml = new DOMDocument();
$xml->preserveWhiteSpace = false;
$xml->load($path) or die('Errore nel caricamento del file di dati del portafoglio.');

$xp = new DOMXPath($xml);

// === metadati portafoglio ===
$portNode = $xp->query('/portafoglio')->item(0);
$valuta   = $portNode && $portNode->hasAttribute('valuta') ? $portNode->getAttribute('valuta') : 'EUR';
$symbols  = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];
$symbol   = $symbols[strtoupper($valuta)] ?? $valuta;

$info          = $xp->query('/portafoglio/informazioni')->item(0);
$tolleranza    = $info && $info->hasAttribute('tolleranza') ? $info->getAttribute('tolleranza') : '';
$ultimoAgg     = $info && $info->hasAttribute('ultimoAggiornamento') ? $info->getAttribute('ultimoAggiornamento') : '';
$commissione   = $info && $info->hasAttribute('commissione') ? $info->getAttribute('commissione') : '0';


$liqNode   = $xp->query('/portafoglio/liquidita/totale')->item(0);
$liquidita = $liqNode ? toFloat($liqNode->textContent) : 0.0;
$liqTargetNode = $xp->query('/portafoglio/liquidita/target')->item(0);
$liqTarget = $liqTargetNode ? toFloat($liqTargetNode->textContent) : 0.0;

// === inizializzazione tabella ===
?>
<table id="tab-portafoglio"
       data-valuta="<?php echo htmlspecialchars($valuta); ?>"
       data-symbol="<?php echo htmlspecialchars($symbol); ?>"
>
  <thead>
    <tr data-path=''>
        <th><button type="button" id="table-button" class="edit-button" data-open-assets>🛠️</button></th>
        <th>Tipo</th>
        <th>Nome</th>
        <th>Qty</th>
        <th>Prezzo</th>
        <th>Valore</th>
        <th>Attuale %</th>
        <th>Target %</th>
        <th>(Δ)Qty</th>
    </tr>
  </thead>
  <tbody>
<?php

// === gestione ricorsiva assets ===
$root = $xp->query('/portafoglio/assets')->item(0);
$BASE_URL = 'brunomichele.gloria.XML-DOM/';

$getPrice = function(string $ticker, string $tipo) use (&$priceCache) : float {
  if (!$ticker) return 0.0;
  if (isset($priceCache[$ticker]) && isset($_SESSION['lastPriceFetch'][$ticker]) && $_SESSION['lastPriceFetch'][$ticker] === date('Ymd')) return $priceCache[$ticker];

  $p = 0.00;
  if ($tipo === 'obbligazione') {
    $p = getPriceBondInvesting($ticker);
    if ($p < 0.00) {
      $p = getPriceBondBI($ticker);
    }
  } else {
    $p = getPriceYahoo($ticker);
  }
  
  $_SESSION['lastPriceFetch'][$ticker] = date('Ymd');
  return $priceCache[$ticker] = (float)$p;
};

[$tbodyHtml, $totalAssets] = renderChildren($root, $xp, $getPrice, $liquidita, '', $valuta);
$_SESSION['priceCache'] = $priceCache;
if (isset($_GET['rebalance'])) {
	try {
		$rebalanceReport = rebalance($path, $priceCache);
		$_SESSION['rebalanceReport'] = $rebalanceReport;
		[$tbodyHtml, $totalAssets] = renderChildren($root, $xp, $getPrice, $liquidita, '', $valuta);
	} catch (Exception $e) {
		$rebalanceError = 'Errore durante il ribilanciamento: ' . $e->getMessage();
		echo '<tr><td colspan="9" class="error-message">' . htmlspecialchars($rebalanceError) . '</td></tr>';
	}
}
echo $tbodyHtml;

?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="9">
        Liquidit&agrave;:
        <span id="liquidita-totale" 
              data-liqTarget="<?php echo htmlspecialchars($liqTarget); ?>"
              data-liqattuale="<?php echo htmlspecialchars(number_format($totalAssets? $liquidita / $totalAssets * 100 : 0, 2, ",", ".")); ?>">
            <?php echo htmlspecialchars(number_format($liquidita, 2, ',', '.') . $symbol); ?>
        </span>
        <?php if ($tolleranza !== '' || $ultimoAgg !== '' || $commissione !== '0'): ?>
        <span id="footer-data"
              data-tolleranza="<?php echo htmlspecialchars($tolleranza); ?>"
              data-commissione="<?php echo htmlspecialchars($commissione); ?>">
             ·
            <?php
                $parts = [];
                if ($tolleranza !== '')   $parts[] = "&nbsp;" . 'Tolleranza: ' . htmlspecialchars($tolleranza) . '%';
                if ($commissione !== '0') $parts[] = "&nbsp;" . 'Commissione: ' . htmlspecialchars($commissione) . htmlspecialchars($symbol);
                echo implode(' · ', $parts);
            ?>
        </span>
        <?php endif; ?>
      </td>
    </tr>
  </tfoot>
</table>