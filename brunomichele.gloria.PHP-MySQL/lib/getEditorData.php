<?php
session_start();
require_once __DIR__ . '/misc.php';

$pdo = getPDO();
$user = requireLogin($pdo);
$userId = (int)$user['ID_Utente'];

$portfolioId = (int)($_GET['portfolioId'] ?? 0);
$scopeBucketId = (int)($_GET['bucketId'] ?? 0);

if ($portfolioId <= 0 || $scopeBucketId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Bad request']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT ID_Portafoglio, ID_Utente, Nome, Valuta, Liquidita, TargetLiquiditaPct, Tolleranza, Commissione, TipoCommissione, ID_Radice
    FROM Portafoglio
    WHERE ID_Portafoglio = ? AND ID_Utente = ?
");
$stmt->execute([$portfolioId, $userId]);
$pf = $stmt->fetch();

if (!$pf) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Portfolio not found']);
    exit;
}

$rootBucketId = (int)$pf['ID_Radice'];

$stmt = $pdo->prepare("SELECT ID_Bucket, ID_Padre, Nome, TargetPctSuPadre FROM Bucket WHERE ID_Bucket = ?");
$stmt->execute([$scopeBucketId]);
$scopeBucket = $stmt->fetch();

if (!$scopeBucket) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Bucket not found']);
    exit;
}

$okSubtree = false;
$cur = $scopeBucketId;
$guard = 0;

while ($guard < 200) {
    $guard++;

    if ($cur === $rootBucketId) {
        $okSubtree = true;
        break;
    }

    $stmt = $pdo->prepare("SELECT ID_Padre FROM Bucket WHERE ID_Bucket = ?");
    $stmt->execute([$cur]);
    $row = $stmt->fetch();

    if (!$row) {
        break;
    }

    if ($row['ID_Padre'] === null) {
        break;
    }

    $cur = (int)$row['ID_Padre'];
}

if (!$okSubtree) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT ID_Bucket, ID_Padre, Nome, TargetPctSuPadre
    FROM Bucket
    WHERE ID_Padre = ?
    ORDER BY Nome
");
$stmt->execute([$scopeBucketId]);
$childBucketsRows = $stmt->fetchAll();

$childBuckets = [];
foreach ($childBucketsRows as $r) {
    $childBuckets[] = [
        'ID_Bucket' => (int)$r['ID_Bucket'],
        'ID_Padre' => $r['ID_Padre'] !== null ? (int)$r['ID_Padre'] : null,
        'Nome' => (string)$r['Nome'],
        'TargetPctSuPadre' => $r['TargetPctSuPadre'] !== null ? (float)$r['TargetPctSuPadre'] : null,
    ];
}

$stmt = $pdo->prepare("
    SELECT
        ca.ID_Bucket,
        ca.ISIN,
        ca.TargetPctNelBucket,
        ca.TaxRatePct,
        a.Nome AS AssetNome,
        a.Valuta,
        a.Tipo,
        a.Borsa,
        e.Ticker,
        e.TER,
        e.Distribuzione,
        e.Indice,
        o.Scadenza,
        o.CedolaPct,
        o.FrequenzaCedola
    FROM ContenutoAsset ca
    INNER JOIN Asset a ON a.ISIN = ca.ISIN
    LEFT JOIN ETF e ON e.ISIN = a.ISIN
    LEFT JOIN Obbligazione o ON o.ISIN = a.ISIN
    WHERE ca.ID_Bucket = ?
    ORDER BY a.Tipo, a.Nome, ca.ISIN
");
$stmt->execute([$scopeBucketId]);
$assetRows = $stmt->fetchAll();

$assets = [];
foreach ($assetRows as $r) {
    $tipo = (string)$r['Tipo'];

    $item = [
        'ID_Bucket' => (int)$r['ID_Bucket'],
        'ISIN' => (string)$r['ISIN'],
        'Tipo' => $tipo,
        'Nome' => $r['AssetNome'] !== null ? (string)$r['AssetNome'] : '',
        'Valuta' => (string)$r['Valuta'],
        'Borsa' => $r['Borsa'] !== null ? (string)$r['Borsa'] : '',
        'TargetPctNelBucket' => $r['TargetPctNelBucket'] !== null ? (float)$r['TargetPctNelBucket'] : null,
        'TaxRatePct' => $r['TaxRatePct'] !== null ? (float)$r['TaxRatePct'] : null,
    ];

    if ($tipo === 'ETF') {
        $item['Ticker'] = $r['Ticker'] !== null ? (string)$r['Ticker'] : '';
        $item['TER'] = $r['TER'] !== null ? (float)$r['TER'] : null;
        $item['Distribuzione'] = $r['Distribuzione'] !== null ? (string)$r['Distribuzione'] : 'Accumulating';
        $item['Indice'] = $r['Indice'] !== null ? (string)$r['Indice'] : '';
    }

    if ($tipo === 'Obbligazione') {
        $item['Scadenza'] = $r['Scadenza'] !== null ? (string)$r['Scadenza'] : '';
        $item['CedolaPct'] = $r['CedolaPct'] !== null ? (float)$r['CedolaPct'] : null;
        $item['FrequenzaCedola'] = $r['FrequenzaCedola'] !== null ? (string)$r['FrequenzaCedola'] : '';
    }

    $assets[] = $item;
}

$isRoot = ($scopeBucketId === $rootBucketId);

$payload = [
    'ok' => true,
    'portfolioId' => (int)$pf['ID_Portafoglio'],
    'rootBucketId' => $rootBucketId,
    'scopeBucketId' => $scopeBucketId,
    'isRoot' => $isRoot,
    'bucket' => [
        'ID_Bucket' => (int)$scopeBucket['ID_Bucket'],
        'ID_Padre' => $scopeBucket['ID_Padre'] !== null ? (int)$scopeBucket['ID_Padre'] : null,
        'Nome' => (string)$scopeBucket['Nome'],
        'TargetPctSuPadre' => $scopeBucket['TargetPctSuPadre'] !== null ? (float)$scopeBucket['TargetPctSuPadre'] : null,
    ],
    'childBuckets' => $childBuckets,
    'assets' => $assets,
];

if ($isRoot) {
    $payload['portfolioInfo'] = [
        'Nome' => $pf['Nome'] !== null ? (string)$pf['Nome'] : '',
        'Valuta' => (string)$pf['Valuta'],
        'Liquidita' => (float)$pf['Liquidita'],
        'TargetLiquiditaPct' => (float)$pf['TargetLiquiditaPct'],
        'Tolleranza' => (float)$pf['Tolleranza'],
        'Commissione' => $pf['Commissione'] !== null ? (float)$pf['Commissione'] : null,
        'TipoCommissione' => (string)$pf['TipoCommissione'],
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload);