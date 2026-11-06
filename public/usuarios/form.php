<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/read_queries.php';

require_permission('USUARIOS_GESTIONAR_CUENTAS');
header('Cache-Control: no-store');

$idParam = $_GET['id'] ?? null;
$isEdit = $idParam !== null && $idParam !== '';
$user = null;

if ($isEdit) {
    require_permission('USUARIOS_VER');
    $id = filter_var($idParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        http_response_code(404);
        exit(ui_notice('Usuario no encontrado.', 'danger'));
    }
    try {
        $sets = read_procedure_sets('EXEC dbo.sp_obtener_usuario @usuario_id=?;', [$id]);
        $user = $sets[0][0] ?? null;
        if (!is_array($user)) {
            http_response_code(404);
            exit(ui_notice('Usuario no encontrado.', 'danger'));
        }
    } catch (PDOException $exception) {
        if (read_is_missing_record($exception)) {
            http_response_code(404);
            exit(ui_notice('Usuario no encontrado.', 'danger'));
        }
        read_error_response('No se pudo cargar la cuenta de usuario.');
    }
}

$canManagePermissions = has_permission('USUARIOS_GESTIONAR_PERMISOS');
$permisosCatalogo = [];
$catalogByCode = [];

if (!$isEdit && $canManagePermissions) {
    try {
        $permisosCatalogo = read_procedure_sets('EXEC dbo.sp_listar_permisos @solo_activos=1;', [])[0] ?? [];
        foreach ($permisosCatalogo as $p) {
            $catalogByCode[$p['codigo']] = $p;
        }
    } catch (PDOException) {
        $permisosCatalogo = [];
    }
}

