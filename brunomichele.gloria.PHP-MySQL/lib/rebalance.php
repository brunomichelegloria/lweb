<?php
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/misc.php';
require_once __DIR__ . '/rebalance_engine.php';

$pdo = getPDO();
$user = requireLogin($pdo);
$userId = (int)$user['ID_Utente'];

$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);

if ($portfolioId <= 0) {
    $_SESSION['flash'] = [
        'title' => 'Errore',
        'details' => ['Portafoglio non valido'],
        'code' => 1
    ];
    header('Location: ../selectPortfolio.php');
    exit;
}

try {
    [$root, $globalInfo, $assetIndex] = preparePortfolioTreeFromDb($pdo, $portfolioId, $userId);
    $priceCache = buildPriceCacheFromTree($root);

    $result = rebalancePreparedTree($root, $globalInfo, $priceCache, true);

    $_SESSION['rebalance_result'] = normalizeRebalanceResult($result, $assetIndex);

    $summary = $result['summary'] ?? [];
    $orders = (int)($summary['orders_count'] ?? 0);

    $_SESSION['flash'] = [
        'title' => 'Ribilanciamento completato',
        'details' => [
            "ordini proposti: {$orders}",
            'I risultati del ribilanciamento sono stati caricati nella pagina del portafoglio.'
        ],
        'code' => 20
    ];
} catch (Throwable $e) {
    $_SESSION['flash'] = [
        'title' => 'Errore',
        'details' => [$e->getMessage()],
        'code' => 1
    ];
}

header('Location: ../portfolio.php?id=' . $portfolioId);
exit;

/**
 * Costruisce l'albero del portafoglio leggendo dal DB.
 * Ritorna:
 * [Bucket $root, array $globalInfo, array $assetIndex]
 */
function preparePortfolioTreeFromDb(PDO $pdo, int $portfolioId, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT
            ID_Portafoglio,
            ID_Utente,
            ID_Radice,
            Valuta,
            Liquidita,
            TargetLiquiditaPct,
            Tolleranza,
            Commissione,
            TipoCommissione
        FROM Portafoglio
        WHERE ID_Portafoglio = ? AND ID_Utente = ?
    ");
    $stmt->execute([$portfolioId, $userId]);
    $pf = $stmt->fetch();

    if (!$pf) {
        throw new RuntimeException('Portafoglio non trovato');
    }

    $rootBucketId = (int)$pf['ID_Radice'];

    [$bucketById, $childrenMap] = loadBucketSubtree($pdo, $rootBucketId);

    $commissione = $pf['Commissione'] !== null ? (float)$pf['Commissione'] : 0.0;
    $tipoCommissione = (string)($pf['TipoCommissione'] ?? 'Fissa');

    $globalInfo = [
        'commissioneFissa' => $tipoCommissione === 'Fissa' ? $commissione : 0.0,
        'commissionePerc'  => $tipoCommissione === 'Percentuale' ? ($commissione / 100.0) : 0.0,
        'tolleranza'       => (float)$pf['Tolleranza'],
        'minTrade'         => max(20.0, ($tipoCommissione === 'Fissa' ? $commissione * 5.0 : 20.0)),
        'accPct'           => max(0.0, (float)$pf['Tolleranza'] / 5.0),
        'commissionTotal'  => 0.0,
        'taxTotal'         => 0.0,
        'residualCash'     => 0.0,
        'cashNet'          => 0.0,
        'defaultTaxRate'   => 0.26,
        'defaultTradeStep' => 1,
        'wf_recompute_phase3' => false,
        'feeFixed'         => $tipoCommissione === 'Fissa' ? $commissione : 0.0,
        'feeRate'          => $tipoCommissione === 'Percentuale' ? ($commissione / 100.0) : 0.0,
        'portfolioId'      => $portfolioId,
        'rootBucketId'     => $rootBucketId,
        'valuta'           => (string)$pf['Valuta'],
    ];

    $root = new Bucket();
    $root->id = '';
    $root->name = 'ROOT';
    $root->type = 'bucket';
    $root->targetPct = 100.0;

    $assetIndex = [];

    // asset direttamente nella root
    $root->children = array_merge(
        buildAssetNodesForBucket($pdo, $rootBucketId, '', $globalInfo, $assetIndex),
        buildChildBucketNodes($pdo, $rootBucketId, '', $bucketById, $childrenMap, $globalInfo, $assetIndex)
    );

    $liq = new Liquidita();
    $liq->id = '/LIQUIDITA';
    $liq->type = 'liquidita';
    $liq->targetPct = (float)$pf['TargetLiquiditaPct'];
    $liq->qty = (float)$pf['Liquidita'];

    $root->children[] = $liq;

    return [$root, $globalInfo, $assetIndex];
}

