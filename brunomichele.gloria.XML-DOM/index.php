<?php
session_start();

$BASE_DIR   = __DIR__ . '/data';
$BASE_REAL  = realpath($BASE_DIR);
$BACKUP_DIR = $BASE_DIR . '/Backup';
$BACKUP_REAL= realpath($BACKUP_DIR);

// Input path richiesto
$rel = $_GET['p'] ?? '';
$rel = ltrim($rel, '/');

$abs = realpath($BASE_DIR . '/' . $rel);
if ($abs === false || !str_starts_with($abs, $BASE_REAL)) {
    http_response_code(400); echo "Percorso non valido."; exit;
}

$isInBackup = ($BACKUP_REAL && str_starts_with($abs, $BACKUP_REAL));

// ===== Azioni POST: mkdir, mkfile, pick, reimport =====
$err = $ok = null;

function sanitize_name($name) {
    $name = trim($name);
    if ($name === '' || $name[0] === '.' || str_contains($name, '/') || str_contains($name, '\\')) return null;
    return $name;
}

if (isset($_SESSION['rebalanceReport'])) {
    unset($_SESSION['rebalanceReport']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Blocca creazioni/modifiche dentro _backup
    if ($isInBackup && in_array($action, ['mkdir','mkfile'], true)) {
        $err = "Operazione non permessa nella cartella di backup.";
    } else switch ($action) {
        case 'mkdir':
            $name = sanitize_name($_POST['name'] ?? '');
            if (!$name) { 
                $err="Nome cartella non valido."; 
                break; 
            }
            $target = $abs . '/' . $name;
            if (file_exists($target)) { 
                $err="Esiste già."; 
                break; 
            }
            if (!mkdir($target, 0755, false)) { 
                $err="Creazione cartella fallita."; 
                break; 
            }
            $ok = "Cartella creata.";
        break;

        case 'mkfile':
            $name = sanitize_name($_POST['name'] ?? '');
            if (!$name || !preg_match('/\.xml$/i', $name)) {
                $err = "Nome file non valido (deve finire in .xml).";
                break;
            }

            $target = $abs . '/' . $name;
            if (file_exists($target)) {
                $err = "Il file esiste già.";
                break;
            }

            $now = date('Ymd-H:i:s');

            $impl = new DOMImplementation();
            $layer = 0;
            if ($rel !== '.') {
                $layer = substr_count($rel, '/') + 1;
            }
            $relDTD = str_repeat('../', $layer) . 'portafoglio.dtd';
            $dtd  = $impl->createDocumentType('portafoglio', '', $relDTD);

            $dom = $impl->createDocument(null, 'portafoglio', $dtd);
            $dom->encoding     = 'UTF-8';
            $dom->formatOutput = true;

            $root = $dom->documentElement;
            $root->setAttribute('valuta', '€');

            $info = $dom->createElement('informazioni');
            $info->setAttribute('tolleranza', '5');
            $info->setAttribute('ultimoAggiornamento', $now);
            $info->setAttribute('commissione', '0');
            $root->appendChild($info);

            $assets = $dom->createElement('assets');
            $root->appendChild($assets);

            $liq = $dom->createElement('liquidita');
            $liqTot = $dom->createElement('totale', '0');
            $liq->appendChild($liqTot);
            $root->appendChild($liq);

            if ($dom->save($target) === false) {
                $err = "Creazione file fallita.";
                break;
            }

            $ok = "File creato ($now).";
        break;

        case 'pick':
            $pick = basename($_POST['pick'] ?? '');
            if ($pick === '' || !preg_match('/\.xml$/i', $pick)) {
                $err = "File non valido.";
                break;
            }

            $relFile = ltrim($rel === '' ? $pick : "$rel/$pick", '/');

            $absFile = realpath($BASE_DIR . '/' . $relFile);
            if (!$absFile || !str_starts_with($absFile, $BASE_REAL)) {
                $err = "Percorso non consentito.";
                break;
            }

            $_SESSION['selectedPortfolio'] = $relFile;

            header('Location: gestionalePortafoglio.php', true, 303);
        exit;

        case 'rm':
            $pick = basename($_POST['pick'] ?? '');
            $target = realpath($abs . '/' . $pick);
            if (!$target || !str_starts_with($target, $BASE_REAL) || $target === $BACKUP_REAL) {
                $err = "Elemento non valido.";
                break;
            }
            if (is_dir($target) && $target !== $BACKUP_REAL) {
                // cartella: rimuovi ricorsivamente
                $it = new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                foreach($files as $file) {
                    if ($file->isDir()) rmdir($file->getRealPath());
                    else unlink($file->getRealPath());
                }
                rmdir($target);
            } else {
                unlink($target);
            }
            $ok = "Elemento rimosso.";
        break;

        case 'reimport':
            // solo se ci troviamo nel ramo backup
            if (!$isInBackup) { 
                $err = "Reimport consentito solo in backup."; 
                break; 
            }
            $src = basename($_POST['src'] ?? '');
            $new = sanitize_name($_POST['newname'] ?? '');
            if (!$src || !$new || !preg_match('/\.bak$/i', $src) || !preg_match('/\.xml$/i', $new)) {
                $err = "Nomi non validi."; 
                break;
            }
            $srcPath = realpath($abs . '/' . $src);
            if (!$srcPath || !str_starts_with($srcPath, $BACKUP_REAL)) { 
                $err="Sorgente non valida."; 
                break; 
            }

            // Mappa nella posizione equivalente del primario: /data + (rel path dentro _backup corrente)
            $relInsideBackup = ltrim(substr($abs, strlen($BACKUP_REAL)), '/'); // es. "progetti/x"
            $destDir = rtrim($BASE_DIR . '/' . $relInsideBackup, '/');
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) { 
                $err="Impossibile creare dir di destinazione."; 
                break; 
            }

            $destPath = $destDir . '/' . $new;
            if (file_exists($destPath)) { 
                $err = "Esiste già nel primario."; 
                break; 
            }

            if (!copy($srcPath, $destPath)) { 
                $err="Copia fallita."; 
                break; 
            }
            $ok = "Reimport riuscito in /data/" . ($relInsideBackup ? $relInsideBackup.'/' : '') . $new;
        break;
    }
}

