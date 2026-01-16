<?php

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = 'localhost';
    $db   = 'portfolio_db';
    $user = 'portfolio_app';
    $pass = 'CambiaQuestaPassword!';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function requireLogin(PDO $pdo): array {
    if (!isset($_SESSION['userId'])) {
        header('Location: index.php');
        exit;
    }
    $userId = (int)$_SESSION['userId'];

    $stmt = $pdo->prepare("SELECT ID_Utente, Username FROM Utente WHERE ID_Utente = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row) {
        session_unset();
        session_destroy();
        header('Location: index.php');
        exit;
    }

    $_SESSION['username'] = $row['Username'];

    return $row;
}

function savePretty(string $path): bool
{
    $txt = @file_get_contents($path);
    if ($txt === false) return false;


    $pretty = preg_replace("/\n/", "", $txt); // mette tutto in un unica riga
    $pretty = preg_replace("/>\s*</", "><", $pretty); // toglie tab o spazi rimasti

    $pretty = preg_replace('/></', ">\n<", $pretty); // manda a capo quando due tag sono vicini
    $pretty = preg_replace('/<([A-Za-z]*)([^\/>]*)>\n<\/$1>/', "<$1$2></$1>", $pretty); // rimuove il \n nel caso di elemento vuoto (inutile visto che il DOM save cancella tutto)

    $lines = preg_split("/\r\n|\r|\n/", $pretty);

    $tabs = 0;
    $out = [];
    foreach($lines as $line) {
        $trim = trim($line);

        if (preg_match('/<?xml/', $trim) || preg_match('/<!DOCTYPE/', $trim)) {
            $out[] = $trim;
            continue;
        }
        if (preg_match('/<[^\/]*>/', $trim) && !preg_match('/<[^\/]*>[^><]*<\//', $trim)) { // <asd> che contiene altri elementi
            $out[] = str_repeat("\t", $tabs) . $trim;
            $tabs++;
        } elseif (preg_match('/<\/[A-Za-z]*>/', $trim) && !preg_match('/<[^\/]*>/', $trim)) { // </asd>
            --$tabs;
            $out[] = str_repeat("\t", $tabs) . $trim;
        } elseif (preg_match('/<[^\/]*>[^><]*<\//', $trim) || preg_match('/<[^\/>]*\/>/', $trim)) {  // <asd>text</asd> || <asd attributo="asd" />
            $out[] = str_repeat("\t", $tabs) . $trim;
        }
    }

    $pretty = implode("\n", $out) . "\n";

    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $pretty, LOCK_EX) === false) return false;
    return @rename($tmp, $path);
}

function sanitize_id($str) {
    // Sostituisci spazi e caratteri non validi con trattino
    $str = preg_replace('/[^a-zA-Z0-9\-_:.]/', '-', $str);
    // Rimuovi trattini multipli consecutivi
    $str = preg_replace('/-+/', '-', $str);
    // Rimuovi trattini iniziali/finali
    $str = trim($str, '-');
    return $str;
}

function toFloat(?string $s): float {
    return is_numeric($s) ? (float)$s : 0.0;
}

function xpathLiteral(string $s): string {
    if (strpos($s, "'") === false) return "'$s'";
    if (strpos($s, '"') === false) return "\"$s\"";
    $parts = explode("'", $s);
    return "concat('" . implode("', \"'\", '", $parts) . "')";
}

function backupAndSave(DOMDocument $xml, string $selectedPortfolio): string {
    libxml_use_internal_errors(true);

    if (!$xml->validate()){
        return 'Errore validazione DOM';
    }


    $final = __DIR__ . '/../data/' . $selectedPortfolio;
    $tmp   = $final . '.tmp';
    $bakCrumb = preg_replace('/.xml$/', '', pathinfo($selectedPortfolio, PATHINFO_FILENAME)) . '.';
    $bak   = __DIR__ . '/../data/backup/' . $bakCrumb . date('Ymd-His') . '.bak';

    if ($xml->save($tmp) === false) return 'Errore salvataggio temp';

    if (!savePretty($tmp)) {
        unlink($tmp);
        return 'Errore rettifica';
    }

    $check = new DOMDocument();
    if (!$check->load($tmp)) {
        unlink($tmp);
        return 'Temp non ben formato';
    }
    if (!$check->validate()) {
        unlink($tmp);
        return 'Temp non conforme DTD';
    }

    copy($final, $bak);
    if (!rename($tmp, $final)) {
        unlink($tmp);
        return 'Rename fallito';
    }

    return '';
}

function getWAC(DOMElement $asset, DOMXPath $xp): array {
    $avgCost = 0.0;
    $qtyCum  = 0.0;
    foreach ($xp->query('operazioni/operazione', $asset) as $op) {
        $qNode = $xp->query('quantita', $op)->item(0);
        $pNode = $xp->query('prezzo',   $op)->item(0);
        $q  = $qNode ? toFloat($qNode->textContent) : 0.0;
        $pr = $pNode ? toFloat($pNode->textContent) : 0.0;
        if ($q > 0) {
            $avgCost = ($avgCost * $qtyCum + $q * $pr) / ($qtyCum + $q);
            $qtyCum += $q;
        } elseif ($q < 0) {
            $qtyCum += $q;
        }
    }
    return [$avgCost, $qtyCum];
}

function sendError($page, $msg) {
    header('Location: ' . $page . '?err=' . $msg, true, 303);
    exit;
}
