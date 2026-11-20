/**
 * Suite Playwright real (Chromium):
 *  - Reordenamiento y edición controlada de paradas en viaje PLANIFICADO.
 *  - Bitácora independiente (/auditoria.php) en sidebar, filtros y lenguaje humano.
 *  - Reportes (Solicitudes, Entregas, Desempeño histórico DW), exportación CSV e impresión.
 *  - Auditoría visual multi-resolución (1366x768..1920x1080, 1280x720, 768x1024, 375x667).
 *
 * Opera EXCLUSIVAMENTE contra TransportesSegurosSA_Test en servidor PHP aislado.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const assert = require('assert');
const { spawn, execFileSync } = require('child_process');
const { chromium } = require('playwright');

const PHP_BIN = process.env.PHP_BIN || 'C:\\AdmBD2026\\Herramientas\\PHP\\8.4\\php.exe';
const DEFAULT_CHROMIUM = 'C:\\Users\\cuent\\AppData\\Local\\ms-playwright\\chromium-1247\\chrome-win64\\chrome.exe';
const CHROMIUM_EXEC = process.env.CHROMIUM_EXEC || (fs.existsSync(DEFAULT_CHROMIUM) ? DEFAULT_CHROMIUM : undefined);
const ROOT = path.resolve(__dirname, '..');
const SHOT_DIR = path.join(ROOT, 'docs', 'screenshots', 'pr4_cierre');
const PORT = 8024;
const BASE_URL = `http://127.0.0.1:${PORT}`;
const SUFFIX = Math.random().toString(36).slice(2, 8);
const TAG = `PWSAR_${SUFFIX.toUpperCase()}`;
const ADMIN_USER = `__pw_sar_${SUFFIX}`;
const ADMIN_PASS = `ClaveSeguraSar!${SUFFIX}99`;

fs.mkdirSync(SHOT_DIR, { recursive: true });

function phpEval(code) {
    const wrapped = `putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test'); $_ENV['TSSA_DB_DATABASE']='TransportesSegurosSA_Test'; require '${ROOT.replace(/\\/g, '/')}/app/bootstrap.php'; $p = db(); require_test_database($p); ${code}`;
    return execFileSync(PHP_BIN, ['-r', wrapped], {
        cwd: ROOT,
        env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test' },
        encoding: 'utf8',
        timeout: 25000,
    }).trim();
}

function startPhpServer() {
    const logPath = path.join(os.tmpdir(), `tssa-pw-sar-${SUFFIX}.log`);
    const outFd = fs.openSync(logPath, 'w');
    const child = spawn(PHP_BIN, ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public')], {
        cwd: ROOT,
        env: {
            ...process.env,
            TSSA_DB_DATABASE: 'TransportesSegurosSA_Test',
            TSSA_APP_URL: BASE_URL,
            TSSA_MAIL_TRANSPORT: 'fake',
        },
        stdio: ['ignore', outFd, outFd],
    });
    return { child, logPath, outFd };
}

async function waitForServer(url, timeoutMs = 15000) {
    const start = Date.now();
    while (Date.now() - start < timeoutMs) {
        try {
            const res = await fetch(url);
            if (res.status > 0) return;
        } catch {
            // Reintentar
        }
        await new Promise((r) => setTimeout(r, 150));
    }
    throw new Error(`Servidor PHP en ${url} no respondió en ${timeoutMs}ms.`);
}

async function login(page) {
    await page.goto(`${BASE_URL}/auth/login.php`, { waitUntil: 'networkidle' });
    await page.fill('input[name="usuario"]', ADMIN_USER);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/auth/login.php'), { timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);
    await page.waitForLoadState('networkidle');
}

async function assertNoDesktopOverflow(page, label) {
    const metrics = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
    }));
    assert(
        metrics.scrollWidth <= metrics.clientWidth + 2,
        `Overflow horizontal en ${label}: scrollWidth=${metrics.scrollWidth} > clientWidth=${metrics.clientWidth}`
    );
}

(async () => {
    let srv = null;
    let browser = null;
    let fixtures = null;
    const pageErrors = [];
    const server500s = [];

    try {
        // 1. Sembrar usuario efímero + viaje PLANIFICADO con 4 paradas y 2 solicitudes asignadas
        const seedJson = phpEval(`
            $maxAud = (int)$p->query('SELECT ISNULL(MAX(auditoria_id), 0) FROM dbo.auditorias')->fetchColumn();
            $hash = password_hash(${JSON.stringify(ADMIN_PASS)}, PASSWORD_BCRYPT);
            $st = $p->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) VALUES (?, ?, ?, 1); SELECT SCOPE_IDENTITY();');
            $st->execute([${JSON.stringify(ADMIN_USER)}, ${JSON.stringify(ADMIN_USER + '@example.test')}, $hash]);
            $st->nextRowset();
            $uid = (int)$st->fetchColumn();
            $st->closeCursor();
            $p->prepare('INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE activo = 1')->execute([$uid]);
            $p->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?")->execute([$uid]);

            $clienteId = (int)$p->query('SELECT TOP (1) cliente_id FROM dbo.clientes WHERE activo = 1 ORDER BY cliente_id')->fetchColumn();
            $tipoMercId = (int)$p->query('SELECT TOP (1) tipo_mercancia_id FROM dbo.tipos_mercancia WHERE activo = 1 ORDER BY tipo_mercancia_id')->fetchColumn();
            $future = (new DateTimeImmutable('+6 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $vence = (new DateTimeImmutable('+1 year', new DateTimeZone('UTC')))->format('Y-m-d');
            $tag = ${JSON.stringify(TAG)};

            $uIds = [];
            $names = ['Bodega Norte San José', 'Centro Heredia', 'Terminal Alajuela', 'Punto Escazú'];
            for ($i = 1; $i <= 4; $i++) {
                $s = $p->prepare('SET NOCOUNT ON; DECLARE @id INT; EXEC dbo.sp_crear_ubicacion @nombre=?,@direccion=?,@latitud=?,@longitud=?,@ubicacion_id=@id OUTPUT; SELECT @id;');
                $s->execute([$tag . ' ' . $names[$i-1], 'Ruta demostrativa #' . $i . ', GAM', 9.91 + $i * 0.025, -84.05 - $i * 0.025]);
                $uIds[$i] = (int)$s->fetchColumn();
                $s->closeCursor();
            }

            $s = $p->prepare('SET NOCOUNT ON; DECLARE @id INT; EXEC dbo.sp_crear_ruta @nombre=?,@origen_id=?,@destino_id=?,@distancia_km=42,@duracion_estimada_min=55,@ruta_id=@id OUTPUT; SELECT @id;');
            $s->execute([$tag . ' Ruta GAM', $uIds[1], $uIds[4]]);
            $rutaId = (int)$s->fetchColumn();
            $s->closeCursor();

            $s = $p->prepare("SET NOCOUNT ON; DECLARE @id INT; EXEC dbo.sp_crear_vehiculo @placa=?,@marca=N'Isuzu',@modelo=N'ELF',@anio=2025,@capacidad_carga_kg=3200,@capacidad_volumen_m3=16,@kilometraje_actual=0,@estado='DISPONIBLE',@pma_kg=4000,@clase_legal='LIVIANO',@es_articulado=0,@estado_homologacion='VERIFICADO',@vehiculo_id=@id OUTPUT; SELECT @id;");
            $s->execute([substr(str_replace('_', '', $tag), 0, 12)]);
            $vehId = (int)$s->fetchColumn();
            $s->closeCursor();

            $s = $p->prepare("SET NOCOUNT ON; DECLARE @id INT; EXEC dbo.sp_crear_conductor @identificacion=?,@nombre=N'Carlos',@primer_apellido=N'Mora',@segundo_apellido=NULL,@telefono=NULL,@correo=NULL,@numero_licencia=?,@fecha_vencimiento_licencia=?,@estado_operativo='DISPONIBLE',@usuario_id=NULL,@codigo_licencia_inicial='B1',@conductor_id=@id OUTPUT; SELECT @id;");
            $s->execute([$tag . '_C', $tag . '_L', $vence]);
            $condId = (int)$s->fetchColumn();
            $s->closeCursor();

            $s = $p->prepare('SET NOCOUNT ON; DECLARE @id INT; EXEC dbo.sp_crear_viaje @codigo=?,@vehiculo_id=?,@conductor_id=?,@ruta_id=?,@fecha_programada=?,@viaje_id=@id OUTPUT; SELECT @id;');
            $s->execute([$tag . '_V1', $vehId, $condId, $rutaId, $future]);
            $viajeId = (int)$s->fetchColumn();
            $s->closeCursor();

            $pIds = [];
            foreach ([[1, $uIds[1], 'RECOGIDA'], [2, $uIds[2], 'MIXTA'], [3, $uIds[4], 'ENTREGA'], [4, $uIds[3], 'INTERMEDIA']] as [$ord, $ubi, $tp]) {
                $s = $p->prepare('SET NOCOUNT ON; DECLARE @id INT; EXEC dbo.sp_agregar_parada @viaje_id=?,@ubicacion_id=?,@orden=?,@tipo_parada=?,@parada_id=@id OUTPUT; SELECT @id;');
                $s->execute([$viajeId, $ubi, $ord, $tp]);
                $pIds[$ord] = (int)$s->fetchColumn();
                $s->closeCursor();
            }

            $solIds = [];
            foreach ([[$uIds[1], $uIds[4], $pIds[1], $pIds[3]], [$uIds[1], $uIds[2], $pIds[1], $pIds[2]]] as [$ori, $des, $pr, $pe]) {
                $s = $p->prepare("SET NOCOUNT ON; DECLARE @m dbo.tipo_mercancias, @sid INT; INSERT INTO @m VALUES (?, N'Mercancía E2E', 90, 1.2); EXEC dbo.sp_crear_solicitud @codigo=NULL,@cliente_id=?,@origen_id=?,@destino_id=?,@destinatario_nombre=N'Ana',@destinatario_apellido1=N'Rojas',@destinatario_apellido2=N'Vega',@destinatario_identificacion=N'102030405',@destinatario_telefono=N'88887777',@fecha_entrega_requerida=?,@mercancias=@m,@solicitud_id=@sid OUTPUT; SELECT @sid;");
                $s->execute([$tipoMercId, $clienteId, $ori, $des, $future]);
                $sid = 0;
                do { if ($s->columnCount() > 0) { $v = $s->fetchColumn(); if ($v !== false) $sid = (int)$v; } } while ($s->nextRowset());
                $s->closeCursor();
                $solIds[] = $sid;

                $s2 = $p->prepare('SET NOCOUNT ON; DECLARE @vsid INT; EXEC dbo.sp_asignar_solicitud_viaje @viaje_id=?,@solicitud_id=?,@parada_recogida_id=?,@parada_entrega_id=?,@viaje_solicitud_id=@vsid OUTPUT;');
                $s2->execute([$viajeId, $sid, $pr, $pe]);
                $s2->closeCursor();
            }

            echo json_encode([
                'uid' => $uid, 'maxAud' => $maxAud, 'uIds' => array_values($uIds),
                'rutaId' => $rutaId, 'vehId' => $vehId, 'condId' => $condId,
                'viajeId' => $viajeId, 'pIds' => $pIds, 'solIds' => $solIds,
            ]);
        `);
        fixtures = JSON.parse(seedJson);

        // 2. Arrancar servidor PHP aislado y Chromium
        srv = startPhpServer();
        await waitForServer(`${BASE_URL}/auth/login.php`);

        const launchOpts = { headless: true };
        if (CHROMIUM_EXEC) launchOpts.executablePath = CHROMIUM_EXEC;
        browser = await chromium.launch(launchOpts);

        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await context.newPage();
        page.on('pageerror', (err) => pageErrors.push(err.message));
        page.on('response', (res) => {
            if (res.status() >= 500) server500s.push(`${res.status()} ${res.url()}`);
        });

        await login(page);

        // ------------------------------------------------------------------
        // FLUJO 1: Reordenar y editar paradas en /viajes/detalle.php
        // ------------------------------------------------------------------
        await page.goto(`${BASE_URL}/viajes/detalle.php?id=${fixtures.viajeId}`, { waitUntil: 'networkidle' });
        await assertNoDesktopOverflow(page, 'viaje detalle 1440x900');
        await page.screenshot({ path: path.join(SHOT_DIR, '01_viaje_paradas_detalle_1440.png'), fullPage: true });

        // Abrir modal Reordenar paradas
        await page.click('button:has-text("Reordenar paradas")');
        await page.waitForSelector('[data-stop-reorder-list]', { timeout: 10000 });
        await page.waitForTimeout(150);
        const modalScrollLocked = await page.evaluate(() => document.documentElement.classList.contains('is-modal-open') && document.body.classList.contains('is-modal-open'));
        assert(modalScrollLocked, 'Al abrir modal de reordenamiento debe activarse el scroll lock global.');
        await page.screenshot({ path: path.join(SHOT_DIR, '02_modal_reordenar_paradas_1440.png') });

        // Mover parada #4 hacia arriba (intercambia #4 <-> #3) y guardar
        const itemsBefore = page.locator('[data-stop-item]');
        await itemsBefore.nth(3).locator('[data-stop-move="up"]').click();
        const badge3 = await itemsBefore.nth(2).locator('[data-stop-order-badge]').innerText();
        assert.strictEqual(badge3.trim(), '#3');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
            page.click('button[type="submit"]:has-text("Guardar orden")'),
        ]);

        // Confirmar en DOM que la parada Terminal Alajuela (antes #4) ahora está en #3
        const thirdRowText = await page.locator('table tbody tr').nth(2).innerText();
        assert(thirdRowText.includes('#3') && thirdRowText.includes('Terminal Alajuela'), `Parada reordenada no refleja #3: ${thirdRowText}`);

        // Intentar reordenamiento inválido (mover entrega #2 por encima de recogida #1) -> error visible dentro del modal
        await page.click('button:has-text("Reordenar paradas")');
        await page.waitForSelector('[data-stop-reorder-list]', { timeout: 10000 });
        await page.locator('[data-stop-item]').nth(1).locator('[data-stop-move="up"]').click();
        await page.click('button[type="submit"]:has-text("Guardar orden")');
        await page.waitForSelector('#mensaje-reordenar-paradas .notification-danger', { timeout: 10000 });
        const errText = await page.locator('#mensaje-reordenar-paradas').innerText();
        assert(errText.toLowerCase().includes('recogida'), `Mensaje de precedencia inválido esperado, recibido: ${errText}`);
        await page.click('.modal button:has-text("Cancelar")');
        await page.waitForTimeout(150);

        // Editar parada no vinculada (botón Editar)
        await page.click('table tbody button:has-text("Editar")');
        await page.waitForSelector('#editar-parada-tipo', { timeout: 10000 });
        await page.screenshot({ path: path.join(SHOT_DIR, '03_modal_editar_parada_1440.png') });
        await page.selectOption('#editar-parada-tipo', 'MIXTA');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
            page.click('button[type="submit"]:has-text("Guardar cambios")'),
        ]);
        const editedRowText = await page.locator('table tbody tr').nth(2).innerText();
        assert(editedRowText.includes('Mixta'), `Tipo editado Mixta no reflejado en tabla: ${editedRowText}`);
        console.log('[OK] E2E 1: Reordenamiento válido, rechazo inválido 409 en modal y edición de parada no vinculada.');

        // ------------------------------------------------------------------
        // FLUJO 2: Bitácora independiente (/auditoria.php) desde sidebar
        // ------------------------------------------------------------------
        const auditLink = page.locator('aside.sidebar a[href="/auditoria.php"]');
        assert.strictEqual(await auditLink.count(), 1, 'Sidebar debe mostrar entrada independiente Historial de actividad.');
        await Promise.all([
            page.waitForURL((u) => u.pathname === '/auditoria.php', { timeout: 15000 }),
            auditLink.click(),
        ]);
        await page.waitForSelector('#auditoria-lista table', { timeout: 15000 });
        assert.strictEqual(await page.locator('#auditoria-cargando').isVisible(), false, 'El indicador de carga de auditoría debe ocultarse al terminar la petición.');
        await assertNoDesktopOverflow(page, 'auditoria 1440x900');
        await page.screenshot({ path: path.join(SHOT_DIR, '04_bitacora_independiente_1440.png'), fullPage: true });

        // Filtrar automáticamente por usuario efímero (sin botón Filtrar, con debounce)
        await page.fill('#auditoria-usuario', ADMIN_USER);
        await page.waitForTimeout(700);
        const audTableText = await page.locator('#auditoria-lista').innerText();
        assert(audTableText.includes('Reordenó las paradas del itinerario de un viaje'), 'La bitácora debe mostrar el reordenamiento en lenguaje humano.');
        await page.locator('#auditoria-lista details summary').first().click();
        await page.waitForTimeout(150);
        await page.screenshot({ path: path.join(SHOT_DIR, '05_bitacora_filtrada_soporte_1440.png'), fullPage: true });
        console.log('[OK] E2E 2: Bitácora independiente en sidebar, filtrado automático por usuario, texto humano y soporte desplegable.');

        // ------------------------------------------------------------------
        // FLUJO 3: Reportes (3 pestañas sin auditoría, Chart.js, DW, CSV, Impresión)
        // ------------------------------------------------------------------
        await page.goto(`${BASE_URL}/reportes.php?seccion=solicitudes`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#reportes-resultado .kpi-card', { timeout: 15000 });
        const tabsText = await page.locator('.tabs-bar').innerText();
        assert(!tabsText.includes('Historial de actividad'), 'Reportes ya no debe incluir pestaña Historial de actividad.');
        await page.waitForTimeout(450);
        await assertNoDesktopOverflow(page, 'reporte solicitudes 1440x900');
        await page.screenshot({ path: path.join(SHOT_DIR, '06_reporte_solicitudes_1440.png'), fullPage: true });

        await page.goto(`${BASE_URL}/reportes.php?seccion=entregas`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#reportes-resultado .kpi-card', { timeout: 15000 });
        await page.waitForTimeout(450);
        await assertNoDesktopOverflow(page, 'reporte entregas 1440x900');
        await page.screenshot({ path: path.join(SHOT_DIR, '07_reporte_entregas_1440.png'), fullPage: true });

        await page.goto(`${BASE_URL}/reportes.php?seccion=historico_dw`, { waitUntil: 'networkidle' });
        await page.waitForSelector('[data-dw-source="TransportesSegurosSA_DW"]', { timeout: 15000 });
        assert.strictEqual(await page.locator('#reportes-cargando').isVisible(), false, 'El indicador de carga de reportes debe ocultarse al terminar la petición.');
        await page.waitForTimeout(500);
        const dwHtmlText = await page.locator('#reportes-resultado').innerText();
        assert(dwHtmlText.includes('Data Warehouse de solo lectura (última carga conocida:'), 'Reporte 3 debe rotular fuente DW solo lectura y última carga.');
        await assertNoDesktopOverflow(page, 'reporte DW 1440x900');
        await page.screenshot({ path: path.join(SHOT_DIR, '08_reporte_dw_historico_1440.png'), fullPage: true });

        // Comprobar descarga CSV con sesión del navegador
        const csvHref = await page.locator('[data-report-export-link][data-export-format="csv"]').getAttribute('href');
        const csvResp = await context.request.get(`${BASE_URL}${csvHref}`);
        assert.strictEqual(csvResp.status(), 200, 'Exportación CSV DW debe responder 200.');
        const csvBuf = await csvResp.body();
        assert(csvBuf[0] === 0xEF && csvBuf[1] === 0xBB && csvBuf[2] === 0xBF, 'CSV descargado debe incluir BOM UTF-8.');

        // Comprobar vista imprimible / PDF sin sidebar
        const printHref = await page.locator('[data-report-export-link][data-export-format="imprimir"]').getAttribute('href');
        await page.goto(`${BASE_URL}${printHref}`, { waitUntil: 'networkidle' });
        await page.waitForSelector('[data-print-report]', { timeout: 10000 });
        assert.strictEqual(await page.locator('aside.sidebar').count(), 0, 'Vista de impresión no debe incluir sidebar.');
        await page.screenshot({ path: path.join(SHOT_DIR, '09_reporte_dw_impresion_1440.png'), fullPage: true });
        console.log('[OK] E2E 3: 3 reportes con KPIs/Chart.js, DW real rotulado, CSV BOM y vista de impresión limpia.');

        // ------------------------------------------------------------------
        // FLUJO 4: Auditoría visual multi-resolución (7 viewports)
        // ------------------------------------------------------------------
        const viewports = [
            { name: '1366x768', width: 1366, height: 768, shot: '10_auditoria_1366x768.png', url: '/auditoria.php', wait: '#auditoria-lista table' },
            { name: '1600x900', width: 1600, height: 900, url: '/reportes.php?seccion=historico_dw', wait: '[data-dw-source]' },
            { name: '1920x1080', width: 1920, height: 1080, url: `/viajes/detalle.php?id=${fixtures.viajeId}`, wait: 'table' },
            { name: '1280x720', width: 1280, height: 720, url: '/reportes.php?seccion=solicitudes', wait: '.kpi-card' },
            { name: '768x1024', width: 768, height: 1024, shot: '11_reporte_dw_768x1024.png', url: '/reportes.php?seccion=historico_dw', wait: '[data-dw-source]' },
            { name: '375x667', width: 375, height: 667, shot: '12_reporte_dw_375x667.png', url: '/reportes.php?seccion=historico_dw', wait: '[data-dw-source]' },
        ];
        for (const vp of viewports) {
            await page.setViewportSize({ width: vp.width, height: vp.height });
            await page.goto(`${BASE_URL}${vp.url}`, { waitUntil: 'networkidle' });
            await page.waitForSelector(vp.wait, { timeout: 15000 });
            await page.waitForTimeout(300);
            if (vp.width >= 1280) {
                await assertNoDesktopOverflow(page, `${vp.url} ${vp.name}`);
            }
            if (vp.shot) {
                await page.screenshot({ path: path.join(SHOT_DIR, vp.shot), fullPage: true });
            }
        }
        console.log('[OK] E2E 4: Auditoría multi-resolución (1366x768..1920x1080, 1280x720, 768x1024, 375x667) sin desbordamiento desktop.');

        assert.strictEqual(pageErrors.length, 0, `Errores JS en navegador: ${pageErrors.join(' | ')}`);
        assert.strictEqual(server500s.length, 0, `Errores HTTP 500: ${server500s.join(' | ')}`);
        console.log('=== PLAYWRIGHT STOPS + AUDIT + REPORTS + DW: 100% PASS ===');
    } catch (err) {
        console.error('FAIL:', err);
        process.exitCode = 1;
    } finally {
        if (browser) {
            try { await browser.close(); } catch {}
        }
        if (srv) {
            try {
                if (process.platform === 'win32' && srv.child.pid) {
                    execFileSync('taskkill', ['/F', '/T', '/PID', String(srv.child.pid)], { stdio: 'ignore' });
                } else {
                    srv.child.kill('SIGTERM');
                }
            } catch {}
            try { fs.closeSync(srv.outFd); } catch {}
            try { fs.unlinkSync(srv.logPath); } catch {}
        }
        if (fixtures) {
            try {
                phpEval(`
                    $fx = json_decode(${JSON.stringify(JSON.stringify(fixtures))}, true);
                    $p->prepare('DELETE FROM dbo.viaje_solicitudes WHERE viaje_id = ?')->execute([$fx['viajeId']]);
                    $p->prepare('DELETE FROM dbo.paradas_viaje WHERE viaje_id = ?')->execute([$fx['viajeId']]);
                    $p->prepare('DELETE FROM dbo.viajes WHERE viaje_id = ?')->execute([$fx['viajeId']]);
                    foreach ($fx['solIds'] as $sid) {
                        $p->prepare('DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id = ?')->execute([$sid]);
                        $p->prepare('DELETE FROM dbo.mercancias WHERE solicitud_id = ?')->execute([$sid]);
                        $p->prepare('DELETE FROM dbo.solicitudes WHERE solicitud_id = ?')->execute([$sid]);
                    }
                    $p->prepare('DELETE FROM dbo.rutas WHERE ruta_id = ?')->execute([$fx['rutaId']]);
                    $p->prepare('DELETE FROM dbo.vehiculos WHERE vehiculo_id = ?')->execute([$fx['vehId']]);
                    $p->prepare('DELETE FROM dbo.conductores_licencias WHERE conductor_id = ?')->execute([$fx['condId']]);
                    $p->prepare('DELETE FROM dbo.conductores WHERE conductor_id = ?')->execute([$fx['condId']]);
                    foreach ($fx['uIds'] as $u) {
                        $p->prepare('DELETE FROM dbo.ubicaciones WHERE ubicacion_id = ?')->execute([$u]);
                    }
                    $p->prepare('DELETE FROM dbo.auditorias WHERE auditoria_id > ?')->execute([$fx['maxAud']]);
                    $p->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?')->execute([$fx['uid']]);
                    $p->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?')->execute([$fx['uid']]);
                `);
            } catch (cleanErr) {
                console.error('CLEANUP ERROR:', cleanErr);
                process.exitCode = 1;
            }
        }
    }
})();
