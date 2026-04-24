<?php
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/misc.php';

$pdo = getPDO();
$user = requireLogin($pdo);
$userId = (int)$user['ID_Utente'];

$parentId = (int)($_POST['parentCartellaId'] ?? 0);
$folderName = trim((string)($_POST['folderName'] ?? ''));

function redirectBack(int $cartellaId): void {
    header('Location: ../selectPortfolio.php?cartella=' . $cartellaId);
    exit;
}

if ($parentId <= 0) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Cartella di destinazione non valida."];
    header('Location: ../selectPortfolio.php');
    exit;
}

if ($folderName === '') {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Inserisci un nome per la cartella."];
    redirectBack($parentId);
}

if (mb_strlen($folderName) > 100) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Nome cartella troppo lungo (max 100 caratteri)."];
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

    $stmt = $pdo->prepare("INSERT INTO Cartella (ID_Utente, ID_Padre, Nome) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $parentId, $folderName]);

    $_SESSION['flash'] = ['type' => 'ok', 'msg' => "Cartella creata: \"$folderName\"."];
    redirectBack($parentId);

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Esiste già una cartella con lo stesso nome qui."];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Errore database durante la creazione della cartella."];
    }
    redirectBack($parentId);
}