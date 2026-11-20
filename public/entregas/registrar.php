<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/document_storage.php';

// 1. Verificar permiso y método antes de evaluar post_max_size (evita confundir 413 con CSRF caducado).
require_permission('ENTREGAS_REGISTRAR');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    operation_error('Método no permitido.', 405);
}

if (document_request_exceeded_post_max_size()) {
    $maxLabel = document_format_bytes(document_effective_max_bytes());
    operation_error("El comprobante supera el tamaño máximo admitido por el servidor ({$maxLabel}).", 413);
}

require_operation_post('ENTREGAS_REGISTRAR');

$rawMode = '';
$fileEntry = $_FILES['comprobante_archivo'] ?? null;

try {
    $assignment = operation_id($_POST['viaje_solicitud_id'] ?? null);
    $state = strtoupper(trim((string)($_POST['estado'] ?? '')));
    if (!in_array($state, ['ENTREGADA', 'FALLIDA'], true)) {
        throw new InvalidArgumentException('Selecciona un resultado válido.');
    }

    $receiver = $identifier = $phone = $proofUrl = $reason = null;

    $hasFile = is_array($fileEntry)
        && isset($fileEntry['error'])
        && !is_array($fileEntry['error'])
        && (int)$fileEntry['error'] !== UPLOAD_ERR_NO_FILE;
    if (is_array($fileEntry) && isset($fileEntry['error']) && is_array($fileEntry['error'])) {
        throw new InvalidArgumentException('Adjunta un único archivo como comprobante de entrega.');
    }
    $rawUrl = trim((string)($_POST['comprobante_url'] ?? ''));
    $hasUrl = $rawUrl !== '';

    if ($state === 'ENTREGADA') {
        $receptorMode = strtolower(trim((string)($_POST['modo_receptor'] ?? '')));
        $rawReceiverName = trim((string)($_POST['receptor_nombre'] ?? ''));
        if ($receptorMode === '') {
            // Retrocompatibilidad: si viene receptor_nombre explícito se usa como tercero; si no, destinatario de la solicitud.
            $receptorMode = $rawReceiverName !== '' ? 'tercero' : 'destinatario';
        }
        if (!in_array($receptorMode, ['destinatario', 'tercero'], true)) {
            throw new InvalidArgumentException('Selecciona quién recibió la carga.');
        }

        if ($receptorMode === 'destinatario') {
            try {
                $destSets = read_procedure_sets('EXEC dbo.sp_obtener_destinatario_asignacion @viaje_solicitud_id = ?;', [$assignment]);
            } catch (PDOException $destEx) {
                if (read_is_missing_record($destEx)) {
                    throw new InvalidArgumentException('La solicitud no está en un viaje en curso.');
                }
                throw new InvalidArgumentException('No se pudo consultar el destinatario registrado en la solicitud.');
            }
            $destRow = $destSets[0][0] ?? null;
            $receiver = operation_text(is_array($destRow) ? ($destRow['destinatario_nombre_completo'] ?? null) : null, 150);
            $identifier = operation_text(is_array($destRow) ? ($destRow['destinatario_identificacion'] ?? null) : null, 30, false);
            $phone = operation_text(is_array($destRow) ? ($destRow['destinatario_telefono'] ?? null) : null, 30, false);
        } else {
            $receiver = operation_text($_POST['receptor_nombre'] ?? null, 150);
            $identifier = operation_text($_POST['receptor_identificacion'] ?? null, 30, false);
            $phone = operation_text($_POST['receptor_telefono'] ?? null, 30, false);
        }

        $rawMode = strtolower(trim((string)($_POST['modo_comprobante'] ?? '')));
        if ($rawMode === '') {
            // Compatibilidad con clientes legados que solo envían comprobante_url
            if ($hasUrl && !$hasFile) {
                $rawMode = 'url';
            } elseif ($hasFile && !$hasUrl) {
                $rawMode = 'archivo';
            }
        }

        if ($hasFile && $hasUrl) {
            throw new InvalidArgumentException('Indica únicamente un comprobante: sube un archivo o vincula una URL externa, pero no ambos.');
        }
        if (!$hasFile && !$hasUrl) {
            throw new InvalidArgumentException('Debes subir un comprobante (PDF, JPG o PNG) o ingresar una URL externa válida.');
        }
        if (!in_array($rawMode, ['archivo', 'url'], true)) {
            throw new InvalidArgumentException('Selecciona un modo de comprobante válido (archivo o URL externa).');
        }
        if ($rawMode === 'archivo' && !$hasFile) {
            throw new InvalidArgumentException('Selecciona un comprobante PDF, JPG o PNG para subir.');
        }
        if ($rawMode === 'url' && !$hasUrl) {
            throw new InvalidArgumentException('Ingresa una URL externa válida para el comprobante.');
        }

        if ($rawMode === 'url') {
            $proofUrl = operation_reference_url($rawUrl);
        }
    } else {
        if ($hasFile || $hasUrl) {
            throw new InvalidArgumentException('Una entrega fallida no debe incluir comprobante adjunto ni URL.');
        }
        $reason = operation_text($_POST['motivo_fallo'] ?? null, 500);
    }
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage(), 422);
}

