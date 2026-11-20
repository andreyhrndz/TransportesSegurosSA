<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$query = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($query, 'UTF-8') < 3 || mb_strlen($query, 'UTF-8') > 150) {
    echo json_encode(['ok' => true, 'results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('curl_init')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'results' => [], 'error' => 'Servicio de búsqueda no disponible.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
    'q' => $query,
    'format' => 'jsonv2',
    'countrycodes' => 'cr',
    'limit' => '5',
    'addressdetails' => '1',
]);

$ch = curl_init($url);
$caInfo = ini_get('curl.cainfo');
$opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => 'TransportesSegurosSA/1.0 (Sistema Logistico CR)',
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Accept-Language: es-CR,es;q=0.9',
    ],
];
if (!empty($caInfo) && file_exists($caInfo)) {
    $opts[CURLOPT_CAINFO] = $caInfo;
}
curl_setopt_array($ch, $opts);

$body = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($body === false || $err !== '' || $httpCode !== 200) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'results' => [],
        'error' => 'No fue posible consultar el buscador de direcciones. Marca el punto directamente en el mapa.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$decoded = json_decode($body, true);
if (!is_array($decoded)) {
    echo json_encode(['ok' => true, 'results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$results = [];
foreach ($decoded as $item) {
    if (!is_array($item) || !isset($item['lat'], $item['lon'])) {
        continue;
    }
    $lat = (float)$item['lat'];
    $lon = (float)$item['lon'];
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        continue;
    }
    $display = trim((string)($item['display_name'] ?? ''));
    $short = trim((string)($item['name'] ?? ''));
    if ($short === '' && $display !== '') {
        $parts = explode(',', $display);
        $short = trim($parts[0]);
    }
    $results[] = [
        'label' => $display !== '' ? $display : ($short !== '' ? $short : sprintf('%.6f, %.6f', $lat, $lon)),
        'short_name' => $short,
        'lat' => round($lat, 6),
        'lon' => round($lon, 6),
    ];
}

echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
exit;
