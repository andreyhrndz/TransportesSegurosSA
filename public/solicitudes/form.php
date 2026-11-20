<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_permission('SOLICITUDES_CREAR');

$canReadLocations = has_permission('UBICACIONES_VER');
$canSaveFrequentLocation = has_permission('UBICACIONES_GESTIONAR');

try {
    $clientes = operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_clientes @buscar=NULL,@solo_activos=1,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;');
    $ubicaciones = $canReadLocations
        ? operation_list('DECLARE @total_registros INT; EXEC dbo.sp_listar_ubicaciones @buscar=NULL,@solo_activos=1,@pagina=1,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;')
        : [];
    $tipos = read_procedure_sets('EXEC dbo.sp_listar_tipos_mercancia @solo_activos=1;', [])[0] ?? [];
} catch (PDOException) {
    read_error_response('No se pudieron cargar los datos del formulario.');
}
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-solicitud">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card modal-card-wide">
        <?= modal_header('titulo-solicitud', 'Nueva solicitud') ?>
        <form hx-post="/solicitudes/guardar.php" hx-target="#mensaje-solicitud" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]" data-solicitud-form="1">
            <section class="modal-card-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div id="mensaje-solicitud" role="alert"></div>

                <div class="field mb-4">
                    <label class="label" for="solicitud-cliente">Cliente <span class="required">*</span></label>
                    <select id="solicitud-cliente" name="cliente_id" data-combobox data-remote-url="/clientes/lista.php" data-placeholder="Buscar cliente por nombre o identificación…" required>
                        <option value="">Selecciona o escribe para buscar…</option>
                        <?php foreach ($clientes as $cliente): ?>
                            <option value="<?= (int)$cliente['cliente_id'] ?>" data-tipo="<?= e($cliente['tipo_cliente'] ?? 'FISICA') ?>" data-nombre="<?= e($cliente['nombre'] ?? '') ?>" data-identificacion="<?= e($cliente['identificacion'] ?? '') ?>" data-telefono="<?= e($cliente['telefono'] ?? '') ?>" data-subtext="<?= e($cliente['correo'] ?? '') ?>"><?= e(trim((string)$cliente['nombre']) . ' · ' . $cliente['identificacion']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="hybrid-locations-section mb-4" data-hybrid-request-locations="1" data-can-save-frequent="<?= $canSaveFrequentLocation ? '1' : '0' ?>">
                    <div class="columns hybrid-locations-grid mb-0">
                        <?php
                        $slots = [
                            'origen' => [
                                'select_id' => 'solicitud-origen',
                                'label' => 'Ubicación de origen',
                                'placeholder' => 'Buscar ubicación guardada…',
                                'summary_prefix' => 'Punto de recogida',
                            ],
                            'destino' => [
                                'select_id' => 'solicitud-destino',
                                'label' => 'Ubicación de destino',
                                'placeholder' => 'Buscar ubicación guardada…',
                                'summary_prefix' => 'Punto de entrega',
                            ],
                        ];
                        ?>
                        <?php foreach ($slots as $slotKey => $slotCfg): ?>
                            <div class="column">
                                <div class="hybrid-location-card" data-location-slot="<?= e($slotKey) ?>" data-summary-prefix="<?= e($slotCfg['summary_prefix']) ?>">
                                    <div class="hybrid-slot-header">
                                        <div class="hybrid-slot-title-group">
                                            <label class="label mb-0" for="<?= e($slotCfg['select_id']) ?>"><?= e($slotCfg['label']) ?> <span class="required">*</span></label>
                                            <span class="hybrid-mode-badge" data-slot-mode-badge>Guardada</span>
                                        </div>
                                        <button class="button is-small button-secondary" type="button" data-open-slot-map="<?= e($slotKey) ?>">
                                            <?= svg_icon('map-pin') ?>
                                            <span>Elegir en el mapa</span>
                                        </button>
                                    </div>

                                    <div class="field mb-0" data-slot-saved-box>
                                        <select id="<?= e($slotCfg['select_id']) ?>" name="<?= e($slotKey) ?>_id" data-combobox <?= $canReadLocations ? 'data-remote-url="/flota/lista.php?seccion=ubicaciones"' : '' ?> data-placeholder="<?= e($slotCfg['placeholder']) ?>" required>
                                            <option value="">Buscar ubicación guardada…</option>
                                            <?php foreach ($ubicaciones as $row): ?>
                                                <option value="<?= (int)$row['ubicacion_id'] ?>" data-subtext="<?= e($row['direccion'] ?? '') ?>"><?= e($row['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="hybrid-point-summary" data-slot-map-summary hidden>
                                        <div class="hybrid-summary-main">
                                            <strong class="hybrid-summary-title" data-slot-summary-title></strong>
                                            <p class="hybrid-summary-ref" data-slot-summary-ref></p>
                                            <span class="hybrid-summary-meta" data-slot-summary-meta></span>
                                        </div>
                                        <div class="hybrid-summary-actions">
                                            <button class="button is-small button-secondary" type="button" data-open-slot-map="<?= e($slotKey) ?>">
                                                <?= svg_icon('edit') ?>
                                                <span>Cambiar</span>
                                            </button>
                                            <button class="button is-small button-secondary" type="button" data-clear-slot-map="<?= e($slotKey) ?>">
                                                <?= svg_icon('close') ?>
                                                <span>Quitar</span>
                                            </button>
                                        </div>
                                    </div>

                                    <input type="hidden" name="<?= e($slotKey) ?>_modo" value="guardada" data-slot-input="modo">
                                    <input type="hidden" name="<?= e($slotKey) ?>_nombre" value="" data-slot-input="nombre" disabled>
                                    <input type="hidden" name="<?= e($slotKey) ?>_direccion" value="" data-slot-input="direccion" disabled>
                                    <input type="hidden" name="<?= e($slotKey) ?>_latitud" value="" data-slot-input="latitud" disabled>
                                    <input type="hidden" name="<?= e($slotKey) ?>_longitud" value="" data-slot-input="longitud" disabled>
                                    <input type="hidden" name="<?= e($slotKey) ?>_guardar_frecuente" value="0" data-slot-input="guardar_frecuente" disabled>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <section class="hybrid-map-drawer mt-3" id="solicitud-mapa-panel" aria-labelledby="solicitud-mapa-titulo" hidden>
                        <div class="hybrid-map-drawer-head">
                            <div class="hybrid-map-drawer-title">
                                <?= svg_icon('map-pin') ?>
                                <h3 class="title is-6 mb-0" id="solicitud-mapa-titulo">Marcar punto en el mapa</h3>
                            </div>
                            <div class="location-map-actions">
                                <button class="button is-small button-secondary" type="button" data-req-map-reset title="Centrar vista en Costa Rica">
                                    <?= svg_icon('map-pin') ?>
                                    <span>Reencuadrar</span>
                                </button>
                                <button class="button is-small button-secondary" type="button" data-req-map-clear hidden>
                                    <?= svg_icon('close') ?>
                                    <span>Quitar marcador</span>
                                </button>
                                <button class="icon-button" type="button" data-close-slot-map aria-label="Cerrar selector de mapa">
                                    <?= svg_icon('close') ?>
                                </button>
                            </div>
                        </div>

                        <div class="location-map-shell hybrid-map-shell" id="solicitud-mapa-shell">
                            <div class="location-map-canvas hybrid-map-canvas" id="solicitud-mapa-canvas" tabindex="0" role="application" aria-label="Mapa interactivo para seleccionar origen o destino. Haz clic para colocar el marcador o arrástralo para ajustar."></div>
                        </div>

                        <div class="location-map-status mt-2" id="solicitud-mapa-estado" role="status" aria-live="polite">
                            <?= svg_icon('map-pin') ?>
                            <span>Haz clic en el mapa para marcar el punto exacto. Puedes arrastrar el marcador para ajustarlo.</span>
                        </div>

                        <div class="notification notification-warning mt-2" id="solicitud-mapa-fallback" role="status" hidden>
                            <?= svg_icon('alert-triangle') ?>
                            <div class="hybrid-fallback-body">
                                <span>No se pudo cargar el mapa interactivo en este momento. Puedes volver a elegir una ubicación guardada o ingresar coordenadas válidas en opciones avanzadas.</span>
                                <div class="mt-2">
                                    <button class="button is-small button-secondary" type="button" data-fallback-use-saved>
                                        <span>Usar ubicación guardada</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="columns mt-3 mb-0">
                            <div class="column is-7">
                                <div class="field mb-2">
                                    <label class="label" for="solicitud-mapa-referencia">Referencia humana del sitio <span class="required">*</span></label>
                                    <input class="input" id="solicitud-mapa-referencia" type="text" maxlength="250" placeholder="Ej. Bodega, portón norte, 100 m este de la plaza…">
                                    <p class="help">Describe señas o accesos útiles para el conductor; el mapa registra únicamente el punto geográfico.</p>
                                </div>
                            </div>
                            <div class="column is-5">
                                <div class="field mb-2">
                                    <label class="label" for="solicitud-mapa-nombre">Nombre o alias <span id="solicitud-mapa-nombre-req" class="required" hidden>*</span></label>
                                    <input class="input" id="solicitud-mapa-nombre" type="text" maxlength="150" placeholder="Ej. Bodega Portón Norte">
                                    <p class="help" id="solicitud-mapa-nombre-help">Opcional para puntos puntuales; obligatorio si guardas como frecuente.</p>
                                </div>
                            </div>
                        </div>

                        <div class="field mt-1 mb-2">
                            <?php if ($canSaveFrequentLocation): ?>
                                <label class="checkbox hybrid-optin-checkbox">
                                    <input type="checkbox" id="solicitud-mapa-frecuente" value="1">
                                    <span><strong>Guardar en ubicaciones frecuentes</strong></span>
                                </label>
                                <p class="help">Desactivado por defecto: el punto pertenecerá únicamente a esta solicitud y no se agregará al catálogo global.</p>
                            <?php else: ?>
                                <label class="checkbox hybrid-optin-checkbox is-disabled">
                                    <input type="checkbox" id="solicitud-mapa-frecuente" value="1" disabled>
                                    <span>Guardar en ubicaciones frecuentes</span>
                                </label>
                                <p class="help">Este punto se usará solo en esta solicitud. Guardar en el catálogo frecuente requiere el permiso de gestión de ubicaciones.</p>
                            <?php endif; ?>
                        </div>

                        <details class="advanced-coords-panel mt-2" id="solicitud-mapa-coords-avanzadas">
                            <summary>Opciones avanzadas: ingresar o ajustar coordenadas manualmente</summary>
                            <div class="advanced-coords-body">
                                <div class="columns mb-0">
                                    <div class="column">
                                        <div class="field mb-0">
                                            <label class="label" for="solicitud-mapa-lat">Latitud (-90 a 90)</label>
                                            <input class="input" id="solicitud-mapa-lat" inputmode="decimal" maxlength="12" placeholder="Ej. 9.934739">
                                        </div>
                                    </div>
                                    <div class="column">
                                        <div class="field mb-0">
                                            <label class="label" for="solicitud-mapa-lon">Longitud (-180 a 180)</label>
                                            <input class="input" id="solicitud-mapa-lon" inputmode="decimal" maxlength="13" placeholder="Ej. -84.087502">
                                        </div>
                                    </div>
                                </div>
                                <p class="help mt-1">Al escribir coordenadas válidas el marcador se actualiza automáticamente en el mapa.</p>
                            </div>
                        </details>

                        <div id="solicitud-mapa-error" class="notification notification-danger mt-3 mb-0" role="alert" hidden></div>

                        <div class="hybrid-map-drawer-foot mt-3">
                            <button class="button is-primary is-small" type="button" id="btn-confirmar-punto-mapa">
                                <?= svg_icon('activate') ?>
                                <span id="btn-confirmar-punto-mapa-texto">Usar este punto como origen</span>
                            </button>
                            <button class="button is-small button-secondary" type="button" data-close-slot-map>
                                <span>Cancelar</span>
                            </button>
                        </div>
                    </section>
                </div>

                <div class="field">
                    <label class="label" for="solicitud-fecha">Fecha y hora de entrega requerida <span class="required">*</span></label>
                    <input class="input" id="solicitud-fecha" name="fecha_entrega" type="text" data-datepicker="datetime" min="<?= e(gmdate('Y-m-d\\TH:i', time() + 60)) ?>" placeholder="dd/mm/aaaa, hh:mm" required>
                    <p class="help">La prioridad operativa inicial se asigna automáticamente según el plazo requerido.</p>
                </div>

                <div class="panel-box-header mt-4 mb-2">
                    <h3 class="title is-6 mb-0">Destinatario previsto de la entrega</h3>
                </div>

                <div class="field mb-3" id="wrap-modo-destinatario">
                    <label class="label">¿Quién recibirá la mercancía? <span class="required">*</span></label>
                    <div class="control" id="grupo-modo-destinatario">
                        <label class="radio mr-4" id="label-mismo-cliente">
                            <input type="radio" name="modo_destinatario" value="mismo_cliente" id="modo-mismo-cliente" checked>
                            <span id="texto-modo-mismo-cliente">El mismo cliente (recepción propia)</span>
                        </label>
                        <label class="radio" id="label-tercero">
                            <input type="radio" name="modo_destinatario" value="tercero" id="modo-tercero">
                            <span id="texto-modo-tercero">Otra persona autorizada</span>
                        </label>
                    </div>
                    <p class="help" id="ayuda-modo-destinatario">Por defecto recibe el cliente registrado, o puedes indicar una persona autorizada.</p>
                </div>

                <div id="seccion-destinatario" class="p-3 mb-3" style="display: none; background: var(--color-surface-subtle); border: 1px solid var(--color-border); border-radius: var(--radius-md);">
                    <div class="field mb-3" id="wrap-destinatario-id">
                        <label class="label" for="destinatario-id">Identificación del receptor</label>
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input" id="destinatario-id" name="destinatario_identificacion" maxlength="30" placeholder="Ej. 1-0111-0222" disabled>
                            </div>
                            <div class="control">
                                <button class="button button-secondary" type="button" id="btn-consultar-hacienda-dest" disabled>
                                    <?= svg_icon('search') ?>
                                    <span>Consultar Hacienda</span>
                                </button>
                            </div>
                        </div>
                        <p class="help" id="dest-id-help">Opcional. Consulta la base del Ministerio de Hacienda para autocompletar el nombre de la persona autorizada.</p>
                        <div id="hacienda-dest-aviso" class="mt-2" hidden></div>
                    </div>

                    <div class="columns">
                        <div class="column">
                            <div class="field">
                                <label class="label" for="destinatario-nombre">Nombre(s) <span class="required">*</span></label>
                                <input class="input" id="destinatario-nombre" name="destinatario_nombre" maxlength="150" disabled>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="destinatario-apellido1">Primer apellido <span class="required">*</span></label>
                                <input class="input" id="destinatario-apellido1" name="destinatario_apellido1" maxlength="100" disabled>
                            </div>
                        </div>
                        <div class="column">
                            <div class="field">
                                <label class="label" for="destinatario-apellido2">Segundo apellido</label>
                                <input class="input" id="destinatario-apellido2" name="destinatario_apellido2" maxlength="100" disabled>
                            </div>
                        </div>
                    </div>

                    <div class="columns">
                        <div class="column is-half">
                            <div class="field">
                                <label class="label" for="destinatario-telefono">Teléfono de contacto</label>
                                <input class="input" id="destinatario-telefono" name="destinatario_telefono" maxlength="30" placeholder="Ej. 8888-8888" disabled>
                            </div>
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
