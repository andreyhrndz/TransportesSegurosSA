<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/file_storage.php';

require_operation_post('DOCUMENTOS_REGISTRAR');

$uploaded = null;
try {
    $request = operation_id($_POST['solicitud_id'] ?? null);
    $type = operation_id($_POST['tipo_documento_id'] ?? null);
    $reference = operation_text($_POST['numero_referencia'] ?? null, 100);

    $uploaded = storage_save_uploaded_proof('documento_archivo');
    $rawUrl = trim((string)($_POST['archivo_url'] ?? ''));
    $url = null;
    if ($uploaded === null) {
        if ($rawUrl === '') {
            throw new InvalidArgumentException('Adjunta un archivo (JPG/PNG/PDF) o ingresa una URL de referencia válida.');
        }
        $url = operation_reference_url($rawUrl);
    }
} catch (InvalidArgumentException | RuntimeException $exception) {
    if ($uploaded !== null) {
        storage_delete_proof_file($uploaded['ruta_interna']);
    }
    operation_error($exception->getMessage());
}

try {
    $stmt = db()->prepare(
        'DECLARE @documento_id INT; EXEC dbo.sp_registrar_documento @solicitud_id=?,@tipo_documento_id=?,@numero_referencia=?,@archivo_url=?,@documento_id=@documento_id OUTPUT,@nombre_archivo_original=?,@mime_type=?,@tamano_bytes=?,@ruta_interna=?;'
    );
    $stmt->execute([
        $request,
        $type,
        $reference,
        $url,
        $uploaded['nombre_original'] ?? null,
        $uploaded['mime_type'] ?? null,
        $uploaded['tamano_bytes'] ?? null,
        $uploaded['ruta_interna'] ?? null,
    ]);
    $stmt->closeCursor();
} catch (PDOException $exception) {
    if ($uploaded !== null) {
        storage_delete_proof_file($uploaded['ruta_interna']);
    }
    operation_sql_error($exception, [
        50406 => 'No tienes permiso para registrar documentos.',
    ]);
}

operation_success('Documento registrado correctamente.', 'documentoRegistrado', '/entregas.php?seccion=documentos');
