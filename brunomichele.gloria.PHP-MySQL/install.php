<?php
session_start();

require_once __DIR__ . '/lib/connection.php';
require_once __DIR__ . '/lib/misc.php';

$errors = [];
$messages = [];

$adminUsername = '';
$runDrop = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUsername = trim((string)($_POST['admin_username'] ?? ''));
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $action = (string)($_POST['action'] ?? 'install');

    if ($action === 'index') {
        header('Location: index.php');
        exit;
    }

    if ($adminUsername === '') {
        $errors[] = 'Inserisci username amministratore MySQL.';
    }

    if (!$errors) {
        try {
            $config = getDbConfig();
            $pdo = getPDO(false, true, $adminUsername, $adminPassword);

            $messages[] = 'Connessione amministrativa riuscita.';

            if ($action === 'drop') {
                runSqlFile($pdo, (string)$config['drop_sql']);
                $messages[] = 'drop.sql eseguito.';
                $messages[] = 'Database e utente applicativo rimossi.';
            } else {
                if (!empty($_POST['run_drop'])) {
                    runSqlFile($pdo, (string)$config['drop_sql']);
                    $messages[] = 'drop.sql eseguito.';
                }

                runSqlFile($pdo, (string)$config['init_sql']);
                $messages[] = 'init.sql eseguito.';

                ensureAppUser($pdo);
                $messages[] = 'Utente dbms configurato.';

                $pdoDb = getPDO(true, false);
                insertDemoData($pdoDb);

                $demo = $config['demo_user'] ?? [];
                $demoUsername = trim((string)($demo['username'] ?? 'demo'));
                $demoEmail = trim((string)($demo['email'] ?? 'demo@example.com'));
                $demoPassword = (string)($demo['password'] ?? 'demo');

                $messages[] = 'Dati demo inseriti.';
                $messages[] = 'Utente demo: ' . $demoUsername . '; Password demo: ' . $demoPassword;
                $messages[] = 'Installazione completata.';
            }

        } catch (Throwable $e) {
            $errors[] = 'Errore installazione: ' . $e->getMessage();
        }
    }
}

