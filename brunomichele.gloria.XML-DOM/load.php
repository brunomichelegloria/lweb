<?php

if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
require __DIR__.'/lib/fetchPrice.php';
require __DIR__.'/lib/fetchBondInvesting.php';
require __DIR__.'/lib/fetchBondBI.php';

ini_set('display_errors', '0');                  // niente errori in pagina
ini_set('log_errors', '1');                      // abilita logging
ini_set('error_log', __DIR__ . '/php-error.log'); // file di log nel progetto
error_reporting(E_ALL);

function toFloat(?string $s): float {
    return is_numeric($s) ? (float)$s : 0.0;
}

function sanitize_id($str) {
    // Sostituisci spazi e caratteri non validi con trattino
    $str = preg_replace('/[^a-zA-Z0-9\-_:.]/', '-', $str);
    // Rimuovi trattini multipli consecutivi
    $str = preg_replace('/-+/', '-', $str);
    // Rimuovi trattini iniziali/finali
    $str = trim($str, '-');
    return $str;
}

function renderChildren(DOMElement $parent, DOMXPath $xp, callable &$getPrice, float $liquidita, string $parentPath): array {
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

        if ($type === 'bucket') {
            // ========= BUCKET =========
            $childPath = $parentPath . '/' . sanitize_id($name);
            $colore = (substr_count($childPath, '/') % 2 === 0) ? 'bucket-details-even' : 'bucket-details-odd';
            [$innerHtml, $innerSum] = renderChildren($child, $xp, $getPrice, 0.00, $childPath);
            if ($included) $sum += $innerSum;

            $items[] = [
                'type' => 'bucket',
                'value' => $innerSum,
                'included' => $included,
                'rowOpen' => '<tr class="bucket-row" '
                        .  'data-type="bucket" '
                        .  'data-target-raw="' . htmlspecialchars($targetRaw) . '" ' 
                        .  'data-path="' . $childPath . '">'
                        .    '<td class="edit-cell"><button type="button" id="' . sanitize_id(htmlspecialchars($childPath)) . '-button" class="edit-button">⚙️</button></td>' //ID potenzialmente NON univoco
                                                                //SOLUZIONE TEMPORANEA ⬆️
                        .    '<td class="tipo">bucket</td>'
                        .    '<td class="nome">' . htmlspecialchars($name) . '</td>'
                        .    '<td class="quantita">-</td>'
                        .    '<td class="prezzo">-</td>'
                        .    '<td class="valore">' . ($innerSum > 0 ? htmlspecialchars(number_format($innerSum, 2, ',', '.')) : '-') . '</td>'
                        .    '<td class="attuale">',
                'rowClose' => '</td>'
                        .    '<td class="target">' . ($targetRaw === '' ? '-' : htmlspecialchars($targetRaw)) . '</td>'
                        .    '<td class="delta-qty">-</td>'
                        .    '<td class="toggle-details-cell"><button type="button" class="toggle-details-button">&#9664</button></td>'
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

            // Qty corrente = somma <quantita> (acquisti/vendite)
            $qty = 0.0;
            foreach ($xp->query('operazioni/operazione/quantita', $child) as $q) {
                $qty += toFloat($q->textContent);
            }

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

            $value = ($type === 'obbligazione') ? $qty * $unitPrice / 100 : $qty * $unitPrice;
            if ($included) $sum += $value;

            $taxRateRow = $child->hasAttribute('taxRate') ? $child->getAttribute('taxRate') : '0.26';
            $tradeStep  = $child->hasAttribute('tradeStep') ? (int)$child->getAttribute('tradeStep') : 0;



            $items[] = [
                'type' => $type,
                'value' => $value,
                'included' => $included,
                'rowOpen' => '<tr data-type="' . htmlspecialchars($type) . '"'
                        .  ' data-path="' . htmlspecialchars($childPath) . '"'
                        .  ' data-ticker="' . htmlspecialchars($ticker) . '"'
                        .  ' data-quantita="' . htmlspecialchars($qty) . '"'
                        .  ' data-costo="' . htmlspecialchars(number_format($avgCost, 6, '.', '')) . '"'
                        .  ' data-taxrate-asset="' . htmlspecialchars($taxRateRow) . '"'
                        .  ' data-tradestep="' . htmlspecialchars($tradeStep) . '"'
                        .  ' data-prezzo="' . htmlspecialchars(number_format($unitPrice, 6, '.', '')) . '"'
                        .  ' data-target-raw="' . htmlspecialchars($targetRaw) . '">'
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
                            .    '<td class="delta-qty">-</td>'
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
        <th>🛠️</th>
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
$priceCache = [];

$getPrice = function(string $ticker, string $tipo) use (&$priceCache) : float {
  if (!$ticker) return 0.0;
  if (isset($priceCache[$ticker])) return $priceCache[$ticker];

  $p = 0.00;
  if ($tipo === 'obbligazione') {
   // $p = getPriceBondInvesting($ticker);
    if ($p < 0.00) {
       // $p = getPriceBondBI($ticker);
    }
  } else {
   // $p = getPriceYahoo($ticker);
  }
  
  if ($p > 0.00) return $priceCache[$ticker] = (float)$p;
  return $p;
};

[$tbodyHtml, $totalAssets] = renderChildren($root, $xp, $getPrice, $liquidita, '');
echo $tbodyHtml;

?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="9">
        Liquidità:
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