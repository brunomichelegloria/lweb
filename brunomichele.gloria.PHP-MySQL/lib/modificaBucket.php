<?php
session_start();
require_once __DIR__ . '/misc.php';

$pdo = getPDO();
$user = requireLogin($pdo);
$userId = (int)$user['ID_Utente'];

$payloadRaw = $_POST['payload'] ?? '';
$payload = json_decode($payloadRaw, true);

if (!is_array($payload)) {
    http_response_code(400);
    $_SESSION['flash'] = ['msg' => 'Richiesta non valida'];
    header('Location: ../selectPortfolio.php');
    exit;
}

$portfolioId = (int)($payload['portfolioId'] ?? 0);
$scopeBucketId = (int)($payload['scopeBucketId'] ?? 0);
$changes = $payload['changes'] ?? null;

if ($portfolioId <= 0 || $scopeBucketId <= 0 || !is_array($changes)) {
    http_response_code(400);
    $_SESSION['flash'] = ['msg' => 'Richiesta non valida'];
    header('Location: ../selectPortfolio.php');
    exit;
}

function parseNullableFloat($v): ?float {
    if ($v === null) return null;
    if (is_string($v)) {
        $v = trim($v);
        if ($v === '') return null;
        $v = str_replace(',', '.', $v);
    }
    if (is_numeric($v)) return (float)$v;
    return null;
}

function isBucketInSubtree(PDO $pdo, int $bucketId, int $rootBucketId): bool {
    $cur = $bucketId;
    $guard = 0;

    while ($guard < 200) {
        $guard++;

        if ($cur === $rootBucketId) return true;

        $stmt = $pdo->prepare("SELECT ID_Padre FROM Bucket WHERE ID_Bucket = ?");
        $stmt->execute([$cur]);
        $row = $stmt->fetch();

        if (!$row) return false;
        if ($row['ID_Padre'] === null) return false;

        $cur = (int)$row['ID_Padre'];
    }

    return false;
}

function subtreeHasAnyAsset(PDO $pdo, int $bucketId): bool {
    $queue = [$bucketId];
    $guard = 0;

    while (!empty($queue) && $guard < 5000) {
        $guard++;
        $cur = array_shift($queue);

        $stmt = $pdo->prepare("SELECT 1 FROM ContenutoAsset WHERE ID_Bucket = ? LIMIT 1");
        $stmt->execute([$cur]);
        if ($stmt->fetch()) return true;

        $stmt = $pdo->prepare("SELECT ID_Bucket FROM Bucket WHERE ID_Padre = ?");
        $stmt->execute([$cur]);
        $children = $stmt->fetchAll();

        foreach ($children as $r) {
            $queue[] = (int)$r['ID_Bucket'];
        }
    }

    return false;
}

