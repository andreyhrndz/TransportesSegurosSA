<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_permission('DOCUMENTOS_REGISTRAR');

try {
    $solicitudes = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=NULL,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;');
    $types = read_procedure_sets('EXEC dbo.sp_listar_tipos_documento @solo_activos=1;', [])[0] ?? [];
} catch (PDOException) {
    read_error_response('No se pudieron cargar los datos del formulario.');
}
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-documento">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-documento', 'Registrar documento') ?>
        <form hx-post="/entregas/documento.php" hx-target="#mensaje-documento" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div id="mensaje-documento" role="alert"></div>

                <div class="field">
                    <label class="label" for="documento-solicitud">Solicitud <span class="required">*</span></label>
                    <select id="documento-solicitud" name="solicitud_id" data-combobox <?= has_permission('SOLICITUDES_VER') ? 'data-remote-url="/solicitudes/lista.php"' : '' ?> data-placeholder="Buscar solicitud por código o cliente…" required>
                        <option value="">Selecciona solicitud…</option>
                        <?php foreach ($solicitudes as $row): ?>
                            <option value="<?= (int)$row['solicitud_id'] ?>" data-subtext="<?= e(($row['cliente_nombre'] ?? '') . ' · ' . humanize_status($row['estado'])) ?>">
                                <?= e($row['codigo_solicitud']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="documento-tipo">Tipo de documento <span class="required">*</span></label>
                    <select id="documento-tipo" name="tipo_documento_id" data-combobox data-placeholder="Buscar tipo de documento…" required>
                        <option value="">Selecciona tipo…</option>
                        <?php foreach ($types as $row): ?>
                            <option value="<?= (int)$row['tipo_documento_id'] ?>" data-subtext="<?= e($row['descripcion'] ?? '') ?>"><?= e($row['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="documento-referencia">Número de referencia <span class="required">*</span></label>
                    <input class="input" id="documento-referencia" name="numero_referencia" maxlength="100" placeholder="Ej. FAC-009842" required>
                </div>

                <div class="field">
                    <label class="label" for="documento-url">URL de referencia <span class="required">*</span></label>
                    <input class="input" id="documento-url" name="archivo_url" type="url" maxlength="500" placeholder="https://…" required>
                    <p class="help">Se vincula la dirección electrónica del documento de respaldo.</p>
                </div>
            </section>
            <?= modal_footer('Registrar', 'is-primary') ?>
        </form>
    </div>
</div>
