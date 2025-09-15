<?php
if (!isset($_GET['ticker'])) {
    http_response_code(400);
    echo "Missing ticker";
    exit;
}

$ticker = preg_replace('/[^A-Za-z0-9.]/', '', $_GET['ticker']);
$url = "https://finance.yahoo.com/quote/$ticker";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_ENCODING, '');
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
curl_setopt($ch, CURLOPT_COOKIEFILE, '');
$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);


if ($code !== 200 || !$html) {
    http_response_code(502);
    echo "Failed to fetch ticker data";
    exit;
}

file_put_contents("debug$ticker.html", $html);

// 1. Cerca il blocco <div class="prices...">...</div>
if (preg_match('/<div[^>]+class="[^"]*prices[^"]*"[^>]*>(.*?)<\/div>/s', $html, $divMatch)) {
    if (preg_match('/>[\d.,]*/', $divMatch[1], $priceMatch)) {
        $priceStr = ltrim($priceMatch[0], '>');
        $priceStr = trim($priceStr);
        $priceStr = str_replace(',', '.', $priceStr);
        $price = floatval($priceStr);
        echo $price . "and" . "\n";
        echo json_encode(['price' => $price]);
        exit;
    }
    echo "preg1";
}

http_response_code(423);
echo "Price not found";
