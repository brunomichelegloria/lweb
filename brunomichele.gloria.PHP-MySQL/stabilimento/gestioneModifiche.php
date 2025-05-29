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

$azione = $_POST['azione'] ?? null;

if (!$azione) {
    echo json_encode(['success' => false, 'message' => 'Azione non valida']);
    exit;
}

switch ($azione) {
    case 'toggle_ombrellone':
        $x = $_POST['x'] ?? null;
        $y = $_POST['y'] ?? null;

        if (!isset($x) || !isset($y)) {
            echo json_encode(['success' => false, 'message' => 'Coordinate non valide']);
            exit;
        }

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

        $stmt = $conn->prepare("UPDATE ombrellone SET disponibile = ? WHERE x = ? AND y = ?");
        $stmt->bind_param("iii", $nuovoStato, $x, $y);
        $stmt->execute();

        echo json_encode(['success' => true, 'newState' => $nuovoStato]);
        break;

    case 'aggiungi_ombrellone':
        $x = $_POST['x1'] ?? null;
        $y = $_POST['y1'] ?? null;

        if ($x !== null && $y !== null) {
            try {
                $stmt = $conn->prepare("INSERT INTO ombrellone (x, y, disponibile) VALUES (?, ?, 1)");
                $stmt->bind_param("ii", $x, $y);
                $stmt->execute();
                echo json_encode(['success' => true, 'message' => 'Ombrellone aggiunto']);
            } catch (mysqli_sql_exception $e) {
                // Controlla se l'errore è una violazione della chiave esterna
                if ($e->getCode() === 1452) {
                    echo json_encode(['success' => false, 'message' => 'La posizione specificata non esiste']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Errore durante l\'aggiunta dell\'ombrellone']);
                }
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Coordinate non valide']);
        }
        break;

    case 'rimuovi_ombrellone':
        $x = $_POST['x1'] ?? null;
        $y = $_POST['y1'] ?? null;

        if ($x !== null && $y !== null) {
            $stmt = $conn->prepare("DELETE FROM ombrellone WHERE x = ? AND y = ?");
            $stmt->bind_param("ii", $x, $y);
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => 'Ombrellone rimosso']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Coordinate non valide']);
        }
        break;

    case 'aggiungi_posizione':
        $x = $_POST['x2'] ?? null;
        $y = $_POST['y2'] ?? null;
        $tipo = $_POST['tipo'] ?? null;

        if ($x !== null && $y !== null && $tipo) {
            $stmt = $conn->prepare("INSERT INTO posizione (x, y, tipo) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $x, $y, $tipo);
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => 'Posizione aggiunta']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Dati non validi']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta']);
        break;

    case 'rimuovi_posizione':
        $x = $_POST['x2'] ?? null;
        $y = $_POST['y2'] ?? null;
        
        if ($x !== null && $y !== null) {
            try {
                $stmt = $conn->prepare("DELETE FROM posizione WHERE x = ? AND y = ?");
                $stmt->bind_param("ii", $x, $y);
                $stmt->execute();
    
                if ($stmt->affected_rows > 0) {
                    echo json_encode(['success' => true, 'message' => 'Posizione rimossa con successo']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Posizione non trovata']);
                }
            } catch (mysqli_sql_exception $e) {
                echo json_encode(['success' => false, 'message' => 'Errore durante la rimozione della posizione']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Coordinate non valide']);
        }
        break;
}

$conn->close();

header('Location: modificheSpiaggia.php');
exit;