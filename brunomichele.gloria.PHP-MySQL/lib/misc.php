<?php
require_once __DIR__ . '/fetchPrice.php';
require_once __DIR__ . '/fetchBondInvesting.php';
require_once __DIR__ . '/fetchBondBI.php';
require_once __DIR__ . '/connection.php';

function priceCacheInit(): void {
	if (!isset($_SESSION['priceCache']) || !is_array($_SESSION['priceCache'])) {
		$_SESSION['priceCache'] = [
			'createdAt' => time(),
			'data' => []
		];
		return;
	}

	$createdAt = (int)($_SESSION['priceCache']['createdAt'] ?? 0);
	if ($createdAt <= 0 || (time() - $createdAt) >= 86400) {
		$_SESSION['priceCache'] = [
			'createdAt' => time(),
			'data' => []
		];
	}
}

function priceCacheGet(string $key): ?float {
	priceCacheInit();

	$data = $_SESSION['priceCache']['data'] ?? [];
	if (!is_array($data)) return null;

	if (!array_key_exists($key, $data)) return null;

	$val = $data[$key];
	if (!is_numeric($val)) return null;

	$f = (float)$val;
	return ($f > 0) ? $f : null;
}

function priceCacheSet(string $key, float $price): void {
	priceCacheInit();
	if ($price <= 0) return;

	$_SESSION['priceCache']['data'][$key] = $price;
}

function getCachedPriceYahoo(string $ticker): float {
	$ticker = trim($ticker);
	if ($ticker === '') return 0.0;

	$key = 'Y:' . strtoupper($ticker);
	$cached = priceCacheGet($key);
	if ($cached !== null) return $cached;

	$p = getPriceYahoo($ticker);
	$price = (is_numeric($p) ? (float)$p : 0.0);

	if ($price > 0) priceCacheSet($key, $price);
	return $price;
}

function getCachedBondPrice(string $isin): float {
	$isin = strtoupper(trim($isin));
	if ($isin === '') return 0.0;

	$key = 'B:' . $isin;
	$cached = priceCacheGet($key);
	if ($cached !== null) return $cached;

	$p = getPriceBondInvesting($isin);
	$price = (is_numeric($p) ? (float)$p : 0.0);

	if ($price <= 0) {
		$p2 = getPriceBondBI($isin);
		$price = (is_numeric($p2) ? (float)$p2 : 0.0);
	}

	if ($price > 0) priceCacheSet($key, $price);
	return $price;
}

function h(string $s): string { 
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); 
}

function requireLogin(PDO $pdo): array {
    if (!isset($_SESSION['userId'])) {
        header('Location: ../index.php');
        exit;
    }
    $userId = (int)$_SESSION['userId'];

    $stmt = $pdo->prepare("SELECT ID_Utente, Username FROM Utente WHERE ID_Utente = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row) {
        session_unset();
        session_destroy();
        header('Location: ../index.php');
        exit;
    }

    $_SESSION['username'] = $row['Username'];

    return $row;
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

        if ($q <= 0) {
            continue;
        }

        if ($tipo === 'BUY') {
            if ($qty < 0.0) {
                $cover = min($q, -$qty);
                $qty += $cover;
                $rest = $q - $cover;
                if ($rest > 0.0) {
                    $qty += $rest;
                    $costTotal += $rest * $p;
                }
                continue;
            }

            $qty += $q;
            $costTotal += $q * $p;
            continue;
        }

        if ($tipo === 'SELL') {
            if ($qty <= 0.0) {
                $qty -= $q;
                $costTotal = 0.0;
                continue;
            }

            if ($q >= $qty) {
                $qty -= $q;
                $costTotal = 0.0;
                continue;
            }

            $ratio = $q / $qty;
            $costTotal -= $costTotal * $ratio;
            $qty -= $q;
        }
    }

    $avgCost = ($qty > 0.0) ? ($costTotal / $qty) : 0.0;
    return [$avgCost, $qty];
}

