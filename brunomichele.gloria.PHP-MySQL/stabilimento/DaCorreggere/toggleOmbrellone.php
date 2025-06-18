<?php
session_start();

if (!isset($_SESSION['accessoPermesso'])) {
    echo json_encode(['success' => false, 'message' => 'Non autorizzato']);
    exit;
}

require_once __DIR__ . '/loginAdmin.php';

$conn = new mysqli($DB_ADMIN_HOST, $DB_ADMIN_USER, $DB_ADMIN_PASS, $DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Errore connessione']);
    exit;
}

$x = $_POST['x'] ?? null;
$y = $_POST['y'] ?? null;

if (!isset($x) || !isset($y)) {
    echo json_encode(['success' => false, 'message' => 'Coordinate non valide']);
    exit;
}

// Ottieni stato corrente
$stmt = $conn->prepare("SELECT disponibile FROM ombrellone WHERE x = ? AND y = ?");
$stmt->bind_param("ii", $x, $y);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Ombrellone non trovato']);
    exit;
}

$row = $res->fetch_assoc();
$nuovoStato = ($row['disponibile'] === 1) ? 0 : 1;

// Aggiorna db
$stmt = $conn->prepare("UPDATE ombrellone SET disponibile = ? WHERE x = ? AND y = ?");
$stmt->bind_param("iii", $nuovoStato, $x, $y);
$stmt->execute();

echo json_encode(['success' => true, 'newState' => $nuovoStato]);