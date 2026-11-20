<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_permission('VIAJES_VER');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit('Viaje no encontrado.');
}

try {
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_viaje @viaje_id=?;', [$id]);
    $viaje = $sets[0][0] ?? null;
    $paradas = $sets[1] ?? [];
    $asignaciones = $sets[2] ?? [];
    $cap = read_procedure_sets('EXEC dbo.sp_obtener_capacidad_viaje @viaje_id=?;', [$id])[0][0] ?? null;
} catch (PDOException) {
    read_error_response();
}

if (!is_array($viaje)) {
    http_response_code(404);
    exit('Viaje no encontrado.');
}

// Cargar destinatarios de cada solicitud asignada
$solicitudesDetalle = [];
foreach ($asignaciones as $asig) {
    $solId = (int)$asig['solicitud_id'];
    try {
        $solRow = read_procedure_sets('EXEC dbo.sp_obtener_solicitud @solicitud_id=?;', [$solId])[0][0] ?? null;
        if (is_array($solRow)) {
            $solicitudesDetalle[$solId] = $solRow;
        }
    } catch (PDOException) {
        // continuar
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hoja de Ruta — <?= e($viaje['codigo_viaje']) ?></title>
    <script src="/assets/js/print_sheet.js" defer></script>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color: #111827; background: #fff; margin: 0; padding: 24px; line-height: 1.45; }
        .sheet { max-width: 920px; margin: 0 auto; }
        .topbar-print { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 2px solid #111827; }
        .actions { display: flex; gap: 8px; }
        .btn { padding: 8px 14px; border-radius: 6px; border: 1px solid #cbd5e1; background: #f8fafc; color: #0f172a; font-weight: 600; cursor: pointer; text-decoration: none; font-size: 14px; }
        .btn-primary { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }
        h1 { margin: 0; font-size: 22px; }
        h2 { font-size: 16px; margin: 22px 0 8px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
        .meta-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 14px; font-size: 13px; }
        .meta-grid span { display: block; font-size: 11px; text-transform: uppercase; color: #64748b; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-weight: 600; }
        .sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 36px; margin-top: 34px; font-size: 13px; }
        .sig-box { border-top: 1px solid #111827; padding-top: 6px; text-align: center; }
        @media print {
            body { padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <div class="topbar-print">
        <div>
            <h1>Hoja de Ruta / Manifiesto — <?= e($viaje['codigo_viaje']) ?></h1>
            <div style="font-size: 13px; color: #475569;">Transportes Seguros S.A. · Emitido el <?= e(format_utc_datetime(gmdate('Y-m-d H:i:s'))) ?></div>
        </div>
        <div class="actions no-print">
            <a class="btn" href="/viajes/detalle.php?id=<?= (int)$id ?>">Volver al viaje</a>
            <button class="btn btn-primary" type="button" data-print-sheet>Imprimir / Guardar PDF</button>
        </div>
    </div>

    <div class="meta-grid">
        <div><span>Estado</span><strong><?= e(humanize_status($viaje['estado'])) ?></strong></div>
        <div><span>Vehículo (Placa)</span><strong><?= e($viaje['placa']) ?></strong></div>
        <div><span>Conductor</span><strong><?= e($viaje['conductor_nombre']) ?></strong></div>
        <div><span>Ruta de referencia</span><strong><?= e($viaje['ruta_nombre']) ?></strong></div>
        <div><span>Salida programada</span><strong><?= e(format_utc_datetime($viaje['fecha_programada_salida'])) ?></strong></div>
        <div>
            <span>Carga asignada</span>
            <strong>
                <?php if (is_array($cap)): ?>
                    <?= e(format_cr_measure($cap['peso_usado_kg'] ?? 0)) ?> / <?= e(format_cr_measure($cap['capacidad_carga_kg'] ?? 0)) ?> kg
                <?php else: ?>
                    —
                <?php endif; ?>
            </strong>
        </div>
    </div>

    <h2>1. Itinerario ordenado de paradas (<?= count($paradas) ?>)</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 55px;">Orden</th>
                <th style="width: 110px;">Tipo</th>
                <th>Ubicación y señas</th>
                <th style="width: 140px;">Coordenadas</th>
                <th style="width: 110px;">Hora llegada</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$paradas): ?>
                <tr><td colspan="5">Sin paradas registradas.</td></tr>
            <?php endif; ?>
            <?php foreach ($paradas as $p): ?>
                <tr>
                    <td><strong>#<?= (int)$p['orden'] ?></strong></td>
                    <td><?= e(humanize_status($p['tipo_parada'])) ?></td>
                    <td>
                        <strong><?= e($p['ubicacion_nombre']) ?></strong>
                        <?php if (!empty($p['direccion'])): ?>
                            <div><?= e($p['direccion']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= ($p['latitud'] !== null && $p['longitud'] !== null) ? e($p['latitud'] . ', ' . $p['longitud']) : 'Sin punto' ?></td>
                    <td></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>2. Órdenes y destinatarios a entregar (<?= count($asignaciones) ?>)</h2>
    <table>
        <thead>
            <tr>
                <th>Solicitud</th>
                <th>Destinatario autorizado</th>
                <th>Teléfono</th>
                <th>Instrucciones / Destino</th>
                <th style="width: 150px;">Firma recepción</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$asignaciones): ?>
                <tr><td colspan="5">Sin solicitudes asignadas.</td></tr>
            <?php endif; ?>
            <?php foreach ($asignaciones as $a): ?>
                <?php $sd = $solicitudesDetalle[(int)$a['solicitud_id']] ?? null; ?>
                <tr>
                    <td><strong><?= e($a['codigo_solicitud']) ?></strong></td>
                    <td>
                        <?php if ($sd): ?>
                            <strong><?= e(trim(($sd['destinatario_nombre'] ?? '') . ' ' . ($sd['destinatario_apellido1'] ?? '') . ' ' . ($sd['destinatario_apellido2'] ?? ''))) ?></strong>
                            <?php if (!empty($sd['destinatario_identificacion'])): ?>
                                <div>ID: <?= e($sd['destinatario_identificacion']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= e($sd['destinatario_telefono'] ?? '—') ?></td>
                    <td>
                        <?= e($sd['destino_nombre'] ?? '') ?>
                        <?php if (!empty($sd['destino_direccion'])): ?>
                            <div><?= e($sd['destino_direccion']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($sd['instrucciones_entrega'])): ?>
                            <div><em>Nota: <?= e($sd['instrucciones_entrega']) ?></em></div>
                        <?php endif; ?>
                    </td>
                    <td style="height: 44px;"></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="sig-grid">
        <div class="sig-box">Firma del despachador</div>
        <div class="sig-box">Firma del conductor (<?= e($viaje['conductor_nombre']) ?>)</div>
    </div>
</div>
</body>
</html>