$stmt = $pdo->prepare("
    SELECT ID_Portafoglio, ID_Utente, ID_Radice
    FROM Portafoglio
    WHERE ID_Portafoglio = ? AND ID_Utente = ?
");
$stmt->execute([$portfolioId, $userId]);
$pf = $stmt->fetch();

if (!$pf) {
    http_response_code(404);
    $_SESSION['flash'] = ['msg' => 'Portafoglio non trovato'];
    header('Location: ../selectPortfolio.php');
    exit;
}

$rootBucketId = (int)$pf['ID_Radice'];

if (!isBucketInSubtree($pdo, $scopeBucketId, $rootBucketId)) {
    http_response_code(403);
    $_SESSION['flash'] = ['msg' => 'Operazione non autorizzata'];
    header('Location: ../portfolio.php?id=' . $portfolioId);
    exit;
}

$isRoot = ($scopeBucketId === $rootBucketId);

$ignoredBucketDeletes = 0;

$appliedCount = 0;
$errorCount = 0;
$lines = [];

function addLine(array &$lines, string $s): void {
    $lines[] = $s;
}

try {
    $pdo->beginTransaction();

    foreach ($changes as $ch) {
        if (!is_array($ch)) continue;

        $kind = (string)($ch['kind'] ?? '');
        $key = (string)($ch['key'] ?? '');
        $fields = $ch['fields'] ?? [];

        if (!is_array($fields)) $fields = [];

        if ($kind === 'portfolio') {
            if (!$isRoot) continue;

            $liq = parseNullableFloat($fields['Liquidita'] ?? null);
            $tliq = parseNullableFloat($fields['TargetLiquiditaPct'] ?? null);
            $tol = parseNullableFloat($fields['Tolleranza'] ?? null);
            $comm = parseNullableFloat($fields['Commissione'] ?? null);
            $tipoComm = (string)($fields['TipoCommissione'] ?? 'Fissa');
            $valuta = (string)($fields['Valuta'] ?? 'EUR');

            if (!in_array($tipoComm, ['Fissa', 'Percentuale'], true)) $tipoComm = 'Fissa';

            $upd = $pdo->prepare("
                UPDATE Portafoglio
                SET
                    Liquidita = COALESCE(?, Liquidita),
                    TargetLiquiditaPct = COALESCE(?, TargetLiquiditaPct),
                    Tolleranza = COALESCE(?, Tolleranza),
                    Commissione = ?,
                    TipoCommissione = ?,
                    Valuta = COALESCE(NULLIF(?, ''), Valuta)
                WHERE ID_Portafoglio = ? AND ID_Utente = ?
            ");
            $upd->execute([
                $liq,
                $tliq,
                $tol,
                $comm,
                $tipoComm,
                $valuta,
                $portfolioId,
                $userId
            ]);

            $appliedCount++;
            addLine($lines, "aggiornato portafoglio");
            continue;
        }

        if ($kind === 'bucket') {
            $isNew = ((string)($fields['new'] ?? '0') === '1');
            $isRemove = ((string)($fields['remove'] ?? '0') === '1');

            $nome = (string)($fields['Nome'] ?? '');
            $target = parseNullableFloat($fields['TargetPctSuPadre'] ?? null);

            $idBucket = (int)($fields['ID_Bucket'] ?? 0);

            if ($isNew) {
                if ($isRemove) continue;
                if (trim($nome) === '') continue;

                $ins = $pdo->prepare("
                    INSERT INTO Bucket (ID_Padre, Nome, TargetPctSuPadre)
                    VALUES (?, ?, ?)
                ");
                $ins->execute([
                    $scopeBucketId,
                    $nome,
                    $target
                ]);
                
                $appliedCount++;
                addLine($lines, "aggiunto bucket: {$nome}");
                continue;
            }
                       if ($idBucket <= 0) continue;

            $stmt = $pdo->prepare("SELECT ID_Padre FROM Bucket WHERE ID_Bucket = ?");
            $stmt->execute([$idBucket]);
            $row = $stmt->fetch();

            if (!$row) continue;
            if ($row['ID_Padre'] === null) continue;
            if ((int)$row['ID_Padre'] !== $scopeBucketId) continue;

            if ($isRemove) {
                if (subtreeHasAnyAsset($pdo, $idBucket)) {
                    $ignoredBucketDeletes++;
                    $errorCount++;
                    addLine($lines, "errore bucket {$idBucket}: contiene asset nel sottoalbero; bucket non rimosso");
                    continue;
                }

                $del = $pdo->prepare("DELETE FROM Bucket WHERE ID_Bucket = ?");
                $del->execute([$idBucket]);
                
                $appliedCount++;
                addLine($lines, "rimosso bucket: {$idBucket}");
                continue;
            }

            if (trim($nome) === '') continue;

            $upd = $pdo->prepare("
                UPDATE Bucket
                SET Nome = ?, TargetPctSuPadre = ?
                WHERE ID_Bucket = ?
            ");
            $upd->execute([$nome, $target, $idBucket]);

            $appliedCount++;
            addLine($lines, "aggiornato bucket: {$nome}");
            continue;
        }

        if ($kind === 'azione' || $kind === 'etf' || $kind === 'obbligazione') {
            $isNew = ((string)($fields['new'] ?? '0') === '1');
            $isRemove = ((string)($fields['remove'] ?? '0') === '1');

            $idBucket = (int)($fields['ID_Bucket'] ?? 0);
            if ($idBucket !== $scopeBucketId) continue;

            $tipo = (string)($fields['tipo'] ?? '');
            if ($tipo === '') {
                if ($kind === 'azione') $tipo = 'Azione';
                if ($kind === 'etf') $tipo = 'ETF';
                if ($kind === 'obbligazione') $tipo = 'Obbligazione';
            }

            if (!in_array($tipo, ['ETF', 'Azione', 'Obbligazione'], true)) continue;

            $isin = (string)($fields['ISIN'] ?? '');
            $isinDetails = (string)($fields['ISIN_DETAILS'] ?? '');

            if ($tipo === 'ETF' && $isin === '' && $isinDetails !== '') {
                $isin = $isinDetails;
            }

            $isin = trim($isin);
            if ($isin === '') {
                $errorCount++;
                addLine($lines, "errore asset {$isin}: ISIN non valido; asset non aggiunto");
                continue;
            }

            if (!$isNew && $key !== '' && str_starts_with($key, 'A:')) {
                $parts = explode(':', $key, 3);
                if (count($parts) === 3) {
                    $kBucket = (int)$parts[1];
                    $kIsin = (string)$parts[2];
                    if ($kBucket !== $scopeBucketId || $kIsin !== $isin) continue;
                }
            }

            if ($isRemove) {
                $del = $pdo->prepare("DELETE FROM ContenutoAsset WHERE ID_Bucket = ? AND ISIN = ?");
                $del->execute([$scopeBucketId, $isin]);
                $appliedCount++;
                addLine($lines, "rimosso asset: {$isin}");
                continue;
            }

            $assetNome = (string)($fields['Nome'] ?? '');
            $valuta = (string)($fields['Valuta'] ?? 'EUR');
            $borsa = (string)($fields['Borsa'] ?? '');

            $targetNelBucket = parseNullableFloat($fields['TargetPctNelBucket'] ?? null);

            $taxPctInput = parseNullableFloat($fields['TaxRate'] ?? null);
            $taxRatePct = null;
            if ($taxPctInput !== null) {
                $taxRatePct = $taxPctInput / 100.0;
                if ($taxRatePct < 0) $taxRatePct = 0.0;
                if ($taxRatePct > 1) $taxRatePct = 1.0;
            }

            $stmt = $pdo->prepare("SELECT ISIN, Tipo FROM Asset WHERE ISIN = ?");
            $stmt->execute([$isin]);
            $existingAsset = $stmt->fetch();

            if (!$existingAsset) {
                $insA = $pdo->prepare("
                    INSERT INTO Asset (ISIN, Nome, Valuta, Tipo, Borsa)
                    VALUES (?, NULLIF(?, ''), COALESCE(NULLIF(?, ''), 'EUR'), ?, NULLIF(?, ''))
                ");
                $insA->execute([$isin, $assetNome, $valuta, $tipo, $borsa]);
            } else {
                $updA = $pdo->prepare("
                    UPDATE Asset
                    SET Nome = NULLIF(?, ''), Valuta = COALESCE(NULLIF(?, ''), Valuta), Borsa = NULLIF(?, '')
                    WHERE ISIN = ?
                ");
                $updA->execute([$assetNome, $valuta, $borsa, $isin]);
            }

                       if ($tipo === 'ETF') {
                $ticker = (string)($fields['Ticker'] ?? '');
                $ter = parseNullableFloat($fields['TER'] ?? null);
                $dist = (string)($fields['Distribuzione'] ?? 'Accumulating');
                $indice = (string)($fields['Indice'] ?? '');

                if (!in_array($dist, ['Accumulating', 'Distributing'], true)) $dist = 'Accumulating';

                $stmt = $pdo->prepare("SELECT 1 FROM ETF WHERE ISIN = ? LIMIT 1");
                $stmt->execute([$isin]);
                if ($stmt->fetch()) {
                    $updE = $pdo->prepare("
                        UPDATE ETF
                        SET
                            Ticker = COALESCE(NULLIF(?, ''), Ticker),
                            TER = ?,
                            Distribuzione = ?,
                            Indice = NULLIF(?, '')
                        WHERE ISIN = ?
                    ");
                    $updE->execute([$ticker, $ter, $dist, $indice, $isin]);
                } else {
                    $insE = $pdo->prepare("
                        INSERT INTO ETF (ISIN, Ticker, TER, Distribuzione, Indice)
                        VALUES (?, ?, ?, ?, NULLIF(?, ''))
                    ");
                    $insE->execute([$isin, $ticker, $ter, $dist, $indice]);
                }
            }

            if ($tipo === 'Azione') {
                $ticker = trim((string)($fields['Ticker'] ?? ''));

                if ($ticker === '') {
                    $errorCount++;
                    addLine($lines, "errore asset {$isin}: ticker mancante; asset non aggiunto");
                    continue;
                }

                $stmt = $pdo->prepare("SELECT 1 FROM Azione WHERE ISIN = ? LIMIT 1");
                $stmt->execute([$isin]);

                if ($stmt->fetch()) {
                    $upd = $pdo->prepare("
                        UPDATE Azione
                        SET Ticker = ?
                        WHERE ISIN = ?
                    ");
                    $upd->execute([$ticker, $isin]);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO Azione (ISIN, Ticker)
                        VALUES (?, ?)
                    ");
                    $ins->execute([$isin, $ticker]);
                }
            }

            if ($tipo === 'Obbligazione') {
                $scadenza = (string)($fields['Scadenza'] ?? '');
                $cedola = parseNullableFloat($fields['CedolaPct'] ?? null);
                $freq = (string)($fields['FrequenzaCedola'] ?? '');

                if ($freq !== '' && !in_array($freq, ['Annuale', 'Semestrale', 'Triennale', 'Mensile'], true)) {
                    $freq = '';
                }

                $stmt = $pdo->prepare("SELECT 1 FROM Obbligazione WHERE ISIN = ? LIMIT 1");
                $stmt->execute([$isin]);
                if ($stmt->fetch()) {
                    $updO = $pdo->prepare("
                        UPDATE Obbligazione
                        SET Scadenza = COALESCE(NULLIF(?, ''), Scadenza), CedolaPct = ?, FrequenzaCedola = NULLIF(?, '')
                        WHERE ISIN = ?
                    ");
                    $updO->execute([$scadenza, $cedola, $freq, $isin]);
                } else {
                    /*if (trim($scadenza) === '') {
                        $errorCount++;
                        addLine($lines, "errore asset {$isin}: scadenza mancante; asset non aggiunto");
                        continue;
                    }    DA RI-AGGUNGERE A PROGETTO FINITO           */
                    $insO = $pdo->prepare("
                        INSERT INTO Obbligazione (ISIN, Scadenza, CedolaPct, FrequenzaCedola)
                        VALUES (?, ?, ?, NULLIF(?, ''))
                    ");
                    $insO->execute([$isin, $scadenza, $cedola, $freq]);
                }
            }
            $stmt = $pdo->prepare("SELECT 1 FROM ContenutoAsset WHERE ID_Bucket = ? AND ISIN = ? LIMIT 1");
            $stmt->execute([$scopeBucketId, $isin]);
            $hasCA = (bool)$stmt->fetch();

            if ($hasCA) {
                $updC = $pdo->prepare("
                    UPDATE ContenutoAsset
                    SET
                        TargetPctNelBucket = ?,
                        TaxRatePct = COALESCE(?, TaxRatePct)
                    WHERE ID_Bucket = ? AND ISIN = ?
                ");
                $updC->execute([$targetNelBucket, $taxRatePct, $scopeBucketId, $isin]);
                $appliedCount++;
                addLine($lines, "aggiornato asset: {$isin}");
            } else {
                $insC = $pdo->prepare("
                    INSERT INTO ContenutoAsset (ID_Bucket, ISIN, TargetPctNelBucket, TaxRatePct)
                    VALUES (?, ?, ?, COALESCE(?, 0.260))
                ");
                $insC->execute([$scopeBucketId, $isin, $targetNelBucket, $taxRatePct]);
                $appliedCount++;
                addLine($lines, "aggiunto asset: {$isin}");
            }

            continue;
        }
    }

    if ($appliedCount > 0) {
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }

    $title = 'Modifiche salvate';
    $code = 20;

    if ($errorCount > 0 && $appliedCount === 0) {
        $title = 'Errore';
        $code = 1;
    } else if ($errorCount > 0 && $appliedCount > 0) {
        $title = 'Modifiche parziali';
        $code = 2;
    }

    if ($appliedCount === 0 && $errorCount === 0) {
        $lines[] = 'nessuna modifica da applicare';
    }

    $_SESSION['flash'] = [
        'title' => $title,
        'details' => $lines,
        'code' => $code
    ];

    header('Location: ../portfolio.php?id=' . $portfolioId);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    $_SESSION['flash'] = [
        'title' => 'Errore',
        'details' => [
            $e->getMessage() . ". Operazione annullata."
        ],
        'code' => 1
    ];
    header('Location: ../portfolio.php?id=' . $portfolioId);
    exit;
}
