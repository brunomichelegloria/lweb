<?php
session_start();
require_once __DIR__ . '/misc.php';

$pdo = getPDO();
$user = requireLogin($pdo);
$userId = (int)$user['ID_Utente'];

$type = (string)($_GET['type'] ?? $_POST['type'] ?? '');
$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$back = (int)($_GET['back'] ?? $_POST['back'] ?? 0);

function redirectBack(int $back): void {
    if ($back > 0) {
        header('Location: ../selectPortfolio.php?cartella=' . $back);
    } else {
        header('Location: ../selectPortfolio.php');
    }
    exit;
}

if (!in_array($type, ['folder', 'portfolio'], true) || $id <= 0) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Richiesta non valida."];
    redirectBack($back);
}

$currentName = null;

try {
    if ($type === 'folder') {
        $stmt = $pdo->prepare("SELECT Nome FROM Cartella WHERE ID_Cartella = ? AND ID_Utente = ?");
        $stmt->execute([$id, $userId]);
        $currentName = $stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT Nome FROM Portafoglio WHERE ID_Portafoglio = ? AND ID_Utente = ?");
        $stmt->execute([$id, $userId]);
        $currentName = $stmt->fetchColumn();
    }

    if ($currentName === false || $currentName === null) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Elemento non trovato o non autorizzato."];
        redirectBack($back);
    }
} catch (PDOException $e) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Errore database."];
    redirectBack($back);
}

$errors = [];
$newName = (string)$currentName;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newName = trim((string)($_POST['newName'] ?? ''));

    if ($newName === '') $errors[] = "Inserisci un nome.";
    if (mb_strlen($newName) > 100) $errors[] = "Nome troppo lungo (max 100 caratteri).";

    if (!$errors) {
        try {
            if ($type === 'folder') {
                $stmt = $pdo->prepare("SELECT ID_Padre FROM Cartella WHERE ID_Cartella = ? AND ID_Utente = ?");
                $stmt->execute([$id, $userId]);
                $parent = $stmt->fetchColumn();
                if ($parent === false) {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Cartella non trovata."];
                    redirectBack($back);
                }
                if ($parent === null && $newName !== 'root') {
                    $errors[] = "La cartella root non può essere rinominata.";
                }
                if (!$errors) {
                    $stmt = $pdo->prepare("UPDATE Cartella SET Nome = ? WHERE ID_Cartella = ? AND ID_Utente = ?");
                    $stmt->execute([$newName, $id, $userId]);
                }
            } else {
                $stmt = $pdo->prepare("UPDATE Portafoglio SET Nome = ? WHERE ID_Portafoglio = ? AND ID_Utente = ?");
                $stmt->execute([$newName, $id, $userId]);
            }

            if (!$errors) {
                $_SESSION['flash'] = ['type' => 'ok', 'msg' => "Nome aggiornato con successo."];
                redirectBack($back);
            }

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = "Esiste già un elemento con questo nome nella stessa posizione.";
            } else {
                $errors[] = "Errore database durante il salvataggio.";
            }
        }
    }
}

$title = ($type === 'folder') ? 'Rinomina Cartella' : 'Rinomina Portafoglio';
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
                    <div class="note">Modifica il nome e salva</div>
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

                <form class="form" method="post">
                    <input type="hidden" name="type" value="<?= h($type) ?>">
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <input type="hidden" name="back" value="<?= (int)$back ?>">

                    <div class="row">
                        <div>
                            <label for="newName">Nuovo nome</label>
                            <input class="input-inline" id="newName" name="newName" value="<?= h($newName) ?>" required>
                            <div class="hint">Max 100 caratteri.</div>
                        </div>
                    </div>

                    <div class="actions">
                        <a class="btn btn-ghost" href="../selectPortfolio.php<?= $back > 0 ? '?cartella='.(int)$back : '' ?>">Annulla</a>
                        <div class="spacer"></div>
                        <button class="btn btn-ok" type="submit">Salva</button>
                    </div>
                </form>
            </div>
        </main>

    </body>
</html>