<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/transport_request_tvp.php';
require_operation_post('SOLICITUDES_CREAR');

try {
    // ASVS 2.2.1/2.2.3: validación positiva antes de formar el lote parametrizado.
    $codigo = operation_text($_POST['codigo'] ?? null, 30);
    $clienteId = operation_id($_POST['cliente_id'] ?? null); $origenId = operation_id($_POST['origen_id'] ?? null); $destinoId = operation_id($_POST['destino_id'] ?? null);
    if ($origenId === $destinoId) { throw new InvalidArgumentException('Origen y destino deben ser distintos.'); }
    $fecha = operation_future_datetime($_POST['fecha_entrega'] ?? null);
    $destinatario = [operation_text($_POST['destinatario_nombre'] ?? null, 150), operation_text($_POST['destinatario_apellido1'] ?? null, 100), operation_text($_POST['destinatario_apellido2'] ?? null, 100), operation_text($_POST['destinatario_identificacion'] ?? null, 30, false), operation_text($_POST['destinatario_telefono'] ?? null, 30, false)];
    $mercancias = $_POST['mercancias'] ?? null;
    if (!is_array($mercancias) || count($mercancias) > 20) { throw new InvalidArgumentException('Incluye entre una y veinte mercancías válidas.'); }
    $tvp = build_tipo_mercancias_tvp(array_values($mercancias));
} catch (InvalidArgumentException $exception) { operation_error($exception->getMessage()); }

try {
    // PDO_SQLSRV no expone TVP nativo: el lote usa el tipo SQL real y cada valor sigue enlazado.
    $values = implode(',', array_fill(0, count($tvp['tipo_mercancias']), '(?,?,?,?)'));
    $sql = 'DECLARE @solicitud_id INT; DECLARE @mercancias dbo.tipo_mercancias; INSERT INTO @mercancias (tipo_mercancia_id,descripcion,peso_kg,volumen_m3) VALUES ' . $values . '; EXEC dbo.sp_crear_solicitud @codigo=?,@cliente_id=?,@origen_id=?,@destino_id=?,@destinatario_nombre=?,@destinatario_apellido1=?,@destinatario_apellido2=?,@destinatario_identificacion=?,@destinatario_telefono=?,@fecha_entrega_requerida=?,@mercancias=@mercancias,@solicitud_id=@solicitud_id OUTPUT; SELECT @solicitud_id AS solicitud_id;';
    $params = []; foreach ($tvp['tipo_mercancias'] as $row) { array_push($params, ...$row); } $params = array_merge($params, [$codigo,$clienteId,$origenId,$destinoId,...$destinatario,$fecha]);
    $statement = db()->prepare($sql); $statement->execute($params); $newId = null; do { if ($statement->columnCount() > 0) { $row = $statement->fetch(); if (is_array($row) && isset($row['solicitud_id'])) { $newId = (int)$row['solicitud_id']; } } } while ($statement->nextRowset()); $statement->closeCursor();
    if ($newId === null || $newId < 1) { throw new RuntimeException('No se recibió el identificador de la solicitud.'); }
} catch (PDOException $exception) { operation_sql_error($exception, [50204=>'Origen y destino deben ser distintos.',50205=>'La fecha de entrega debe ser futura.',50206=>'Incluye al menos una mercancía.',50207=>'Revisa el peso y volumen de las mercancías.',50208=>'El cliente no está activo.']); } catch (RuntimeException) { operation_error('No se pudo registrar la solicitud. Inténtalo nuevamente.', 500); }
operation_success('Solicitud registrada correctamente.', 'solicitudCreada', '/solicitudes/detalle.php?id=' . $newId);
