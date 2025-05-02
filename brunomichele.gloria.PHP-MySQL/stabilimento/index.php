<?php
// Connessione al database
$host = '127.0.0.1';
$user = 'siteuser';
$password = 'bellapw'; // o la password se ne hai impostata una

//$user = 'adminStabilimento'; $password = 'adminpassword';
$database = 'stabilimento';

$conn = new mysqli($host, $user, $password, $database);

// Verifica connessione
if ($conn->connect_error) {
    die("Connessione fallita: " . $conn->connect_error);
}

// Recupera le posizioni e lo stato degli ombrelloni
$sql = "
    SELECT p.x, p.y, p.tipo, o.disponibile
    FROM posizioni p
    LEFT JOIN ombrelloni o ON p.x = o.x AND p.y = o.y
    ORDER BY p.y, p.x
";

$result = $conn->query($sql);

// Organizza i dati in una matrice per la griglia
$griglia = [];
while ($row = $result->fetch_assoc()) {
    $griglia[$row['y']][$row['x']] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Stabilimento Balneare</title>
	<link rel="stylesheet" href="layout.css" type="text/css" />
    <style>
        body { font-family: sans-serif; padding: 20px; }
        .griglia { display: inline-block; border: 1px solid #ccc; }
        .riga { display: flex; }
        .cella {
            width: 40px; height: 40px; margin: 2px;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid #ddd; font-size: 20px;
        }
        .sabbia { background-color: #f5deb3; }  /* sabbia */
        .prato  { background-color: #b6f5b3; }  /* prato */
        .vuoto  { background-color: #eee; }     /* posizione senza ombrellone */
        .libero { background-color: #7fff7f; }  /* ombrellone libero */
        .occupato { background-color: #ff7f7f; }/* ombrellone occupato */
    </style>
</head>
<body>
    <h1>Griglia Ombrelloni</h1>
    <div class="griglia">
        <?php foreach ($griglia as $riga): ?>
            <div class="riga">
                <?php foreach ($riga as $cella):
                    $classi = ['cella'];

                    // Colore base in base al tipo
                    $classi[] = ($cella['tipo'] === 'P') ? 'prato' : 'sabbia';

                    // Ombrellone presente?
                    if ($cella['disponibile'] !== null) {
                        $classi[] = $cella['disponibile'] ? 'libero' : 'occupato';
                        $icona = '⛱️';
                    } else {
                        $classi[] = 'vuoto';
                        $icona = '';
                    }
                ?>
                    <div class="<?= implode(' ', $classi) ?>"><?= $icona ?></div>
                <?php endforeach ?>
            </div>
        <?php endforeach ?>
    </div>
</body>
</html>

