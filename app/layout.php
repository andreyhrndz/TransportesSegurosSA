<?php
declare(strict_types=1);

function layout_start(string $activo, string $titulo, string $descripcion): void
{
    $usuario = require_login();
    $puedeClientes = has_permission('CLIENTES_VER');
    $puedeReportes = has_permission('REPORTES_VER');
    $puedeAuditoria = has_permission('AUDITORIA_VER');
    $puedeSolicitudes = has_permission('SOLICITUDES_VER');
    $puedeViajes = has_permission('VIAJES_VER');
    $puedeEntregas = has_any_permission(['ENTREGAS_VER', 'INCIDENCIAS_VER', 'DOCUMENTOS_VER', 'NOTIFICACIONES_VER']);
    $puedeFlota = has_any_permission(['FLOTAS_VER', 'RUTAS_VER', 'UBICACIONES_VER', 'CATALOGOS_VER']);
    $puedeUsuarios = has_permission('USUARIOS_VER');

    $grupoSeccion = match ($activo) {
        'solicitudes', 'viajes', 'entregas' => 'Operaciones',
        'usuarios', 'flota' => 'Administración',
        default => 'Principal',
    };
    $nombreUsuario = (string)($usuario['nombre_usuario'] ?? 'Usuario');
    $inicialesUsuario = user_initials($nombreUsuario);
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="htmx-config" content='{"includeIndicatorStyles":false}'>
    <title><?= e($titulo) ?> — Transportes Seguros S.A.</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="/assets/css/estilos.css">
    <script src="/assets/js/htmx.min.js" defer></script>
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main-content">Saltar al contenido principal</a>
<div class="app-shell" id="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Navegación principal">
        <div class="brand">
            <div class="brand-identity">
                <strong class="brand-title">Transportes Seguros</strong>
                <span class="brand-subtitle">Operaciones logísticas</span>
            </div>
            <span class="brand-monogram" aria-hidden="true">TS</span>
        </div>
        <nav class="sidebar-navigation" aria-label="Módulos del sistema">
            <?php if ($puedeReportes || $puedeAuditoria || $puedeClientes): ?>
            <div class="nav-group">
                <p class="nav-section-label">Principal</p>
                <div class="menu-list">
                <?php if ($puedeReportes): ?>
                    <a class="sidebar-link <?= $activo === 'dashboard' ? 'active' : '' ?>" href="/" <?= $activo === 'dashboard' ? 'aria-current="page"' : '' ?> data-tooltip="Panel general"><?= svg_icon('dashboard') ?><span class="sidebar-link-text">Panel general</span></a>
                <?php endif; ?>
                <?php if ($puedeReportes || $puedeAuditoria): ?>
                    <a class="sidebar-link <?= $activo === 'reportes' ? 'active' : '' ?>" href="/reportes.php" <?= $activo === 'reportes' ? 'aria-current="page"' : '' ?> data-tooltip="Reportes"><?= svg_icon('reports') ?><span class="sidebar-link-text">Reportes</span></a>
                <?php endif; ?>
                <?php if ($puedeClientes): ?>
                    <a class="sidebar-link <?= $activo === 'clientes' ? 'active' : '' ?>" href="/clientes.php" <?= $activo === 'clientes' ? 'aria-current="page"' : '' ?> data-tooltip="Clientes"><?= svg_icon('clients') ?><span class="sidebar-link-text">Clientes</span></a>
                <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($puedeSolicitudes || $puedeViajes || $puedeEntregas): ?>
            <div class="nav-group">
                <p class="nav-section-label">Operaciones</p>
                <div class="menu-list">
                <?php if ($puedeSolicitudes): ?>
                    <a class="sidebar-link <?= $activo === 'solicitudes' ? 'active' : '' ?>" href="/solicitudes.php" <?= $activo === 'solicitudes' ? 'aria-current="page"' : '' ?> data-tooltip="Solicitudes"><?= svg_icon('requests') ?><span class="sidebar-link-text">Solicitudes</span></a>
                <?php endif; ?>
                <?php if ($puedeViajes): ?>
                    <a class="sidebar-link <?= $activo === 'viajes' ? 'active' : '' ?>" href="/viajes.php" <?= $activo === 'viajes' ? 'aria-current="page"' : '' ?> data-tooltip="Viajes"><?= svg_icon('trips') ?><span class="sidebar-link-text">Viajes</span></a>
                <?php endif; ?>
                <?php if ($puedeEntregas): ?>
                    <a class="sidebar-link <?= $activo === 'entregas' ? 'active' : '' ?>" href="/entregas.php" <?= $activo === 'entregas' ? 'aria-current="page"' : '' ?> data-tooltip="Entregas"><?= svg_icon('deliveries') ?><span class="sidebar-link-text">Entregas</span></a>
                <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($puedeFlota || $puedeUsuarios): ?>
            <div class="nav-group">
                <p class="nav-section-label">Administración</p>
                <div class="menu-list">
                <?php if ($puedeUsuarios): ?>
                    <a class="sidebar-link <?= $activo === 'usuarios' ? 'active' : '' ?>" href="/usuarios.php" <?= $activo === 'usuarios' ? 'aria-current="page"' : '' ?> data-tooltip="Usuarios y permisos"><?= svg_icon('users') ?><span class="sidebar-link-text">Usuarios y permisos</span></a>
                <?php endif; ?>
                <?php if ($puedeFlota): ?>
                    <a class="sidebar-link <?= $activo === 'flota' ? 'active' : '' ?>" href="/flota.php" <?= $activo === 'flota' ? 'aria-current="page"' : '' ?> data-tooltip="Flota y maestros"><?= svg_icon('fleet') ?><span class="sidebar-link-text">Flota y maestros</span></a>
                <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </nav>
        <div class="sidebar-bottom">
            <button class="sidebar-collapse-btn" type="button" data-sidebar-collapse aria-expanded="true" aria-controls="sidebar" data-tooltip="Expandir navegación">
                <span class="collapse-icon-open"><?= svg_icon('panel-left-close') ?></span>
                <span class="collapse-icon-closed"><?= svg_icon('panel-left-open') ?></span>
                <span class="sidebar-collapse-label">Contraer menú</span>
            </button>
        </div>
    </aside>
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-left">
                <button class="icon-button sidebar-toggle" type="button" aria-controls="sidebar" aria-expanded="false" data-sidebar-toggle><?= svg_icon('menu', 'Abrir navegación') ?></button>
                <div class="mobile-brand">Transportes Seguros</div>
                <nav class="topbar-breadcrumb" aria-label="Ruta de navegación">
                    <span class="breadcrumb-group"><?= e($grupoSeccion) ?></span>
                    <?= svg_icon('chevron-right', '', 'breadcrumb-separator') ?>
                    <span class="breadcrumb-current"><?= e($titulo) ?></span>
                </nav>
            </div>
            <div class="topbar-right">
                <div class="user-menu" data-user-menu>
                    <button class="user-menu-trigger" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="user-account-dropdown" data-user-menu-toggle>
                        <span class="user-avatar" aria-hidden="true"><?= e($inicialesUsuario) ?></span>
                        <span class="user-meta">
                            <span class="user-badge"><?= e($nombreUsuario) ?></span>
                            <span class="user-role">Sesión activa</span>
                        </span>
                        <?= svg_icon('chevron-down', '', 'user-menu-chevron') ?>
                    </button>
                    <div class="user-dropdown" id="user-account-dropdown" role="menu" hidden>
                        <div class="user-dropdown-header">
                            <span class="user-avatar user-avatar-lg" aria-hidden="true"><?= e($inicialesUsuario) ?></span>
                            <div class="user-dropdown-info">
                                <strong><?= e($nombreUsuario) ?></strong>
                                <span>Cuenta autenticada</span>
                            </div>
                        </div>
                        <div class="user-dropdown-divider" role="separator"></div>
                        <form action="/auth/logout.php" method="post" class="user-logout-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <button class="user-dropdown-item is-danger-item" type="submit" role="menuitem">
                                <?= svg_icon('logout') ?>
                                <span>Cerrar sesión</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>
        <main class="main-content" id="main-content">
            <div class="page-intro"><div><h1 class="title is-3"><?= e($titulo) ?></h1><p class="page-subtitle"><?= e($descripcion) ?></p></div></div>
<?php
}

function layout_end(): void
{
?>
        </main>
    </div>
</div>
<div class="sidebar-backdrop" id="sidebar-backdrop" data-sidebar-close hidden aria-hidden="true"></div>
<div id="modal-contenido" aria-live="polite"></div>
<div id="aviso-temporal" class="toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
<?php
}
