<?php

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = 'localhost';
    $db   = 'portfolio_db';
    $user = 'portfolio_app';
    $pass = 'CambiaQuestaPassword!';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function requireLogin(PDO $pdo): array {
    if (!isset($_SESSION['userId'])) {
        header('Location: index.php');
        exit;
    }
    $userId = (int)$_SESSION['userId'];

    $stmt = $pdo->prepare("SELECT ID_Utente, Username FROM Utente WHERE ID_Utente = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row) {
        session_unset();
        session_destroy();
        header('Location: index.php');
        exit;
    }

    $_SESSION['username'] = $row['Username'];

    return $row;
}

function renderBucketChildrenDB(PDO $pdo, int $bucketId, array $bucketById, array $childrenMap, string $parentPath): array {
    $childrenItems = [];
    $sum = 0.0;

    $stmtA = $pdo->prepare("
        SELECT
            ca.ID_Bucket,
            ca.ISIN,
            ca.TargetPctNelBucket,
            ca.TaxRatePct,
            a.Nome AS AssetNome,
            a.Valuta,
            a.Tipo,
            e.Ticker
        FROM ContenutoAsset ca
        INNER JOIN Asset a ON a.ISIN = ca.ISIN
        LEFT JOIN ETF e ON e.ISIN = a.ISIN
        WHERE ca.ID_Bucket = ?
        ORDER BY a.Tipo, a.Nome, ca.ISIN
    ");
    $stmtA->execute([$bucketId]);
    $assetRows = $stmtA->fetchAll();

    foreach ($assetRows as $r) {
        $tipo = strtolower((string)$r['Tipo']);
        $assetNome = (string)($r['AssetNome']?? ($r['Ticker'] ?? $r['ISIN']));
        $isin = (string)$r['ISIN'];
        $valuta = (string)($r['Valuta'] ?? 'EUR');
        $ticker = (string)($r['Ticker'] ?? '');

        $targetAssetRaw = $r['TargetPctNelBucket'];
        $includedAsset = ($targetAssetRaw !== null);
        $targetPrint = ($targetAssetRaw === null) ? '-' : number_format((float)$targetAssetRaw, 2, ',', '.');

        [$avgCost, $qty] = getWAC_DB($pdo, $bucketId, $isin);

        $unitPrice = 0.0;
        $value = 0.0;
        if ($includedAsset) $sum += $value;

        $childAssetKey = ($tipo === 'etf' && $ticker !== '') ? $ticker : $isin;
        $assetPath = $parentPath . '/' . sanitize_id($childAssetKey);

        $prezzoPrint = ($unitPrice > 0) ? number_format($unitPrice, 2, ',', '.') : '-';
        $valorePrint = ($value > 0) ? number_format($value, 2, ',', '.') : '-';

        $childrenItems[] = [
            'type' => $tipo,
            'value' => $value,
            'included' => $includedAsset,
            'rowOpen' =>
                '<tr class="asset-row" data-type="' . h($tipo) . '"'
                . ' data-path="' . h($assetPath) . '"'
                . ' data-nome="' . h($assetNome) . '"'
                . ' data-isin="' . h($isin) . '"'
                . ' data-ticker="' . h($ticker) . '"'
                . ' data-quantita="' . h((string)$qty) . '"'
                . ' data-valuta="' . h($valuta) . '"'
                . ' data-costo="' . h(number_format((float)$avgCost, 6, '.', '')) . '"'
                . ' data-tax-rate="' . h(number_format((float)$r['TaxRatePct'] * 100, 2, '.', '')) . '"'
                . ' data-prezzo="' . h(number_format((float)$unitPrice, 6, '.', '')) . '"'
                . ' data-target-raw="' . h($targetPrint) . '">'
                . '<td class="edit-cell"><button type="button" class="edit-button" data-role="ops-gear">⚙️</button></td>'
                . '<td class="tipo">' . h($tipo) . '</td>'
                . '<td class="nome">' . h($assetNome) . '</td>'
                . '<td class="quantita">' . h((string)$qty) . '</td>'
                . '<td class="prezzo">' . h($prezzoPrint) . '</td>'
                . '<td class="valore">' . h($valorePrint) . '</td>'
                . '<td class="attuale">',
            'rowClose' =>
                '</td>'
                . '<td class="target">' . h($targetPrint) . '</td>'
                . '<td class="delta-qty">-</td>'
                . '</tr>'
        ];
    }

    foreach ($childrenMap[$bucketId] ?? [] as $childId) {
        [$hChild, $sChild] = renderBucketsDB($childId, $bucketById, $childrenMap, $parentPath);
        $childrenItems[] = [
            'type' => 'bucket',
            'value' => $sChild,
            'included' => true,
            'rowOpen' => $hChild,
            'rowClose' => ''
        ];
        $sum += $sChild;
    }

    $html = '';
    $denom = $sum;

    foreach ($childrenItems as $it) {
        $html .= $it['rowOpen'] . $it['rowClose'];
    }

    return [$html, $denom];
}

function renderBucketsDB(int $bucketId, array $bucketById, array $childrenMap, string $parentPath): array {
    $pdo = getPDO();

    $b = $bucketById[$bucketId];

    $name = $b['Nome'];
    $targetRaw = $b['TargetPctSuPadre'];
    $included = ($targetRaw !== null);

    $childPath = $parentPath . '/' . sanitize_id($name);
    $colore = (substr_count($childPath, '/') % 2 === 0) ? 'bucket-details-even' : 'bucket-details-odd';

    [$innerHtml, $innerSum] = renderBucketChildrenDB($pdo, $bucketId, $bucketById, $childrenMap, $childPath);
    if (!$included) {
        $innerSum = 0.0;
    }

    $targetPrint = ($targetRaw === null) ? '-' : number_format((float)$targetRaw, 2, ',', '.');

    $rowOpen =
        '<tr class="bucket-row" data-type="bucket" data-nome="' . h($name) . '" data-path="' . h($childPath) . '" data-target-raw="' . h($targetPrint) . '">'
        . '<td class="edit-cell"><button type="button" class="edit-button" data-open-assets data-bucket-id="' . (int)$bucketId . '">🛠️</button></td>'
        . '<td class="tipo">bucket</td>'
        . '<td class="nome">' . h($name) . '</td>'
        . '<td class="quantita">-</td>'
        . '<td class="prezzo">-</td>'
        . '<td class="valore">' . ($innerSum > 0 ? h(number_format($innerSum, 2, ',', '.')) : '-') . '</td>'
        . '<td class="attuale">';

    $rowClose =
        '</td>'
        . '<td class="target">' . h($targetPrint) . '</td>'
        . '<td class="delta-qty">-</td>'
        . '<td class="toggle-details-cell"><button type="button" class="toggle-details-button" data-toggle="' . (int)$bucketId . '">&#9664;</button></td>'
        . '</tr>'
        . '<tr class="bucket-details ' . h($colore) . '" data-details-of="' . (int)$bucketId . '">'
        . '<td colspan="9"><table class="bucket-table"><tbody>'
        . $innerHtml
        . '</tbody></table></td>'
        . '</tr>';

    $att = '-';
    $html = $rowOpen . $att . $rowClose;

    return [$html, $innerSum];
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

function toFloat(?string $s): float {
    return is_numeric($s) ? (float)$s : 0.0;
}

function getWAC_DB(PDO $pdo, int $bucketId, string $isin): array {
    $stmt = $pdo->prepare("
        SELECT Tipo, Quantita, PrezzoEseguito
        FROM Operazione
        WHERE ID_Bucket = ? AND ISIN = ?
        ORDER BY DataOra ASC, ID_Operazione ASC
    ");
    $stmt->execute([$bucketId, $isin]);
    $ops = $stmt->fetchAll();

    $qty = 0.0;
    $costTotal = 0.0;

    foreach ($ops as $op) {
        $tipo = (string)$op['Tipo'];
        $q = (float)$op['Quantita'];
        $p = (float)$op['PrezzoEseguito'];

        if ($q <= 0) continue;

        if ($tipo === 'BUY') {
            $costTotal += $q * $p;
            $qty += $q;
            continue;
        }

        if ($tipo === 'SELL') {
            if ($qty <= 0) {
                $qty = 0.0;
                $costTotal = 0.0;
                continue;
            }

            if ($q >= $qty) {
                $qty = 0.0;
                $costTotal = 0.0;
                continue;
            }

            $ratio = $q / $qty;
            $costTotal -= $costTotal * $ratio;
            $qty -= $q;
        }
    }

    $avgCost = ($qty > 0) ? ($costTotal / $qty) : 0.0;

    return [$avgCost, $qty];
}


function sendError($page, $msg) {
    header('Location: ' . $page . '?err=' . $msg, true, 303);
    exit;
}
