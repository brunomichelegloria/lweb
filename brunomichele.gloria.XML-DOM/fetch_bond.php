<?php
header('Content-Type: application/json');

file_put_contents("log_ticker.txt", print_r($_GET, true));

$isin = $_GET['ticker'] ?? '';
$isin = preg_replace('/[^A-Za-z0-9]/', '', $isin); //Sanifica ISIN

if (strlen($isin) < 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ISIN']);
    exit;
}

$search_url = "https://it.investing.com/search/?q=" . urlencode($isin);
$headers = ["User-Agent: Mozilla/5.0"];

function getHtml($url, $headers) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

//Cerca la pagina
$search_html = getHtml($search_url, $headers);
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($search_html);
libxml_clear_errors();
$xpath = new DOMXPath($dom);
$items = $xpath->query("//a[contains(@class, 'js-inner-all-results-quote-item')]");
if ($items->length === 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Bond not found']);
    exit;
}

$relative_link = $items[0]->getAttribute("href");
$full_link = "https://it.investing.com" . $relative_link;

//Estrae il prezzo
$bond_html = getHtml($full_link, $headers);
$dom2 = new DOMDocument();
libxml_use_internal_errors(true);
$dom2->loadHTML($bond_html);
libxml_clear_errors();
$xpath2 = new DOMXPath($dom2);
$meta_nodes = $xpath2->query("//meta[@name='description']");

foreach ($meta_nodes as $meta) {
    $content = $meta->getAttribute("content");
    if (preg_match('/Ultimo prezzo oggi: ([\d.,]+)/', $content, $match)) {
        $price = floatval(str_replace(',', '.', $match[1]));
        echo json_encode(['price' => $price]);
        exit;
    }
}

// ==== FALLBACK: Borsa Italiana (se Investing non ha dato esito) ====
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
                    header('Content-Type: application/json; charset=utf-8');
                    // Ritorniamo la QUOTA (€/100) in 'price'; in JS farai unitPrice = price / 100.
                    echo json_encode(['price' => (float)$num]);
                    exit;
                }
            }
        }
    }

http_response_code(422);
echo json_encode(['error' => 'Price not found']);