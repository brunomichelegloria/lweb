<?php
$DBG = __DIR__ . '/dbg.log'; // DEBUG
file_put_contents($DBG, "BOOT ".date('H:i:s')."\n", FILE_APPEND); // DEBUG
date_default_timezone_set('Europe/Rome');

if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
require __DIR__.'/fetchPrice.php';
require __DIR__.'/fetchBondInvesting.php';
require __DIR__.'/fetchBondBI.php';

ini_set('display_errors', '0');                  // niente errori in pagina
ini_set('log_errors', '1');                      // abilita logging
ini_set('error_log', __DIR__ . '/php-error.log'); // file di log nel progetto
error_reporting(E_ALL);

function toFloat(?string $s): float {
    return is_numeric($s) ? (float)$s : 0.0;
}

function renderChildren(DOMElement $parent, DOMXPath $xp, callable &$getPrice, float $liquidita, bool $isRoot): array {
    global $DBG; // DEBUG
    file_put_contents($DBG, "[renderChildren] parent=".$parent->nodeName."\n", FILE_APPEND); // DEBUG
    $children = $xp->query('azione | etf | obbligazione | bucket', $parent);
    $html = '';
    $sum = 0.0;

    foreach ($children as $child) {
        $type = strtolower($child->nodeName);
        $nameNode   = $xp->query('nome',   $child)->item(0);
        $targetNode = $xp->query('target', $child)->item(0);
        $name      = $nameNode   ? trim($nameNode->textContent)   : 'Bucket';
        $targetRaw = $targetNode ? trim($targetNode->textContent) : '';

        if ($type === 'bucket') {
            // ========= BUCKET =========
            [$innerHtml, $innerSum] = renderChildren($child, $xp, $getPrice, 0.00, false);
            $sum += $innerSum;

            $html .= '<tr data-type="bucket" class="bucket-row" '
                  .  'data-target-raw="' . htmlspecialchars($targetRaw) . '" '
                  .  'data-valore="'     . htmlspecialchars(number_format($innerSum, 6, '.', '')) . '">';
            $html .= 	'<td class="tipo">bucket</td>';
            $html .= 	'<td class="nome">' . htmlspecialchars($name) . '</td>';
            $html .= 	'<td class="quantita">-</td>';
            $html .= 	'<td class="prezzo">-</td>';
            $html .= 	'<td class="valore">' . htmlspecialchars(number_format($innerSum, 2, ',', '.')) . '</td>';
            $html .= 	'<td class="attuale">-</td>';
            $html .= 	'<td class="target">' . ($targetRaw === '' ? '-' : htmlspecialchars($targetRaw)) . '</td>';
            $html .= 	'<td class="delta-qty">-</td>';
            $html .= '</tr>';

            // Riga dettagli con sub-tabella, inizialmente nascosta
            $html .= '<tr class="bucket-details" style="display:none">';
            $html .= 	'<td colspan="8"><table class="bucket-table"><tbody>';
            $html .= 	$innerHtml;
            $html .= 	'</tbody></table></td>';
            $html .= '</tr>';

        } else if (in_array($type, ['azione', 'etf', 'obbligazione'])) {
            // ========= ASSET =========

            $ticker     = $child->getAttribute('ticker');
            $nameNode   = $xp->query('nome',   $child)->item(0);
            $targetNode = $xp->query('target', $child)->item(0);
            $name       = $nameNode   ? trim($nameNode->textContent)   : $ticker;
            $targetRaw  = $targetNode ? trim($targetNode->textContent) : '';

            // Qty corrente = somma <quantita> (acquisti/vendite)
            $qty = 0.0;
            foreach ($xp->query('operazioni/operazione/quantita', $child) as $q) {
                $qty += toFloat($q->textContent);
            }
            $ticker = $child->getAttribute('ticker'); //DEBUG
            file_put_contents($DBG, "[asset] type=$type ticker=".($ticker ?: 'NULL')."\n", FILE_APPEND); // DEBUG

            // WAC (solo acquisti; vendite non lo aggiornano)
            $avgCost = 0.0;
            $qtyCum  = 0.0;
            foreach ($xp->query('operazioni/operazione', $child) as $op) {
                $qNode = $xp->query('quantita', $op)->item(0);
                $pNode = $xp->query('prezzo',   $op)->item(0);
                $q  = $qNode ? toFloat($qNode->textContent) : 0.0;
                $pr = $pNode ? toFloat($pNode->textContent) : 0.0;
                if ($q > 0) {
                    $avgCost = ($avgCost * $qtyCum + $q * $pr) / ($qtyCum + $q);
                    $qtyCum += $q;
                } elseif ($q < 0) {
                    $qtyCum += $q;
                    if ($qtyCum < 0) $qtyCum = 0;
                }
            }
            $quoted = $getPrice($ticker, $type);
            $unitPrice = (is_numeric($quoted) ? (float)$quoted : 0.0);
             file_put_contents($DBG, "[asset] unitPrice=$unitPrice ticker=".($ticker ?: 'NULL')."\n", FILE_APPEND); // DEBUG

            $value = ($type === 'obbligazione') ? $qty * $unitPrice / 100 : $qty * $unitPrice;
            $sum += $value;

            $taxRateRow = $child->hasAttribute('taxRate') ? $child->getAttribute('taxRate') : '0.26';
            $tradeStep  = $child->hasAttribute('tradeStep') ? (int)$child->getAttribute('tradeStep') : 0;

            $html .= '<tr data-type="' . htmlspecialchars($type) . '"'
                .  ' data-ticker="' . htmlspecialchars($ticker) . '"'
                .  ' data-quantita="' . htmlspecialchars($qty) . '"'
                .  ' data-costo="' . htmlspecialchars(number_format($avgCost, 6, '.', '')) . '"'
                .  ' data-taxrate-asset="' . htmlspecialchars($taxRateRow) . '"'
                .  ' data-tradestep="' . htmlspecialchars($tradeStep) . '"'
                .  ' data-prezzo="' . htmlspecialchars(number_format($unitPrice, 6, '.', '')) . '"'
                .  ' data-valore="' . htmlspecialchars(number_format($value, 6, '.', '')) . '"'
                .  ' data-target-raw="' . htmlspecialchars($targetRaw) . '">';
            $html .=   '<td class="tipo">' . htmlspecialchars($type) . '</td>';
            $html .=   '<td class="nome">' . htmlspecialchars($name) . '</td>';
            $html .=   '<td class="quantita">' . htmlspecialchars($qty) . '</td>';
            $html .=   '<td class="prezzo">' . ($unitPrice > 0 ? htmlspecialchars(number_format($unitPrice, 2, ',', '.')) : '-') . '</td>';
            $html .=   '<td class="valore">' . ($value > 0 ? htmlspecialchars(number_format($value, 2, ',', '.')) : '-') . '</td>';
            $html .=   '<td class="attuale">-</td>';
            $html .=   '<td class="target">' . ($targetRaw === '' ? '-' : htmlspecialchars($targetRaw)) . '</td>';
            $html .=   '<td class="delta-qty">-</td>';
            $html .= '</tr>';
        }
    }
	
	foreach ($children as $child) {
		
	}

    return [$html, $sum];
}

