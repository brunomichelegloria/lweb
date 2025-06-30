<?php
header('Content-Type: application/json');

$ticker = $_GET['ticker'] ?? '';
$ticker = preg_replace('/[^A-Za-z0-9.]/', '', $ticker);
$url = "https://query1.finance.yahoo.com/v8/finance/chart/$ticker?symbol=$ticker&interval=1d";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_ENCODING, '');
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

// verifica e restituisce il prezzo
if (
    isset($data['chart']['result'][0]['meta']['regularMarketPrice']) &&
    is_numeric($data['chart']['result'][0]['meta']['regularMarketPrice'])
) {
    $price = floatval($data['chart']['result'][0]['meta']['regularMarketPrice']);
    echo json_encode(['price' => $price]);
    exit;
}

http_response_code(422);
echo json_encode(['error' => 'Price not found']);