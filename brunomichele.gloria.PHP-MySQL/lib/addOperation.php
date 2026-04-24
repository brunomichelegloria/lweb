<?php
session_start();
require_once __DIR__ . '/misc.php';

$pdo = getPDO();
$user = requireLogin($pdo);
$userId = (int)$user['ID_Utente'];
unset($_SESSION['rebalance_result']);

$portfolioId = (int)($_POST['portfolioId'] ?? 0);
$bucketId = (int)($_POST['bucketId'] ?? 0);
$isin = strtoupper(trim((string)($_POST['isin'] ?? '')));
$tipo = strtoupper(trim((string)($_POST['tipo'] ?? '')));
$qty = (int)($_POST['qty'] ?? 0);
$price = (float)($_POST['price'] ?? -1);

if ($portfolioId <= 0 || $bucketId <= 0 || $isin === '' || ($tipo !== 'BUY' && $tipo !== 'SELL') || $qty <= 0 || $price < 0) {
	$_SESSION['flash'] = [
		'title' => 'Errore',
		'details' => ['dati operazione non validi'],
		'code' => 1
	];
	header('Location: ../portfolio.php?id=' . $portfolioId);
	exit;
}

$stmt = $pdo->prepare("
	SELECT ID_Radice
	FROM Portafoglio
	WHERE ID_Portafoglio = ? AND ID_Utente = ?
");
$stmt->execute([$portfolioId, $userId]);
$row = $stmt->fetch();

if (!$row) {
	$_SESSION['flash'] = [
		'title' => 'Errore',
		'details' => ['portafoglio non trovato'],
		'code' => 1
	];
	header('Location: ../selectPortfolio.php');
	exit;
}

$rootId = (int)$row['ID_Radice'];

function isBucketInTree(PDO $pdo, int $bucketId, int $rootId): bool {
	$cur = $bucketId;
	for ($i = 0; $i < 200; $i++) {
		if ($cur === $rootId) return true;

		$s = $pdo->prepare("SELECT ID_Padre FROM Bucket WHERE ID_Bucket = ?");
		$s->execute([$cur]);
		$r = $s->fetch();

		if (!$r) return false;
		$cur = ($r['ID_Padre'] !== null) ? (int)$r['ID_Padre'] : 0;
		if ($cur <= 0) return false;
	}
	return false;
}

if (!isBucketInTree($pdo, $bucketId, $rootId)) {
	$_SESSION['flash'] = [
		'title' => 'Errore',
		'details' => ['bucket non appartenente al portafoglio'],
		'code' => 1
	];
	header('Location: ../portfolio.php?id=' . $portfolioId);
	exit;
}

$chk = $pdo->prepare("SELECT 1 FROM ContenutoAsset WHERE ID_Bucket = ? AND ISIN = ? LIMIT 1");
$chk->execute([$bucketId, $isin]);
if (!$chk->fetchColumn()) {
	$_SESSION['flash'] = [
		'title' => 'Errore',
		'details' => ['asset non presente nel bucket'],
		'code' => 1
	];
	header('Location: ../portfolio.php?id=' . $portfolioId);
	exit;
}

try {
	$ins = $pdo->prepare("
		INSERT INTO Operazione (ID_Bucket, ISIN, DataOra, Tipo, Quantita, PrezzoEseguito)
		VALUES (?, ?, NOW(), ?, ?, ?)
	");
	$ins->execute([$bucketId, $isin, $tipo, $qty, $price]);

	$_SESSION['flash'] = [
		'title' => 'Operazione salvata',
		'details' => ["aggiunta operazione {$tipo} {$isin} qty={$qty} price=" . number_format($price, 6, '.', '')],
		'code' => 20
	];
} catch (Throwable $e) {
	$_SESSION['flash'] = [
		'title' => 'Errore',
		'details' => ['errore salvataggio operazione'],
		'code' => 1
	];
}

header('Location: ../portfolio.php?id=' . $portfolioId);
exit;