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
      </section>
      <footer class="modal-card-foot"><div class="buttons"><button class="button is-primary" type="submit">Guardar</button><button class="button button-secondary" type="button" data-close-modal>Cancelar</button></div></footer>
    </form>
  </div>
</div>
