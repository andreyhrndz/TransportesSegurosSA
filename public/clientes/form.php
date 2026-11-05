<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_permission('CLIENTES_CREAR');
$tipo = ($_GET['tipo'] ?? '') === 'juridico' ? 'juridico' : 'fisico';
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-modal">
  <div class="modal-background" data-close-modal></div>
  <div class="modal-card">
    <header class="modal-card-head"><p class="modal-card-title" id="titulo-modal"><?= $tipo === 'fisico' ? 'Nuevo cliente' : 'Nueva empresa' ?></p><button class="modal-close icon-button" type="button" aria-label="Cerrar" data-close-modal><?= svg_icon('close') ?></button></header>
    <form hx-post="/clientes/guardar.php" hx-target="#mensaje-form" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
      <section class="modal-card-body">
        <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div id="mensaje-form" role="alert"></div>
        <div class="field">
          <label class="label" for="identificacion">Identificación <span class="required">*</span></label>
          <div class="field has-addons">
            <div class="control is-expanded">
              <input class="input" id="identificacion" name="identificacion" maxlength="30" placeholder="<?= $tipo === 'fisico' ? 'Ej. 1-0111-0222' : 'Ej. 3-101-000000' ?>" required autofocus>
            </div>
            <div class="control">
              <button class="button button-secondary" type="button" id="btn-consultar-hacienda-cli">
                <?= svg_icon('search') ?>
                <span>Consultar Hacienda</span>
              </button>
            </div>
          </div>
          <p class="help">Consulta la base oficial del Ministerio de Hacienda para autocompletar el nombre o razón social.</p>
          <div id="hacienda-cli-aviso" class="mt-2" hidden></div>
        </div>
        <?php if ($tipo === 'fisico'): ?>
          <div class="columns"><div class="column"><div class="field"><label class="label" for="nombre">Nombre <span class="required">*</span></label><input class="input" id="nombre" name="nombre" maxlength="100" required></div></div><div class="column"><div class="field"><label class="label" for="primer_apellido">Primer apellido <span class="required">*</span></label><input class="input" id="primer_apellido" name="primer_apellido" maxlength="100" required></div></div></div>
          <div class="field"><label class="label" for="segundo_apellido">Segundo apellido</label><input class="input" id="segundo_apellido" name="segundo_apellido" maxlength="100"></div>
          <div class="field"><label class="label" for="telefono">Teléfono</label><input class="input" id="telefono" name="telefono" type="tel" maxlength="30"></div>
        <?php else: ?>
          <div class="field"><label class="label" for="razon_social">Razón social <span class="required">*</span></label><input class="input" id="razon_social" name="razon_social" maxlength="150" required></div>
          <div class="field"><label class="label" for="nombre_comercial">Nombre comercial</label><input class="input" id="nombre_comercial" name="nombre_comercial" maxlength="150"></div>
          <div class="field"><label class="label" for="representante_legal">Representante legal</label><input class="input" id="representante_legal" name="representante_legal" maxlength="150"></div>
        <?php endif; ?>
        <div class="field"><label class="label" for="correo">Correo electrónico <span class="required">*</span></label><input class="input" id="correo" name="correo" type="email" maxlength="150" required></div>
        <p class="help">Los campos marcados con * son obligatorios.</p>
        <script>
        (function() {
            const inputId = document.getElementById('identificacion');
            const btnHacienda = document.getElementById('btn-consultar-hacienda-cli');
            const avisoHacienda = document.getElementById('hacienda-cli-aviso');
            const inputNom = document.getElementById('nombre');
            const inputAp1 = document.getElementById('primer_apellido');
            const inputAp2 = document.getElementById('segundo_apellido');
            const inputRazon = document.getElementById('razon_social');
            const inputComercial = document.getElementById('nombre_comercial');

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
                            if (inputRazon) {
                                inputRazon.value = res.nombre;
                                if (inputComercial && !inputComercial.value) inputComercial.value = res.nombre;
                            } else if (inputNom) {
                                const partes = res.nombre.split(/\s+/).filter(Boolean);
                                if (partes.length >= 3) {
                                    inputAp1.value = partes[0];
                                    inputAp2.value = partes[1];
                                    inputNom.value = partes.slice(2).join(' ');
                                } else if (partes.length === 2) {
                                    inputAp1.value = partes[0];
                                    inputNom.value = partes[1];
                                } else {
                                    inputNom.value = res.nombre;
                                }
                            }
                        } else if (res.status === 'no_encontrado') {
                            setAviso('warning', 'Identificación no encontrada en Hacienda. Completa los datos manualmente.');
                        } else if (res.status === 'limite_excedido') {
                            setAviso('warning', 'Límite de consultas excedido. Completa los datos manualmente.');
                        } else {
                            setAviso('warning', res.mensaje || 'Servicio no disponible. Completa manualmente.');
                        }
                    })
                    .catch(() => {
                        btnHacienda.disabled = false;
                        setAviso('warning', 'No fue posible conectar con Hacienda. Completa manualmente.');
                    });
            });
        })();
        </script>
      </section>
      <footer class="modal-card-foot"><div class="buttons"><button class="button is-primary" type="submit">Guardar</button><button class="button button-secondary" type="button" data-close-modal>Cancelar</button></div></footer>
    </form>
  </div>
</div>