function valutaToSimbolo(?string $sigla): ?string {
    if ($sigla === null) return null;
    $mappa = [
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        'JPY' => '¥',
        'CHF' => 'Fr',
        'AUD' => 'A$',
        'CAD' => 'C$',
        'CNY' => '¥',
        'RUB' => '₽'
    ];
    $sigla = strtoupper(trim($sigla));
    return $mappa[$sigla] ?? null;
}

function buildAssetRowPartsDB(
	string $tipo,
	string $bucketId,
	string $assetNome,
	string $isin,
	string $ticker,
	float $qty,
	string $valuta,
	float $avgCost,
	float $taxRatePct,
	float $unitPrice,
	string $targetPrint,
	string $prezzoPrint,
	string $valorePrint,
    array $rebalanceMap = []
    ): array {
	$tipo = strtolower($tipo);

    $rebKey = ((int)$bucketId) . '|' . $isin;
    $deltaQty = isset($rebalanceMap[$rebKey]) ? (float)$rebalanceMap[$rebKey]['deltaQty'] : 0.0;
    $deltaNote = isset($rebalanceMap[$rebKey]) ? (string)$rebalanceMap[$rebKey]['note'] : '';
    $deltaClass = 'delta-default';

    if ($deltaQty > 0) {
        $deltaPrint = '+' . rtrim(rtrim(number_format($deltaQty, 6, '.', ''), '0'), '.');
        $deltaClass = 'delta-buy';
    } elseif ($deltaQty < 0) {
        $deltaPrint = rtrim(rtrim(number_format($deltaQty, 6, '.', ''), '0'), '.');
        $deltaClass = 'delta-sell';
    } else {
        $deltaPrint = '-';
    }

	$rowOpen =
		'<tr class="asset-row" data-type="' . h($tipo) . '"'
		. ' data-bucket-id="' . h((string)$bucketId) . '"'
		. ' data-nome="' . h($assetNome) . '"'
		. ' data-isin="' . h($isin) . '"'
		. ' data-ticker="' . h($ticker) . '"'
		. ' data-quantita="' . h((string)$qty) . '"'
		. ' data-valuta="' . h($valuta) . '"'
		. ' data-costo="' . h(number_format((float)$avgCost, 6, '.', '')) . '"'
		. ' data-tax-rate="' . h(number_format((float)$taxRatePct * 100, 2, '.', '')) . '"'
		. ' data-prezzo="' . h(number_format((float)$unitPrice, 6, '.', '')) . '"'
		. ' data-target-raw="' . h($targetPrint) . '">' . "\n"
		. '<td class="edit-cell"><button type="button" class="edit-button" data-role="ops-gear">⚙️</button></td>' . "\n"
		. '<td class="tipo">' . h($tipo) . '</td>' . "\n"
		. '<td class="nome">' . h($assetNome) . '</td>' . "\n"
		. '<td class="quantita">' . h((string)$qty) . '</td>' . "\n"
		. '<td class="prezzo">' . h($prezzoPrint) . '</td>' . "\n"
		. '<td class="valore">' . h($valorePrint) . '</td>' . "\n"
		. '<td class="attuale">';

	$rowClose =
		'</td>' . "\n"
		. '<td class="target">' . h($targetPrint) . '</td>' . "\n"
		. '<td class="delta-qty"' . ($deltaNote !== '' ? ' title="' . h($deltaNote) . '"' : '') . '>' . h($deltaPrint) . '</td>' . "\n"
		. '</tr>' . "\n";

	return [$rowOpen, $rowClose];
}

