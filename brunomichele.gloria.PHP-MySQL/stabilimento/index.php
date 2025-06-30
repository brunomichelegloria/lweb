<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>

<?php
require_once './db_connect.php';
$conn = getDbConnection(); //Utente pubblico

$sql = "SELECT
    p.x,
    p.y,
    p.terreno,
    CASE
        WHEN o.id IS NOT NULL THEN 1
        ELSE 0
    END AS ombrellone_presente,
    acq.tipo AS tipo_acquisto_corrente
    FROM Posizione p
    LEFT JOIN Ombrellone o
        ON o.posizione_x = p.x AND o.posizione_y = p.y
    LEFT JOIN Acquisto acq
        ON acq.posizione_x = p.x
        AND acq.posizione_y = p.y
        AND (
            (acq.data_fine IS NULL AND acq.data_inizio = CURDATE())
            OR (CURDATE() BETWEEN acq.data_inizio AND acq.data_fine)
        )
    GROUP BY p.x, p.y
    ORDER BY p.y, p.x";

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
</head>
<body>
    <div id="wave1"></div>
    <div id="wave2"></div>
    <div id="wave3"></div>
    <div id="adminLoginBox">
        <img src="./img/beach-umbrella.svg" alt="Login" id="adminLoginIcon">
            <div id="adminLogin">
                <h2 style="margin-top:0;">Login Amministratore</h2>
                <form method="POST" action="login.php">
                    <label for="username">Username:</label>
                    <input type="text" name="username" required>
                    <label for="password">Password:</label>
                    <input type="password" name="password" required>
                    <button type="submit" name="invio">Login</button>
                </form>
            </div>
    </div>

    <div id="userLoginBox">
        <div id="userLoginIconBox" style="position: relative; cursor: pointer;">
            <span id="userLoginIconLabel" >
                <svg viewBox="0 0 500 200" style="border: none; transform: scale(3);">
                    <path fill="transparent" id="curve" d="M50,250 A200,200 0 0,1 450,250" />
                    <text>
                        <textPath alignment-baseline="top" xlink:href="#curve" startOffset="10%">
                            Accedi!
                        </textPath>
                    </text>
                </svg>
            </span>
            <img src="./img/life-belt.png" alt="Login Utente" id="userLoginIcon">
        </div>
        <div id="userLoginWrapper">
            <div id="userLogin">
                <h2 style="margin-top:0;">Login Utente</h2>
                <form method="POST" action="login.php">
                    <label for="username">E-mail:</label>
                    <input type="text" name="username" required>
                    <br />
                    <label for="password">Password:</label>
                    <input type="password" name="password" required>
                    <button type="submit" name="invio">Login</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const adminIcon = document.getElementById('adminLoginIcon');
        const adminLog = document.getElementById('adminLogin');

        adminIcon.addEventListener('click', (event) => {
            event.stopPropagation();
            adminLog.style.display = (adminLog.style.display === 'block') ? 'none' : 'block';
        });

        document.addEventListener('click', (event) => {
            if (!adminLog.contains(event.target)) {
                adminLog.style.display = 'none';
            }
        });

        const userIconBox = document.getElementById('userLoginIconBox');
        const userIcon = document.getElementById('userLoginIcon');
        const userLog = document.getElementById('userLogin');
        const userLogWrapper = document.getElementById('userLoginWrapper');

        userIconBox.addEventListener('click', (event) => {
            event.stopPropagation();
            userIcon.classList.add('appear');
            userLogWrapper.classList.add('appear');
        });

        document.addEventListener('click', (event) => {
            if (!userLog.contains(event.target) && !userIcon.contains(event.target)) {
                userIcon.classList.remove('appear');
                userLogWrapper.classList.remove('appear');
            }
        });
    </script>
    
    <div class="griglia" style="
        grid-template-columns: repeat(<?php echo count($griglia[0]); ?>, 1fr);
        grid-template-rows: repeat(<?php echo count($griglia); ?>, 1fr);">
        <?php if (!empty($griglia)): ?>
            <?php foreach ($griglia as $riga): ?>
                <?php foreach ($riga as $cella):
                    $classi = ['cella'];

                    // Colore base in base al tipo
                    $classi[] = strtolower($cella['terreno']);

                    // Ombrellone presente?
                    if ((int)$cella['ombrellone_presente'] === 1) {
                        if ($cella['tipo_acquisto_corrente'] === NULL)
                            $classi[] = 'libero';
                        elseif ($cella['tipo_acquisto_corrente'] === 'Abbonamento')
                            $classi[] = 'abbonamento';
                        else
                            $classi[] = 'occupato';
                        $icona = <<<SVG
                            <svg class="{$classi[count($classi)-1]}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 60">
                                <path d="M33,5.487V3c0-1.657-1.343-3-3-3s-3,1.343-3,3v2.487C14.89,6.971,5.339,17.292,5.148,29.772
                                    c-0.02,1.266,0.758,2.408,1.944,2.854C13.06,34.87,19.888,36.184,27,36.473V54h-9.235c-1.657,0-3,1.343-3,3s1.343,3,3,3h24.47
                                    c1.657,0,3-1.343,3-3s-1.343-3-3-3H33V36.473c7.112-0.289,13.94-1.604,19.908-3.847c1.186-0.446,1.963-1.588,1.944-2.854
                                    C54.661,17.292,45.11,6.971,33,5.487z M17.893,15.759c-1.545,3.66-2.562,8.1-2.888,13.023c-1.264-0.31-2.505-0.651-3.705-1.039
                                    C11.92,22.959,14.366,18.734,17.893,15.759z M20.954,29.913c0.419-8.319,3.058-14.638,6.046-17.268v17.816
                                    C24.949,30.371,22.929,30.186,20.954,29.913z M33,12.645c2.987,2.629,5.627,8.949,6.046,17.268
                                    c-1.975,0.273-3.995,0.458-6.046,0.549V12.645z M44.994,28.782c-0.326-4.923-1.343-9.364-2.888-13.023
                                    c3.527,2.975,5.973,7.201,6.593,11.984C47.5,28.132,46.258,28.473,44.994,28.782z" />
                            </svg>
SVG;
                    } else {
                        $classi[] = 'vuoto';
                        $icona = '';
                    }
                ?>
                <div class="<?= implode(' ', $classi) ?>"><?= $icona ?></div>
                <?php endforeach ?>
            <?php endforeach ?>
        <?php else: ?>
            <div style="padding:2em; text-align:center;">Nessuna posizione presente nel database.</div>
        <?php endif; ?>
    </div>
    <footer>
        <img src="./img/Tropicana.png" alt="Logo Stabilimento" class="tropicana">
    </footer>
</body>
</html>

