<?php
session_start();

require_once __DIR__ . '/lib/misc.php';

$errors = [];
$username = '';

if (isset($_SESSION['userId'])) {
    header('Location: selectPortfolio.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $errors[] = "Inserisci username e password.";
    } else {
    	try {
            $pdo = getPDO();

            $stmt = $pdo->prepare("SELECT ID_Utente, Username, PasswordHash FROM Utente WHERE Username = ?");
            $stmt->execute([$username]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($password, $row['PasswordHash'])) {
                $errors[] = "Credenziali non valide.";
            } else {
                session_regenerate_id(true);
                $_SESSION['userId'] = (int)$row['ID_Utente'];
                $_SESSION['username'] = $row['Username'];
                header('Location: selectPortfolio.php');
                exit;
            }

        } catch (PDOException $e) {
            $errors[] = "Errore database: " . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="it">
	<head>
		<meta charset="utf-8">
		<title>Login</title>
		<link rel="stylesheet" href="selector.css">
	</head>

	<body>

		<header>
			<div class="crumbs">
				<span>Login</span>
			</div>
		</header>

		<main>
			<div class="card">
				<div class="toolbar">
					<strong>Accesso</strong>
					<div class="note">Inserisci le tue credenziali</div>
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

				<form class="form" method="post">
					<div class="row">
						<div>
							<label for="username">Username</label>
							<input class="input-inline" id="username" name="username" value="<?= h($username) ?>" required>
						</div>

						<div>
							<label for="password">Password</label>
							<input class="input-inline" id="password" name="password" type="password" required>
						</div>
					</div>

					<div class="actions">
						<a class="btn btn-ghost" href="register.php">Crea account</a>
						<div class="spacer"></div>
						<button class="btn btn-ok" type="submit">Entra</button>
					</div>
				</form>

				<footer></footer>
			</div>
		</main>

	</body>
</html>