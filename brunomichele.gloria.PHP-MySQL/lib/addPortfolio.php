<?php
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/misc.php';

$pdo = getPDO();
$user = requireLogin($pdo);
$userId = (int)$user['ID_Utente'];

$parentId = (int)($_POST['parentCartellaId'] ?? 0);
$portfolioName = trim((string)($_POST['portfolioName'] ?? ''));

function redirectBack(int $cartellaId): void {
    header('Location: ../selectPortfolio.php?cartella=' . $cartellaId);
    exit;
}

if ($parentId <= 0) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Cartella di destinazione non valida."];
    header('Location: ../selectPortfolio.php');
    exit;
}

if ($portfolioName === '') {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Inserisci un nome per il portafoglio."];
    redirectBack($parentId);
}

if (mb_strlen($portfolioName) > 100) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Nome portafoglio troppo lungo (max 100 caratteri)."];
    redirectBack($parentId);
}

try {

    $stmt = $pdo->prepare("SELECT 1 FROM Cartella WHERE ID_Cartella = ? AND ID_Utente = ?");
    $stmt->execute([$parentId, $userId]);
    if (!$stmt->fetchColumn()) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Cartella non trovata o non autorizzata."];
        header('Location: selectPortfolio.php');
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO Bucket (ID_Padre, Nome, TargetPctSuPadre) VALUES (NULL, ?, NULL)");
    $stmt->execute(['root']);
    $rootBucketId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO Portafoglio (ID_Cartella, ID_Utente, Nome, ID_Radice)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$parentId, $userId, $portfolioName, $rootBucketId]);

    $pdo->commit();

    $_SESSION['flash'] = ['type' => 'ok', 'msg' => "Portafoglio creato: \"$portfolioName\"."];
    redirectBack($parentId);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();

    if ($e->getCode() === '23000') {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Esiste già un portafoglio con lo stesso nome qui (o vincolo violato)."];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Errore database durante la creazione del portafoglio."];
    }
    redirectBack($parentId);
}