function loadBucketSubtree(PDO $pdo, int $rootBucketId): array {
    $bucketById = [];
    $childrenMap = [];

    $stmtRoot = $pdo->prepare("
        SELECT ID_Bucket, ID_Padre, Nome, TargetPctSuPadre
        FROM Bucket
        WHERE ID_Bucket = ?
    ");
    $stmtRoot->execute([$rootBucketId]);
    $rootRow = $stmtRoot->fetch();

    if (!$rootRow) {
        throw new RuntimeException('Bucket radice non trovato');
    }

    $bucketById[$rootBucketId] = [
        'ID_Bucket' => (int)$rootRow['ID_Bucket'],
        'ID_Padre' => $rootRow['ID_Padre'] !== null ? (int)$rootRow['ID_Padre'] : null,
        'Nome' => (string)$rootRow['Nome'],
        'TargetPctSuPadre' => $rootRow['TargetPctSuPadre'] !== null ? (float)$rootRow['TargetPctSuPadre'] : null,
    ];

    $queue = [$rootBucketId];

    while (!empty($queue)) {
        $batch = array_splice($queue, 0, 50);
        $placeholders = implode(',', array_fill(0, count($batch), '?'));

        $stmt = $pdo->prepare("
            SELECT ID_Bucket, ID_Padre, Nome, TargetPctSuPadre
            FROM Bucket
            WHERE ID_Padre IN ($placeholders)
            ORDER BY Nome
        ");
        $stmt->execute($batch);
        $rows = $stmt->fetchAll();

        foreach ($rows as $r) {
            $idB = (int)$r['ID_Bucket'];
            $idP = $r['ID_Padre'] !== null ? (int)$r['ID_Padre'] : null;

            if (isset($bucketById[$idB])) {
                continue;
            }

            $bucketById[$idB] = [
                'ID_Bucket' => $idB,
                'ID_Padre' => $idP,
                'Nome' => (string)$r['Nome'],
                'TargetPctSuPadre' => $r['TargetPctSuPadre'] !== null ? (float)$r['TargetPctSuPadre'] : null,
            ];

            if (!isset($childrenMap[$idP])) {
                $childrenMap[$idP] = [];
            }
            $childrenMap[$idP][] = $idB;
            $queue[] = $idB;
        }
    }

    return [$bucketById, $childrenMap];
}

function buildChildBucketNodes(
    PDO $pdo,
    int $parentBucketId,
    string $parentPath,
    array $bucketById,
    array $childrenMap,
    array $globalInfo,
    array &$assetIndex
): array {
    $out = [];
    $childIds = $childrenMap[$parentBucketId] ?? [];

    foreach ($childIds as $bucketId) {
        $b = $bucketById[$bucketId];

        $node = new Bucket();
        $node->type = 'bucket';
        $node->name = (string)$b['Nome'];
        $node->targetPct = $b['TargetPctSuPadre'] !== null ? (float)$b['TargetPctSuPadre'] : 0.0;

        $node->id = 'B|' . $bucketId;

        $node->children = array_merge(
            buildAssetNodesForBucket($pdo, $bucketId, $node->id, $globalInfo, $assetIndex),
            buildChildBucketNodes($pdo, $bucketId, $node->id, $bucketById, $childrenMap, $globalInfo, $assetIndex)
        );

        $out[] = $node;
    }

    return $out;
}

function buildAssetNodesForBucket(
    PDO $pdo,
    int $bucketId,
    string $parentPath,
    array $globalInfo,
    array &$assetIndex
): array {
    $stmt = $pdo->prepare("
        SELECT
            ca.ID_Bucket,
            ca.ISIN,
            ca.TargetPctNelBucket,
            ca.TaxRatePct,
            a.Nome AS AssetNome,
            a.Valuta,
            a.Tipo,
            CASE
                WHEN a.Tipo = 'ETF' THEN e.Ticker
                WHEN a.Tipo = 'Azione' THEN az.Ticker
                ELSE NULL
            END AS Ticker
        FROM ContenutoAsset ca
        INNER JOIN Asset a ON a.ISIN = ca.ISIN
        LEFT JOIN ETF e ON e.ISIN = a.ISIN
        LEFT JOIN Azione az ON az.ISIN = a.ISIN
        WHERE ca.ID_Bucket = ?
        ORDER BY a.Tipo, a.Nome, ca.ISIN
    ");
    $stmt->execute([$bucketId]);
    $rows = $stmt->fetchAll();

    $out = [];

    foreach ($rows as $r) {
        $tipo = strtolower((string)$r['Tipo']);

        $node = new Asset();
        $node->type = 'asset';
        $node->subtype = $tipo;
        $node->isBond = ($tipo === 'obbligazione');
        $node->targetPct = $r['TargetPctNelBucket'] !== null ? (float)$r['TargetPctNelBucket'] : 0.0;
        $node->taxRate = $r['TaxRatePct'] !== null ? (float)$r['TaxRatePct'] : (float)$globalInfo['defaultTaxRate'];
        $node->tradeStep = $node->isBond ? 1000 : 1;

        $isin = (string)$r['ISIN'];
        $ticker = trim((string)($r['Ticker'] ?? ''));

        if ($node->isBond) {
            $node->ticker = $isin;
            $assetKey = $isin;
        } else {
            $node->ticker = $ticker !== '' ? $ticker : $isin;
            $assetKey = $node->ticker;
        }

        [$node->wac, $node->qty] = getWAC_DB($pdo, $bucketId, $isin);

        $node->id = $node->id = 'A|' . $bucketId . '|' . $isin;

        $out[] = $node;

        $assetIndex[$node->id] = [
            'bucketId' => $bucketId,
            'isin' => $isin,
            'ticker' => $ticker,
            'tipo' => (string)$r['Tipo'],
            'nome' => $r['AssetNome'] !== null && $r['AssetNome'] !== '' ? (string)$r['AssetNome'] : ($ticker !== '' ? $ticker : $isin),
        ];
    }

    return $out;
}

function buildPriceCacheFromTree(Bucket $root): array {
    $priceCache = [];
    $assets = collectAssets($root, false);

    foreach ($assets as $asset) {
        if (!($asset instanceof Asset)) {
            continue;
        }

        if ($asset->isBond) {
            $price = getCachedBondPrice($asset->ticker);
        } else {
            $price = getCachedPriceYahoo($asset->ticker);
        }

        if (is_numeric($price) && (float)$price > 0.0) {
            $priceCache[$asset->ticker] = (float)$price;
        }
    }

    return $priceCache;
}

function normalizeRebalanceResult(array $result, array $assetIndex): array {
    $opsIn = $result['ops'] ?? [];
    $summary = $result['summary'] ?? [];

    $opsOut = [];

    foreach ($opsIn as $id => $pair) {
        $deltaQty = $pair[0] ?? 0;
        $note = trim((string)($pair[1] ?? ''));

        $meta = $assetIndex[$id] ?? [
            'bucketId' => null,
            'isin' => '',
            'ticker' => '',
            'tipo' => '',
            'nome' => $id,
        ];

        $opsOut[] = [
            'id' => $id,
            'bucketId' => $meta['bucketId'],
            'isin' => $meta['isin'],
            'ticker' => $meta['ticker'],
            'tipo' => $meta['tipo'],
            'nome' => $meta['nome'],
            'deltaQty' => $deltaQty,
            'note' => $note,
        ];
    }

    usort($opsOut, function(array $a, array $b) {
        $da = abs((float)$a['deltaQty']);
        $db = abs((float)$b['deltaQty']);
        if ($da === $db) {
            return strcmp((string)$a['nome'], (string)$b['nome']);
        }
        return $db <=> $da;
    });

    return [
        'generatedAt' => date('c'),
        'ops' => $opsOut,
        'summary' => $summary,
    ];
}