function renderBucketChildrenDB(PDO $pdo, int $bucketId, array $bucketById, array $childrenMap, string $parentPath, array $rebalanceMap = []): array {
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
            COALESCE(e.Ticker, az.Ticker) AS Ticker
        FROM ContenutoAsset ca
        INNER JOIN Asset a ON a.ISIN = ca.ISIN
        LEFT JOIN ETF e ON e.ISIN = a.ISIN
        LEFT JOIN Azione az ON az.ISIN = a.ISIN
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
        $targetPrint = ($targetAssetRaw === null) ? '-' : number_format((float)$targetAssetRaw, 2, ',', '.');

        [$avgCost, $qty] = getWAC_DB($pdo, $bucketId, $isin);

        if ($tipo === 'obbligazione') {
            $unitPrice = getCachedBondPrice($isin);
        } else {
            $t = (($tipo === 'etf' || $tipo === 'azione') && $ticker !== '') ? $ticker : $isin;
            $unitPrice = getCachedPriceYahoo($t);
        }

        if ($tipo === 'obbligazione') {
            $value = ($qty > 0 && $unitPrice > 0) ? ($qty * $unitPrice / 100.0) : 0.0;
        } else {
            $value = ($qty > 0 && $unitPrice > 0) ? ($qty * $unitPrice) : 0.0;
        }

        $sum += $value;

        $childAssetKey = ($tipo === 'etf' && $ticker !== '') ? $ticker : $isin;
        $assetPath = $parentPath . '/' . sanitize_id($childAssetKey);

        $prezzoPrint = ($unitPrice > 0) ? number_format($unitPrice, 2, ',', '.') : '-';
        $valorePrint = ($value > 0) ? number_format($value, 2, ',', '.') : '-';

        [$rowOpen, $rowClose] = buildAssetRowPartsDB(
            $tipo,
            $bucketId,
            $assetNome,
            $isin,
            $ticker,
            (float)$qty,
            $valuta,
            (float)$avgCost,
            (float)$r['TaxRatePct'],
            (float)$unitPrice,
            $targetPrint,
            $prezzoPrint,
            $valorePrint,
            $rebalanceMap
        );

        $childrenItems[] = [
            'type' => $tipo,
            'value' => $value,
            'rowOpen' => $rowOpen,
            'rowClose' => $rowClose
        ];
    }

    $childBucketIds = $childrenMap[$bucketId] ?? [];
    $childBucketSums = [];

    foreach ($childBucketIds as $childId) {
        [, $sChild] = renderBucketsDB((int)$childId, $bucketById, $childrenMap, $parentPath, 0.0, $rebalanceMap);
        $childBucketSums[(int)$childId] = (float)$sChild;
        $sum += (float)$sChild;
    }

    $html = '';
    $denom = $sum;

    foreach ($childBucketIds as $childId) {
        [$hChild, $sChild] = renderBucketsDB((int)$childId, $bucketById, $childrenMap, $parentPath, $denom, $rebalanceMap);

        $childrenItems[] = [
            'type' => 'bucket',
            'value' => $sChild,
            'included' => true,
            'rowOpen' => $hChild,
            'rowClose' => ''
        ];
    }

    foreach ($childrenItems as $it) {
        if (($it['type'] ?? '') === 'bucket') {
            $html .= ($it['rowOpen'] ?? '');
            continue;
        }

        $v = (float)($it['value'] ?? 0);
        $att = ($denom > 0 && $v > 0) ? number_format($v / $denom * 100, 2, ',', '.') : '-';
        $html .= ($it['rowOpen'] ?? '') . $att . ($it['rowClose'] ?? '');
    }

    return [$html, $denom];
}

