<?php
header('Content-Type: text/html; charset=utf-8');

function toFloat(?string $s): float {
    if ($s === null) return 0.0;
    $s = trim($s);
    $s = str_replace(['.', ','], ['', '.'], $s);
    return is_numeric($s) ? (float)$s : 0.0;
}

$xml = new DOMDocument();
$xml->preserveWhiteSpace = false;
$xml->load('./data/portafoglio.xml');

$xp = new DOMXPath($xml);

// valuta del portafoglio e simbolo
$portNode = $xp->query('/portafoglio')->item(0);
$valuta = $portNode && $portNode->hasAttribute('valuta') ? $portNode->getAttribute('valuta') : 'EUR';
$symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];
$symbol = $symbols[strtoupper($valuta)] ?? $valuta;

// tolleranza / ultimo aggiornamento
$info = $xp->query('/portafoglio/informazioni')->item(0);
$tolleranza = $info && $info->hasAttribute('tolleranza') ? $info->getAttribute('tolleranza') : '';
$ultimoAgg = $info && $info->hasAttribute('ultimoAggiornamento') ? $info->getAttribute('ultimoAggiornamento') : '';

// lista asset
$nodes = $xp->query('/portafoglio/assets/*[self::azione or self::etf or self::obbligazione]');

// liquidità
$liqNode = $xp->query('/portafoglio/liquidita/totale')->item(0);
$liquidita = $liqNode ? toFloat($liqNode->textContent) : 0.0;

ob_start();
?>
<table id="tab-portafoglio"
       data-valuta="<?php echo htmlspecialchars($valuta); ?>"
       data-symbol="<?php echo htmlspecialchars($symbol); ?>"
       <?php if ($tolleranza !== ''): ?>
       data-tolleranza="<?php echo htmlspecialchars($tolleranza); ?>"
       <?php endif; ?>
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
            <th>ΔQty</th>
        </tr>
    </thead>
    <tbody>
<?php
foreach ($nodes as $n) {
    /** @var DOMElement $n */
    $tipo   = strtolower($n->nodeName); // 'azione' | 'etf' | 'obbligazione'
    $ticker = $n->getAttribute('ticker'); // resta nei data-* per fetch prezzi

    $nomeNode = $xp->query('nome', $n)->item(0);
    $nome = $nomeNode ? trim($nomeNode->textContent) : $ticker;

    $targetNode = $xp->query('target', $n)->item(0);
    $target = $targetNode ? toFloat($targetNode->textContent) : 0.0;

    // somma quantità dalle operazioni
    $ops = $xp->query('operazioni/operazione/quantita', $n);
    $qty = 0.0;
    foreach ($ops as $q) { $qty += toFloat($q->textContent); }

    echo '<tr data-type="', htmlspecialchars($tipo),
         '" data-ticker="', htmlspecialchars($ticker),
         '" data-quantita="', htmlspecialchars($qty), '">';

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
                <?php if ($tolleranza !== '' || $ultimoAgg !== ''): ?>
                <span class="muted" style="margin-left:1rem;">
                <?php if ($tolleranza !== '') echo 'Tolleranza: ', htmlspecialchars($tolleranza), '%'; ?>
                <?php if ($ultimoAgg !== '') echo ' &middot; Ultimo aggiornamento: ', htmlspecialchars($ultimoAgg); ?>
                </span>
                <?php endif; ?>
            </td>
        </tr>
    </tfoot>
</table>
<?php
echo ob_get_clean();
