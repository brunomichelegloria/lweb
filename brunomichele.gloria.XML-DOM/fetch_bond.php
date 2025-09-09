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

//Cerca la pagina bond
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

//Estrae il prezzo dalla pagina
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

http_response_code(422);
echo json_encode(['error' => 'Price not found']);