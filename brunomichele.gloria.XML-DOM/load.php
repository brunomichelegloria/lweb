<?php
header('Content-Type: text/html; charset=utf-8');

function toFloat(?string $s): float {
    if ($s === null) return 0.0;
    $s = trim($s);
    // 1.234,56 -> 1234.56
    $s = str_replace(['.', ','], ['', '.'], $s);
    return is_numeric($s) ? (float)$s : 0.0;
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

// === lista asset ===
$nodes = $xp->query('/portafoglio/assets/*[self::azione or self::etf or self::obbligazione]');

$liqNode   = $xp->query('/portafoglio/liquidita/totale')->item(0);
$liquidita = $liqNode ? toFloat($liqNode->textContent) : 0.0;

ob_start();
?>
<table id="tab-portafoglio"
       data-valuta="<?php echo htmlspecialchars($valuta); ?>"
       data-symbol="<?php echo htmlspecialchars($symbol); ?>"
       <?php if ($tolleranza !== ''): ?>
       data-tolleranza="<?php echo htmlspecialchars($tolleranza); ?>"
       <?php endif; ?>
       data-commissione="<?php echo htmlspecialchars($commissione); ?>"
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
foreach ($nodes as $n) {
    $tipo   = strtolower($n->nodeName); // 'azione' | 'etf' | 'obbligazione'
    $ticker = $n->getAttribute('ticker');

    $nomeNode = $xp->query('nome', $n)->item(0);
    $nome = $nomeNode ? trim($nomeNode->textContent) : $ticker;

    $targetNode = $xp->query('target', $n)->item(0);
    $target = $targetNode ? toFloat($targetNode->textContent) : 0.0;

    // qty corrente = somma delle quantita' in <operazioni>
    $opsQ = $xp->query('operazioni/operazione/quantita', $n);
    $qty  = 0.0;
    foreach ($opsQ as $q) { $qty += toFloat($q->textContent); }

    // costo medio WAC (solo su acquisti; le vendite non lo cambiano)
    $avgCost = 0.0;
    $qtyCum  = 0.0;
    $opsAll  = $xp->query('operazioni/operazione', $n);
    foreach ($opsAll as $op) {
        $qNode = $xp->query('quantita', $op)->item(0);
        $pNode = $xp->query('prezzo',   $op)->item(0);
        $q  = $qNode ? toFloat($qNode->textContent) : 0.0;
        $pr = $pNode ? toFloat($pNode->textContent) : 0.0;

        if ($q > 0) {
            $avgCost = ($avgCost * $qtyCum + $q * $pr) / ($qtyCum + $q);
            $qtyCum += $q;
        } else if ($q < 0) {
            $qtyCum += $q;
            if ($qtyCum < 0) $qtyCum = 0;
        }
    }

    $taxRateRow = $n->hasAttribute('taxRate') ? $n->getAttribute('taxRate') : '0.26';

    echo '<tr data-type="', htmlspecialchars($tipo),
         '" data-ticker="', htmlspecialchars($ticker),
         '" data-quantita="', htmlspecialchars($qty),
         '" data-costo="', htmlspecialchars(number_format($avgCost, 6, '.', '')),
         '" data-taxrate-asset="', htmlspecialchars($taxRateRow), '">';

    echo '  <td class="tipo">', htmlspecialchars($tipo), '</td>';
    echo '  <td class="nome">', htmlspecialchars($nome), '</td>';
    echo '  <td class="quantita">', htmlspecialchars($qty), '</td>';
    echo '  <td class="prezzo">-</td>';
    echo '  <td class="valore">-</td>';
    echo '  <td class="attuale">-</td>';
    echo '  <td class="target">', htmlspecialchars($target), '</td>';
    echo '  <td class="delta-qty">-</td>';
    echo '</tr>', PHP_EOL;
}
?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="8">
        Liquidità:
        <span id="liquidita-totale"><?php echo htmlspecialchars($symbol . ' ' . number_format($liquidita, 2, ',', '.')); ?></span>
        <?php if ($tolleranza !== '' || $ultimoAgg !== '' || $commissione !== '0'): ?>
        <span style="margin-left:1rem; opacity:.7;">
          <?php
            $parts = [];
            if ($tolleranza !== '')   $parts[] = 'Tolleranza: ' . htmlspecialchars($tolleranza) . '%';
            if ($commissione !== '0') $parts[] = 'Commissione: ' . htmlspecialchars($commissione) . ' ' . htmlspecialchars($symbol);
            if ($ultimoAgg !== '')    $parts[] = 'Ultimo agg.: ' . htmlspecialchars($ultimoAgg);
            echo implode(' · ', $parts);
          ?>
        </span>
        <?php endif; ?>
      </td>
    </tr>
  </tfoot>
</table>
<?php
echo ob_get_clean();
