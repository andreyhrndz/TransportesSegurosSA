<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/document_storage.php';

require_permission('ENTREGAS_VER');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Método no permitido.');
}

$entregaId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($entregaId === false) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}

try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_comprobante_entrega @entrega_id = ?;', [(int)$entregaId]);
} catch (PDOException $exception) {
    if (read_is_missing_record($exception)) {
        http_response_code(404);
        exit('Comprobante no encontrado.');
    }
    $code = (int)($exception->errorInfo[1] ?? 0);
    if ($code === 50103) {
        http_response_code(403);
        exit('No tienes permiso para descargar comprobantes de entrega.');
    }
    error_log('TSSA deliveries: SQL read error on proof download code ' . $code);
    http_response_code(500);
    exit('No se pudo consultar el comprobante de entrega.');
}

$row = $sets[0][0] ?? null;
if (
    !is_array($row)
    || strtoupper((string)($row['estado'] ?? '')) !== 'ENTREGADA'
    || strtoupper((string)($row['comprobante_origen'] ?? '')) !== 'INTERNA'
) {
    http_response_code(404);
    exit('La entrega indicada no dispone de un comprobante adjunto descargable.');
}

$mime = strtolower(trim((string)($row['comprobante_mime_type'] ?? '')));
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(404);
    exit('Formato de comprobante no disponible.');
}

$storageKey = (string)($row['comprobante_storage_key'] ?? '');
$realPath = document_resolve_existing_path($storageKey);
if ($realPath === null) {
    error_log('TSSA deliveries: stored proof missing for entrega_id=' . (int)$entregaId);
    http_response_code(404);
    exit('El comprobante adjunto no se encuentra disponible en el almacenamiento privado.');
}

$size = @filesize($realPath);
if ($size === false || $size < 1) {
    http_response_code(404);
    exit('El comprobante adjunto no se encuentra disponible.');
}

$canonicalExt = match ($mime) {
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    default => 'png',
};
$displayName = document_sanitize_display_name((string)($row['comprobante_nombre_original'] ?? 'comprobante.' . $canonicalExt), $canonicalExt);
$asciiName = document_header_filename_ascii($displayName);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($displayName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Content-Length: ' . (string)$size);

if ($method === 'GET') {
    readfile($realPath);
}
exit;
