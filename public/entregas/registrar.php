<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/file_storage.php';

require_operation_post('ENTREGAS_REGISTRAR');

$uploaded = null;
try {
    $assignment = operation_id($_POST['viaje_solicitud_id'] ?? null);
    $state = strtoupper((string)($_POST['estado'] ?? ''));
    if (!in_array($state, ['ENTREGADA', 'FALLIDA'], true)) {
        throw new InvalidArgumentException('Selecciona un resultado válido.');
    }

    $receiver = $identifier = $phone = $proof = $reason = null;
    if ($state === 'ENTREGADA') {
        $receiver = operation_text($_POST['receptor_nombre'] ?? null, 150);
        $identifier = operation_text($_POST['receptor_identificacion'] ?? null, 30, false);
        $phone = operation_text($_POST['receptor_telefono'] ?? null, 30, false);

        $uploaded = storage_save_uploaded_proof('comprobante_archivo');
        $rawUrl = trim((string)($_POST['comprobante_url'] ?? ''));
        if ($uploaded === null && $rawUrl !== '') {
            $proof = operation_reference_url($rawUrl);
        }
    } else {
        $reason = operation_text($_POST['motivo_fallo'] ?? null, 500);
    }
} catch (InvalidArgumentException | RuntimeException $exception) {
    if ($uploaded !== null) {
        storage_delete_proof_file($uploaded['ruta_interna']);
    }
    operation_error($exception->getMessage());
}

try {
    $stmt = db()->prepare(
        'DECLARE @documento_id INT; EXEC dbo.sp_registrar_entrega @viaje_solicitud_id=?,@estado=?,@receptor_nombre=?,@receptor_identificacion=?,@receptor_telefono=?,@comprobante_url=?,@motivo_fallo=?,@nombre_archivo_original=?,@mime_type=?,@tamano_bytes=?,@ruta_interna=?,@documento_id=@documento_id OUTPUT,@permitir_sin_comprobante=1;'
    );
    $stmt->execute([
        $assignment,
        $state,
        $receiver,
        $identifier,
        $phone,
        $proof,
        $reason,
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
        50320 => 'El resultado de entrega no es válido.',
        50321 => 'La entrega exitosa exige indicar el receptor efectivo.',
        50322 => 'La entrega fallida exige un motivo.',
        50323 => 'La solicitud no está en un viaje en curso.',
        50324 => 'La solicitud ya tiene una entrega registrada.',
    ]);
}

$rawReturnTo = trim((string)($_POST['return_to'] ?? ''));
$redirectTarget = preg_match('#^(/viajes/detalle\.php\?id=[1-9]\d*|/entregas\.php\?seccion=(pendientes|entregas)|/solicitudes/detalle\.php\?id=[1-9]\d*)$#', $rawReturnTo) === 1
    ? $rawReturnTo
    : '/entregas.php?seccion=entregas';

$msg = $uploaded !== null
    ? 'Entrega registrada correctamente y comprobante archivado en Documentos.'
    : 'Entrega registrada correctamente.';
operation_success($msg, 'entregaRegistrada', $redirectTarget);
