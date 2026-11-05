<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_permission('SOLICITUDES_CREAR');

try {
    $clientes = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_clientes @buscar=NULL,@solo_activos=1,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;');
    $ubicaciones = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_ubicaciones @buscar=NULL,@solo_activos=1,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;');
    $tipos = read_procedure_sets('EXEC dbo.sp_listar_tipos_mercancia @solo_activos=1;', [])[0] ?? [];
} catch (PDOException) {
    read_error_response('No se pudieron cargar los datos del formulario.');
}
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-solicitud">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card modal-card-wide">
        <?= modal_header('titulo-solicitud', 'Nueva solicitud') ?>
        <form hx-post="/solicitudes/guardar.php" hx-target="#mensaje-solicitud" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div id="mensaje-solicitud" role="alert"></div>

                <div class="columns">
                    <div class="column">
                        <div class="field">
                            <label class="label" for="solicitud-codigo">Código <span class="required">*</span></label>
                            <input class="input" id="solicitud-codigo" name="codigo" maxlength="30" placeholder="Ej. SOL-2026-0100" required autofocus>
                        </div>
                    </div>
                    <div class="column">
                        <div class="field">
                            <label class="label" for="solicitud-cliente">Cliente <span class="required">*</span></label>
                            <select id="solicitud-cliente" name="cliente_id" data-combobox data-remote-url="/clientes/lista.php" data-placeholder="Buscar cliente por nombre o identificación…" required>
                                <option value="">Selecciona o escribe para buscar…</option>
                                <?php foreach ($clientes as $cliente): ?>
                                    <option value="<?= (int)$cliente['cliente_id'] ?>" data-subtext="<?= e($cliente['correo'] ?? '') ?>"><?= e(trim((string)$cliente['nombre']) . ' · ' . $cliente['identificacion']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="columns">
                    <div class="column">
                        <div class="field">
                            <label class="label" for="solicitud-origen">Ubicación de origen <span class="required">*</span></label>
                            <select id="solicitud-origen" name="origen_id" data-combobox data-remote-url="/flota/lista.php?seccion=ubicaciones" data-placeholder="Buscar ubicación de origen…" required>
                                <option value="">Selecciona ubicación…</option>
                                <?php foreach ($ubicaciones as $row): ?>
                                    <option value="<?= (int)$row['ubicacion_id'] ?>" data-subtext="<?= e($row['direccion'] ?? '') ?>"><?= e($row['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="column">
                        <div class="field">
                            <label class="label" for="solicitud-destino">Ubicación de destino <span class="required">*</span></label>
                            <select id="solicitud-destino" name="destino_id" data-combobox data-remote-url="/flota/lista.php?seccion=ubicaciones" data-placeholder="Buscar ubicación de destino…" required>
                                <option value="">Selecciona ubicación…</option>
                                <?php foreach ($ubicaciones as $row): ?>
                                    <option value="<?= (int)$row['ubicacion_id'] ?>" data-subtext="<?= e($row['direccion'] ?? '') ?>"><?= e($row['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label class="label" for="solicitud-fecha">Fecha y hora de entrega requerida <span class="required">*</span></label>
                    <input class="input" id="solicitud-fecha" name="fecha_entrega" type="text" data-datepicker="datetime" min="<?= e(gmdate('Y-m-d\\TH:i', time() + 60)) ?>" placeholder="dd/mm/aaaa, hh:mm" required>
                    <p class="help">La prioridad operativa inicial se asigna automáticamente según el plazo requerido.</p>
                </div>

                <h3 class="title is-6 mt-4 mb-3">Destinatario esperado</h3>
                <div class="columns">
                    <div class="column">
                        <div class="field">
                            <label class="label" for="destinatario-nombre">Nombre <span class="required">*</span></label>
                            <input class="input" id="destinatario-nombre" name="destinatario_nombre" maxlength="150" required>
                        </div>
                    </div>
                    <div class="column">
                        <div class="field">
                            <label class="label" for="destinatario-apellido1">Primer apellido <span class="required">*</span></label>
                            <input class="input" id="destinatario-apellido1" name="destinatario_apellido1" maxlength="100" required>
                        </div>
                    </div>
                </div>
                <div class="columns">
                    <div class="column">
                        <div class="field">
                            <label class="label" for="destinatario-apellido2">Segundo apellido <span class="required">*</span></label>
                            <input class="input" id="destinatario-apellido2" name="destinatario_apellido2" maxlength="100" required>
                        </div>
                    </div>
                    <div class="column">
                        <div class="field">
                            <label class="label" for="destinatario-id">Identificación</label>
                            <input class="input" id="destinatario-id" name="destinatario_identificacion" maxlength="30">
                        </div>
                    </div>
                    <div class="column">
                        <div class="field">
                            <label class="label" for="destinatario-telefono">Teléfono</label>
                            <input class="input" id="destinatario-telefono" name="destinatario_telefono" maxlength="30">
                        </div>
                    </div>
                </div>

                <div class="panel-box-header mt-4 mb-2">
                    <h3 class="title is-6 mb-0">Mercancías declaradas</h3>
                    <button class="button is-small button-secondary" type="button" data-add-mercancia data-max-items="20">
                        <?= svg_icon('plus') ?>
                        <span>Agregar línea</span>
                    </button>
                </div>
                <div id="mercancias-lista" data-mercancias-lista>
                    <fieldset class="merchandise-row item-row" data-mercancia-row>
                        <legend class="is-sr-only">Mercancía 1</legend>
                        <div class="columns is-mobile">
                            <div class="column is-4">
                                <div class="field">
                                    <label class="label">Tipo de mercancía</label>
                                    <select name="mercancias[0][tipo_mercancia_id]" data-combobox data-placeholder="Buscar tipo…" required>
                                        <option value="">Selecciona</option>
                                        <?php foreach ($tipos as $tipo): ?>
                                            <option value="<?= (int)$tipo['tipo_mercancia_id'] ?>"><?= e($tipo['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="column">
                                <div class="field">
                                    <label class="label">Descripción</label>
                                    <input class="input" name="mercancias[0][descripcion]" maxlength="250" required>
                                </div>
                            </div>
                            <div class="column is-2">
                                <div class="field">
                                    <label class="label">Peso (kg)</label>
                                    <input class="input" name="mercancias[0][peso_kg]" inputmode="decimal" maxlength="13" required>
                                </div>
                            </div>
                            <div class="column is-2">
                                <div class="field">
                                    <label class="label">Vol. (m³)</label>
                                    <input class="input" name="mercancias[0][volumen_m3]" inputmode="decimal" maxlength="13">
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </div>
                <template id="mercancia-row-template">
                    <fieldset class="merchandise-row item-row" data-mercancia-row>
                        <legend class="is-sr-only">Mercancía</legend>
                        <div class="columns is-mobile">
                            <div class="column is-4">
                                <div class="field">
                                    <label class="label">Tipo de mercancía</label>
                                    <select name="mercancias[__index__][tipo_mercancia_id]" data-combobox data-placeholder="Buscar tipo…" required>
                                        <option value="">Selecciona</option>
                                        <?php foreach ($tipos as $tipo): ?>
                                            <option value="<?= (int)$tipo['tipo_mercancia_id'] ?>"><?= e($tipo['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="column">
                                <div class="field">
                                    <label class="label">Descripción</label>
                                    <input class="input" name="mercancias[__index__][descripcion]" maxlength="250" required>
                                </div>
                            </div>
                            <div class="column is-2">
                                <div class="field">
                                    <label class="label">Peso (kg)</label>
                                    <input class="input" name="mercancias[__index__][peso_kg]" inputmode="decimal" maxlength="13" required>
                                </div>
                            </div>
                            <div class="column is-2">
                                <div class="field">
                                    <label class="label">Vol. (m³)</label>
                                    <input class="input" name="mercancias[__index__][volumen_m3]" inputmode="decimal" maxlength="13">
                                </div>
                            </div>
                            <div class="column is-narrow">
                                <button class="icon-button" type="button" data-remove-mercancia aria-label="Quitar mercancía"><?= svg_icon('close') ?></button>
                            </div>
                        </div>
                    </fieldset>
                </template>
            </section>
            <?= modal_footer('Crear solicitud', 'is-primary') ?>
        </form>
    </div>
</div>
