<?php
session_start();

if (!isset($_SESSION['accessoPermesso']) || $_SESSION['accessoPermesso'] !== true) {
    header('Location: ../../brunomichele.gloria.XHTML-CSS/home.html');
    exit();
}

// Connessione al database
require_once __DIR__ . '/loginAdmin.php';
$conn = new mysqli($DB_ADMIN_HOST, $DB_ADMIN_USER, $DB_ADMIN_PASS, $DB_NAME);
if ($conn->connect_error) {
    die("Connessione fallita: " . $conn->connect_error);
}

// Recupera le posizioni e lo stato degli ombrelloni
$sql = "
    SELECT p.x, p.y, p.tipo, o.disponibile
    FROM posizione p
    LEFT JOIN ombrellone o ON p.x = o.x AND p.y = o.y
    ORDER BY p.x, p.y
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
	<link rel="stylesheet" href="layoutAdmin.css" type="text/css" />
</head>
<body>
    <h1>Lido Marcello</h1>
    <p style="margin: 0; padding: 0;">Dove si fa macello</p>
    <a href="logout.php">
        <img src="./img/arrow-sm-left-svgrepo-com.svg" alt="Login" id="backIcon">
    </a>

    <div class="griglia" style="
        grid-template-columns: repeat(<?php echo count($griglia[0]); ?>, 1fr);
        grid-template-rows: repeat(<?php echo count($griglia); ?>, 1fr);">
        <?php foreach ($griglia as $riga): ?>
            <?php foreach ($riga as $cella):
                $classi = ['cella'];

                // Colore base in base al tipo
                $classi[] = ($cella['tipo'] === 'P') ? 'prato' : 'sabbia';

                // Ombrellone presente?
                if ($cella['disponibile'] !== null) {
                    $classi[] = $cella['disponibile'] ? 'libero' : 'occupato';
                    $icona = '<svg class="' . end($classi) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 60">
                                <path d="M33,5.487V3c0-1.657-1.343-3-3-3s-3,1.343-3,3v2.487C14.89,6.971,5.339,17.292,5.148,29.772
                                    c-0.02,1.266,0.758,2.408,1.944,2.854C13.06,34.87,19.888,36.184,27,36.473V54h-9.235c-1.657,0-3,1.343-3,3s1.343,3,3,3h24.47
	                                c1.657,0,3-1.343,3-3s-1.343-3-3-3H33V36.473c7.112-0.289,13.94-1.604,19.908-3.847c1.186-0.446,1.963-1.588,1.944-2.854
	                                C54.661,17.292,45.11,6.971,33,5.487z M17.893,15.759c-1.545,3.66-2.562,8.1-2.888,13.023c-1.264-0.31-2.505-0.651-3.705-1.039
	                                C11.92,22.959,14.366,18.734,17.893,15.759z M20.954,29.913c0.419-8.319,3.058-14.638,6.046-17.268v17.816
	                                C24.949,30.371,22.929,30.186,20.954,29.913z M33,12.645c2.987,2.629,5.627,8.949,6.046,17.268
	                                c-1.975,0.273-3.995,0.458-6.046,0.549V12.645z M44.994,28.782c-0.326-4.923-1.343-9.364-2.888-13.023
	                                c3.527,2.975,5.973,7.201,6.593,11.984C47.5,28.132,46.258,28.473,44.994,28.782z" />
                              </svg>';
                } else {
                    $classi[] = 'vuoto';
                    $icona = '';
                }
            ?>
            <div class="<?= implode(' ', $classi) ?>" onclick="toggleOmbrellone(this, <?= $cella['x'] ?>, <?= $cella['y'] ?>)">
                <?= $icona ?>
                <span class="coordinate">&#40;<?= $cella['x'] ?>,<?= $cella['y'] ?>&#41;</span>
            </div>
            <?php endforeach ?>
        <?php endforeach ?>
    </div>

    <script>
        function toggleOmbrellone(element, x, y) {

            fetch('gestioneModifiche.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `azione=toggle_ombrellone&x=${x}&y=${y}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const svg = element.querySelector('svg');
                    if (data.newState === 0) {
                        element.classList.remove('libero');
                        element.classList.add('occupato');
                        if (svg) {
                            svg.classList.remove('libero');
                            svg.classList.add('occupato');
                        }
                    } else {
                        element.classList.remove('occupato');
                        element.classList.add('libero');
                        if (svg) {
                            svg.classList.remove('occupato');
                            svg.classList.add('libero');
                    }
                }
                } else {
                    alert("Errore: " + data.message);
                }
            })
            .catch(error => console.error('Errore nella richiesta:', error));
        }
    </script>


    <button id="menuToggle">⚙️ Modifiche</button>
        <div id="modificaMenu">
            <div style="display: flex; width: 100%; align-items: center; justify-content: space-between;">
                <h3>Modifica Spiaggia</h3>
                <div>
                    <label class="switch">
                        <input type="checkbox" id="modeSwitch">
                        <span class="slider"></span>
                    </label>
                    <span id="modeLabel">Aggiungi</span>
                </div>
            </div>

            <form method="POST" action="gestioneModifiche.php">
                <fieldset>
                    <legend>Ombrellone</legend>
                    X: <input type="number" name="x1" required>
                    Y: <input type="number" name="y1" required>
                    <button type="submit" name="azione" value="aggiungi_ombrellone">Aggiungi</button>
                </fieldset>
            </form>

            <form method="POST" action="gestioneModifiche.php">
                <fieldset>
                    <legend>Posizione</legend>
                    X: <input type="number" name="x2" required>
                    Y: <input type="number" name="y2" required>
                    Tipo terreno: <select name="tipo" required>
                        <option value="S">Sabbia</option>
                        <option value="P">Prato</option>
                    </select>
                    <button type="submit" name="azione" value="aggiungi_posizione">Aggiungi</button>
                </fieldset>
            </form>
        </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggleButton = document.getElementById('menuToggle');
            const menu = document.getElementById('modificaMenu');

            toggleButton.addEventListener('click', (e) => {
                e.stopPropagation();
                if (menu.style.display === 'none' || !menu.classList.contains('flex')) {
                    menu.style.display = 'flex';
                    menu.classList.add('flex'); // Aggiungi la classe per il layout orizzontale
                } else {
                    menu.style.display = 'none';
                    menu.classList.remove('flex'); // Rimuovi la classe per nascondere il menu
                }
            });

            document.addEventListener('click', (e) => {
                if (!menu.contains(e.target) && e.target !== toggleButton) {
                    menu.style.display = 'none';
                    menu.classList.remove('flex'); // Nascondi il menu se clicchi fuori
                }
            });
        });

        document.addEventListener('DOMContentLoaded', () => {
            const modeSwitch = document.getElementById('modeSwitch');
            const modeLabel = document.getElementById('modeLabel');
            const buttons = document.querySelectorAll('button[type="submit"]');

            modeSwitch.addEventListener('change', () => {
                const mode = modeSwitch.checked ? 'rimuovi_' : 'aggiungi_';
                modeLabel.textContent = modeSwitch.checked ? 'Rimuovi' : 'Aggiungi';

                buttons.forEach(button => {
                    const baseValue = button.getAttribute('value').replace(/(aggiungi_|rimuovi_)/, '');
                    const newValue = mode + baseValue;
                    button.setAttribute('value', newValue);
                    button.textContent = modeLabel.textContent;
                });
            });
        });
</script>

</body>
</html>

