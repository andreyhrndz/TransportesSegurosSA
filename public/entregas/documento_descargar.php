<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/document_storage.php';

require_permission('DOCUMENTOS_VER');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Método no permitido.');
}

$docId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($docId === false) {
    http_response_code(404);
    exit('Documento no encontrado.');
}

try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_documento @documento_id = ?;', [(int)$docId]);
} catch (PDOException $exception) {
    if (read_is_missing_record($exception)) {
        http_response_code(404);
        exit('Documento no encontrado.');
    }
    $code = (int)($exception->errorInfo[1] ?? 0);
    if ($code === 50103) {
        http_response_code(403);
        exit('No tienes permiso para descargar documentos.');
    }
    error_log('TSSA documents: SQL read error on download code ' . $code);
    http_response_code(500);
    exit('No se pudo consultar el documento.');
}

$doc = $sets[0][0] ?? null;
if (!is_array($doc) || strtoupper((string)($doc['origen_archivo'] ?? 'EXTERNA')) !== 'INTERNA') {
    http_response_code(404);
    exit('El documento indicado no dispone de un archivo adjunto descargable.');
}

$mime = strtolower(trim((string)($doc['mime_type'] ?? '')));
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(404);
    exit('Formato de documento no disponible.');
}

$storageKey = (string)($doc['storage_key'] ?? '');
$realPath = document_resolve_existing_path($storageKey);
if ($realPath === null) {
    error_log('TSSA documents: stored file missing for documento_id=' . (int)$docId);
    http_response_code(404);
    exit('El archivo adjunto no se encuentra disponible en el almacenamiento privado.');
}

$size = @filesize($realPath);
if ($size === false || $size < 1) {
    http_response_code(404);
    exit('El archivo adjunto no se encuentra disponible.');
}

$canonicalExt = match ($mime) {
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    default => 'png',
};
$displayName = document_sanitize_display_name((string)($doc['nombre_original'] ?? 'documento.' . $canonicalExt), $canonicalExt);
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
