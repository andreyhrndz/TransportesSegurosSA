<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/document_storage.php';

// 1. Verificar permiso y método antes de evaluar post_max_size (evita confundir 413 con CSRF caducado).
require_permission('DOCUMENTOS_REGISTRAR');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    operation_error('Método no permitido.', 405);
}

if (document_request_exceeded_post_max_size()) {
    $maxLabel = document_format_bytes(document_effective_max_bytes());
    operation_error("El archivo supera el tamaño máximo admitido por el servidor ({$maxLabel}).", 413);
}

require_operation_post('DOCUMENTOS_REGISTRAR');

try {
    $request = operation_id($_POST['solicitud_id'] ?? null);
    $type = operation_id($_POST['tipo_documento_id'] ?? null);
    $reference = operation_text($_POST['numero_referencia'] ?? null, 100);

    $rawUrl = trim((string)($_POST['archivo_url'] ?? ''));
    $hasUrl = $rawUrl !== '';

    $fileEntry = $_FILES['archivo'] ?? null;
    $hasFile = is_array($fileEntry)
        && isset($fileEntry['error'])
        && !is_array($fileEntry['error'])
        && (int)$fileEntry['error'] !== UPLOAD_ERR_NO_FILE;
    if (is_array($fileEntry) && isset($fileEntry['error']) && is_array($fileEntry['error'])) {
        throw new InvalidArgumentException('Adjunta un único archivo por documento.');
    }

    $rawMode = strtolower(trim((string)($_POST['modo_respaldo'] ?? '')));
    if ($rawMode === '') {
        // Compatibilidad con clientes legados que solo envían archivo_url sin modo_respaldo.
        if ($hasUrl && !$hasFile) {
            $rawMode = 'url';
        } elseif ($hasFile && !$hasUrl) {
            $rawMode = 'archivo';
        }
    }

    if ($hasFile && $hasUrl) {
        throw new InvalidArgumentException('Indica únicamente un respaldo: sube un archivo o vincula una URL externa, pero no ambos.');
    }
    if (!$hasFile && !$hasUrl) {
        throw new InvalidArgumentException('Debes subir un archivo (PDF, JPG o PNG) o ingresar una URL externa válida.');
    }
    if (!in_array($rawMode, ['archivo', 'url'], true)) {
        throw new InvalidArgumentException('Selecciona un modo de respaldo válido (archivo o URL externa).');
    }
    if ($rawMode === 'archivo' && !$hasFile) {
        throw new InvalidArgumentException('Selecciona un archivo PDF, JPG o PNG para subir.');
    }
    if ($rawMode === 'url' && !$hasUrl) {
        throw new InvalidArgumentException('Ingresa una URL externa válida.');
    }

    if ($rawMode === 'url') {
        $url = operation_reference_url($rawUrl);
    }
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage(), 422);
}

if ($rawMode === 'url') {
    try {
        $stmt = db()->prepare(
            'DECLARE @documento_id INT; '
            . 'EXEC dbo.sp_registrar_documento @solicitud_id=?,@tipo_documento_id=?,@numero_referencia=?,@archivo_url=?,@documento_id=@documento_id OUTPUT;'
        );
        $stmt->execute([$request, $type, $reference, $url]);
        $stmt->closeCursor();
    } catch (PDOException $exception) {
        operation_sql_error($exception, [
            50406 => 'No tienes permiso para registrar documentos.',
            50431 => 'La solicitud seleccionada no existe.',
            50432 => 'El tipo de documento seleccionado no está activo.',
            50433 => 'Revisa el número de referencia y la URL del documento.',
        ]);
    }

    operation_success('Documento registrado correctamente.', 'documentoRegistrado', '/entregas.php?seccion=documentos&solicitud_id=' . $request);
}

// Rama de carga segura de archivo local privado (PDF / JPG / PNG)
$stored = null;
try {
    $stored = document_validate_and_store_upload($fileEntry);

    $stmt = db()->prepare(
        'DECLARE @documento_id INT; '
        . 'EXEC dbo.sp_registrar_documento_archivo '
        . '@solicitud_id=?,@tipo_documento_id=?,@numero_referencia=?,'
        . '@storage_key=?,@nombre_original=?,@mime_type=?,@tamano_bytes=?,@sha256_hex=?,'
        . '@documento_id=@documento_id OUTPUT;'
    );
    $stmt->execute([
        $request,
        $type,
        $reference,
        $stored['storage_key'],
        $stored['nombre_original'],
        $stored['mime_type'],
        $stored['tamano_bytes'],
        $stored['sha256_hex'],
    ]);
    $stmt->closeCursor();
} catch (DocumentUploadException $exception) {
    if (is_array($stored) && isset($stored['storage_key'])) {
        document_delete_stored_file($stored['storage_key']);
    }
    operation_error($exception->getMessage(), $exception->httpStatus);
} catch (PDOException $exception) {
    if (is_array($stored) && isset($stored['storage_key'])) {
        document_delete_stored_file($stored['storage_key']);
    }
    operation_sql_error($exception, [
        50406 => 'No tienes permiso para registrar documentos.',
        50431 => 'La solicitud seleccionada no existe.',
        50432 => 'El tipo de documento seleccionado no está activo.',
        50433 => 'El archivo adjunto no cumple los requisitos de formato o tamaño.',
        50434 => 'Conflicto al reservar el almacenamiento del documento. Inténtalo nuevamente.',
    ]);
} catch (Throwable) {
    if (is_array($stored) && isset($stored['storage_key'])) {
        document_delete_stored_file($stored['storage_key']);
    }
    error_log('TSSA documents: unexpected error storing attachment');
    operation_error('No se pudo registrar el documento adjunto. Inténtalo nuevamente.', 500);
}

operation_success('Documento adjunto registrado correctamente.', 'documentoRegistrado', '/entregas.php?seccion=documentos&solicitud_id=' . $request);