// ===== Lettura contenuti directory =====
$items = scandir($abs) ?: [];
$dirs = $files = [];
foreach ($items as $name) {
    if ($name === '.' || $name === '..') continue;
    if ($name === 'Backup' && !str_starts_with(realpath($abs . '/' . $name), $BACKUP_REAL)) {
    // se in radice /data mostra Backup, altrimenti la tratti come normale dir
    }
    $full = $abs . '/' . $name;
    if (is_dir($full)) $dirs[] = $name; 
    elseif (preg_match('/.((xml)|(bak))$/', $name)) $files[] = $name;
}
natcasesort($dirs); 
natcasesort($files);

// ===== Breadcrumb =====
$parts = $rel === '' ? [] : explode('/', $rel);
$crumbs = []; 
$acc = '';
$crumbs[] = ['label' => 'data', 'p' => ''];

foreach ($parts as $part) {
    if ($part === '' || preg_match('/^\./', $part)) continue;
    $acc = ($acc === '' ? $part : $acc . '/' . $part);
    $crumbs[] = ['label' => $part, 'p' => $acc];
}
?>

<!doctype html>

<html lang="it">
    <head>
        <meta charset="utf-8">
        <title>Seleziona portafoglio</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="selector.css" rel="stylesheet">
    </head>


    <body>
        <header>
            <div class="crumbs">
                <?php foreach ($crumbs as $i => $c): ?>
                    <?php if ($i) echo '<span class="sep">/</span>'; ?>
                    <a href="?p=<?= urlencode($c['p']) ?>"><?= htmlspecialchars($c['label']) ?></a>
                <?php endforeach; ?>
            </div>
        </header>

        <main>
            <div class="card">
                <?php if ($err): ?><div class="flash error">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>
                <?php if ($ok):  ?><div class="flash" style="background:#0f1a12;color:#aef3c1">✅ <?= htmlspecialchars($ok) ?></div><?php endif; ?>

                <div class="toolbar">
                    <?php if (!$isInBackup): ?>
                    <form method="post" style="display:flex;gap:6px;align-items:center">
                        <input type="hidden" name="action" value="mkdir">
                        <input class="input-inline" type="text" name="name" placeholder="Nuova cartella">
                        <button class="btn btn-ghost" type="submit">Crea cartella</button>
                    </form>
                    <form method="post" style="display:flex;gap:6px;align-items:center">
                        <input type="hidden" name="action" value="mkfile">
                        <input class="input-inline" type="text" name="name" placeholder="Nuovo file.xml">
                        <button class="btn btn-ghost" type="submit">Nuovo file XML</button>
                    </form>
                    <?php else: ?>
                    <span class="tag">Backup: sola navigazione</span>
                    <?php endif; ?>
                    <span class="note"><?= $isInBackup ? 'Stai navigando i backup' : 'Filesystem primario' ?></span>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Nome:</th>
                            <th>Ultima modifica</th>
                            <th>Azione</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($rel !== '' || $rel !== '.'): ?>
                        <tr>
                            <td class="name">
                                <span class="icon folder">📂</span>
                                <a href="?p=<?= urlencode(dirname($rel)) ?>">..</a>
                            </td>
                            <td></td><td></td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach ($dirs as $d): ?>
                            <tr>
                                <td class="name">
                                    <span class="icon folder">📁</span>
                                    <a href="?p=<?= urlencode($rel === '' ? $d : "$rel/$d") ?>"><?= htmlspecialchars($d) ?></a>
                                </td>
                                <td><?= htmlspecialchars(date('Y-m-d H:i', filemtime($abs . '/' . $d))) ?></td>
                                <td>
                                    <span class="tag">Cartella</span>
                                    <?php if (strcmp($BACKUP_REAL, $abs . '/' . $d)): ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Non esiste un cestino dove recuperare il file. Confermi l\'operazione?')">
                                        <input type="hidden" name="action" value="rm">
                                        <input type="hidden" name="pick" value="<?= htmlspecialchars($d) ?>">
                                        <button class="btn btn-ghost" type="submit">🗑️</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php foreach ($files as $f): ?>
                            <?php
                            $full = $abs . '/' . $f;
                            $isXml = preg_match('/\.xml$/i', $f);
                            ?>
                            <tr>
                                <td class="name">
                                    <span class="icon file">📄</span>
                                    <span><?= htmlspecialchars($f) ?></span>
                                </td>
                                <td><?= htmlspecialchars(date('Y-m-d H:i', filemtime($full))) ?></td>
                                <td>
                                    <?php if ($isXml): ?>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="action" value="pick">
                                        <input type="hidden" name="pick" value="<?= htmlspecialchars($f) ?>">
                                        <button class="btn btn-ok" type="submit">Usa questo portafoglio</button>
                                    </form>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Non esiste un cestino dove recuperare il file. Confermi l\'operazione?')">
                                        <input type="hidden" name="action" value="rm">
                                        <input type="hidden" name="pick" value="<?= htmlspecialchars($f) ?>">
                                        <button class="btn btn-ghost" type="submit">🗑️</button>
                                    </form>
                                    <?php elseif (!$isInBackup): ?>
                                    <span class="tag">File</span>
                                    <?php endif; ?>
                                    <?php if ($isInBackup): ?>
                                    <form method="post" style="display:inline-flex;gap:6px;margin-left:8px;vertical-align:middle">
                                        <input type="hidden" name="action" value="reimport">
                                        <input type="hidden" name="src" value="<?= htmlspecialchars($f) ?>">
                                        <input class="input-inline" type="text" name="newname" placeholder="nuovo-nome.xml">
                                        <button class="btn" type="submit">Reimporta</button>
                                    </form>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Non esiste un cestino dove recuperare il file. Confermi l\'operazione?')">
                                        <input type="hidden" name="action" value="rm">
                                        <input type="hidden" name="pick" value="<?= htmlspecialchars($f) ?>">
                                        <button class="btn btn-ghost" type="submit">🗑️</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($dirs) && empty($files)): ?>
                            <tr><td colspan="3">Cartella vuota.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <footer>
                    Radice: <code>/data<?= $rel ? '/'.htmlspecialchars(preg_replace('/^\.\//', '', $rel)) : '' ?></code>
                    <?= $isInBackup ? ' · <strong>Modalità backup (sola navigazione / reimport)</strong>' : '' ?>
                </footer>
            </div>
        </main>
    </body>

</html>

<?php
// CAMBIARE FOOTER
// CORREGGERE DATA MODIFICA FILE IN MKFILE
// IMPORTAZIONE MANCA SELEZIONE DESTINAZIONE
?>