<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/document_storage.php';
require_permission('DOCUMENTOS_REGISTRAR');

$preselectedSolicitudId = filter_var($_GET['solicitud_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$preselectedSolicitudId = $preselectedSolicitudId !== false ? (int)$preselectedSolicitudId : 0;

try {
    $solicitudes = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @buscar=NULL,@estado=NULL,@cliente_id=NULL,@desde=NULL,@hasta=NULL,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;');
    $types = read_procedure_sets('EXEC dbo.sp_listar_tipos_documento @solo_activos=1;', [])[0] ?? [];
} catch (PDOException) {
    read_error_response('No se pudieron cargar los datos del formulario.');
}

$maxBytes = document_effective_max_bytes();
$maxLabel = document_format_bytes($maxBytes);
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-documento">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card">
        <?= modal_header('titulo-documento', 'Registrar documento') ?>
        <form
            hx-post="/entregas/documento.php"
            hx-encoding="multipart/form-data"
            enctype="multipart/form-data"
            hx-target="#mensaje-documento"
            hx-swap="innerHTML"
            hx-disabled-elt="find button[type=submit]"
            data-doc-upload-form
            data-max-bytes="<?= $maxBytes ?>"
            data-max-label="<?= e($maxLabel) ?>"
        >
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div id="mensaje-documento" role="alert"></div>

                <div class="field">
                    <label class="label" for="documento-solicitud">Solicitud <span class="required">*</span></label>
                    <select id="documento-solicitud" name="solicitud_id" data-combobox <?= has_permission('SOLICITUDES_VER') ? 'data-remote-url="/solicitudes/lista.php"' : '' ?> data-placeholder="Buscar solicitud por código o cliente…" required>
                        <option value="">Selecciona solicitud…</option>
                        <?php foreach ($solicitudes as $row): ?>
                            <?php $sid = (int)$row['solicitud_id']; ?>
                            <option value="<?= $sid ?>" data-subtext="<?= e(($row['cliente_nombre'] ?? '') . ' · ' . humanize_status($row['estado'])) ?>" <?= $sid === $preselectedSolicitudId ? 'selected' : '' ?>>
                                <?= e($row['codigo_solicitud']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="columns">
                    <div class="column">
                        <div class="field">
                            <label class="label" for="documento-tipo">Tipo de documento <span class="required">*</span></label>
                            <select id="documento-tipo" name="tipo_documento_id" data-combobox data-placeholder="Buscar tipo de documento…" required>
                                <option value="">Selecciona tipo…</option>
                                <?php foreach ($types as $row): ?>
                                    <option value="<?= (int)$row['tipo_documento_id'] ?>" data-subtext="<?= e($row['descripcion'] ?? '') ?>"><?= e($row['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="column">
                        <div class="field">
                            <label class="label" for="documento-referencia">Número de referencia <span class="required">*</span></label>
                            <input class="input" id="documento-referencia" name="numero_referencia" maxlength="100" placeholder="Ej. FAC-009842" required>
                        </div>
                    </div>
                </div>

                <fieldset class="field doc-mode-fieldset">
                    <legend class="label">Origen del respaldo <span class="required">*</span></legend>
                    <div class="doc-mode-switch" role="radiogroup" aria-label="Tipo de respaldo documental">
                        <label class="doc-mode-option is-active" data-doc-mode-label="archivo">
                            <input type="radio" name="modo_respaldo" value="archivo" checked>
                            <?= svg_icon('upload') ?>
                            <span>Subir archivo local</span>
                        </label>
                        <label class="doc-mode-option" data-doc-mode-label="url">
                            <input type="radio" name="modo_respaldo" value="url">
                            <?= svg_icon('link') ?>
                            <span>Vincular URL externa</span>
                        </label>
                    </div>
                </fieldset>

                <div id="documento-panel-archivo" class="field" data-doc-panel="archivo">
                    <label class="label" for="documento-archivo">Archivo de respaldo <span class="required">*</span></label>
                    <div class="doc-dropzone" data-doc-dropzone tabindex="0" role="group" aria-describedby="documento-archivo-ayuda documento-archivo-estado">
                        <input
                            class="doc-file-input"
                            id="documento-archivo"
                            name="archivo"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                            required
                        >
                        <div class="doc-dropzone-icon" aria-hidden="true"><?= svg_icon('upload') ?></div>
                        <div class="doc-dropzone-copy">
                            <strong>Arrastra un PDF, JPG o PNG aquí</strong>
                            <span id="documento-archivo-ayuda">o usa el botón para elegir desde tu equipo · Máximo <?= e($maxLabel) ?> · Un archivo por documento.</span>
                        </div>
                        <button class="button is-small button-secondary" type="button" data-doc-browse>
                            <?= svg_icon('paperclip') ?>
                            <span>Seleccionar archivo</span>
                        </button>
                    </div>

                    <div class="doc-upload-status" id="documento-archivo-estado" role="status" aria-live="polite" data-doc-status>
                        Sin archivo seleccionado
                    </div>

                    <div class="doc-file-card" data-doc-preview hidden>
                        <div class="doc-file-card-info">
                            <?= svg_icon('file-text') ?>
                            <div class="cell-stack">
                                <strong data-doc-preview-name></strong>
                                <span data-doc-preview-meta></span>
                            </div>
                        </div>
                        <button class="button is-small button-secondary" type="button" data-doc-clear>
                            <?= svg_icon('close') ?>
                            <span>Quitar</span>
                        </button>
                    </div>
                </div>

                <div id="documento-panel-url" class="field" data-doc-panel="url" hidden>
                    <label class="label" for="documento-url">URL de referencia <span class="required">*</span></label>
                    <input class="input" id="documento-url" name="archivo_url" type="url" maxlength="500" placeholder="https://…" disabled>
                    <p class="help">Solo enlaces HTTP o HTTPS sin credenciales. El servidor guarda el enlace sin descargar contenido remoto.</p>
                </div>
            </section>
            <?= modal_footer('Registrar', 'is-primary') ?>
        </form>
    </div>
</div>
