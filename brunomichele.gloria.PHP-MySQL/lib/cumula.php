<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/misc.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$pdo = getPDO();
$user = requireLogin($pdo);
$userId = (int)$user['ID_Utente'];

$portfolioId = isset($_POST['portfolio_id']) ? (int)$_POST['portfolio_id'] : 0;

if ($portfolioId <= 0) {
    setFlash('error', 'Errore', ['Portfolio non valido.'], 'CUMUL_400');
    header('Location: ../index.php');
    exit;
}

try {
    $stmt = $pdo->prepare('
        SELECT ID_Radice, ID_Utente
        FROM Portafoglio
        WHERE ID_Portafoglio = :pid
        LIMIT 1
    ');
    $stmt->execute([':pid' => $portfolioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        setFlash('error', 'Errore', ['Portafoglio non trovato.'], 'CUMUL_404');
        header('Location: ../index.php');
        exit;
    }

    if ((int)$row['ID_Utente'] !== $userId) {
        setFlash('error', 'Errore', ['Accesso non autorizzato al portafoglio richiesto.'], 'CUMUL_403');
        header('Location: ../index.php');
        exit;
    }

    $rootBucketId = (int)$row['ID_Radice'];
    $allBucketIds = getBucketSubtreeIds($pdo, $rootBucketId);

    $stmtAssets = $pdo->prepare('
        SELECT ISIN
        FROM ContenutoAsset
        WHERE ID_Bucket = :bid
    ');

    $stmtDeleteOps = $pdo->prepare('
        DELETE FROM Operazione
        WHERE ID_Bucket = :bid AND ISIN = :isin
    ');

    $stmtInsertCumulated = $pdo->prepare('
        INSERT INTO Operazione (ID_Bucket, ISIN, DataOra, Tipo, Quantita, PrezzoEseguito)
        VALUES (:bid, :isin, :dt, \'BUY\', :qty, :px)
    ');

    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s');

    $warnings = [];
    $assetsProcessed = 0;
    $opsDeletedTotal = 0;
    $opsInsertedTotal = 0;
    $skippedNegative = 0;

    $pdo->beginTransaction();

    foreach ($allBucketIds as $bid) {
        $bid = (int)$bid;

        $stmtAssets->execute([':bid' => $bid]);
        $isins = $stmtAssets->fetchAll(PDO::FETCH_COLUMN, 0);

        foreach ($isins as $isin) {
            $isin = (string)$isin;

            [$avgCost, $qty] = getWAC_DB($pdo, $bid, $isin);
            $avgCost = (float)$avgCost;
            $qty = (float)$qty;

            if ($qty < 0.0) {
                $skippedNegative++;
                $warnings[] = "Skip cumula: bucket $bid, ISIN $isin, qty negativa ($qty).";
                $assetsProcessed++;
                continue;
            }

            $stmtDeleteOps->execute([':bid' => $bid, ':isin' => $isin]);
            $opsDeletedTotal += $stmtDeleteOps->rowCount();

            if ($qty > 0.0) {
                if (!is_finite($avgCost) || $avgCost < 0.0) {
                    throw new RuntimeException("WAC non valido per bucket $bid, ISIN $isin.");
                }

                $stmtInsertCumulated->execute([
                    ':bid' => $bid,
                    ':isin' => $isin,
                    ':dt' => $now,
                    ':qty' => $qty,
                    ':px' => $avgCost
                ]);
                $opsInsertedTotal++;
            }

            $assetsProcessed++;
        }
    }

    $pdo->commit();

    $lines = [
        "Asset processati: $assetsProcessed",
        "Operazioni cancellate: $opsDeletedTotal",
        "Operazioni inserite: $opsInsertedTotal"
    ];

    if ($skippedNegative > 0) {
        $lines[] = "Asset saltati per qty negativa: $skippedNegative";
        $lines = array_merge($lines, $warnings);
        setFlash('warning', 'Cumula completata con avvisi', $lines, 'CUMUL_WARN');
    } else {
        setFlash('ok', 'Cumula completata', $lines, 'CUMUL_OK');
    }

    header('Location: ../portfolio.php?id=' . urlencode((string)$portfolioId));
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('error', 'Errore cumula', [$e->getMessage()], 'CUMUL_ERR');
    header('Location: ../portfolio.php?id=' . urlencode((string)$portfolioId));
    exit;
}