<?php
$dom = new DOMDocument();
$dom->load("data/portafoglio.xml");
$assets = $dom->getElementsByTagName("asset");

echo "<table class=\"portafoglio\">";
echo "<tr><th>Nome</th><th>Valore</th><th>Target %</th><th>Attuale %</th><th>Tolleranza %</th><th>Azioni ±</th></tr>";

foreach ($assets as $asset) {
    $nome = $asset->getElementsByTagName("nome")[0]->nodeValue;
    $tipo = $asset->getElementsByTagName("tipo")[0]->nodeValue;
    $ticker = $asset->getElementsByTagName("ticker")[0]->nodeValue;
    $target = $asset->getElementsByTagName("target")[0]->nodeValue;

    $quantitaTotale = 0;
    $operazioni = $asset->getElementsByTagName("operazione");

    foreach ($operazioni as $op) {
        $quantita = floatval($op->getElementsByTagName("quantita")[0]->nodeValue);
        $quantitaTotale += $quantita;
    }

    echo "<tr data-tipo=\"" . htmlspecialchars($tipo) .  "\" data-ticker=\"" . htmlspecialchars($ticker) . "\" data-quantita=\"$quantitaTotale\" data-target=\"" . htmlspecialchars($target) . "\">";
    echo "<td class=\"nome\">" . htmlspecialchars($nome) . "</td>";
    echo "<td class=\"valore\">0</td>"; // Aggiornato tramite js
    echo "<td class=\"target\">" . htmlspecialchars($target) . "</td>";
    echo "<td class=\"attuale\">0</td>"; // Same
    echo "<td class=\"tolleranza\">0</td>"; //Same
    echo "<td class=\"azioni\">0</td>";
    echo "</tr>";
    
}

echo "</table>";
?>