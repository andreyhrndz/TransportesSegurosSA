<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';

$action = (string)($_GET['accion'] ?? 'registrar');
if (!in_array($action, ['registrar', 'resolver'], true)) {
    http_response_code(404);
    exit('Acción no disponible.');
}
require_permission($action === 'registrar' ? 'INCIDENCIAS_REGISTRAR' : 'INCIDENCIAS_RESOLVER');

if ($action === 'registrar') {
    try {
        $solicitudes = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=NULL,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;');
        $types = read_procedure_sets('EXEC dbo.sp_listar_tipos_incidencia @solo_activos=1;', [])[0] ?? [];
    } catch (PDOException) {
        read_error_response('No se pudieron cargar los datos del formulario.');
    }
} else {
    $incident = filter_var($_GET['incidencia_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($incident === false) {
        http_response_code(404);
        exit('Incidencia no encontrada.');
    }
}
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-incidencia">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-incidencia', $action === 'registrar' ? 'Registrar incidencia' : 'Resolver incidencia') ?>
        <form hx-post="/entregas/incidencia.php" hx-target="#mensaje-incidencia" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="accion" value="<?= e($action) ?>">
                <div id="mensaje-incidencia" role="alert"></div>

                <?php if ($action === 'registrar'): ?>
                    <div class="field">
                        <label class="label" for="incidencia-solicitud">Solicitud <span class="required">*</span></label>
                        <select id="incidencia-solicitud" name="solicitud_id" data-combobox <?= has_permission('SOLICITUDES_VER') ? 'data-remote-url="/solicitudes/lista.php"' : '' ?> data-placeholder="Buscar solicitud por código o cliente…" required>
                            <option value="">Selecciona solicitud…</option>
                            <?php foreach ($solicitudes as $row): ?>
                                <option value="<?= (int)$row['solicitud_id'] ?>" data-subtext="<?= e(($row['origen_nombre'] ?? '') . ' → ' . ($row['destino_nombre'] ?? '') . ' · ' . humanize_status($row['estado'])) ?>">
                                    <?= e($row['codigo_solicitud'] . ' · ' . humanize_status($row['estado'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label" for="incidencia-tipo">Tipo de incidencia <span class="required">*</span></label>
                        <select id="incidencia-tipo" name="tipo_incidencia_id" data-combobox data-placeholder="Buscar tipo de incidencia…" required>
                            <option value="">Selecciona tipo…</option>
                            <?php foreach ($types as $row): ?>
                                <option value="<?= (int)$row['tipo_incidencia_id'] ?>" data-subtext="<?= e($row['descripcion'] ?? '') ?>"><?= e($row['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label" for="incidencia-descripcion">Descripción del evento <span class="required">*</span></label>
                        <textarea class="textarea" id="incidencia-descripcion" name="descripcion" maxlength="500" placeholder="Describe detalladamente la incidencia presentada" required></textarea>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="incidencia_id" value="<?= (int)$incident ?>">
                    <div class="field">
                        <label class="label" for="incidencia-resolucion">Detalle de la resolución <span class="required">*</span></label>
                        <textarea class="textarea" id="incidencia-resolucion" name="resolucion" maxlength="500" placeholder="Indica las acciones tomadas para solventar el evento" required autofocus></textarea>
                    </div>
                <?php endif; ?>
            </section>
            <?= modal_footer($action === 'registrar' ? 'Registrar' : 'Resolver', 'is-primary') ?>
        </form>
    </div>
</div>
