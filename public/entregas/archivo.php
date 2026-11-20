<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/file_storage.php';

require_login();
if (!has_permission('DOCUMENTOS_VER') && !has_permission('ENTREGAS_VER')) {
    http_response_code(403);
    exit('No tienes permiso para ver este archivo.');
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit('Documento no encontrado.');
}

try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_documento_archivo @documento_id=?;', [(int)$id]);
    $doc = $sets[0][0] ?? null;
} catch (PDOException $exception) {
    http_response_code(read_is_missing_record($exception) ? 404 : 500);
    exit('Documento no disponible.');
}

if (!is_array($doc) || empty($doc['ruta_interna'])) {
    http_response_code(404);
    exit('Archivo no disponible.');
}

$fullPath = storage_resolve_proof_path((string)$doc['ruta_interna']);
if ($fullPath === null) {
    http_response_code(404);
    exit('Archivo no encontrado en el almacenamiento.');
}

$mime = (string)($doc['mime_type'] ?? 'application/octet-stream');
if (!array_key_exists($mime, storage_allowed_mimes())) {
    $mime = 'application/octet-stream';
}

$downloadName = preg_replace('/[^A-Za-z0-9._\-]/', '_', (string)($doc['nombre_archivo_original'] ?? 'comprobante')) ?: 'comprobante';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($fullPath));
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($fullPath);
exit;
