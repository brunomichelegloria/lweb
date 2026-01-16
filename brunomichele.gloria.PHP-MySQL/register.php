<?php
session_start();

require_once __DIR__ . '/lib/misc.php';


$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$errors = [];
$username = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($username === '') $errors[] = "Inserisci un username.";
    if (mb_strlen($username) < 3) $errors[] = "Username troppo corto (min 3 caratteri).";
    if (mb_strlen($username) > 50) $errors[] = "Username troppo lungo (max 50 caratteri).";

    if ($email !== '') {
        if (mb_strlen($email) > 254) $errors[] = "Email troppo lunga (max 254 caratteri).";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email non valida.";
    } else {
        $email = null;
    }

    if ($password === '') $errors[] = "Inserisci una password.";
    if (strlen($password) < 8) $errors[] = "Password troppo corta (min 8 caratteri).";
    if ($password !== $password2) $errors[] = "Le password non coincidono.";

    if (!$errors) {
        try {
            $pdo = getPDO();
            $pdo->beginTransaction();

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $sql = "INSERT INTO Utente (Username, Email, PasswordHash) VALUES (:u, :e, :p)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':u' => $username,
                ':e' => $email,
                ':p' => $hash,
            ]);

            $newUserId = (int)$pdo->lastInsertId();

            $sql = "INSERT INTO Cartella (ID_Utente, ID_Padre, Nome) VALUES (?, NULL, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$newUserId, 'root']);

            $pdo->commit();

            $_SESSION['flash'] = ['type' => 'ok', 'msg' => "Registrazione completata. Ora puoi accedere."];
            header('Location: index.php');
            exit;

        } catch (PDOException $e) {
            if ($pdo && $pdo->inTransaction()) $pdo->rollBack();

            if ($e->getCode() === '23000') {
                $errors[] = "Username o email già in uso. Scegline un altro.";
            } else {
                $errors[] = "Errore database.";
            }
        }
    }
}

?>
<!doctype html>
<html lang="it">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<title>Registrazione</title>
		<link rel="stylesheet" href="selector.css">
	</head>

	<body>
		<header>
			<div class="crumbs">
				<a href="index.php">Home</a><span class="sep">/</span><span>Registrazione</span>
			</div>
		</header>

		<main>
			<div class="card">
				<div class="toolbar">
					<strong>Registrazione</strong>
					<div class="note">Crea un account per gestire i portafogli</div>
				</div>

				<div class="warnline">
					Email non obbligatoria.<strong>Se non inserisci un’email</strong>, tutti i dati verranno cancellati dal database a fine settimana.
				</div>

				<?php if ($flash && ($flash['type'] ?? '') === 'ok'): ?>
				<div class="flash ok"><?= h((string)$flash['msg']) ?></div>
				<?php endif; ?>

				<?php if ($errors): ?>
				<div class="flash error">
					<ul style="margin:0; padding-left:18px;">
						<?php foreach ($errors as $e): ?>
						<li><?= h($e) ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>

				<form class="form" method="post" action="">
					<div class="row">
						<div>
							<label for="email">Email (opzionale)</label>
							<input class="input-inline" id="email" name="email" type="email" autocomplete="email" value="<?= h((string)$email) ?>" placeholder="es. nome@dominio.it">
							<div class="hint">Se non inserisci l’email, l’account verrà eliminato a fine settimana.</div>
						</div>
						<div>
							<label for="username">Username</label>
							<input class="input-inline" id="username" name="username" autocomplete="username" value="<?= h($username) ?>" placeholder="es. mario.rossi" required>
							<div class="hint">Min 3 caratteri. Deve essere unico.</div>
						</div>

						<div>
							<label for="password">Password</label>
							<input class="input-inline" id="password" name="password" type="password" autocomplete="new-password" required>
							<div class="hint">Min 8 caratteri.</div>
						</div>

						<div>
							<label for="password2">Conferma password</label>
							<input class="input-inline" id="password2" name="password2" type="password" autocomplete="new-password" required>
						</div>
					</div>

					<div class="actions">
						<a class="btn btn-ghost" href="index.php">Ho già un account</a>
						<div class="spacer"></div>
						<button class="btn btn-ok" type="submit">Crea account</button>
					</div>
				</form>

				<footer style="padding-left: 18px; padding-bottom: 12px">Suggerimento: usa una password che non riutilizzi altrove.</footer>
			</div>
		</main>
	</body>
</html>