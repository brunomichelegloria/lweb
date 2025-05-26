<?php
session_start();

// Controlla se l'accesso è permesso
if (!isset($_SESSION['accessoPermesso'])) {
    // Accesso non valido, redirect
    header('Location: /brunomichele.gloria.XHTML-CSS/home.html');
    exit();
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Modifiche Spiaggia</title>
</head>
<body>
    <h1>Benvenuto, <?= htmlspecialchars($_SESSION['userName']) ?>!</h1>
    <p>Accesso a modificheSpiaggia.php riuscito! Hello world!</p>
</body>
</html>
