<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_permission('VIAJES_CREAR');

try {
    $vehiculos = operation_list(
        'DECLARE @total_registros INT; EXEC dbo.sp_listar_vehiculos @estado=?,@solo_activos=1,@buscar=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        ['DISPONIBLE']
    );
    $conductores = operation_list(
        'DECLARE @total_registros INT; EXEC dbo.sp_listar_conductores @estado=?,@solo_activos=1,@buscar=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        ['DISPONIBLE']
    );
    $rutas = operation_list(
        'DECLARE @total_registros INT; EXEC dbo.sp_listar_rutas @origen_id=NULL,@destino_id=NULL,@solo_activas=1,@buscar=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;'
    );
} catch (PDOException) {
    read_error_response('No se pudieron cargar los recursos disponibles.');
}
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-viaje">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-viaje', 'Nuevo viaje') ?>
        <form hx-post="/viajes/guardar.php" hx-target="#mensaje-viaje" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div id="mensaje-viaje" role="alert"></div>
                <div id="aviso-diagnostico-habilitacion" class="notification is-light py-2 px-3 mb-3" style="display: none;" role="status" aria-live="polite"></div>

                <div class="field">
                    <label class="label" for="viaje-vehiculo">Vehículo disponible <span class="required">*</span></label>
                    <select id="viaje-vehiculo" name="vehiculo_id" data-combobox data-remote-url="/flota/lista.php?seccion=vehiculos&estado=DISPONIBLE" data-placeholder="Buscar unidad por placa o modelo…" required>
                        <option value="">Selecciona vehículo…</option>
                        <?php foreach ($vehiculos as $row): ?>
                            <?php
                            $pmaDisplay = format_number_str($row['pma_kg'] ?? $row['capacidad_carga_kg']);
                            $subtextVeh = 'PMA: ' . $pmaDisplay . ' kg · ' . ($row['clase_legal'] ?? 'LIVIANO') . (!empty($row['es_articulado']) ? ' (Articulado)' : '');
                            ?>
                            <option value="<?= (int)$row['vehiculo_id'] ?>" data-pma="<?= e($row['pma_kg'] ?? $row['capacidad_carga_kg']) ?>" data-articulado="<?= (int)($row['es_articulado'] ?? 0) ?>" data-clase="<?= e($row['clase_legal'] ?? 'LIVIANO') ?>" data-subtext="<?= e($subtextVeh) ?>">
                                <?= e($row['placa'] . ' · ' . $row['marca'] . ' ' . $row['modelo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="viaje-conductor">Conductor disponible <span class="required">*</span></label>
                    <select id="viaje-conductor" name="conductor_id" data-combobox data-remote-url="/flota/lista.php?seccion=conductores&estado=DISPONIBLE" data-placeholder="Buscar conductor disponible…" required>
                        <option value="">Selecciona conductor…</option>
                        <?php foreach ($conductores as $row): ?>
                            <?php
                            $licSub = !empty($row['licencias_verificadas']) ? ('Lic: ' . $row['licencias_verificadas']) : ('Lic. ' . $row['numero_licencia']);
                            ?>
                            <option value="<?= (int)$row['conductor_id'] ?>" data-licencias="<?= e($row['licencias_verificadas'] ?? '') ?>" data-vencimiento="<?= e($row['fecha_vencimiento_licencia'] ?? '') ?>" data-subtext="<?= e($licSub) ?>">
                                <?= e(trim($row['nombre'] . ' ' . $row['primer_apellido'] . ' ' . ($row['segundo_apellido'] ?? ''))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="viaje-ruta">Ruta activa <span class="required">*</span></label>
                    <select id="viaje-ruta" name="ruta_id" data-combobox data-remote-url="/flota/lista.php?seccion=rutas" data-placeholder="Buscar ruta por nombre, origen o destino…" required>
                        <option value="">Selecciona ruta…</option>
                        <?php foreach ($rutas as $row): ?>
                            <option value="<?= (int)$row['ruta_id'] ?>" data-subtext="<?= e(($row['origen_nombre'] ?? '') . ' → ' . ($row['destino_nombre'] ?? '')) ?>"><?= e($row['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="viaje-fecha">Fecha y hora de salida programada <span class="required">*</span></label>
                    <input class="input" id="viaje-fecha" name="fecha_programada" type="text" data-datepicker="datetime" min="<?= e(gmdate('Y-m-d\\TH:i', time() + 60)) ?>" placeholder="dd/mm/aaaa, hh:mm" required>
                </div>
            </section>
            <?= modal_footer('Crear viaje', 'is-primary') ?>
        </form>
    </div>
</div>