function renderBucketsDB(int $bucketId, array $bucketById, array $childrenMap, string $parentPath, float $parentDenom, array $rebalanceMap = []): array {
    $pdo = getPDO();

    $b = $bucketById[$bucketId];

    $name = $b['Nome'];
    $targetRaw = $b['TargetPctSuPadre'];

    $childPath = $parentPath . '/' . sanitize_id($name);
    $colore = (substr_count($childPath, '/') % 2 === 0) ? 'bucket-details-even' : 'bucket-details-odd';

    [$innerHtml, $innerSum] = renderBucketChildrenDB($pdo, $bucketId, $bucketById, $childrenMap, $childPath, $rebalanceMap);

    $targetPrint = ($targetRaw === null) ? '-' : number_format((float)$targetRaw, 2, ',', '.');

    $rowOpen =
        '<tr class="bucket-row" data-type="bucket" data-nome="' . h($name) . '" data-path="' . h($childPath) . '" data-target-raw="' . h($targetPrint) . '">' . "\n"
        . '<td class="edit-cell"><button type="button" class="edit-button" data-open-assets data-bucket-id="' . (int)$bucketId . '">🛠️</button></td>' . "\n"
        . '<td class="tipo">bucket</td>' . "\n"
        . '<td class="nome">' . h($name) . '</td>' . "\n"
        . '<td class="quantita">-</td>' . "\n"
        . '<td class="prezzo">-</td>' . "\n"
        . '<td class="valore">' . ($innerSum > 0 ? h(number_format($innerSum, 2, ',', '.')) : '-') . '</td>' . "\n"
        . '<td class="attuale">';

    $rowClose =
        '</td>' . "\n"
        . '<td class="target">' . h($targetPrint) . '</td>' . "\n"
        . '<td class="delta-qty" style="text-align:center;">'
            . '<div style="display:inline-block; white-space:nowrap;">'
                . '<button style="visibility:hidden;">&#9664;</button>' // Bottone invisibile per allineamento
                . '<span style="display:inline-block;">-</span>'
                . '<button type="button" class="toggle-details-button" data-toggle="' . (int)$bucketId . '" style="position:relative;right:-40px;">&#9664;</button>'
            . '</div>'
        . '</td>' . "\n"
        . '</tr>' . "\n"
        . '<tr class="bucket-details ' . h($colore) . '" data-details-of="' . (int)$bucketId . '">' . "\n"
        . '<td colspan="9"><table class="bucket-table"><tbody>' . "\n"
        . $innerHtml
        . '</tbody></table></td>' . "\n"
        . '</tr>' . "\n";

    $att = ($parentDenom > 0 && $innerSum > 0) ? number_format($innerSum / $parentDenom * 100, 2, ',', '.') : '-';
    $html = $rowOpen . $att . $rowClose;

    return [$html, $innerSum];
}