// Grupos funcionales para visualización ordenada en la interfaz
$gruposPermisos = [
    'Clientes' => ['CLIENTES_VER', 'CLIENTES_CREAR', 'CLIENTES_EDITAR', 'CLIENTES_DESACTIVAR'],
    'Solicitudes' => ['SOLICITUDES_VER', 'SOLICITUDES_CREAR', 'SOLICITUDES_AJUSTAR_PRIORIDAD', 'SOLICITUDES_CANCELAR'],
    'Viajes' => ['VIAJES_VER', 'VIAJES_CREAR', 'VIAJES_PARADAS', 'VIAJES_ASIGNAR', 'VIAJES_INICIAR', 'VIAJES_FINALIZAR', 'VIAJES_CANCELAR'],
    'Entregas e Incidencias' => ['ENTREGAS_VER', 'ENTREGAS_REGISTRAR', 'INCIDENCIAS_VER', 'INCIDENCIAS_REGISTRAR', 'INCIDENCIAS_RESOLVER', 'DOCUMENTOS_VER', 'DOCUMENTOS_REGISTRAR'],
    'Flota y Catálogos' => ['FLOTAS_VER', 'FLOTAS_GESTIONAR', 'RUTAS_VER', 'RUTAS_GESTIONAR', 'UBICACIONES_VER', 'UBICACIONES_GESTIONAR', 'CATALOGOS_VER', 'CATALOGOS_GESTIONAR'],
    'Reportes y Auditoría' => ['REPORTES_VER', 'AUDITORIA_VER', 'NOTIFICACIONES_VER'],
    'Administración' => ['USUARIOS_VER', 'USUARIOS_GESTIONAR_CUENTAS', 'USUARIOS_GESTIONAR_PERMISOS'],
];
?>
<div class="modal is-active" role="dialog" aria-modal="true" aria-labelledby="titulo-form-usuario">
    <div class="modal-background" data-close-modal></div>
    <div class="modal-card <?= !$isEdit ? 'modal-card-wide' : '' ?>">
        <?= modal_header('titulo-form-usuario', $isEdit ? 'Editar usuario' : 'Invitar usuario · Nuevo usuario') ?>
        <?php if ($isEdit): ?>
            <form hx-post="/usuarios/guardar.php" hx-target="#mensaje-form-usuario" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
                <section class="modal-card-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="usuario_id" value="<?= (int)$user['usuario_id'] ?>">
                    <div id="mensaje-form-usuario" role="alert"></div>

                    <div class="field">
                        <label class="label" for="usr-nombre">Nombre de usuario</label>
                        <input class="input" id="usr-nombre" name="nombre_usuario" maxlength="80" autocomplete="off" value="<?= e($user['nombre_usuario'] ?? '') ?>" readonly>
                        <p class="help">El identificador de cuenta no puede modificarse tras el alta.</p>
                    </div>

                    <div class="field">
                        <label class="label" for="usr-correo">Correo de acceso <span class="required">*</span></label>
                        <input class="input" id="usr-correo" name="correo_acceso" type="email" maxlength="150" autocomplete="off" value="<?= e($user['correo_acceso'] ?? '') ?>" required autofocus>
                    </div>
                </section>
                <?= modal_footer('Guardar cambios', 'is-primary') ?>
            </form>
        <?php else: ?>
            <form hx-post="/usuarios/invitar.php" hx-target="#mensaje-form-usuario" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
                <section class="modal-card-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div id="mensaje-form-usuario" role="alert"></div>

                    <div class="field mb-4">
                        <label class="label" for="usr-correo-invitacion">Correo electrónico del colaborador <span class="required">*</span></label>
                        <div class="control input-with-icon">
                            <?= svg_icon('envelope', '', 'input-icon') ?>
                            <input class="input" id="usr-correo-invitacion" name="correo" type="email" maxlength="150" autocomplete="off" placeholder="colaborador@transportesseguros.cr" required autofocus>
                        </div>
                        <p class="help">El colaborador recibirá un enlace seguro de uso único para completar sus datos personales y establecer su contraseña.</p>
                    </div>

                    <?php if ($canManagePermissions): ?>
                        <div class="box p-3 mb-3" style="background: var(--color-surface-subtle); border: 1px solid var(--color-border); border-radius: var(--radius-md);">
                            <div class="is-flex is-justify-content-space-between is-align-items-center mb-2">
                                <label class="label mb-0" style="font-size: 0.95rem;">Permisos individuales a conceder tras activación</label>
                                <span id="contador-permisos-invitacion" class="tag is-info is-light">0 permisos seleccionados</span>
                            </div>
                            <p class="help mb-3">Selecciona los permisos específicos que se asignarán de forma automática a la cuenta una vez que el colaborador complete el onboarding.</p>

                            <div class="field mb-3">
                                <div class="control input-with-icon">
                                    <?= svg_icon('search', '', 'input-icon') ?>
                                    <input class="input is-small" id="filtro-permisos-invitacion" type="search" placeholder="Buscar permiso por nombre o código…" autocomplete="off">
                                </div>
                            </div>

                            <div id="aviso-cero-permisos" class="notification notification-warning py-2 px-3 mb-3 is-size-7">
                                <label class="checkbox is-flex is-align-items-center">
                                    <input type="checkbox" id="confirmar-sin-permisos" name="confirmar_sin_permisos" value="1" class="mr-2">
                                    <span><strong>Invitar con cero permisos:</strong> El colaborador activará su cuenta, pero no tendrá acceso a ninguna función operativa hasta que un administrador le asigne permisos.</span>
                                </label>
                            </div>

                            <div id="lista-permisos-contenedor" style="max-height: 250px; overflow-y: auto; padding-right: 0.25rem;">
                                <?php foreach ($gruposPermisos as $grupoNom => $codigosGrupo): ?>
                                    <div class="mb-3 grupo-permisos-seccion" data-grupo="<?= e($grupoNom) ?>">
                                        <div class="has-text-weight-bold is-size-7 has-text-info mb-1 is-uppercase" style="letter-spacing: 0.05em; border-bottom: 1px solid var(--color-border); padding-bottom: 0.2rem;"><?= e($grupoNom) ?></div>
                                        <?php foreach ($codigosGrupo as $codigo): ?>
                                            <?php
                                            $item = $catalogByCode[$codigo] ?? null;
                                            if (!$item) continue;
                                            $actorHasPerm = has_permission($codigo);
                                            ?>
                                            <div class="item-permiso-fila py-1" data-codigo="<?= e($codigo) ?>" data-nombre="<?= e(strtolower($item['nombre'])) ?>">
                                                <label class="checkbox is-flex is-align-items-start <?= !$actorHasPerm ? 'has-text-grey' : '' ?>" style="font-size: 0.85rem; cursor: <?= $actorHasPerm ? 'pointer' : 'not-allowed' ?>;">
                                                    <input type="checkbox" name="permisos[]" value="<?= e($codigo) ?>" class="chk-permiso mr-2 mt-1" <?= !$actorHasPerm ? 'disabled title="Tu cuenta no posee este permiso para poder otorgarlo"' : '' ?>>
                                                    <div>
                                                        <strong><?= e($item['nombre']) ?></strong>
                                                        <span class="has-text-grey is-size-7 ml-1">(<?= e($codigo) ?>)</span>
                                                        <?php if (!$actorHasPerm): ?>
                                                            <span class="tag is-warning is-light is-small ml-1" style="font-size: 0.7rem;">No disponible en tu perfil</span>
                                                        <?php endif; ?>
                                                        <span class="is-block is-size-7 has-text-grey-light"><?= e($item['descripcion']) ?></span>
                                                    </div>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="notification notification-info is-light py-2 px-3 mb-3 is-size-7">
                            <p>Tu cuenta cuenta con <code>USUARIOS_GESTIONAR_CUENTAS</code> pero no con <code>USUARIOS_GESTIONAR_PERMISOS</code>. La invitación se creará con cero permisos predeterminados al completar el onboarding.</p>
                        </div>
                    <?php endif; ?>
                </section>
                <?= modal_footer('Enviar invitación', 'is-primary') ?>
            </form>
        <?php endif; ?>
    </div>
</div>
