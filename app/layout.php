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
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($titulo) ?> — Transportes Seguros S.A.</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <!-- Recurso externo limitado por CSP; producción debe fijar SRI o alojar el CSS aprobado. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="/assets/css/estilos.css">
    <script src="/assets/js/htmx.min.js" defer></script>
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main-content">Saltar al contenido principal</a>
<div class="app-shell" id="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Navegación principal">
        <div class="brand"><span class="brand-mark" aria-hidden="true"><?= svg_icon('brand') ?></span><div><strong>Transportes Seguros</strong><span>Administración logística</span></div></div>
        <nav class="sidebar-navigation">
            <p class="nav-section-label">Principal</p>
            <div class="menu-list">
            <?php if ($puedeReportes): ?>
                <a class="sidebar-link <?= $activo === 'dashboard' ? 'active' : '' ?>" href="/"><?= svg_icon('dashboard') ?><span>Panel general</span></a>
            <?php endif; ?>
            <?php if ($puedeReportes || $puedeAuditoria): ?>
                <a class="sidebar-link <?= $activo === 'reportes' ? 'active' : '' ?>" href="/reportes.php"><?= svg_icon('dashboard') ?><span>Reportes</span></a>
            <?php endif; ?>
            <?php if ($puedeClientes): ?>
                <a class="sidebar-link <?= $activo === 'clientes' ? 'active' : '' ?>" href="/clientes.php"><?= svg_icon('clients') ?><span>Clientes</span></a>
            <?php endif; ?>
            </div>
            <?php if ($puedeSolicitudes || $puedeViajes || $puedeEntregas): ?>
            <p class="nav-section-label nav-section-spaced">Operaciones</p>
            <div class="menu-list">
            <?php if ($puedeSolicitudes): ?>
                <a class="sidebar-link <?= $activo === 'solicitudes' ? 'active' : '' ?>" href="/solicitudes.php"><?= svg_icon('requests') ?><span>Solicitudes</span></a>
            <?php endif; ?>
            <?php if ($puedeViajes): ?>
                <a class="sidebar-link <?= $activo === 'viajes' ? 'active' : '' ?>" href="/viajes.php"><?= svg_icon('trips') ?><span>Viajes</span></a>
            <?php endif; ?>
            <?php if ($puedeEntregas): ?>
                <a class="sidebar-link <?= $activo === 'entregas' ? 'active' : '' ?>" href="/entregas.php"><?= svg_icon('deliveries') ?><span>Entregas</span></a>
            <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($puedeFlota || $puedeUsuarios): ?>
            <p class="nav-section-label nav-section-spaced">Administración</p>
            <div class="menu-list">
                <?php if ($puedeUsuarios): ?>
                <a class="sidebar-link <?= $activo === 'usuarios' ? 'active' : '' ?>" href="/usuarios.php"><?= svg_icon('users') ?><span>Usuarios y permisos</span></a>
                <?php endif; ?>
                <?php if ($puedeFlota): ?>
                <a class="sidebar-link <?= $activo === 'flota' ? 'active' : '' ?>" href="/flota.php"><?= svg_icon('fleet') ?><span>Flota y maestros</span></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </nav>
        <div class="sidebar-bottom"><span class="sidebar-status" aria-hidden="true"></span>Acceso protegido</div>
    </aside>
    <div class="main-area">
        <header class="topbar">
            <button class="icon-button sidebar-toggle" type="button" aria-controls="sidebar" aria-expanded="false" data-sidebar-toggle><?= svg_icon('menu', 'Abrir navegación') ?></button>
            <div class="mobile-brand">Transportes Seguros</div>
            <div class="topbar-right"><span class="user-badge"><?= e($usuario['nombre_usuario']) ?></span><form action="/auth/logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="button button-secondary is-small" type="submit"><?= svg_icon('logout') ?><span>Cerrar sesión</span></button></form></div>
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
