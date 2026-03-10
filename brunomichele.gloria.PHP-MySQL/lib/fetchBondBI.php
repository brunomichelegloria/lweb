<?php
function getPriceBondBI(string $isin): float{

    $isin = preg_replace('/[^A-Za-z0-9]/', '', $isin); //Sanifica ISIN
    if ($isin === '' || strlen($isin) < 10) return -1.00;


    $borsaUrl = "https://www.borsaitaliana.it/borsa/search/scheda.html?code=" . urlencode($isin) . "&lang=it";

    $ch = curl_init($borsaUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING       => '', // gzip
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:141.0) Gecko/20100101 Firefox/141.0',
        CURLOPT_HTTPHEADER     => ['Accept-Language: it-IT,it;q=0.9,en;q=0.8'],
    ]);
    $html = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http >= 200 && $http < 300 && $html) {
        // parse -> trova il primo numero in .summary-value
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xp = new DOMXPath($dom);

        // prende il primo <strong> in .summary-value
        $node = $xp->query("(//div[contains(concat(' ', normalize-space(@class), ' '), ' summary-value ')]//strong)[1]")->item(0);

        if ($node) {
            $raw = trim($node->textContent);

            // estrae il primo valore numerico (. come separatore migliaia , come decimale)
            $txt = trim(str_replace("\xc2\xa0", ' ', (string)$raw)); // normalizza nbsp
            if (preg_match('/\d{1,3}(?:\.\d{3})*(?:,\d+)?|\d+(?:,\d+)?/', $txt, $m)) {
                $num = $m[0];
                // normalizza "1.234,56" -> "1234.56"
                $num = str_replace('.', '', $num);
                $num = str_replace(',', '.', $num);

                if (is_numeric($num)) {
                    // Ritorniamo la QUOTA (€/100) in 'price'; in JS unitPrice = price / 100.
                    return (float)$num;
                }
            }
        }
    }
    return -1.00;
}

if (!debug_backtrace()) {
    header('Content-Type: application/json; charset=utf-8');
    $i = $_GET['ticker'] ?? '';
    $p = getPriceBondBI($i);
    if ($p <= 0) {
        echo json_encode(['error' => 'Invalid ISIN']);
    } else {
        echo json_encode(['price' => $p]);
    }
    exit;
}