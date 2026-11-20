/**
 * Suite Playwright real (Chromium) — Cierre final PR #4:
 *  - Cambio A: Filtros automáticos en /reportes.php y /auditoria.php sin botones Aplicar/Filtrar,
 *    conservando Limpiar, eventos reales de Tom Select + Flatpickr + búsqueda con debounce,
 *    0 peticiones duplicadas, reseteo de paginación a 1 y sincronización exacta de CSV / Imprimir.
 *  - Cambio B: Etiquetas inequívocas (Solicitudes, Entregas operativas, Análisis histórico (DW))
 *    y distinción concisa de origen (base operacional en tiempo real vs DW solo lectura por ETL).
 *  - Cambio C: Selector inteligente de paradas en modal Asignar solicitud (escenarios a–f en navegador +
 *    scroll lock + dropdown sin recortes).
 *  - Visores desktop prioritarios (1366x768, 1440x900, 1600x900, 1920x1080) + tablet/móvil (768x1024, 375x667).
 *
 * Opera EXCLUSIVAMENTE contra TransportesSegurosSA_Test en servidor PHP aislado con cuenta efímera.
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
const SHOT_DIR = path.join(ROOT, 'docs', 'screenshots', 'pr4_final');
const PORT = 8026;
const BASE_URL = `http://127.0.0.1:${PORT}`;
const SUFFIX = Math.random().toString(36).slice(2, 8);
const TAG = `PWFIN_${SUFFIX.toUpperCase()}`;
const ADMIN_USER = `__pw_fin_${SUFFIX}`;
const ADMIN_PASS = `ClaveSeguraFin!${SUFFIX}99`;

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
    const logPath = path.join(os.tmpdir(), `tssa-pw-fin-${SUFFIX}.log`);
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

async function selectTomOption(page, selectSelector, value) {
    await page.evaluate(({ sel, val }) => {
        const el = document.querySelector(sel);
        if (!el) throw new Error(`Selector no encontrado: ${sel}`);
        if (el.tomselect) {
            el.tomselect.setValue(String(val), false);
        } else {
            el.value = String(val);
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }, { sel: selectSelector, val: value });
}

async function getTomState(page, selectSelector) {
    return page.evaluate((sel) => {
        const el = document.querySelector(sel);
        if (!el) return null;
        const ts = el.tomselect || null;
        const opts = ts
            ? Object.values(ts.options).map((o) => ({ value: String(o.value), text: String(o.text) }))
            : Array.from(el.options).filter((o) => o.value !== '').map((o) => ({ value: String(o.value), text: o.textContent.trim() }));
        return {
            disabled: Boolean(el.disabled || ts?.isDisabled),
            value: String(el.value || ''),
            options: opts,
        };
    }, selectSelector);
}

async function assertNoDesktopOverflow(page, label) {
    const m = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
    }));
    assert(m.scrollWidth <= m.clientWidth + 2, `Overflow horizontal en ${label}: ${m.scrollWidth} > ${m.clientWidth}`);
}

(async () => {
    let srv = null;
    let browser = null;
    let fx = null;
    const pageErrors = [];
    const server500s = [];

    try {
        // ------------------------------------------------------------------
        // 1. Sembrar fixtures aislados en TransportesSegurosSA_Test
        // ------------------------------------------------------------------
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
            $future = (new DateTimeImmutable('+7 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $vence = (new DateTimeImmutable('+1 year', new DateTimeZone('UTC')))->format('Y-m-d');
            $tag = ${JSON.stringify(TAG)};

            // 6 ubicaciones:
            // uA (San José Norte), uA_dup (mismas coords/ciudad que uA pero distinto ID!), uInter, uB (Heredia), uC (Alajuela), uSinParada (Cartago)
            $ubiDefs = [
                'uA'         => [$tag . ' Bodega San José A', 'San José centro', 9.933300, -84.083300],
                'uA_dup'     => [$tag . ' Bodega San José A (Otra)', 'San José centro', 9.933300, -84.083300],
                'uInter'     => [$tag . ' Peaje Intermedio', 'Ruta 1', 9.965000, -84.120000],
                'uB'         => [$tag . ' Centro Heredia B', 'Heredia centro', 9.998000, -84.117000],
                'uC'         => [$tag . ' Terminal Alajuela C', 'Alajuela centro', 10.016000, -84.211000],
                'uSinParada' => [$tag . ' Bodega Cartago D', 'Cartago centro', 9.864000, -83.919000],
            ];
            $uIds = [];
            foreach ($ubiDefs as $k => [$nom, $dir, $lat, $lon]) {
                $s = $p->prepare('SET NOCOUNT ON; DECLARE @id INT; EXEC dbo.sp_crear_ubicacion @nombre=?,@direccion=?,@latitud=?,@longitud=?,@ubicacion_id=@id OUTPUT; SELECT @id;');
                $s->execute([$nom, $dir, $lat, $lon]);
                $uIds[$k] = (int)$s->fetchColumn();
                $s->closeCursor();
            }

            $s = $p->prepare('SET NOCOUNT ON; DECLARE @id INT; EXEC dbo.sp_crear_ruta @nombre=?,@origen_id=?,@destino_id=?,@distancia_km=55,@duracion_estimada_min=75,@ruta_id=@id OUTPUT; SELECT @id;');
            $s->execute([$tag . ' Ruta Integral', $uIds['uA'], $uIds['uC']]);
            $rutaId = (int)$s->fetchColumn();
            $s->closeCursor();

            $s = $p->prepare("SET NOCOUNT ON; DECLARE @id INT; EXEC dbo.sp_crear_vehiculo @placa=?,@marca=N'Isuzu',@modelo=N'NPR',@anio=2025,@capacidad_carga_kg=3500,@capacidad_volumen_m3=20,@kilometraje_actual=0,@estado='DISPONIBLE',@pma_kg=4000,@clase_legal='LIVIANO',@es_articulado=0,@estado_homologacion='VERIFICADO',@vehiculo_id=@id OUTPUT; SELECT @id;");
            $s->execute([substr(str_replace('_', '', $tag), 0, 12)]);
            $vehId = (int)$s->fetchColumn();
            $s->closeCursor();

            $s = $p->prepare("SET NOCOUNT ON; DECLARE @id INT; EXEC dbo.sp_crear_conductor @identificacion=?,@nombre=N'Jorge',@primer_apellido=N'Salas',@segundo_apellido=NULL,@telefono=NULL,@correo=NULL,@numero_licencia=?,@fecha_vencimiento_licencia=?,@estado_operativo='DISPONIBLE',@usuario_id=NULL,@codigo_licencia_inicial='B1',@conductor_id=@id OUTPUT; SELECT @id;");
            $s->execute([$tag . '_C', $tag . '_L', $vence]);
            $condId = (int)$s->fetchColumn();
            $s->closeCursor();

            $s = $p->prepare('SET NOCOUNT ON; DECLARE @id INT; EXEC dbo.sp_crear_viaje @codigo=?,@vehiculo_id=?,@conductor_id=?,@ruta_id=?,@fecha_programada=?,@viaje_id=@id OUTPUT; SELECT @id;');
            $s->execute([$tag . '_V1', $vehId, $condId, $rutaId, $future]);
            $viajeId = (int)$s->fetchColumn();
            $s->closeCursor();

            // 7 paradas cubriendo todos los escenarios (a)-(f):
            // #1 uA (MIXTA)
            // #2 uInter (INTERMEDIA) -> nunca debe aparecer
            // #3 uB (MIXTA) -> sirve como entrega para uA->uB y recogida para uB->uC
            // #4 uC (ENTREGA)
            // #5 uA_dup (RECOGIDA) -> mismas coordenadas que uA pero distinto ubicacion_id
            // #6 uA (RECOGIDA) -> segunda recogida en uA (orden 6)
            // #7 uC (ENTREGA) -> segunda entrega en uC (orden 7)
            $stopDefs = [
                [1, $uIds['uA'], 'MIXTA'],
                [2, $uIds['uInter'], 'INTERMEDIA'],
                [3, $uIds['uB'], 'MIXTA'],
                [4, $uIds['uC'], 'ENTREGA'],
                [5, $uIds['uA_dup'], 'RECOGIDA'],
                [6, $uIds['uA'], 'RECOGIDA'],
                [7, $uIds['uC'], 'ENTREGA'],
            ];
            $pIds = [];
            foreach ($stopDefs as [$ord, $ubi, $tp]) {
                $s = $p->prepare('SET NOCOUNT ON; DECLARE @id INT; EXEC dbo.sp_agregar_parada @viaje_id=?,@ubicacion_id=?,@orden=?,@tipo_parada=?,@parada_id=@id OUTPUT; SELECT @id;');
                $s->execute([$viajeId, $ubi, $ord, $tp]);
                $pIds[$ord] = (int)$s->fetchColumn();
                $s->closeCursor();
            }

            // 5 solicitudes elegibles:
            // solAB: uA -> uB (única pareja válida #1 -> #3; #6 queda excluida porque 6 > 3; #5 uA_dup excluida)
            // solBC: uB -> uC (parejas #3 -> #4 y #3 -> #7; prueba MIXTA como recogida y 2 entregas)
            // solAC: uA -> uC (parejas #1->#4, #1->#7, #6->#7; al elegir #6 en recogida, #4 desaparece!)
            // solBA_inv: uB -> uA (recogida #3 orden 3, entrega #1 orden 1 -> 0 parejas por orden invertido!)
            // solAD_none: uA -> uSinParada (sin parada de entrega compatible!)
            $solDefs = [
                'solAB'      => [$uIds['uA'], $uIds['uB']],
                'solBC'      => [$uIds['uB'], $uIds['uC']],
                'solAC'      => [$uIds['uA'], $uIds['uC']],
                'solBA_inv'  => [$uIds['uB'], $uIds['uA']],
                'solAD_none' => [$uIds['uA'], $uIds['uSinParada']],
            ];
            $solIds = [];
            foreach ($solDefs as $k => [$ori, $des]) {
                $s = $p->prepare("SET NOCOUNT ON; DECLARE @m dbo.tipo_mercancias, @sid INT; INSERT INTO @m VALUES (?, N'Carga PWFIN', 60, 0.8); EXEC dbo.sp_crear_solicitud @codigo=NULL,@cliente_id=?,@origen_id=?,@destino_id=?,@destinatario_nombre=N'Marta',@destinatario_apellido1=N'Arias',@destinatario_apellido2=N'Solis',@destinatario_identificacion=N'109998887',@destinatario_telefono=N'88886666',@fecha_entrega_requerida=?,@mercancias=@m,@solicitud_id=@sid OUTPUT; SELECT @sid;");
                $s->execute([$tipoMercId, $clienteId, $ori, $des, $future]);
                $sid = 0;
                do { if ($s->columnCount() > 0) { $v = $s->fetchColumn(); if ($v !== false) $sid = (int)$v; } } while ($s->nextRowset());
                $s->closeCursor();
                $solIds[$k] = $sid;
            }

            echo json_encode([
                'uid' => $uid, 'maxAud' => $maxAud, 'uIds' => $uIds,
                'rutaId' => $rutaId, 'vehId' => $vehId, 'condId' => $condId,
                'viajeId' => $viajeId, 'pIds' => $pIds, 'solIds' => $solIds,
            ]);
        `);
        fx = JSON.parse(seedJson);

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

        // ==================================================================
        // CAMBIO A + B: REPORTES (filtros automáticos, sin Aplicar, nombres claros)
        // ==================================================================
        const repRequests = [];
        page.on('request', (req) => {
            if (req.url().includes('/reportes/lista.php')) repRequests.push(req.url());
        });

        await page.goto(`${BASE_URL}/reportes.php?seccion=solicitudes`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#reportes-resultado .kpi-card', { timeout: 15000 });

        // Comprobar ausencia de botón Aplicar y presencia de Limpiar
        assert.strictEqual(await page.locator('#reportes-filtros button[type="submit"]').count(), 0, 'Reportes NO debe tener botón Aplicar.');
        assert.strictEqual(await page.locator('#reportes-filtros a:has-text("Limpiar")').count(), 1, 'Reportes DEBE conservar botón Limpiar.');

        // Comprobar nombres exactos de las 3 pestañas (Cambio B)
        const tabs = await page.locator('.tabs-bar .tab-chip').allInnerTexts();
        assert.deepStrictEqual(
            tabs.map((t) => t.trim()),
            ['Solicitudes', 'Entregas operativas', 'Análisis histórico (DW)'],
            `Etiquetas de pestañas inesperadas: ${JSON.stringify(tabs)}`
        );
        const srcBanner1 = await page.locator('.report-source-banner').innerText();
        assert(srcBanner1.includes('Base operacional (datos en tiempo real)'), `Origen operacional no claro en Solicitudes: ${srcBanner1}`);
        await page.waitForTimeout(350);
        await assertNoDesktopOverflow(page, 'reportes solicitudes 1440x900');
        await page.screenshot({ path: path.join(SHOT_DIR, '01_reportes_filtros_auto_1440.png'), fullPage: true });

        // 1) Filtro automático por Tom Select (estado=REGISTRADA) -> exactamente 1 petición HTMX, sin botón Aplicar
        repRequests.length = 0;
        const pResp1 = page.waitForResponse((r) => r.url().includes('/reportes/lista.php') && r.url().includes('estado=REGISTRADA'), { timeout: 10000 });
        await selectTomOption(page, '#reporte-estado', 'REGISTRADA');
        await pResp1;
        await page.waitForTimeout(250);
        assert.strictEqual(repRequests.length, 1, `Cambiar estado Tom Select debe emitir 1 sola petición HTMX (emitidas: ${repRequests.length}).`);

        // 2) Filtro automático por búsqueda con debounce (~350ms) + sincronización de CSV / Imprimir
        repRequests.length = 0;
        const pResp2 = page.waitForResponse((r) => r.url().includes('/reportes/lista.php') && r.url().includes('buscar=SOL-'), { timeout: 10000 });
        await page.fill('#reporte-buscar', 'SOL-');
        await pResp2;
        await page.waitForTimeout(250);
        assert.strictEqual(repRequests.length, 1, `Búsqueda con debounce debe emitir 1 petición HTMX (emitidas: ${repRequests.length}).`);

        // 3) Filtro automático por fecha Flatpickr (desde)
        repRequests.length = 0;
        const pResp3 = page.waitForResponse((r) => r.url().includes('/reportes/lista.php') && r.url().includes('desde=2026-01-01'), { timeout: 10000 });
        await page.evaluate(() => {
            const el = document.querySelector('#reporte-desde');
            el._flatpickr.setDate('2026-01-01', true);
        });
        await pResp3;
        await page.waitForTimeout(200);
        assert.strictEqual(repRequests.length, 1, `Cambiar fecha Flatpickr debe emitir 1 petición HTMX (emitidas: ${repRequests.length}).`);

        // Confirmar que Exportar CSV e Imprimir/PDF heredan EXACTAMENTE los filtros activos
        const csvHref = await page.locator('[data-report-export-link][data-export-format="csv"]').getAttribute('href');
        const prtHref = await page.locator('[data-report-export-link][data-export-format="imprimir"]').getAttribute('href');
        assert(csvHref.includes('estado=REGISTRADA') && csvHref.includes('buscar=SOL-') && csvHref.includes('desde=2026-01-01'), `CSV href desincronizado: ${csvHref}`);
        assert(prtHref.includes('estado=REGISTRADA') && prtHref.includes('buscar=SOL-') && prtHref.includes('desde=2026-01-01'), `Imprimir href desincronizado: ${prtHref}`);
        const csvRes = await context.request.get(`${BASE_URL}${csvHref}`);
        assert.strictEqual(csvRes.status(), 200, 'Descarga CSV filtrada debe responder 200.');

        // 4) Botón Limpiar resetea todos los filtros, conserva la pestaña y limpia enlaces de exportación
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
            page.click('#reportes-filtros a:has-text("Limpiar")'),
        ]);
        await page.waitForSelector('#reportes-resultado .kpi-card', { timeout: 15000 });
        assert.strictEqual(await page.inputValue('#reporte-buscar'), '', 'Limpiar debe vaciar búsqueda.');
        assert.strictEqual(await page.inputValue('#reporte-desde'), '', 'Limpiar debe vaciar fecha desde.');
        const csvCleanHref = await page.locator('[data-report-export-link][data-export-format="csv"]').getAttribute('href');
        assert(!csvCleanHref.includes('estado=') && !csvCleanHref.includes('buscar=') && !csvCleanHref.includes('desde='), `Limpiar debe limpiar href CSV: ${csvCleanHref}`);

        // 5) Pestaña Entregas operativas y pestaña Análisis histórico (DW)
        await page.goto(`${BASE_URL}/reportes.php?seccion=entregas`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#reportes-resultado .kpi-card', { timeout: 15000 });
        assert((await page.locator('.report-source-banner').innerText()).includes('Base operacional (datos en tiempo real)'));

        await page.goto(`${BASE_URL}/reportes.php?seccion=historico_dw`, { waitUntil: 'networkidle' });
        await page.waitForSelector('[data-dw-source="TransportesSegurosSA_DW"]', { timeout: 15000 });
        const dwBanner = await page.locator('.report-source-dw').innerText();
        assert(
            dwBanner.includes('Data Warehouse de solo lectura (última carga conocida:')
            && dwBanner.includes('consolidados por ETL')
            && dwBanner.includes('puede no coincidir con cambios operativos recientes'),
            `Banner DW incompleto: ${dwBanner}`
        );
        await page.waitForTimeout(350);
        await page.screenshot({ path: path.join(SHOT_DIR, '02_reportes_dw_claridad_1440.png'), fullPage: true });
        console.log('[OK] Cambio A+B en Reportes: filtros automáticos (0 duplicados), Limpiar, CSV/Imprimir sincronizados y claridad DW.');

        // ==================================================================
        // CAMBIO A: HISTORIAL DE ACTIVIDAD (/auditoria.php)
        // ==================================================================
        const audRequests = [];
        page.on('request', (req) => {
            if (req.url().includes('/auditoria/lista.php')) audRequests.push(req.url());
        });

        await page.setViewportSize({ width: 1366, height: 768 });
        await page.goto(`${BASE_URL}/auditoria.php`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#auditoria-lista table', { timeout: 15000 });
        assert.strictEqual(await page.locator('#auditoria-filtros button[type="submit"]').count(), 0, 'Historial de actividad NO debe tener botón Filtrar.');
        assert.strictEqual(await page.locator('#auditoria-filtros a:has-text("Limpiar")').count(), 1, 'Historial de actividad DEBE conservar Limpiar.');

        // Ir a página 2 si hay paginación, luego cambiar filtro y confirmar que regresa a página 1
        const nextBtn = page.locator('#auditoria-lista .pagination-bar button:has-text("Siguiente")');
        if (await nextBtn.isEnabled()) {
            const pPage2 = page.waitForResponse((r) => r.url().includes('/auditoria/lista.php') && r.url().includes('pagina=2'), { timeout: 10000 });
            await nextBtn.click();
            await pPage2;
            assert((await page.locator('#auditoria-lista .pagination-info').innerText()).includes('Página 2'));
        }

        audRequests.length = 0;
        const pAudUser = page.waitForResponse((r) => r.url().includes('/auditoria/lista.php') && r.url().includes(`usuario=${ADMIN_USER}`), { timeout: 10000 });
        await page.fill('#auditoria-usuario', ADMIN_USER);
        await pAudUser;
        await page.waitForTimeout(250);
        assert.strictEqual(audRequests.length, 1, `Búsqueda automática en bitácora debe disparar 1 petición (emitidas: ${audRequests.length}).`);
        assert((await page.locator('#auditoria-lista .pagination-info').innerText()).includes('Página 1'), 'Cambiar filtro debe reiniciar paginación a Página 1.');

        audRequests.length = 0;
        const pAudMod = page.waitForResponse((r) => r.url().includes('/auditoria/lista.php') && r.url().includes('entidad=solicitudes'), { timeout: 10000 });
        await selectTomOption(page, '#auditoria-modulo', 'solicitudes');
        await pAudMod;
        await page.waitForTimeout(200);
        assert.strictEqual(audRequests.length, 1, `Cambiar módulo Tom Select en bitácora debe disparar 1 petición (emitidas: ${audRequests.length}).`);

        await page.evaluate(() => window.scrollTo(0, 0));
        await assertNoDesktopOverflow(page, 'auditoria filtros auto 1366x768');
        await page.screenshot({ path: path.join(SHOT_DIR, '03_auditoria_filtros_auto_1366.png'), fullPage: true });

        // Limpiar en bitácora
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
            page.click('#auditoria-filtros a:has-text("Limpiar")'),
        ]);
        await page.waitForSelector('#auditoria-lista table', { timeout: 15000 });
        assert.strictEqual(await page.inputValue('#auditoria-usuario'), '', 'Limpiar en bitácora debe vaciar búsqueda.');
        console.log('[OK] Cambio A en Historial de actividad: filtros automáticos (0 duplicados), reinicio a página 1 y Limpiar.');

        // ==================================================================
        // CAMBIO C: SELECTOR INTELIGENTE DE PARADAS EN MODAL ASIGNAR SOLICITUD
        // ==================================================================
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto(`${BASE_URL}/viajes/detalle.php?id=${fx.viajeId}`, { waitUntil: 'networkidle' });
        await page.click('button:has-text("Asignar solicitud")');
        await page.waitForSelector('[data-assign-stops-root]', { timeout: 10000 });
        await page.waitForTimeout(200);

        // Scroll lock activo al abrir modal
        const scrollLocked = await page.evaluate(() => document.documentElement.classList.contains('is-modal-open'));
        assert(scrollLocked, 'El modal Asignar solicitud debe activar scroll lock global.');

        // Estado inicial: ambos selectores bloqueados y botón Asignar deshabilitado
        const initRec = await getTomState(page, '#parada-recogida');
        const initEnt = await getTomState(page, '#parada-entrega');
        const submitBtn = page.locator('.modal button[type="submit"]');
        assert(initRec.disabled && initEnt.disabled, 'Recogida y entrega deben iniciar bloqueados hasta elegir solicitud.');
        assert.strictEqual(await submitBtn.isDisabled(), true, 'Botón Asignar debe iniciar deshabilitado.');
        await page.screenshot({ path: path.join(SHOT_DIR, '04_modal_asignar_inicial_1440.png') });

        // Escenario (a), (b), (c) + preselección única: SolAB (uA -> uB)
        // Solo #1 (uA MIXTA) y #3 (uB MIXTA) son válidos; #2 INTERMEDIA, #5 uA_dup (mismas coords distinto ID!) y #6 uA (orden 6 > 3) quedan EXCLUIDOS!
        await selectTomOption(page, '#asignar-solicitud', fx.solIds.solAB);
        await page.waitForTimeout(150);
        const recAB = await getTomState(page, '#parada-recogida');
        const entAB = await getTomState(page, '#parada-entrega');
        assert.deepStrictEqual(recAB.options.map((o) => Number(o.value)), [fx.pIds[1]], `SolAB recogida debe incluir solo #1 (sin uA_dup ni orden 6): ${JSON.stringify(recAB.options)}`);
        assert.deepStrictEqual(entAB.options.map((o) => Number(o.value)), [fx.pIds[3]], `SolAB entrega debe incluir solo #3 MIXTA (sin INTERMEDIA): ${JSON.stringify(entAB.options)}`);
        assert.strictEqual(Number(recAB.value), fx.pIds[1], 'Pareja única SolAB debe preseleccionar recogida #1.');
        assert.strictEqual(Number(entAB.value), fx.pIds[3], 'Pareja única SolAB debe preseleccionar entrega #3.');
        assert.strictEqual(await submitBtn.isDisabled(), false, 'Botón Asignar debe habilitarse con pareja única válida.');

        // Escenario (e) + múltiples parejas + reactividad rec->ent: cambiar a SolAC (uA -> uC)
        // Recogidas compatibles: #1 y #6; Entregas compatibles: #4 y #7; parejas: (#1->#4, #1->#7, #6->#7).
        await selectTomOption(page, '#asignar-solicitud', fx.solIds.solAC);
        await page.waitForTimeout(150);
        const recAC0 = await getTomState(page, '#parada-recogida');
        const entAC0 = await getTomState(page, '#parada-entrega');
        assert.strictEqual(recAC0.value, '', 'Cambiar a solicitud con múltiples parejas debe limpiar selección previa incompatible.');
        assert.deepStrictEqual(recAC0.options.map((o) => Number(o.value)), [fx.pIds[1], fx.pIds[6]], 'SolAC debe listar recogidas #1 y #6 (excluyendo uA_dup #5 e INTERMEDIA #2).');
        assert.deepStrictEqual(entAC0.options.map((o) => Number(o.value)), [fx.pIds[4], fx.pIds[7]], 'SolAC debe listar entregas #4 y #7.');

        // Al elegir #6 (orden 6) en recogida -> entrega #4 (orden 4) debe quedar excluida automáticamente, dejando solo #7 (orden 7)!
        await selectTomOption(page, '#parada-recogida', fx.pIds[6]);
        await page.waitForTimeout(150);
        const entACAfter6 = await getTomState(page, '#parada-entrega');
        assert.deepStrictEqual(entACAfter6.options.map((o) => Number(o.value)), [fx.pIds[7]], 'Al elegir recogida #6, entrega debe filtrarse únicamente a #7 (orden > 6).');
        await selectTomOption(page, '#parada-entrega', fx.pIds[7]);
        await page.waitForTimeout(150);
        assert.strictEqual(await submitBtn.isDisabled(), false, 'Con pareja #6 -> #7 el botón Asignar debe habilitarse.');
        const modalBoundsOk = await page.evaluate(() => {
            const card = document.querySelector('.modal-card')?.getBoundingClientRect();
            const recCtrl = document.querySelector('#parada-recogida')?.tomselect?.control?.getBoundingClientRect();
            const entCtrl = document.querySelector('#parada-entrega')?.tomselect?.control?.getBoundingClientRect();
            if (!card || !recCtrl || !entCtrl) return { ok: false };
            return {
                ok: recCtrl.right <= card.right - 8 && entCtrl.right <= card.right - 8,
                cardRight: card.right,
                recRight: recCtrl.right,
                entRight: entCtrl.right,
            };
        });
        assert(modalBoundsOk.ok, `Combobox de paradas desborda la tarjeta modal: ${JSON.stringify(modalBoundsOk)}`);
        await page.screenshot({ path: path.join(SHOT_DIR, '05_modal_asignar_filtrado_1440.png') });

        // Escenario (b) MIXTA en recogida: SolBC (uB -> uC) -> recogida #3 MIXTA, entregas #4 y #7
        await selectTomOption(page, '#asignar-solicitud', fx.solIds.solBC);
        await page.waitForTimeout(150);
        const recBC = await getTomState(page, '#parada-recogida');
        const entBC = await getTomState(page, '#parada-entrega');
        assert.deepStrictEqual(recBC.options.map((o) => Number(o.value)), [fx.pIds[3]], 'SolBC debe aceptar #3 MIXTA como recogida.');
        assert.deepStrictEqual(entBC.options.map((o) => Number(o.value)), [fx.pIds[4], fx.pIds[7]], 'SolBC debe mostrar entregas #4 y #7.');

        // Escenario (d) Recogida después de entrega: SolBA_inv (uB orden 3 -> uA orden 1) -> aviso claro + botón deshabilitado
        await selectTomOption(page, '#asignar-solicitud', fx.solIds.solBA_inv);
        await page.waitForTimeout(150);
        const avisoInv = await page.locator('#asignar-validacion-aviso').innerText();
        assert(await page.locator('#asignar-validacion-aviso').isVisible(), 'Orden invertido debe mostrar aviso visible.');
        assert(avisoInv.toLowerCase().includes('después') || avisoInv.toLowerCase().includes('reordena'), `Aviso orden invertido inesperado: ${avisoInv}`);
        assert.strictEqual(await submitBtn.isDisabled(), true, 'Con orden invertido el botón Asignar debe quedar deshabilitado.');

        // Escenario (f) Sin parada compatible en destino: SolAD_none (uA -> uSinParada) -> aviso concreto + botón deshabilitado
        await selectTomOption(page, '#asignar-solicitud', fx.solIds.solAD_none);
        await page.waitForTimeout(150);
        const avisoNone = await page.locator('#asignar-validacion-aviso').innerText();
        assert(await page.locator('#asignar-validacion-aviso').isVisible(), 'Ausencia de parada compatible debe mostrar aviso.');
        assert(avisoNone.includes('No hay una parada de entrega en su destino'), `Aviso sin entrega inesperado: ${avisoNone}`);
        assert.strictEqual(await submitBtn.isDisabled(), true, 'Sin parada compatible el botón Asignar debe quedar deshabilitado.');
        await page.screenshot({ path: path.join(SHOT_DIR, '06_modal_asignar_sin_parada_1440.png') });

        // Asignación real exitosa con SolAB (pareja única #1 -> #3) y verificación en tabla del viaje!
        await selectTomOption(page, '#asignar-solicitud', fx.solIds.solAB);
        await page.waitForTimeout(150);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
            submitBtn.click(),
        ]);
        const tripHtml = await page.locator('main').innerText();
        assert(tripHtml.includes('#1 · ' + TAG + ' Bodega San José A') && tripHtml.includes('#3 · ' + TAG + ' Centro Heredia B'), 'La asignación real debe persistir y mostrarse en Solicitudes asociadas.');
        console.log('[OK] Cambio C: todos los escenarios (a)-(f), Tom Select reactivo, preselección única, bloqueos y asignación real.');

        // ==================================================================
        // AUDITORÍA MULTI-RESOLUCIÓN (1366, 1440, 1600, 1920, 768, 375)
        // ==================================================================
        const viewports = [
            { name: '1366x768', width: 1366, height: 768, url: '/reportes.php?seccion=solicitudes', wait: '#reportes-resultado .kpi-card' },
            { name: '1600x900', width: 1600, height: 900, url: '/reportes.php?seccion=historico_dw', wait: '[data-dw-source]' },
            { name: '1920x1080', width: 1920, height: 1080, url: '/auditoria.php', wait: '#auditoria-lista table' },
            { name: '768x1024', width: 768, height: 1024, url: '/reportes.php?seccion=entregas', wait: '#reportes-resultado .kpi-card' },
            { name: '375x667', width: 375, height: 667, url: '/auditoria.php', wait: '#auditoria-lista table' },
        ];
        for (const vp of viewports) {
            await page.setViewportSize({ width: vp.width, height: vp.height });
            await page.goto(`${BASE_URL}${vp.url}`, { waitUntil: 'networkidle' });
            await page.waitForSelector(vp.wait, { timeout: 15000 });
            if (vp.width >= 1280) {
                await assertNoDesktopOverflow(page, `${vp.url} ${vp.name}`);
            }
        }
        console.log('[OK] Visores multi-resolución (1366x768, 1440x900, 1600x900, 1920x1080, 768x1024, 375x667) sin overflow desktop.');

        assert.strictEqual(pageErrors.length, 0, `Errores JS en consola: ${pageErrors.join(' | ')}`);
        assert.strictEqual(server500s.length, 0, `Errores HTTP 500: ${server500s.join(' | ')}`);
        console.log('=== PLAYWRIGHT PR #4 FINAL UX (A + B + C): 100% PASS ===');
    } catch (err) {
        console.error('FAIL:', err);
        process.exitCode = 1;
    } finally {
        if (browser) { try { await browser.close(); } catch {} }
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
        if (fx) {
            try {
                phpEval(`
                    $fx = json_decode(${JSON.stringify(JSON.stringify(fx))}, true);
                    $p->prepare('DELETE FROM dbo.viaje_solicitudes WHERE viaje_id = ?')->execute([$fx['viajeId']]);
                    $p->prepare('DELETE FROM dbo.paradas_viaje WHERE viaje_id = ?')->execute([$fx['viajeId']]);
                    $p->prepare('DELETE FROM dbo.viajes WHERE viaje_id = ?')->execute([$fx['viajeId']]);
                    foreach (array_values($fx['solIds']) as $sid) {
                        $p->prepare('DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id = ?')->execute([$sid]);
                        $p->prepare('DELETE FROM dbo.mercancias WHERE solicitud_id = ?')->execute([$sid]);
                        $p->prepare('DELETE FROM dbo.solicitudes WHERE solicitud_id = ?')->execute([$sid]);
                    }
                    $p->prepare('DELETE FROM dbo.rutas WHERE ruta_id = ?')->execute([$fx['rutaId']]);
                    $p->prepare('DELETE FROM dbo.vehiculos WHERE vehiculo_id = ?')->execute([$fx['vehId']]);
                    $p->prepare('DELETE FROM dbo.conductores_licencias WHERE conductor_id = ?')->execute([$fx['condId']]);
                    $p->prepare('DELETE FROM dbo.conductores WHERE conductor_id = ?')->execute([$fx['condId']]);
                    foreach (array_values($fx['uIds']) as $u) {
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
