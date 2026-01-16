<?php
session_start();

require_once __DIR__ . '/lib/misc.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = getPDO();
$user = requireLogin($pdo);
$userId = (int)$user['ID_Utente'];
$pageError = null;

$stmt = $pdo->prepare("SELECT Username FROM Utente WHERE ID_Utente = ?");
$stmt->execute([$userId]);
$dbUser = $stmt->fetchColumn();

if ($dbUser === false) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

$currentCartellaId = isset($_GET['cartella']) ? (int)$_GET['cartella'] : null;

if ($currentCartellaId === null) {
    $stmt = $pdo->prepare("SELECT ID_Cartella FROM Cartella WHERE ID_Utente = ? AND ID_Padre IS NULL");
    $stmt->execute([$userId]);
    $currentCartellaId = (int)$stmt->fetchColumn();
}

$stmt = $pdo->prepare("SELECT ID_Cartella, ID_Padre, Nome FROM Cartella WHERE ID_Cartella = ? AND ID_Utente = ?");
$stmt->execute([$currentCartellaId, $userId]);
$currentCartella = $stmt->fetch();
if (!$currentCartella) {
    http_response_code(404);
    $pageError = "Cartella non trovata";
}

$stmt = $pdo->prepare("SELECT ID_Cartella, Nome FROM Cartella WHERE ID_Utente = ? AND ID_Padre = ? ORDER BY Nome");
$stmt->execute([$userId, $currentCartellaId]);
$cartelle = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT ID_Portafoglio, Nome FROM Portafoglio WHERE ID_Utente = ? AND ID_Cartella = ? ORDER BY Nome");
$stmt->execute([$userId, $currentCartellaId]);
$portfolios = $stmt->fetchAll();
?>
<!doctype html>
<html lang="it">
    <head>
        <meta charset="utf-8">
        <title>Seleziona Portafoglio</title>
        <link rel="stylesheet" href="selector.css">
    </head>

    <body>

        <header>
            <div class="crumbsbar">
                <div class="crumbsleft">
                    <a href="selectPortfolio.php">Root</a>
                        <?php if (!$pageError && $currentCartella['ID_Padre'] !== null): ?>
                        <span class="sep">/</span>
                        <a href="selectPortfolio.php?cartella=<?= (int)$currentCartella['ID_Padre'] ?>">..</a>
                        <?php endif; ?>
                </div>

                <div class="crumbsright">
                    <div class="userpill">Utente: <?= h($_SESSION['username'] ?? '') ?></div>
                    <a class="btn btn-ghost" href="logout.php">Logout</a>
                </div>
            </div>
        </header>

        <main>
            <?php if ($flash): ?>
            <div class="card" style="margin-bottom:14px;">
                <div class="flash <?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'error' ?>">
                    <?= h((string)($flash['msg'] ?? '')) ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($pageError): ?>
            <div class="card">
                <div class="toolbar">
                    <strong>Errore</strong>
                    <div class="note"><a class="btn btn-ghost" href="logout.php">Logout</a></div>
                </div>
                <div class="flash error"><?= h($pageError) ?></div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="toolbar toolbar-split">
                    <div class="toolrow">
                        <strong>Aggiungi Cartella:</strong>
                        <form class="mini-form" action="lib/addFolder.php" method="post">
                            <input type="hidden" name="parentCartellaId" value="<?= (int)$currentCartellaId ?>">
                            <input class="input-inline" type="text" name="folderName" placeholder="Nome Cartella" required>
                            <button class="btn btn-ok" type="submit">Crea</button>
                        </form>
                    </div>

                    <div class="toolrow">
                        <strong>Aggiungi Portafoglio:</strong>
                        <form class="mini-form" action="lib/addPortfolio.php" method="post">
                            <input type="hidden" name="parentCartellaId" value="<?= (int)$currentCartellaId ?>">
                            <input class="input-inline" type="text" name="portfolioName" placeholder="Nome Portafoglio" required>
                            <button class="btn btn-ok" type="submit">Crea</button>
                        </form>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Tipo</th>
                            <th class="col-actions">Azioni</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($cartelle as $f): ?>
                        <tr>
                            <td class="name">
                                <span class="icon folder"></span>
                                <a href="selectPortfolio.php?cartella=<?= (int)$f['ID_Cartella'] ?>">
                                    <?= h($f['Nome']) ?>
                                </a>
                            </td>
                            <td>Cartella</td>
                            <td class="actions-cell">
                                <a class="btn btn-ghost btn-sm" href="lib/renameItem.php?type=folder&id=<?= (int)$f['ID_Cartella'] ?>&back=<?= (int)$currentCartellaId ?>">Modifica</a>
                                <a class="btn btn-danger btn-sm" href="lib/deleteItem.php?type=folder&id=<?= (int)$f['ID_Cartella'] ?>&back=<?= (int)$currentCartellaId ?>">Elimina</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php foreach ($portfolios as $p): ?>
                        <tr>
                            <td class="name">
                                <span class="icon file"></span>
                                <a href="portfolio.php?id=<?= (int)$p['ID_Portafoglio'] ?>">
                                    <?= h($p['Nome']) ?>
                                </a>
                            </td>
                            <td>Portafoglio</td>
                            <td class="actions-cell">
                                <a class="btn btn-ghost btn-sm" href="lib/renameItem.php?type=portfolio&id=<?= (int)$p['ID_Portafoglio'] ?>&back=<?= (int)$currentCartellaId ?>">Modifica</a>
                                <a class="btn btn-danger btn-sm" href="lib/deleteItem.php?type=portfolio&id=<?= (int)$p['ID_Portafoglio'] ?>&back=<?= (int)$currentCartellaId ?>">Elimina</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (count($cartelle) === 0 && count($portfolios) === 0): ?>
                        <tr class="empty-row">
                            <td colspan="3">
                                <div class="emptybox">
                                    Nessun contenuto in questa cartella.
                                    <span class="note">Crea una cartella o un portafoglio usando i form sopra.</span>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                    </tbody>
                </table>

                <div class="footer">
                    <strong><?= h($currentCartella['Nome'] ?? 'Root') ?></strong>
                    <div class="note"><?= count($cartelle) ?> cartelle, <?= count($portfolios) ?> portafogli</div>
                </div>
            </div>
            <?php endif; ?>
        </main>

    </body>
</html>