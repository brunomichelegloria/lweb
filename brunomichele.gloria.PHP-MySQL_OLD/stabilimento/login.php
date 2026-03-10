<?php
session_start();
require 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $conn = getDbConnection('admin');

    if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("SELECT id, password, nome FROM Cliente WHERE email = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($idCliente, $hashDb, $nome);
            $stmt->fetch();

            if (hash_equals($hashDb, hash('sha256', $password))) {
                $_SESSION['ruolo'] = 'cliente';
                $_SESSION['cliente_id'] = $idCliente;
                $_SESSION['email'] = $username;
                $_SESSION['nome'] = $nome;
                header("Location: area_clienti.php");
                exit;
            } else {
                $errore = "Password errata.";
                echo "<script>alert('$errore');</script>";
                header("refresh: 0; url=index.php");
            }
        } else {
            $errore = "Utente non trovato.";
            echo "<script>alert('$errore');</script>";
            header("refresh: 0; url=index.php");
        }
    } else {
    
        $stmt = $conn->prepare("SELECT password FROM Amministratore WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($hashDb);
            $stmt->fetch();

            if (hash_equals($hashDb, hash('sha256', $password))) {
                $_SESSION['ruolo'] = 'admin';
                $_SESSION['admin_username'] = $username;
                header("Location: modificheSpiaggia.php");
                exit;
            } else {
                $errore = "Password errata.";
                echo "<script>alert('$errore');</script>";
                header("refresh: 0; url=index.php");
            }
        } else {
            $errore = "Admin non trovato.";
            echo "<script>alert('$errore');</script>";
            header("refresh: 0; url=index.php");
        }
    }

    $stmt->close();
    $conn->close();
}
?>