function renderRootChildrenDB(PDO $pdo, int $rootBucketId, array $bucketById, array $childrenMap, float $liquidita, array $rebalanceMap = []): array {
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
			COALESCE(e.Ticker, az.Ticker) AS Ticker
		FROM ContenutoAsset ca
		INNER JOIN Asset a ON a.ISIN = ca.ISIN
		LEFT JOIN ETF e ON e.ISIN = a.ISIN
        LEFT JOIN Azione az ON az.ISIN = a.ISIN
		WHERE ca.ID_Bucket = ?
		ORDER BY a.Tipo, a.Nome, ca.ISIN
	");
	$stmtA->execute([$rootBucketId]);
	$assetRows = $stmtA->fetchAll();

	foreach ($assetRows as $r) {
		$tipo = strtolower((string)$r['Tipo']);
		$assetNome = (string)($r['AssetNome'] ?? ($r['Ticker'] ?? $r['ISIN']));
		$isin = (string)$r['ISIN'];
		$valuta = (string)($r['Valuta'] ?? 'EUR');
		$ticker = (string)($r['Ticker'] ?? '');

		$targetAssetRaw = $r['TargetPctNelBucket'];
		$targetPrint = ($targetAssetRaw === null) ? '-' : number_format((float)$targetAssetRaw, 2, ',', '.');

		[$avgCost, $qty] = getWAC_DB($pdo, $rootBucketId, $isin);

		$unitPrice = 0.0;
		if ($tipo === 'obbligazione') {
			$unitPrice = getCachedBondPrice($isin);
		} else {
			$t = (($tipo === 'etf' || $tipo === 'azione') && $ticker !== '') ? $ticker : $isin;
			$unitPrice = getCachedPriceYahoo($t);
		}

		if ($tipo === 'obbligazione') {
			$value = ($qty > 0 && $unitPrice > 0) ? ($qty * $unitPrice / 100.0) : 0.0;
		} else {
			$value = ($qty > 0 && $unitPrice > 0) ? ($qty * $unitPrice) : 0.0;
		}

		$sum += $value;

		$prezzoPrint = ($unitPrice > 0) ? number_format($unitPrice, 2, ',', '.') : '-';
		$valorePrint = ($value > 0) ? number_format($value, 2, ',', '.') : '-';

		$childAssetKey = ($tipo === 'etf' && $ticker !== '') ? $ticker : $isin;
		$assetPath = '/' . sanitize_id($childAssetKey);

		[$rowOpen, $rowClose] = buildAssetRowPartsDB(
			$tipo,
			$rootBucketId,
			$assetNome,
			$isin,
			$ticker,
			(float)$qty,
			$valuta,
			(float)$avgCost,
			(float)$r['TaxRatePct'],
			(float)$unitPrice,
			$targetPrint,
			$prezzoPrint,
			$valorePrint,
            $rebalanceMap
		);

		$childrenItems[] = [
			'type' => $tipo,
			'value' => $value,
			'rowOpen' => $rowOpen,
			'rowClose' => $rowClose
		];
	}

	$childBucketIds = $childrenMap[$rootBucketId] ?? [];
    $childBucketSums = [];

    foreach ($childBucketIds as $childId) {
        [, $sChild] = renderBucketsDB((int)$childId, $bucketById, $childrenMap, '', 0.0, $rebalanceMap);
        $childBucketSums[(int)$childId] = (float)$sChild;
        $sum += (float)$sChild;
    }

	$html = '';
	$denom = $sum + max(0.0, $liquidita);

    foreach ($childBucketIds as $childId) {
        [$hChild, $sChild] = renderBucketsDB((int)$childId, $bucketById, $childrenMap, '', $denom, $rebalanceMap);

        $childrenItems[] = [
            'type' => 'bucket',
            'value' => $sChild,
            'rowOpen' => $hChild,
            'rowClose' => ''
        ];
    }

	foreach ($childrenItems as $it) {
		if (($it['type'] ?? '') === 'bucket') {
			$html .= ($it['rowOpen'] ?? '');
			continue;
		}

		$v = (float)($it['value'] ?? 0);
        $att = ($denom > 0 && $v > 0)? number_format($v / $denom * 100, 2, ',', '.') : '-';

		$html .= ($it['rowOpen'] ?? '') . $att . ($it['rowClose'] ?? '');
	}

	return [$html, $denom];
}

function sendError($page, $msg) {
    header('Location: ' . $page . '?err=' . $msg, true, 303);
    exit;
}

function getBucketSubtreeIds(PDO $pdo, int $rootBucketId): array {
    $all = [];
    $frontier = [$rootBucketId];
    $seen = [$rootBucketId => true];

    while (!empty($frontier)) {
        foreach ($frontier as $bid) {
            $all[] = (int)$bid;
        }

        $placeholders = implode(',', array_fill(0, count($frontier), '?'));
        $stmt = $pdo->prepare("SELECT ID_Bucket FROM Bucket WHERE ID_Padre IN ($placeholders)");
        $stmt->execute($frontier);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        $next = [];
        foreach ($children as $cid) {
            $cid = (int)$cid;
            if (!isset($seen[$cid])) {
                $seen[$cid] = true;
                $next[] = $cid;
            }
        }
        $frontier = $next;
    }

    return $all;
}

function setFlash(string $type, string $title, array $details, string $code): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'title' => $title,
        'details' => $details,
        'code' => $code
    ];
}