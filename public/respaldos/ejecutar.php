<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';

require_permission('AUDITORIA_VER');
require_operation_post('USUARIOS_GESTIONAR_CUENTAS');

$accion = strtoupper(trim((string)($_POST['accion'] ?? '')));
if (!in_array($accion, ['FULL', 'DIFF', 'LOG', 'ETL'], true)) {
    operation_error('Tipo de operación de respaldo no válido.', 422);
}

try {
    if ($accion === 'ETL') {
        $stmt = db()->prepare('EXEC TransportesSegurosSA_DW.dbo.sp_cargar_datawarehouse;');
        $stmt->execute();
        $totalHechos = null;
        do {
            if ($stmt->columnCount() > 0) {
                $row = $stmt->fetch();
                if (is_array($row) && isset($row['TotalHechos'])) {
                    $totalHechos = (int)$row['TotalHechos'];
                }
            }
        } while ($stmt->nextRowset());
        $stmt->closeCursor();

        // Auditar sincronización ETL
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        if ($uid > 0) {
            $aud = db()->prepare("INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_nuevos) VALUES (?, N'respaldos', N'TransportesSegurosSA_DW', N'ETL_SYNC', ?);");
            $aud->execute([$uid, 'Sincronización manual ETL completada. Total hechos: ' . ($totalHechos ?? 0)]);
        }

        $msg = 'ETL del Data Warehouse sincronizado correctamente' . ($totalHechos !== null ? " ({$totalHechos} entregas en fact_entregas)." : '.');
        operation_success($msg, 'respaldoGenerado', '/respaldos.php');
    }

    $stmt = db()->prepare('DECLARE @ruta NVARCHAR(500); EXEC dbo.sp_ejecutar_respaldo_operativo @tipo=?, @ruta_generada=@ruta OUTPUT; SELECT @ruta AS ruta;');
    $stmt->execute([$accion]);
    $ruta = '';
    do {
        if ($stmt->columnCount() > 0) {
            $row = $stmt->fetch();
            if (is_array($row) && isset($row['ruta'])) {
                $ruta = (string)$row['ruta'];
            }
        }
    } while ($stmt->nextRowset());
    $stmt->closeCursor();

    $archivo = $ruta !== '' ? basename($ruta) : '';
    $msg = "Respaldo {$accion} generado y verificado (CHECKSUM + RESTORE VERIFYONLY OK)" . ($archivo !== '' ? ": {$archivo}" : '.');
    operation_success($msg, 'respaldoGenerado', '/respaldos.php');
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        50911 => 'Primero debes generar un Backup FULL base antes de ejecutar un Diferencial o Transaction Log.',
        50912 => 'La base de datos debe estar en modelo de recuperación FULL para respaldar el Transaction Log (.trn).',
        50913 => 'No se pudo determinar la carpeta de respaldos del motor SQL Server.',
    ]);
}
