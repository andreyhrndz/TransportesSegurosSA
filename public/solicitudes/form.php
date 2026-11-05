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
                            <label class="label">Código de solicitud</label>
                            <input class="input is-static has-text-subtle" type="text" value="Código asignado al guardar (automático)" readonly tabindex="-1">
                        </div>
                    </div>
                    <div class="column">
                        <div class="field">
                            <label class="label" for="solicitud-cliente">Cliente <span class="required">*</span></label>
                            <select id="solicitud-cliente" name="cliente_id" data-combobox data-remote-url="/clientes/lista.php" data-placeholder="Buscar cliente por nombre o identificación…" required>
                                <option value="">Selecciona o escribe para buscar…</option>
                                <?php foreach ($clientes as $cliente): ?>
                                    <option value="<?= (int)$cliente['cliente_id'] ?>" data-tipo="<?= e($cliente['tipo_cliente'] ?? 'FISICA') ?>" data-nombre="<?= e($cliente['nombre'] ?? '') ?>" data-identificacion="<?= e($cliente['identificacion'] ?? '') ?>" data-telefono="<?= e($cliente['telefono'] ?? '') ?>" data-subtext="<?= e($cliente['correo'] ?? '') ?>"><?= e(trim((string)$cliente['nombre']) . ' · ' . $cliente['identificacion']) ?></option>
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

                <div class="panel-box-header mt-4 mb-2">
                    <h3 class="title is-6 mb-0">Destinatario previsto de la orden</h3>
                </div>

                <div class="field mb-3">
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
                </div>

                <div id="seccion-destinatario" class="p-3 mb-3" style="background: var(--color-surface-subtle); border: 1px solid var(--color-border); border-radius: var(--radius-md);">
                    <div class="field mb-3" id="wrap-destinatario-id">
                        <label class="label" for="destinatario-id">Identificación <span class="required" id="dest-id-req">*</span></label>
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input" id="destinatario-id" name="destinatario_identificacion" maxlength="30" placeholder="Ej. 1-0111-0222">
                            </div>
                            <div class="control">
                                <button class="button button-secondary" type="button" id="btn-consultar-hacienda-dest">
                                    <?= svg_icon('search') ?>
                                    <span>Consultar Hacienda</span>
                                </button>
                            </div>
                        </div>
                        <p class="help" id="dest-id-help">Consulta la base del Ministerio de Hacienda para autocompletar el nombre.</p>
                        <div id="hacienda-dest-aviso" class="mt-2" hidden></div>
                    </div>

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
                        <div class="column">
                            <div class="field">
                                <label class="label" for="destinatario-apellido2">Segundo apellido <span class="required">*</span></label>
                                <input class="input" id="destinatario-apellido2" name="destinatario_apellido2" maxlength="100" required>
                            </div>
                        </div>
                    </div>

                    <div class="columns">
                        <div class="column is-half">
                            <div class="field">
                                <label class="label" for="destinatario-telefono">Teléfono de contacto</label>
                                <input class="input" id="destinatario-telefono" name="destinatario_telefono" maxlength="30" placeholder="Ej. 8888-8888">
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                (function() {
                    const selectCliente = document.getElementById('solicitud-cliente');
                    const radioMismo = document.getElementById('modo-mismo-cliente');
                    const radioTercero = document.getElementById('modo-tercero');
                    const labelMismo = document.getElementById('label-mismo-cliente');
                    const inputId = document.getElementById('destinatario-id');
                    const inputNom = document.getElementById('destinatario-nombre');
                    const inputAp1 = document.getElementById('destinatario-apellido1');
                    const inputAp2 = document.getElementById('destinatario-apellido2');
                    const inputTel = document.getElementById('destinatario-telefono');
                    const btnHacienda = document.getElementById('btn-consultar-hacienda-dest');
                    const avisoHacienda = document.getElementById('hacienda-dest-aviso');

                    let clienteActual = null;

                    function setAviso(tipo, texto) {
                        if (!avisoHacienda) return;
                        if (!texto) {
                            avisoHacienda.hidden = true;
                            avisoHacienda.innerHTML = '';
                            return;
                        }
                        avisoHacienda.hidden = false;
                        const alertClass = tipo === 'success' ? 'notification-success' : (tipo === 'warning' ? 'notification-warning' : 'notification-info');
                        avisoHacienda.className = 'notification ' + alertClass + ' p-2 mt-2';
                        avisoHacienda.textContent = texto;
                    }

                    function rellenarDesdeCliente() {
                        if (!clienteActual) return;
                        if (clienteActual.tipo_cliente === 'JURIDICA') {
                            // Empresa no recibe personalmente: exigir persona de contacto
                            if (labelMismo) labelMismo.style.display = 'none';
                            radioTercero.checked = true;
                            document.getElementById('texto-modo-tercero').textContent = 'Persona física autorizada por la empresa';
                            inputNom.value = '';
                            inputAp1.value = '';
                            inputAp2.value = '';
                            inputId.value = '';
                            inputTel.value = clienteActual.telefono || '';
                            [inputNom, inputAp1, inputAp2, inputId, inputTel].forEach(el => el.readOnly = false);
                            if (btnHacienda) btnHacienda.disabled = false;
                            setAviso('info', 'Para empresas jurídicas, registra la persona física autorizada para recibir.');
                        } else {
                            if (labelMismo) labelMismo.style.display = 'inline-flex';
                            document.getElementById('texto-modo-tercero').textContent = 'Otra persona autorizada';
                            if (radioMismo.checked) {
                                inputId.value = clienteActual.identificacion || '';
                                inputTel.value = clienteActual.telefono || '';
                                inputNom.value = clienteActual.nombre || '';
                                inputAp1.value = clienteActual.primer_apellido || 'Cliente';
                                inputAp2.value = clienteActual.segundo_apellido || 'Titular';
                                [inputNom, inputAp1, inputAp2, inputId, inputTel].forEach(el => el.readOnly = true);
                                if (btnHacienda) btnHacienda.disabled = true;
                                setAviso('', '');
                            } else {
                                [inputNom, inputAp1, inputAp2, inputId, inputTel].forEach(el => el.readOnly = false);
                                if (btnHacienda) btnHacienda.disabled = false;
                                setAviso('', '');
                            }
                        }
                    }

                    function cargarCliente(clienteId) {
                        if (!clienteId) {
                            clienteActual = null;
                            return;
                        }
                        fetch('/clientes/info.php?cliente_id=' + encodeURIComponent(clienteId))
                            .then(res => res.ok ? res.json() : null)
                            .then(data => {
                                if (data) {
                                    clienteActual = data;
                                    rellenarDesdeCliente();
                                }
                            })
                            .catch(() => {});
                    }

                    selectCliente?.addEventListener('change', function() {
                        cargarCliente(this.value);
                    });

                    radioMismo?.addEventListener('change', rellenarDesdeCliente);
                    radioTercero?.addEventListener('change', function() {
                        [inputNom, inputAp1, inputAp2, inputId, inputTel].forEach(el => el.readOnly = false);
                        if (btnHacienda) btnHacienda.disabled = false;
                        setAviso('', '');
                    });

                    btnHacienda?.addEventListener('click', function() {
                        const idVal = inputId.value.trim();
                        if (!idVal) {
                            setAviso('warning', 'Ingresa una identificación antes de consultar.');
                            return;
                        }
                        setAviso('info', 'Consultando Ministerio de Hacienda…');
                        btnHacienda.disabled = true;

                        fetch('/api/hacienda.php?identificacion=' + encodeURIComponent(idVal))
                            .then(res => res.json())
                            .then(res => {
                                btnHacienda.disabled = false;
                                if (res.status === 'encontrado' && res.nombre) {
                                    setAviso('success', 'Registro oficial encontrado: ' + res.nombre);
                                    // Separación prudente de nombres/apellidos si vienen en una sola cadena
                                    const partes = res.nombre.split(/\s+/).filter(Boolean);
                                    if (partes.length >= 3) {
                                        inputAp1.value = partes[0];
                                        inputAp2.value = partes[1];
                                        inputNom.value = partes.slice(2).join(' ');
                                    } else if (partes.length === 2) {
                                        inputAp1.value = partes[0];
                                        inputAp2.value = '-';
                                        inputNom.value = partes[1];
                                    } else {
                                        inputNom.value = res.nombre;
                                        if (!inputAp1.value) inputAp1.value = '-';
                                        if (!inputAp2.value) inputAp2.value = '-';
                                    }
                                } else if (res.status === 'no_encontrado') {
                                    setAviso('warning', 'Identificación no registrada en Hacienda. Digita los datos manualmente.');
                                } else if (res.status === 'limite_excedido') {
                                    setAviso('warning', 'Límite de consultas excedido. Digita los datos manualmente.');
                                } else {
                                    setAviso('warning', res.mensaje || 'Servicio no disponible. Continúa manualmente.');
                                }
                            })
                            .catch(() => {
                                btnHacienda.disabled = false;
                                setAviso('warning', 'No fue posible conectar con Hacienda. Digita los datos manualmente.');
                            });
                    });
                })();
                </script>

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