function runSqlFile(PDO $pdo, string $path): void {
    if (!is_file($path)) {
        throw new RuntimeException("File SQL non trovato: {$path}");
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("File SQL vuoto o non leggibile: {$path}");
    }

    $config = getDbConfig();

    $sql = str_replace(
        ['{{DB_HOST}}', '{{DB_NAME}}', '{{DB_USER}}', '{{DB_PASS}}', '{{DB_CHARSET}}', '{{DB_COLLATE}}'],
        [
            (string)$config['host'],
            str_replace('`', '``', (string)$config['database']),
            str_replace("'", "''", (string)$config['username']),
            str_replace("'", "''", (string)$config['password']),
            (string)$config['charset'],
            (string)$config['collate'],
        ],
        $sql
    );

    foreach (splitSqlStatements($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function splitSqlStatements(string $sql): array {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    return array_filter(array_map('trim', explode(';', $sql)));
}

function insertDemoData(PDO $pdo): void {
    $pdo->beginTransaction();

    try {
        $config = getDbConfig();

        $demo = $config['demo_user'] ?? [];
        $demoUsername = trim((string)($demo['username'] ?? 'demo'));
        $demoEmail = trim((string)($demo['email'] ?? 'demo@example.com'));
        $demoPassword = (string)($demo['password'] ?? 'demo');

        if ($demoUsername === '' || $demoPassword === '') {
            throw new RuntimeException('Utente demo non configurato correttamente in dati_generali.php.');
        }

        $passwordHash = password_hash($demoPassword, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO Utente (Username, Email, PasswordHash)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                Email = VALUES(Email),
                PasswordHash = VALUES(PasswordHash)
        ");
        $stmt->execute([$demoUsername, $demoEmail !== '' ? $demoEmail : null, $passwordHash]);

        $stmt = $pdo->prepare("SELECT ID_Utente FROM Utente WHERE Username = ?");
        $stmt->execute([$demoUsername]);
        $userId = (int)$stmt->fetchColumn();

        if ($userId <= 0) {
            throw new RuntimeException('Utente demo non creato.');
        }

        $cartellaId = ensureDemoCartella($pdo, $userId, 'Demo');

        createDemoPortfolio(
            $pdo,
            $userId,
            $cartellaId,
            'Portafoglio demo bilanciato',
            1500.00,
            5.000,
            5.000,
            1.0000,
            'Fissa'
        );

        createDemoPortfolio(
            $pdo,
            $userId,
            $cartellaId,
            'Portafoglio demo aggressivo',
            500.00,
            2.000,
            5.000,
            0.1000,
            'Percentuale'
        );

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function ensureDemoCartella(PDO $pdo, int $userId, string $nome): int {
    $stmt = $pdo->prepare("
        SELECT ID_Cartella
        FROM Cartella
        WHERE ID_Utente = ? AND ID_Padre IS NULL AND Nome = ?
    ");
    $stmt->execute([$userId, $nome]);
    $id = (int)$stmt->fetchColumn();

    if ($id > 0) {
        return $id;
    }

    $stmt = $pdo->prepare("
        INSERT INTO Cartella (ID_Utente, ID_Padre, Nome)
        VALUES (?, NULL, ?)
    ");
    $stmt->execute([$userId, $nome]);

    return (int)$pdo->lastInsertId();
}

function createDemoPortfolio(
    PDO $pdo,
    int $userId,
    int $cartellaId,
    string $nome,
    float $liquidita,
    float $targetLiquiditaPct,
    float $tolleranza,
    float $commissione,
    string $tipoCommissione
): void {
    $stmt = $pdo->prepare("
        SELECT p.ID_Portafoglio
        FROM Portafoglio p
        WHERE p.ID_Utente = ? AND p.ID_Cartella = ? AND p.Nome = ?
    ");
    $stmt->execute([$userId, $cartellaId, $nome]);

    if ($stmt->fetchColumn()) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO Bucket (ID_Padre, Nome, TargetPctSuPadre)
        VALUES (NULL, ?, NULL)
    ");
    $stmt->execute(['Root - ' . $nome]);
    $rootBucketId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO Portafoglio (
            ID_Cartella,
            ID_Utente,
            Nome,
            Valuta,
            Liquidita,
            TargetLiquiditaPct,
            Tolleranza,
            Commissione,
            TipoCommissione,
            ID_Radice
        )
        VALUES (?, ?, ?, 'EUR', ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $cartellaId,
        $userId,
        $nome,
        $liquidita,
        $targetLiquiditaPct,
        $tolleranza,
        $commissione,
        $tipoCommissione,
        $rootBucketId
    ]);

    $azioniBucketId = createDemoBucket($pdo, $rootBucketId, 'Azioni', 60.0000);
    $bondBucketId = createDemoBucket($pdo, $rootBucketId, 'Obbligazioni', 35.0000);

    upsertDemoAzione($pdo, 'US0378331005', 'Apple Inc.', 'USD', 'AAPL', $azioniBucketId, 60.0000, 3, 180.00);
    upsertDemoEtf($pdo, 'IE00B4L5Y983', 'iShares Core MSCI World UCITS ETF', 'EUR', 'SWDA.MI', $azioniBucketId, 40.0000, 5, 85.00);
    upsertDemoBond($pdo, 'IT0005438004', 'BTP demo 2030', 'EUR', $bondBucketId, 100.0000, 3000, 95.50);
}

function createDemoBucket(PDO $pdo, int $parentId, string $nome, float $target): int {
    $stmt = $pdo->prepare("
        INSERT INTO Bucket (ID_Padre, Nome, TargetPctSuPadre)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$parentId, $nome, $target]);

    return (int)$pdo->lastInsertId();
}

function upsertDemoAsset(PDO $pdo, string $isin, string $nome, string $valuta, string $tipo): void {
    $stmt = $pdo->prepare("
        INSERT INTO Asset (ISIN, Nome, Valuta, Tipo, Borsa)
        VALUES (?, ?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE
            Nome = VALUES(Nome),
            Valuta = VALUES(Valuta),
            Tipo = VALUES(Tipo)
    ");
    $stmt->execute([$isin, $nome, $valuta, $tipo]);
}

function upsertDemoAzione(PDO $pdo, string $isin, string $nome, string $valuta, string $ticker, int $bucketId, float $target, float $qty, float $price): void {
    upsertDemoAsset($pdo, $isin, $nome, $valuta, 'Azione');

    $stmt = $pdo->prepare("
        INSERT INTO Azione (ISIN, Ticker)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE Ticker = VALUES(Ticker)
    ");
    $stmt->execute([$isin, $ticker]);

    attachDemoAsset($pdo, $bucketId, $isin, $target);
    addDemoBuy($pdo, $bucketId, $isin, $qty, $price);
}

function upsertDemoEtf(PDO $pdo, string $isin, string $nome, string $valuta, string $ticker, int $bucketId, float $target, float $qty, float $price): void {
    upsertDemoAsset($pdo, $isin, $nome, $valuta, 'ETF');

    $stmt = $pdo->prepare("
        INSERT INTO ETF (ISIN, Ticker, TER, Distribuzione, Indice)
        VALUES (?, ?, 0.2000, 'Accumulating', 'MSCI World')
        ON DUPLICATE KEY UPDATE
            Ticker = VALUES(Ticker),
            TER = VALUES(TER),
            Distribuzione = VALUES(Distribuzione),
            Indice = VALUES(Indice)
    ");
    $stmt->execute([$isin, $ticker]);

    attachDemoAsset($pdo, $bucketId, $isin, $target);
    addDemoBuy($pdo, $bucketId, $isin, $qty, $price);
}

function upsertDemoBond(PDO $pdo, string $isin, string $nome, string $valuta, int $bucketId, float $target, float $qty, float $price): void {
    upsertDemoAsset($pdo, $isin, $nome, $valuta, 'Obbligazione');

    $stmt = $pdo->prepare("
        INSERT INTO Obbligazione (ISIN, Scadenza, CedolaPct, FrequenzaCedola)
        VALUES (?, '2030-12-31', 3.5000, 'Annuale')
        ON DUPLICATE KEY UPDATE
            Scadenza = VALUES(Scadenza),
            CedolaPct = VALUES(CedolaPct),
            FrequenzaCedola = VALUES(FrequenzaCedola)
    ");
    $stmt->execute([$isin]);

    attachDemoAsset($pdo, $bucketId, $isin, $target);
    addDemoBuy($pdo, $bucketId, $isin, $qty, $price);
}

function attachDemoAsset(PDO $pdo, int $bucketId, string $isin, float $target): void {
    $stmt = $pdo->prepare("
        INSERT INTO ContenutoAsset (ID_Bucket, ISIN, TargetPctNelBucket, TaxRatePct)
        VALUES (?, ?, ?, 0.2600)
        ON DUPLICATE KEY UPDATE
            TargetPctNelBucket = VALUES(TargetPctNelBucket),
            TaxRatePct = VALUES(TaxRatePct)
    ");
    $stmt->execute([$bucketId, $isin, $target]);
}

function addDemoBuy(PDO $pdo, int $bucketId, string $isin, float $qty, float $price): void {
    $stmt = $pdo->prepare("
        INSERT INTO Operazione (ID_Bucket, ISIN, DataOra, Tipo, Quantita, PrezzoEseguito)
        VALUES (?, ?, NOW(), 'BUY', ?, ?)
    ");
    $stmt->execute([$bucketId, $isin, $qty, $price]);
}

function ensureAppUser(PDO $pdo): void {
    $config = getDbConfig();

    $dbName = (string)$config['database'];
    $dbUser = (string)$config['username'];
    $dbPass = (string)$config['password'];
    $dbHost = (string)$config['host'];

    try {
        $pdo->exec("CREATE USER IF NOT EXISTS '{$dbUser}'@'{$dbHost}' IDENTIFIED BY '{$dbPass}'");
    } catch (Throwable $e) {
        // fallback per MySQL 5.6 e versioni precedenti che non supportano CREATE USER IF NOT EXISTS
        try {
            $pdo->exec("ALTER USER '{$dbUser}'@'{$dbHost}' IDENTIFIED BY '{$dbPass}'");
        } catch (Throwable $e2) {
            throw new RuntimeException("Impossibile creare o aggiornare utente DB: " . $e2->getMessage());
        }
    }

    $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$dbName}`.* TO '{$dbUser}'@'{$dbHost}'");
    $pdo->exec("FLUSH PRIVILEGES");
}

?>
<!doctype html>
<html lang="it">
    <head>
        <meta charset="utf-8">
        <title>Installazione</title>
        <link rel="stylesheet" href="lib/selector.css">
    </head>

    <body>

        <header>
            <div class="crumbs">
                <span>Installazione</span>
            </div>
        </header>

        <main>
            <div class="card">
                <div class="toolbar">
                    <strong>Installazione database</strong>
                    <div class="note">Inserisci le credenziali amministrative MySQL</div>
                </div>

                <?php if ($errors): ?>
                <div class="flash error">
                    <ul>
                        <?php foreach ($errors as $e): ?>
                        <li><?= h($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ($messages): ?>
                <div class="flash ok">
                    <ul>
                        <?php foreach ($messages as $m): ?>
                        <li><?= h($m) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form class="form" method="post">
                    <div class="row">
                        <div>
                            <label for="admin_username">Username admin MySQL</label>
                            <input class="input-inline" id="admin_username" name="admin_username" value="<?= h($adminUsername) ?>">
                        </div>

                        <div>
                            <label for="admin_password">Password admin MySQL</label>
                            <input class="input-inline" id="admin_password" name="admin_password" type="password">
                        </div>

                        <div>
                            <label>
                                <input type="checkbox" name="run_drop" value="1" <?= $runDrop ? 'checked' : '' ?>>
                                Esegui drop.sql prima di init.sql
                            </label>
                        </div>
                    </div>

                    <div class="actions">
                        <button class="btn btn-ghost" type="submit" name="action" value="index">Vai a index.php</button>
                        <div class="spacer"></div>
                        <button class="btn btn-danger" type="submit" name="action" value="drop" onclick="return confirm('Confermi di voler eliminare database e utente applicativo?')">Drop DB</button>
                        <button class="btn btn-ok" type="submit" name="action" value="install">Installa</button>
                    </div>
                </form>

                <footer></footer>
            </div>
        </main>

    </body>
</html>