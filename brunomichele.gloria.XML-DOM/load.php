<?php
$dom = new DOMDocument();
$dom->load("data/portafoglio.xml");
$assets = $dom->getElementsByTagName("asset");

echo "<table>";
echo "<tr><th>Nome</th><th>Valore</th><th>Target %</th><th>Attuale %</th></tr>";

foreach ($assets as $asset) {
    $nome = $asset->getElementsByTagName("nome")[0]->nodeValue;
    $ticker = $asset->getElementsByTagName("ticker")[0]->nodeValue;
    $target = $asset->getElementsByTagName("target")[0]->nodeValue;

    $quantitaTotale = 0;
    $operazioni = $asset->getElementsByTagName("operazione");

    foreach ($operazioni as $op) {
        $quantita = floatval($op->getElementsByTagName("quantita")[0]->nodeValue);
        $quantitaTotale += $quantita;
    }

    echo "<tr data-ticker=\"" . htmlspecialchars($ticker) . "\" data-quantita=\"$quantitaTotale\">";
    echo "<td class=\"nome\">" . htmlspecialchars($nome) . "</td>";
    echo "<td class=\"valore\">0</td>";       // verrà aggiornato via JS
    echo "<td class=\"target\">" . htmlspecialchars($target) . "</td>";
    echo "<td class=\"attuale\">0</td>";     // verrà aggiornato via JS
    echo "</tr>";
}

echo "</table>";
?>