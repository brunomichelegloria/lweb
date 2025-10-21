<?php

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

function xpathLiteral(string $s): string {
    if (strpos($s, "'") === false) return "'$s'";
    if (strpos($s, '"') === false) return "\"$s\"";
    $parts = explode("'", $s);
    return "concat('" . implode("', \"'\", '", $parts) . "')";
}
