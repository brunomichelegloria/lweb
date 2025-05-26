<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_POST['userName']) || empty($_POST['password'])) {
        echo "<p>Accesso Negato!</p>";
    } else {
        $conn = new mysqli('127.0.0.1', 'siteuser', 'bellapw', 'stabilimento');
        if ($conn->connect_error) {
            die("Connessione fallita: " . $conn->connect_error);
        }

        //SQL injection
        $stmt = $conn->prepare("SELECT password FROM utenti WHERE username = ?");
        $stmt->bind_param("s", $_POST['userName']);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows == 1) {
            $stmt->bind_result($hash);
            $stmt->fetch();

            if (password_verify($_POST['password'], $hash)) {
                $_SESSION['userName'] = $_POST['userName'];
                $_SESSION['dataLogin'] = time();
                $_SESSION['accessoPermesso'] = true;

                header("Location: modificheSpiaggia.php");
                exit();
            } else {
                echo "<p>Accesso negato: password errata!</p>";
            }
        } else {
            echo "<p>Accesso negato: utente inesistente!</p>";
        }

        $stmt->close();
        $conn->close();
    }
}
?>