$xml = new DOMDocument();
$xml->preserveWhiteSpace = false;
$xml->load('data/portafoglio.xml');

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
    <tr>
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
$BASE_URL = 'brunomichele.gloria.XML-DOM/'; // ← ADATTA a dove gira il tuo progetto

$priceCache = [];

$getPrice = function(string $ticker, string $tipo) use (&$priceCache) : float {
  if (!$ticker) return 0.0;
  if (isset($priceCache[$ticker])) return $priceCache[$ticker];

  $p = 0.00;
  if ($tipo === 'obbligazione') {
    $p = getPriceBondInvesting($ticker);
    if ($p < 0.00) {
    	$p = getPriceBondBI($ticker);
    }
  } else {
    $p = getPriceYahoo($ticker);
  }
  
  if ($p > 0.00) return $priceCache[$ticker] = (float)$p;
  return $p;
};

[$tbodyHtml, $totalAssets] = renderChildren($root, $xp, $getPrice, $liquidita, true);
echo $tbodyHtml;

?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="8">
        Liquidità:
        <span id="liquidita-totale" 
              data-liqTarget="<?php echo htmlspecialchars($liqTarget); ?>">
            <?php echo htmlspecialchars(number_format($liquidita, 2, ',', '.') . $symbol); ?>
        </span>
        <?php if ($tolleranza !== '' || $ultimoAgg !== '' || $commissione !== '0'): ?>
        <span id="footer-data"
              data-tolleranza="<?php echo htmlspecialchars($tolleranza); ?>"
              data-commissione="<?php echo htmlspecialchars($commissione); ?>">
             ·
            <?php
                $parts = [];
                if ($tolleranza !== '')   $parts[] = "&nbsp" . 'Tolleranza: ' . htmlspecialchars($tolleranza) . '%';
                if ($commissione !== '0') $parts[] = "&nbsp" . 'Commissione: ' . htmlspecialchars($commissione) . htmlspecialchars($symbol);
                echo implode(' · ', $parts);
            ?>
        </span>
        <?php endif; ?>
      </td>
    </tr>
  </tfoot>
</table>