<?php
session_start();
require_once __DIR__ . '/lib/connection.php';
require_once __DIR__ . '/lib/misc.php';

$pdo = getPDO();
$user = requireLogin($pdo);
$userId = (int)$user['ID_Utente'];

$type = (string)($_GET['type'] ?? $_POST['type'] ?? '');
$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$back = (int)($_GET['back'] ?? $_POST['back'] ?? 0);

function redirectBack(int $back): void {
    header('Location: ../selectPortfolio.php' . ($back > 0 ? '?cartella=' . $back : ''));
    exit;
}

function getBucketSubtreeIds(PDO $pdo, int $rootId): array {
    $all = [];
    $queue = [$rootId];

    while ($queue) {
        $chunk = array_splice($queue, 0, 200);
        foreach ($chunk as $b) $all[$b] = true;

        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("SELECT ID_Bucket FROM Bucket WHERE ID_Padre IN ($placeholders)");
        $stmt->execute($chunk);
        $kids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($kids as $kid) {
            $kid = (int)$kid;
            if (!isset($all[$kid])) $queue[] = $kid;
        }
    }

    return array_map('intval', array_keys($all));
}

function getCartellaSubtreeIds(PDO $pdo, int $userId, int $rootCartellaId): array {
    $all = [];
    $queue = [$rootCartellaId];

    while ($queue) {
        $chunk = array_splice($queue, 0, 200);
        foreach ($chunk as $c) $all[$c] = true;

        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $params = array_merge([$userId], $chunk);

        $stmt = $pdo->prepare("SELECT ID_Cartella FROM Cartella WHERE ID_Utente = ? AND ID_Padre IN ($placeholders)");
        $stmt->execute($params);
        $kids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($kids as $kid) {
            $kid = (int)$kid;
            if (!isset($all[$kid])) $queue[] = $kid;
        }
    }

    return array_map('intval', array_keys($all));
}

if (!in_array($type, ['folder', 'portfolio'], true) || $id <= 0) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Richiesta non valida."];
    redirectBack($back);
}

$itemName = '';
$errors = [];

try {
    if ($type === 'folder') {
        $stmt = $pdo->prepare("SELECT Nome, ID_Padre FROM Cartella WHERE ID_Cartella = ? AND ID_Utente = ?");
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => "Cartella non trovata o non autorizzata."];
            redirectBack($back);
        }
        if ($row['ID_Padre'] === null) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => "La cartella root non può essere eliminata."];
            redirectBack($back);
        }

        $itemName = (string)$row['Nome'];

    } else {
        $stmt = $pdo->prepare("SELECT Nome FROM Portafoglio WHERE ID_Portafoglio = ? AND ID_Utente = ?");
        $stmt->execute([$id, $userId]);
        $name = $stmt->fetchColumn();

        if ($name === false) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => "Portafoglio non trovato o non autorizzato."];
            redirectBack($back);
        }

        $itemName = (string)$name;
    }
} catch (PDOException $e) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Errore database."];
    redirectBack($back);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($type === 'folder') {
            $subtree = getCartellaSubtreeIds($pdo, $userId, $id);

            $placeholders = implode(',', array_fill(0, count($subtree), '?'));
            $params = array_merge([$userId], $subtree);

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Portafoglio WHERE ID_Utente = ? AND ID_Cartella IN ($placeholders)");
            $stmt->execute($params);
            $n = (int)$stmt->fetchColumn();

            if ($n > 0) {
                $errors[] = "Impossibile eliminare: nella cartella (o in una sottocartella) è presente almeno un portafoglio.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM Cartella WHERE ID_Cartella = ? AND ID_Utente = ?");
                $stmt->execute([$id, $userId]);

                $_SESSION['flash'] = ['type' => 'ok', 'msg' => "Cartella eliminata."];
                redirectBack($back);
            }

        } else {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT ID_Radice FROM Portafoglio WHERE ID_Portafoglio = ? AND ID_Utente = ?");
            $stmt->execute([$id, $userId]);
            $rootBucket = $stmt->fetchColumn();
            if ($rootBucket === false) {
                $pdo->rollBack();
                $_SESSION['flash'] = ['type' => 'error', 'msg' => "Portafoglio non trovato."];
                redirectBack($back);
            }
            $rootBucket = (int)$rootBucket;

            $bucketIds = getBucketSubtreeIds($pdo, $rootBucket);
            $ph = implode(',', array_fill(0, count($bucketIds), '?'));

            $sql = "DELETE o
                    FROM Operazione o
                    JOIN ContenutoAsset ca ON ca.ID_Bucket = o.ID_Bucket AND ca.ISIN = o.ISIN
                    WHERE ca.ID_Bucket IN ($ph)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bucketIds);

            $stmt = $pdo->prepare("DELETE FROM ContenutoAsset WHERE ID_Bucket IN ($ph)");
            $stmt->execute($bucketIds);

            $stmt = $pdo->prepare("DELETE FROM Portafoglio WHERE ID_Portafoglio = ? AND ID_Utente = ?");
            $stmt->execute([$id, $userId]);

            $stmt = $pdo->prepare("DELETE FROM Bucket WHERE ID_Bucket = ?");
            $stmt->execute([$rootBucket]);

            $pdo->commit();

            $_SESSION['flash'] = ['type' => 'ok', 'msg' => "Portafoglio eliminato."];
            redirectBack($back);
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = "Errore database durante l'eliminazione: " . $e->getMessage();
    }
}

$title = ($type === 'folder') ? 'Elimina Cartella' : 'Elimina Portafoglio';
?>
<!doctype html>
<html lang="it">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= h($title) ?></title>
        <link rel="stylesheet" href="../selector.css">
    </head>

    <body>

        <header>
            <div class="crumbsbar">
                <div class="crumbsleft">
                    <a href="../selectPortfolio.php">Root</a>
                        <?php if ($back > 0): ?>
                        <span class="sep">/</span>
                        <a href="../selectPortfolio.php?cartella=<?= (int)$back ?>">Indietro</a>
                        <?php endif; ?>
                </div>
                    <div class="crumbsright">
                    <div class="userpill">Utente: <?= h($_SESSION['username'] ?? '') ?></div>
                    <a class="btn btn-ghost" href="../logout.php">Logout</a>
                </div>
            </div>
        </header>

        <main>
            <div class="card">
                <div class="toolbar">
                    <strong><?= h($title) ?></strong>
                    <div class="note">Operazione irreversibile</div>
                </div>

                <?php if ($errors): ?>
                <div class="flash error">
                    <ul style="margin:0; padding-left:18px;">
                        <?php foreach ($errors as $e): ?>
                        <li><?= h($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="form">
                    <p class="dangertext">
                        Confermi l'eliminazione di <strong><?= h($itemName) ?></strong>?
                    </p>

                    <form method="post">
                        <input type="hidden" name="type" value="<?= h($type) ?>">
                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                        <input type="hidden" name="back" value="<?= (int)$back ?>">

                        <div class="actions">
                            <a class="btn btn-ghost" href="../selectPortfolio.php<?= $back > 0 ? '?cartella='.(int)$back : '' ?>">Annulla</a>
                            <div class="spacer"></div>
                            <button class="btn btn-danger" type="submit">Elimina</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>

    </body>
</html>