$sqlErrorMap = [
    50319 => 'No tienes permiso para registrar entregas.',
    50320 => 'El resultado de entrega no es válido.',
    50321 => 'La entrega exitosa exige receptor y un único comprobante.',
    50322 => 'La entrega fallida exige un motivo.',
    50323 => 'La solicitud no está en un viaje en curso.',
    50324 => 'La solicitud ya tiene una entrega registrada.',
    50433 => 'El comprobante adjunto no cumple los requisitos de formato o tamaño.',
    50434 => 'Conflicto al reservar el almacenamiento del comprobante. Inténtalo nuevamente.',
];

// Rama FALLIDA o ENTREGADA con URL externa (100% retrocompatible)
if ($state === 'FALLIDA' || $rawMode === 'url') {
    try {
        $stmt = db()->prepare(
            'EXEC dbo.sp_registrar_entrega '
            . '@viaje_solicitud_id=?,@estado=?,@receptor_nombre=?,@receptor_identificacion=?,'
            . '@receptor_telefono=?,@comprobante_url=?,@motivo_fallo=?;'
        );
        $stmt->execute([$assignment, $state, $receiver, $identifier, $phone, $proofUrl, $reason]);
        $stmt->closeCursor();
    } catch (PDOException $exception) {
        operation_sql_error($exception, $sqlErrorMap);
    }

    operation_success('Entrega registrada correctamente.', 'entregaRegistrada', '/entregas.php?seccion=entregas');
}

// Rama ENTREGADA con comprobante local privado (PDF / JPG / PNG)
$stored = null;
try {
    $stored = document_validate_and_store_upload($fileEntry);

    $stmt = db()->prepare(
        'EXEC dbo.sp_registrar_entrega '
        . '@viaje_solicitud_id=?,@estado=?,@receptor_nombre=?,@receptor_identificacion=?,'
        . '@receptor_telefono=?,@comprobante_url=NULL,@motivo_fallo=NULL,'
        . '@comprobante_storage_key=?,@comprobante_nombre_original=?,@comprobante_mime_type=?,'
        . '@comprobante_tamano_bytes=?,@comprobante_sha256_hex=?;'
    );
    $stmt->execute([
        $assignment,
        $state,
        $receiver,
        $identifier,
        $phone,
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
    operation_sql_error($exception, $sqlErrorMap);
} catch (Throwable) {
    if (is_array($stored) && isset($stored['storage_key'])) {
        document_delete_stored_file($stored['storage_key']);
    }
    error_log('TSSA deliveries: unexpected error storing delivery proof');
    operation_error('No se pudo registrar la entrega con el comprobante adjunto. Inténtalo nuevamente.', 500);
}

operation_success('Entrega registrada correctamente con comprobante adjunto.', 'entregaRegistrada', '/entregas.php?seccion=entregas');
