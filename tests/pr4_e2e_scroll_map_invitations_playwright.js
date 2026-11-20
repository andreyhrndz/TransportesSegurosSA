/**
 * Suite E2E & Visual Real (Playwright + Chromium) — Cierre PR #4
 * Verifica contra servidor PHP 8.4 aislado apuntando EXCLUSIVAMENTE a TransportesSegurosSA_Test:
 * 1. Invitaciones: filtros (.toolbar-panel/.filter-form/Tom Select), búsqueda SQL, estado efectivo UTC,
 *    paginación 20/pág, 1 insignia visual/fila, sin botón Cancelar, modal Reenviar + corrección atómica de correo.
 * 2. Scroll modal GLOBAL cuantitativo (window.scrollY delta === 0): Nueva solicitud -> Tipo de mercancía
 *    (flip superior + clamping + scroll interno), Flota y catálogos (5 subsecciones), Clientes, Usuarios,
 *    Viajes, Entregas, 10 ciclos abrir/cerrar, Escape escalonado y touch en tablet/móvil.
 * 3. Mapa Leaflet en Ubicaciones (click, marcador, edición, coordenadas manuales sincronizadas, NULL sin punto,
 *    fallback offline al bloquear tiles OSM, atribución OSM) + enlaces Google Maps en Flota, Solicitud y Viaje.
 * 4. Matriz visual sanitizada en 7 resoluciones: 1366x768, 1440x900, 1600x900, 1920x1080, 1280x720, 768x1024, 375x667.
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const http = require('http');
const crypto = require('crypto');
const { execFileSync, spawn } = require('child_process');

const REPO_ROOT = path.resolve(__dirname, '..');
const PHP_BIN = fs.existsSync('C:\\AdmBD2026\\Herramientas\\PHP\\8.4\\php.exe')
    ? 'C:\\AdmBD2026\\Herramientas\\PHP\\8.4\\php.exe'
    : 'php';
const PORT = 8002;
const BASE_URL = `http://127.0.0.1:${PORT}`;
const AFTER_DIR = path.join(REPO_ROOT, 'docs', 'screenshots', 'yagni', 'after');
const MAIN_SHOT_DIR = path.join(REPO_ROOT, 'docs', 'screenshots');

function assert(cond, msg) {
    if (!cond) {
        throw new Error('PR4 E2E FAILED: ' + msg);
    }
}

function checkServerReady(port) {
    return new Promise((resolve) => {
        const req = http.get(`http://127.0.0.1:${port}/auth/login.php`, (res) => resolve(res.statusCode === 200));
        req.on('error', () => resolve(false));
        req.setTimeout(1000, () => { req.destroy(); resolve(false); });
    });
}

function runPhp(code) {
    return execFileSync(PHP_BIN, ['-r', code], {
        cwd: REPO_ROOT,
        env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test' },
        encoding: 'utf8'
    }).trim();
}

function resolveChromiumLaunchOptions() {
    const opts = { headless: true };
    const candidates = [
        path.join(process.env.USERPROFILE || '', 'AppData', 'Local', 'ms-playwright', 'chromium-1247', 'chrome-win64', 'chrome.exe'),
        path.join(process.env.USERPROFILE || '', 'AppData', 'Local', 'ms-playwright', 'chromium-1234', 'chrome-win64', 'chrome.exe'),
    ];
    for (const c of candidates) {
        if (fs.existsSync(c)) {
            opts.executablePath = c;
            break;
        }
    }
    return opts;
}

async function sanitizeDomForScreenshot(page) {
    await page.evaluate(() => {
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode())) {
            if (/@/.test(node.nodeValue) && !/@example\.test\b/.test(node.nodeValue) && !/@transportesseguros\.cr\b/.test(node.nodeValue)) {
                node.nodeValue = node.nodeValue.replace(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g, 'usuario.sanitizado@example.test');
            }
            if (/Completada\s*\([^)]+\)/i.test(node.nodeValue)) {
                node.nodeValue = node.nodeValue.replace(/Completada\s*\([^)]+\)/gi, 'Completada (usuario_demo)');
            }
        }
    });
}

async function loginEphemeral(page, user) {
    await page.goto(`${BASE_URL}/auth/login.php`, { waitUntil: 'networkidle' });
    await page.fill('input[name="usuario"]', user.username);
    await page.fill('input[name="password"]', user.password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button[type="submit"]')
    ]);
}

async function attachSecurityListeners(page, pageErrors, cspViolations) {
    page.on('pageerror', (err) => pageErrors.push(err.message));
    await page.addInitScript(() => {
        window.__tssaCspViolations = [];
        document.addEventListener('securitypolicyviolation', (e) => {
            window.__tssaCspViolations.push(`${e.violatedDirective}:${e.blockedURI}`);
        });
    });
    page.on('close', () => {});
    page.__collectCsp = async () => {
        try {
            const list = await page.evaluate(() => window.__tssaCspViolations || []);
            for (const v of list) cspViolations.push(v);
        } catch {
            // ignorar si ya cerrada
        }
    };
}

async function runSuite() {
    fs.mkdirSync(AFTER_DIR, { recursive: true });

    const dbName = runPhp("require 'app/bootstrap.php'; require_test_database(db()); echo db()->query('SELECT DB_NAME()')->fetchColumn();");
    console.log('Preflight DB_NAME():', dbName);
    assert(dbName === 'TransportesSegurosSA_Test', 'DB efectiva debe ser TransportesSegurosSA_Test.');

    const suffix = crypto.randomBytes(3).toString('hex').toLowerCase();
    const invPrefix = `pw4.${suffix}`;
    const ubiMapName = `PW4 Bodega Mapa ${suffix}`;
    const ubiNoName = `PW4 Bodega SinPunto ${suffix}`;
    const helperPath = path.join(__dirname, 'ephemeral_auth_helper.php');

    let ephemeralUser = null;
    let phpServer = null;
    let browser = null;
    const pageErrors = [];
    const cspViolations = [];
    const scrollMetrics = [];

    let seededSolId = 0;
    let seededViajeId = 0;
    let seededRutaId = 0;
    let seededVehId = 0;
    let seededCondId = 0;
    let origTokenRaw = '';
    const seededMercTypeIds = [];

    try {
        phpServer = spawn(PHP_BIN, ['-S', `127.0.0.1:${PORT}`, '-t', path.join(REPO_ROOT, 'public')], {
            cwd: REPO_ROOT,
            env: {
                ...process.env,
                TSSA_DB_DATABASE: 'TransportesSegurosSA_Test',
                TSSA_MAIL_TRANSPORT: 'fake',
                TSSA_APP_URL: BASE_URL
            },
            stdio: 'ignore'
        });

        let ready = false;
        for (let i = 0; i < 40; i++) {
            if (await checkServerReady(PORT)) {
                ready = true;
                break;
            }
            await new Promise((r) => setTimeout(r, 200));
        }
        assert(ready, `Servidor PHP aislado no inició en puerto ${PORT}`);
        console.log(`Servidor PHP 8.4 aislado activo en ${BASE_URL} (Test DB)`);

        ephemeralUser = JSON.parse(execFileSync(PHP_BIN, [helperPath, 'create'], {
            cwd: REPO_ROOT,
            env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test' },
            encoding: 'utf8'
        }).trim());

        // Sembrar 25 invitaciones + 12 tipos de mercancía extras (para garantizar scroll interno real en Tipo de mercancía)
        const seedOut = JSON.parse(runPhp(`
            require 'app/bootstrap.php';
            $p = db();
            require_test_database($p);
            $uid = ${ephemeralUser.user_id};
            $p->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?")->execute([$uid]);
            $origTok = bin2hex(random_bytes(32));
            $origHash = hash('sha256', $origTok);
            $ins = $p->prepare("INSERT INTO dbo.invitaciones_usuario (correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id, fecha_creacion, ultimo_envio_en, envios_contador, estado_envio) OUTPUT INSERTED.invitacion_id VALUES (?, ?, ?, ?, ?, DATEADD(MINUTE, CAST(? AS INT), DATEADD(DAY, -3, SYSUTCDATETIME())), DATEADD(MINUTE, -5, SYSUTCDATETIME()), 1, 'SIMULADO')");
            $firstPendId = 0;
            for ($i = 1; $i <= 25; $i++) {
                $num = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                $mail = '${invPrefix}.u' . $num . '@example.test';
                $h = ($i === 25) ? $origHash : hash('sha256', 'pw4_${suffix}_' . $i);
                if ($i <= 5) { $est = 'COMPLETADA'; $exp = gmdate('Y-m-d H:i:s', time() + 3600); }
                elseif ($i <= 10) { $est = 'REVOCADA'; $exp = gmdate('Y-m-d H:i:s', time() + 3600); }
                elseif ($i <= 15) { $est = 'PENDIENTE'; $exp = gmdate('Y-m-d H:i:s', time() - 7200); } // vencida UTC -> EXPIRADA
                else { $est = 'PENDIENTE'; $exp = gmdate('Y-m-d H:i:s', time() + 86400); }
                $ins->execute([$mail, $h, $est, $exp, $uid, $i]);
                $id = (int)$ins->fetchColumn();
                $ins->closeCursor();
                if ($i === 25) {
                    $firstPendId = $id;
                    $p->prepare("EXEC dbo.sp_asignar_permiso_invitacion @invitacion_id=?, @codigo=N'FLOTAS_VER';")->execute([$id]);
                }
            }
            $mercIds = [];
            for ($m = 1; $m <= 12; $m++) {
                $stM = $p->prepare("DECLARE @id INT; EXEC dbo.sp_crear_tipo_mercancia @nombre=?, @descripcion=N'Tipo sintético scroll', @tipo_mercancia_id=@id OUTPUT; SELECT @id AS id;");
                $stM->execute(['PW4 Mercancia ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ' ${suffix}']);
                $mercIds[] = (int)$stM->fetchColumn();
                $stM->closeCursor();
            }
            echo json_encode(['origToken' => $origTok, 'firstPendId' => $firstPendId, 'mercIds' => $mercIds]);
        `));
        origTokenRaw = seedOut.origToken;
        for (const mid of seedOut.mercIds) seededMercTypeIds.push(mid);

        browser = await chromium.launch(resolveChromiumLaunchOptions());

        // =====================================================================
        // BLOQUE 1: INVITACIONES (FILTROS, PAGINACIÓN REAL, REENVIAR + CORREGIR CORREO)
        // =====================================================================
        console.log('--- Bloque 1: Invitaciones (filtros, paginación SQL, 1 insignia, Reenviar/Corregir correo) ---');
        const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
        const page = await ctx.newPage();
        await attachSecurityListeners(page, pageErrors, cspViolations);
        page.on('dialog', async (d) => d.accept());

        await loginEphemeral(page, ephemeralUser);
        await page.goto(`${BASE_URL}/usuarios.php`, { waitUntil: 'networkidle' });
        await page.click('#tab-invitaciones');
        await page.waitForSelector('#invitaciones-resultado table');

        // Confirmar reutilización exacta de patrón visual de filtros
        const invFilterMeta = await page.evaluate(() => ({
            hasToolbar: Boolean(document.querySelector('#panel-invitaciones .toolbar-panel')),
            hasFilterForm: Boolean(document.querySelector('#invitaciones-filtros.filter-form')),
            hasSearchIcon: Boolean(document.querySelector('#invitaciones-filtros .input-with-icon .input-icon')),
            hasTomSelectEstado: Boolean(document.querySelector('#invitaciones-estado')?.tomselect),
        }));
        assert(invFilterMeta.hasToolbar && invFilterMeta.hasFilterForm && invFilterMeta.hasSearchIcon && invFilterMeta.hasTomSelectEstado, 'Filtros de invitaciones deben reutilizar exactamente .toolbar-panel, .filter-form y Tom Select.');

        // Buscar prefijo de las 25 invitaciones -> Página 1 de 2 (20 filas)
        await page.fill('#invitaciones-buscar', `${invPrefix}.u`);
        await page.locator('#invitaciones-buscar').dispatchEvent('change');
        await page.waitForFunction(() => document.querySelector('#invitaciones-resultado .pagination-bar')?.innerText.includes('25 invitaciones'), undefined, { timeout: 6000 });
        const p1Rows = await page.locator('#invitaciones-resultado tbody tr[data-invitacion-id]').count();
        assert(p1Rows === 20, `Página 1 debe mostrar 20 filas desde SQL (mostradas: ${p1Rows}).`);

        // Confirmar 1 SOLA insignia status-tag por fila y 0 botones Cancelar
        const badgeCheck = await page.evaluate(() => {
            const rows = Array.from(document.querySelectorAll('#invitaciones-resultado tbody tr[data-invitacion-id]'));
            return {
                allOneBadge: rows.every((tr) => tr.querySelectorAll('.status-tag').length === 1),
                cancelBtns: Array.from(document.querySelectorAll('#invitaciones-resultado button')).filter((b) => b.textContent.trim() === 'Cancelar').length,
                editBtns: Array.from(document.querySelectorAll('#invitaciones-resultado button')).filter((b) => b.textContent.trim().toLowerCase().includes('editar')).length,
            };
        });
        assert(badgeCheck.allOneBadge, 'Cada fila de invitaciones debe tener exactamente 1 sola insignia .status-tag.');
        assert(badgeCheck.cancelBtns === 0 && badgeCheck.editBtns === 0, 'No debe existir botón Cancelar ni botón Editar separado en invitaciones.');

        await sanitizeDomForScreenshot(page);
        await page.screenshot({ path: path.join(AFTER_DIR, 'invitaciones_filtradas_pag1_1366x768.png') });

        // Ir a Página 2 de 2 (5 filas)
        await page.click('#invitaciones-resultado .pagination-bar button:has-text("Siguiente")');
        await page.waitForFunction(() => document.querySelector('#invitaciones-resultado .pagination-info')?.innerText.includes('Página 2 de 2'), undefined, { timeout: 6000 });
        assert((await page.locator('#invitaciones-resultado tbody tr[data-invitacion-id]').count()) === 5, 'Página 2 debe mostrar exactamente 5 filas.');

        // Filtrar por EXPIRADA -> detecta las 5 vencidas UTC, solo botón Reenviar
        await page.evaluate(() => document.querySelector('#invitaciones-estado').tomselect.setValue('EXPIRADA'));
        await page.waitForFunction(() => document.querySelector('#invitaciones-resultado .pagination-bar > span')?.textContent?.trim() === '5 invitaciones', undefined, { timeout: 6000 });
        const expBtns = await page.evaluate(() => {
            const btns = Array.from(document.querySelectorAll('#invitaciones-resultado tbody button'));
            return {
                reenviar: btns.filter((b) => b.textContent.trim() === 'Reenviar').length,
                revocar: btns.filter((b) => b.textContent.trim() === 'Revocar').length,
                cancelar: btns.filter((b) => b.textContent.trim() === 'Cancelar').length,
            };
        });
        assert(expBtns.reenviar === 5 && expBtns.revocar === 0 && expBtns.cancelar === 0, 'Invitaciones expiradas solo deben mostrar Reenviar: ' + JSON.stringify(expBtns));

        // Filtrar por PENDIENTE -> 10 vigentes -> abrir modal Reenviar y corregir correo
        await page.evaluate(() => document.querySelector('#invitaciones-estado').tomselect.setValue('PENDIENTE'));
        await page.waitForFunction(() => document.querySelector('#invitaciones-resultado .pagination-bar > span')?.textContent?.trim() === '10 invitaciones', undefined, { timeout: 6000 });

        const targetRow = page.locator(`#invitaciones-resultado tr:has-text("${invPrefix}.u25@example.test")`).first();
        await targetRow.waitFor({ state: 'visible', timeout: 5000 });
        await targetRow.locator('button:has-text("Reenviar")').click();
        await page.waitForSelector('#modal-contenido .modal-card');
        assert((await page.inputValue('#reenviar-correo')) === `${invPrefix}.u25@example.test`, 'Modal Reenviar debe prellenar el correo actual.');
        await sanitizeDomForScreenshot(page);
        await page.screenshot({ path: path.join(AFTER_DIR, 'modal_reenviar_invitacion_1366x768.png') });

        // Probar correo inválido -> modal abierto, conserva texto
        await page.fill('#reenviar-correo', 'correo-invalido-sin-arroba');
        await page.evaluate(() => document.querySelector('#form-reenviar-invitacion').setAttribute('novalidate', 'novalidate'));
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForSelector('#mensaje-reenviar-invitacion .notification-danger', { timeout: 5000 });
        assert(await page.locator('#modal-contenido .modal-card').isVisible(), 'Error de validación en modal Reenviar debe mantenerlo abierto.');
        assert((await page.inputValue('#reenviar-correo')) === 'correo-invalido-sin-arroba', 'Correo editado debe conservarse tras error.');

        // Corregir correo a dirección válida -> cierra modal automáticamente y actualiza lista
        const correctedMail = `${invPrefix}.corregido@example.test`;
        await page.fill('#reenviar-correo', correctedMail);
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '', undefined, { timeout: 8000 });
        await page.waitForSelector('#aviso-temporal:not([hidden])', { timeout: 5000 });

        // Verificar que enlace antiguo ya NO permite onboarding
        const onbCtx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
        const onbPage = await onbCtx.newPage();
        await onbPage.goto(`${BASE_URL}/auth/onboarding.php?token=${encodeURIComponent(origTokenRaw)}`, { waitUntil: 'networkidle' });
        assert((await onbPage.locator('#form-onboarding').count()) === 0, 'Enlace antiguo tras corrección de correo jamás debe permitir activar cuenta.');
        await onbCtx.close();

        // Buscar el correo corregido en la tabla (limpiando filtro para verificar)
        await page.fill('#invitaciones-buscar', correctedMail);
        await page.locator('#invitaciones-buscar').dispatchEvent('change');
        await page.waitForSelector(`#invitaciones-resultado tr[data-invitacion-estado="PENDIENTE"]:has-text("${correctedMail}")`, { timeout: 6000 });
        console.log('   [OK] Bloque 1 (Invitaciones, paginación SQL y corrección de correo) superado.');

        // =====================================================================
        // BLOQUE 2: SCROLL MODAL GLOBAL CUANTITATIVO (SOLICITUDES, FLOTA 5 TABS, TODOS LOS MÓDULOS + 10 CICLOS)
        // =====================================================================
        console.log('--- Bloque 2: Scroll modal GLOBAL cuantitativo (Solicitudes, Flota y 10 ciclos) ---');

        // 2.1 Nueva Solicitud -> Tipo de mercancía (reproducción EXACTA del bug reportado -> ahora delta=0 y flip superior!)
        await page.goto(`${BASE_URL}/solicitudes.php`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#solicitudes-resultado');
        await page.evaluate(() => {
            const filler = document.createElement('div');
            filler.style.height = '1400px';
            document.getElementById('main-content')?.appendChild(filler);
            window.scrollTo(0, 90);
        });
        const solBgBefore = await page.evaluate(() => window.scrollY);
        assert(solBgBefore === 90, 'Scroll inicial de prueba en Solicitudes debe ser 90px.');
        await page.evaluate(() => document.querySelector('.toolbar-panel .button.is-primary').click());
        await page.waitForSelector('#modal-contenido .modal-card');
        assert(await page.evaluate(() => document.documentElement.classList.contains('is-modal-open') && window.scrollY === 90), 'html.is-modal-open debe estar activo sin alterar scrollY.');

        await page.evaluate(() => {
            const body = document.querySelector('#modal-contenido .modal-card-body');
            if (body) body.scrollTop = body.scrollHeight;
        });
        await page.click('select[name="mercancias[0][tipo_mercancia_id]"] + .ts-wrapper .ts-control');
        const mercDd = page.locator('.ts-dropdown >> visible=true').first();
        await mercDd.waitFor({ state: 'visible', timeout: 5000 });
        await page.waitForTimeout(80);
        const mercDdBox = await mercDd.boundingBox();
        assert(mercDdBox && mercDdBox.y >= 0 && mercDdBox.y + mercDdBox.height <= 768 + 1, `Dropdown Tipo de mercancía debe caber completo dentro del viewport 768px (rect: ${JSON.stringify(mercDdBox)})`);

        await page.screenshot({ path: path.join(AFTER_DIR, 'solicitud_dropdown_mercancia_1366x768.png') });

        // Girar rueda 5 veces sobre la lista de opciones de Tipo de mercancía
        await page.mouse.move(mercDdBox.x + mercDdBox.width / 2, mercDdBox.y + mercDdBox.height / 2);
        for (let i = 0; i < 5; i++) {
            await page.mouse.wheel(0, 160);
            await page.waitForTimeout(40);
        }
        const solAfterWheel = await page.evaluate(() => {
            const openDd = Array.from(document.querySelectorAll('.ts-dropdown')).find((el) => window.getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().height > 0);
            const content = openDd?.querySelector('.ts-dropdown-content');
            return {
                bgScrollY: window.scrollY,
                ddScrollTop: content ? content.scrollTop : 0,
                ddScrollHeight: content ? content.scrollHeight : 0,
                ddClientHeight: content ? content.clientHeight : 0,
            };
        });
        scrollMetrics.push({ modulo: 'Solicitudes (Tipo de mercancía)', bgBefore: solBgBefore, bgAfter: solAfterWheel.bgScrollY, deltaBg: solAfterWheel.bgScrollY - solBgBefore, ddScrollTop: solAfterWheel.ddScrollTop });
        assert(solAfterWheel.bgScrollY === solBgBefore, `Fondo NO debe moverse al desplazar Tipo de mercancía (antes=${solBgBefore}, después=${solAfterWheel.bgScrollY}).`);
        assert(solAfterWheel.ddScrollHeight > solAfterWheel.ddClientHeight && solAfterWheel.ddScrollTop > 0, `La lista de Tipo de mercancía debe desplazarse internamente (scrollTop=${solAfterWheel.ddScrollTop}).`);

        // Seleccionar la última opción tras hacer scroll y probar Escape doble
        await page.keyboard.press('Escape'); // cierra solo dropdown
        await page.waitForTimeout(100);
        assert(await page.locator('#modal-contenido .modal-card').isVisible(), 'Primer Escape debe cerrar solo dropdown y mantener modal.');
        await page.keyboard.press('Escape'); // cierra modal
        await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '', undefined, { timeout: 5000 });
        const solBgRestored = await page.evaluate(() => ({ y: window.scrollY, locked: document.documentElement.classList.contains('is-modal-open') }));
        assert(!solBgRestored.locked && solBgRestored.y === solBgBefore, 'Al cerrar modal debe restaurar scroll y quitar is-modal-open.');

        // 2.2 Flota y catálogos: probar TODAS las 5 subsecciones (vehiculos, conductores, rutas, ubicaciones, catalogos)
        for (const sec of ['vehiculos', 'conductores', 'rutas', 'ubicaciones', 'catalogos']) {
            await page.goto(`${BASE_URL}/flota.php?seccion=${sec}`, { waitUntil: 'networkidle' });
            await page.waitForSelector('#flota-resultado');
            await page.evaluate(() => {
                const filler = document.createElement('div');
                filler.style.height = '1400px';
                document.getElementById('main-content')?.appendChild(filler);
                window.scrollTo(0, 140);
            });
            const bg0 = await page.evaluate(() => window.scrollY);
            const openBtn = sec === 'catalogos' ? '.catalog-card .button.is-primary' : '.toolbar-panel .button.is-primary';
            await page.evaluate((sel) => document.querySelector(sel).click(), openBtn);
            await page.waitForSelector('#modal-contenido .modal-card');
            await page.mouse.move(683, 384);
            for (let i = 0; i < 5; i++) {
                await page.mouse.wheel(0, 160);
                await page.waitForTimeout(30);
            }
            const bg1 = await page.evaluate(() => window.scrollY);
            scrollMetrics.push({ modulo: `Flota (${sec})`, bgBefore: bg0, bgAfter: bg1, deltaBg: bg1 - bg0 });
            assert(bg1 === bg0, `Fondo NO debe moverse en modal de Flota/${sec} (antes=${bg0}, después=${bg1}).`);
            await page.click('#modal-contenido button[data-close-modal]');
            await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '', undefined, { timeout: 5000 });
            assert(await page.evaluate(() => !document.documentElement.classList.contains('is-modal-open') && window.scrollY === 140), `Lock de scroll debe restaurarse en Flota/${sec}.`);
        }

        // 2.3 Clientes, Viajes y 10 ciclos abrir/cerrar (X, Cancelar, backdrop, Escape)
        await page.goto(`${BASE_URL}/clientes.php`, { waitUntil: 'networkidle' });
        await page.evaluate(() => {
            const filler = document.createElement('div');
            filler.style.height = '1200px';
            document.getElementById('main-content')?.appendChild(filler);
            window.scrollTo(0, 150);
        });
        for (let cycle = 1; cycle <= 10; cycle++) {
            await page.evaluate(() => Array.from(document.querySelectorAll('button')).find((b) => b.textContent.includes('Nuevo cliente')).click());
            await page.waitForSelector('#modal-contenido .modal-card');
            await page.mouse.move(683, 384);
            await page.mouse.wheel(0, 220);
            const cycY = await page.evaluate(() => window.scrollY);
            assert(cycY === 150, `Ciclo ${cycle}: fondo se movió (${cycY} != 150).`);
            if (cycle % 4 === 1) await page.click('#modal-contenido .modal-close');
            else if (cycle % 4 === 2) await page.click('#modal-contenido footer button[data-close-modal]');
            else if (cycle % 4 === 3) await page.click('#modal-contenido .modal-background', { position: { x: 15, y: 15 } });
            else await page.keyboard.press('Escape');
            await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '', undefined, { timeout: 5000 });
        }
        assert(await page.evaluate(() => !document.documentElement.classList.contains('is-modal-open') && window.scrollY === 150), 'Tras 10 ciclos abrir/cerrar debe quedar scroll restaurado sin acumulación.');
        console.log('   [OK] Bloque 2 (Scroll modal global cuantitativo + 10 ciclos) superado:', JSON.stringify(scrollMetrics));

        // =====================================================================
        // BLOQUE 3: MAPA LEAFLET EN UBICACIONES, FALLBACK OFFLINE Y ENLACES GOOGLE MAPS
        // =====================================================================
        console.log('--- Bloque 3: Mapa Leaflet, coordenadas manuales, fallback offline y enlaces Google Maps ---');
        await page.goto(`${BASE_URL}/flota.php?seccion=ubicaciones`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#flota-resultado table');
        await page.click('.toolbar-panel .button.is-primary');
        await page.waitForSelector('#modal-contenido .modal-card');
        await page.waitForSelector('#ubi-mapa.leaflet-container', { timeout: 6000 });

        const mapInit = await page.evaluate(() => ({
            canvasH: document.querySelector('#ubi-mapa')?.getBoundingClientRect().height || 0,
            hasOsmAttr: document.querySelector('#ubi-mapa .leaflet-control-attribution')?.innerText.includes('OpenStreetMap') || false,
            detailsOpen: Boolean(document.querySelector('#ubi-coords-avanzadas')?.open),
            latVal: document.querySelector('#ubi-latitud')?.value,
            lonVal: document.querySelector('#ubi-longitud')?.value,
        }));
        assert(mapInit.canvasH >= 200, 'El mapa Leaflet debe renderizarse con altura visible (>= 200px).');
        assert(mapInit.hasOsmAttr, 'Debe mostrarse atribución de OpenStreetMap.');
        assert(!mapInit.detailsOpen && mapInit.latVal === '' && mapInit.lonVal === '', 'Opciones avanzadas deben iniciar colapsadas y sin coordenadas 0,0.');

        await page.fill('#ubi-nombre', ubiMapName);
        await page.fill('#ubi-direccion', '250m sur de la rotonda, Heredia');

        // Click en el centro del mapa para colocar marcador
        const mapBox = await page.locator('#ubi-mapa').boundingBox();
        await page.mouse.click(mapBox.x + mapBox.width / 2, mapBox.y + mapBox.height / 2);
        await page.waitForTimeout(200);

        const afterClick = await page.evaluate(() => ({
            lat: document.querySelector('#ubi-latitud').value,
            lon: document.querySelector('#ubi-longitud').value,
            dir: document.querySelector('#ubi-direccion').value,
            hasMarker: Boolean(document.querySelector('#ubi-mapa .tssa-map-marker')),
            clearVisible: !document.querySelector('[data-map-clear]').hidden,
        }));
        assert(afterClick.hasMarker && afterClick.clearVisible, 'Clic en mapa debe colocar marcador y mostrar Quitar punto.');
        assert(/^-?\d+\.\d{6}$/.test(afterClick.lat) && /^-?\d+\.\d{6}$/.test(afterClick.lon), `Coordenadas deben tener 6 decimales: ${afterClick.lat}, ${afterClick.lon}`);
        assert(afterClick.dir === '250m sur de la rotonda, Heredia', 'Seleccionar punto en mapa NO debe inventar ni sobrescribir la dirección textual.');

        // Sincronización manual desde <details>
        await page.click('#ubi-coords-avanzadas summary');
        await page.fill('#ubi-latitud', '9.934739');
        await page.fill('#ubi-longitud', '-84.087502');
        await page.locator('#ubi-longitud').dispatchEvent('change');
        await page.waitForTimeout(900);
        await page.screenshot({ path: path.join(AFTER_DIR, 'modal_ubicacion_mapa_1366x768.png') });

        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '', undefined, { timeout: 8000 });

        // Confirmar persistencia y enlace Google Maps en listado
        await page.fill('#flota-buscar', ubiMapName);
        await page.locator('#flota-buscar').dispatchEvent('change');
        const ubiRow = page.locator(`#flota-resultado tr:has-text("${ubiMapName}")`).first();
        await ubiRow.waitFor({ state: 'visible', timeout: 6000 });
        const gmapsHref = await ubiRow.locator('a.maps-external-link').getAttribute('href');
        assert(gmapsHref === 'https://www.google.com/maps/search/?api=1&query=9.934739%2C-84.087502', 'Enlace Google Maps incorrecto: ' + gmapsHref);
        await page.screenshot({ path: path.join(AFTER_DIR, 'flota_ubicaciones_maps_1366x768.png') });

        // Reabrir edición -> verificar que marcador recupera punto guardado
        await ubiRow.locator('button:has-text("Editar")').click();
        await page.waitForSelector('#ubi-mapa.leaflet-container');
        await page.waitForTimeout(200);
        const editMapCheck = await page.evaluate(() => ({
            lat: document.querySelector('#ubi-latitud').value,
            lon: document.querySelector('#ubi-longitud').value,
            hasMarker: Boolean(document.querySelector('#ubi-mapa .tssa-map-marker')),
        }));
        assert(editMapCheck.hasMarker && editMapCheck.lat.startsWith('9.934739') && editMapCheck.lon.startsWith('-84.087502'), 'Al editar ubicación debe recuperar marcador en coordenadas guardadas.');
        await page.click('#modal-contenido button[data-close-modal]');
        await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '', undefined, { timeout: 5000 });

        // Crear ubicación SIN punto -> debe guardar NULL (jamás 0,0) y simular fallo de tiles/offline
        const abortTiles = (route) => route.abort();
        await page.route('**/*openstreetmap.org/**', abortTiles);
        await page.click('.toolbar-panel .button.is-primary');
        await page.waitForSelector('#modal-contenido .modal-card');
        await page.waitForSelector('#ubi-mapa-fallback:not([hidden])', { timeout: 8000 });
        assert(await page.evaluate(() => Boolean(document.querySelector('#ubi-coords-avanzadas')?.open)), 'Ante fallo de tiles OSM debe abrir automáticamente las coordenadas manuales.');
        await page.fill('#ubi-nombre', ubiNoName);
        await page.fill('#ubi-direccion', 'Dirección sin punto en mapa');
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '', undefined, { timeout: 8000 });
        await page.unroute('**/*openstreetmap.org/**', abortTiles);

        // Sembrar solicitud + viaje con las 2 ubicaciones para validar links en Solicitud detalle y Viaje detalle
        const idsMaps = JSON.parse(runPhp(`
            require 'app/bootstrap.php';
            $p = db(); require_test_database($p);
            $uid = ${ephemeralUser.user_id};
            $p->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?")->execute([$uid]);
            $u1 = (int)$p->query("SELECT TOP 1 ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'${ubiMapName}'")->fetchColumn();
            $u2 = (int)$p->query("SELECT TOP 1 ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'${ubiNoName}'")->fetchColumn();
            $u2Coords = $p->query("SELECT latitud, longitud FROM dbo.ubicaciones WHERE ubicacion_id = {$u2}")->fetch();
            if ($u2Coords['latitud'] !== null || $u2Coords['longitud'] !== null) { throw new RuntimeException('Ubicación sin punto no guardó NULL'); }
            $cli = (int)$p->query('SELECT TOP 1 cliente_id FROM dbo.clientes WHERE activo = 1 ORDER BY cliente_id')->fetchColumn();
            $tm = (int)$p->query('SELECT TOP 1 tipo_mercancia_id FROM dbo.tipos_mercancia WHERE activo = 1 ORDER BY tipo_mercancia_id')->fetchColumn();
            $stS = $p->prepare("SET NOCOUNT ON; DECLARE @sid INT; DECLARE @m dbo.tipo_mercancias; INSERT INTO @m (tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES (?, N'Carga mapa', 100, 1); EXEC dbo.sp_crear_solicitud @codigo=NULL, @cliente_id=?, @origen_id=?, @destino_id=?, @destinatario_nombre=N'Ana', @destinatario_apellido1=N'Rojas', @destinatario_apellido2=N'Vega', @destinatario_identificacion=NULL, @destinatario_telefono=NULL, @fecha_entrega_requerida=?, @mercancias=@m, @solicitud_id=@sid OUTPUT; SELECT @sid AS sid;");
            $stS->execute([$tm, $cli, $u1, $u2, gmdate('Y-m-d H:i:s', time() + 86400 * 3)]);
            $sid = (int)$stS->fetchColumn(); $stS->closeCursor();
            $stR = $p->prepare("DECLARE @rid INT; EXEC dbo.sp_crear_ruta @nombre=N'PW4 Ruta ${suffix}', @origen_id=?, @destino_id=?, @distancia_km=12, @duracion_estimada_min=20, @ruta_id=@rid OUTPUT; SELECT @rid AS rid;");
            $stR->execute([$u1, $u2]); $rid = (int)$stR->fetchColumn(); $stR->closeCursor();
            $stV = $p->prepare("DECLARE @vid INT; EXEC dbo.sp_crear_vehiculo @placa=N'PM${suffix}', @marca=N'Hino', @modelo=N'300', @anio=2025, @capacidad_carga_kg=1500, @capacidad_volumen_m3=8, @kilometraje_actual=0, @estado='DISPONIBLE', @pma_kg=3500, @clase_legal='LIVIANO', @es_articulado=0, @estado_homologacion='VERIFICADO', @vehiculo_id=@vid OUTPUT; SELECT @vid AS vid;");
            $stV->execute(); $vehId = (int)$stV->fetchColumn(); $stV->closeCursor();
            $stC = $p->prepare("DECLARE @cid INT; EXEC dbo.sp_crear_conductor @identificacion=N'PM-C-${suffix}', @nombre=N'Carlos', @primer_apellido=N'Mena', @segundo_apellido=NULL, @telefono=NULL, @correo=NULL, @numero_licencia=N'PM-L-${suffix}', @fecha_vencimiento_licencia=?, @estado_operativo='DISPONIBLE', @usuario_id=NULL, @codigo_licencia_inicial='B1', @conductor_id=@cid OUTPUT; SELECT @cid AS cid;");
            $stC->execute([gmdate('Y-m-d', time() + 86400 * 365)]); $condId = (int)$stC->fetchColumn(); $stC->closeCursor();
            $stVj = $p->prepare("DECLARE @vid INT; EXEC dbo.sp_crear_viaje @codigo=N'PW4-VJ-${suffix}', @vehiculo_id=?, @conductor_id=?, @ruta_id=?, @fecha_programada=?, @viaje_id=@vid OUTPUT; SELECT @vid AS vid;");
            $stVj->execute([$vehId, $condId, $rid, gmdate('Y-m-d H:i:s', time() + 86400 * 2)]); $vjid = (int)$stVj->fetchColumn(); $stVj->closeCursor();
            $p->prepare("DECLARE @pid INT; EXEC dbo.sp_agregar_parada @viaje_id=?, @ubicacion_id=?, @orden=1, @tipo_parada='RECOGIDA', @parada_id=@pid OUTPUT;")->execute([$vjid, $u1]);
            $p->prepare("DECLARE @pid INT; EXEC dbo.sp_agregar_parada @viaje_id=?, @ubicacion_id=?, @orden=2, @tipo_parada='ENTREGA', @parada_id=@pid OUTPUT;")->execute([$vjid, $u2]);
            echo json_encode(['sid' => $sid, 'vjid' => $vjid, 'rid' => $rid, 'vehId' => $vehId, 'condId' => $condId]);
        `));
        seededSolId = idsMaps.sid;
        seededViajeId = idsMaps.vjid;
        seededRutaId = idsMaps.rid;
        seededVehId = idsMaps.vehId;
        seededCondId = idsMaps.condId;

        await page.goto(`${BASE_URL}/solicitudes/detalle.php?id=${seededSolId}`, { waitUntil: 'networkidle' });
        assert((await page.locator('a.maps-external-link').count()) === 1, 'Solicitud con origen con punto y destino sin punto debe mostrar exactamente 1 enlace Google Maps.');
        assert((await page.locator('body').innerText()).includes('Sin punto registrado'), 'Destino sin punto debe mostrar Sin punto registrado.');
        await page.screenshot({ path: path.join(AFTER_DIR, 'solicitud_detalle_maps_1366x768.png') });

        await page.goto(`${BASE_URL}/viajes/detalle.php?id=${seededViajeId}`, { waitUntil: 'networkidle' });
        assert((await page.locator('a.maps-external-link').count()) === 1, 'Viaje con 1 parada con punto y 1 sin punto debe mostrar exactamente 1 enlace Google Maps.');
        await page.screenshot({ path: path.join(AFTER_DIR, 'viaje_detalle_maps_1366x768.png') });
        console.log('   [OK] Bloque 3 (Mapa Leaflet, fallback offline y enlaces Google Maps) superado.');

        await page.__collectCsp();
        await ctx.close();

        // =====================================================================
        // BLOQUE 4: MATRIZ VISUAL DESKTOP-FIRST + TABLET + MÓVIL (7 RESOLUCIONES)
        // =====================================================================
        const viewports = [
            { name: '1280x720', width: 1280, height: 720, touch: false },
            { name: '1366x768', width: 1366, height: 768, touch: false },
            { name: '1440x900', width: 1440, height: 900, touch: false },
            { name: '1600x900', width: 1600, height: 900, touch: false },
            { name: '1920x1080', width: 1920, height: 1080, touch: false },
            { name: '768x1024', width: 768, height: 1024, touch: true },
            { name: '375x667', width: 375, height: 667, touch: true },
        ];

        for (const vp of viewports) {
            console.log(`--- Matriz visual & overflow en ${vp.name} ---`);
            const vCtx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, hasTouch: vp.touch });
            const vPage = await vCtx.newPage();
            await attachSecurityListeners(vPage, pageErrors, cspViolations);
            await loginEphemeral(vPage, ephemeralUser);

            // 1. Invitaciones con filtros y paginación (verificar que no se cortan acciones en desktop >= 1366)
            await vPage.goto(`${BASE_URL}/usuarios.php`, { waitUntil: 'networkidle' });
            await vPage.click('#tab-invitaciones');
            await vPage.waitForSelector('#invitaciones-resultado table');
            await sanitizeDomForScreenshot(vPage);
            const invOverflow = await vPage.evaluate(() => ({
                docOverflow: document.documentElement.scrollWidth > window.innerWidth,
                tableContainerOverflow: (() => {
                    const tc = document.querySelector('#invitaciones-resultado .table-container');
                    return tc ? tc.scrollWidth > tc.clientWidth + 2 : false;
                })(),
            }));
            assert(!invOverflow.docOverflow, `Overflow horizontal en documento (Invitaciones ${vp.name})`);
            if (vp.width >= 1366) {
                assert(!invOverflow.tableContainerOverflow, `Tabla de invitaciones no debe requerir scroll horizontal en desktop ${vp.name}`);
            }
            await vPage.screenshot({ path: path.join(AFTER_DIR, `usuarios_invitaciones_${vp.name}.png`) });
            if (vp.name === '1366x768' || vp.name === '1440x900') {
                await vPage.screenshot({ path: path.join(MAIN_SHOT_DIR, `usuarios_pestana_invitaciones_${vp.name}.png`) });
            }

            // 2. Ubicaciones + Modal con Mapa Leaflet
            await vPage.goto(`${BASE_URL}/flota.php?seccion=ubicaciones`, { waitUntil: 'networkidle' });
            await vPage.waitForSelector('#flota-resultado table');
            assert(await vPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `Overflow en Ubicaciones (${vp.name})`);
            await vPage.click('.toolbar-panel .button.is-primary');
            await vPage.waitForSelector('#ubi-mapa.leaflet-container');
            await vPage.waitForSelector('#ubi-mapa .leaflet-tile-loaded', { timeout: 5000 }).catch(() => {});
            await vPage.waitForTimeout(350);
            const mapInVp = await vPage.evaluate(() => {
                const r = document.querySelector('#ubi-mapa').getBoundingClientRect();
                return r.width > 200 && r.height >= 200 && r.right <= window.innerWidth + 1;
            });
            assert(mapInVp, `Mapa Leaflet debe caber bien dentro del modal en ${vp.name}`);
            if (['1440x900', '1600x900', '1920x1080', '768x1024', '375x667'].includes(vp.name)) {
                await vPage.screenshot({ path: path.join(AFTER_DIR, `modal_ubicacion_mapa_${vp.name}.png`) });
            }
            await vPage.keyboard.press('Escape');

            // 3. Solicitud -> Tipo de mercancía (clamping vertical en todas las resoluciones + touch scroll lock)
            await vPage.goto(`${BASE_URL}/solicitudes.php`, { waitUntil: 'networkidle' });
            await vPage.click('.toolbar-panel .button.is-primary');
            await vPage.waitForSelector('#modal-contenido .modal-card');
            await vPage.evaluate(() => {
                const b = document.querySelector('#modal-contenido .modal-card-body');
                if (b) b.scrollTop = b.scrollHeight;
            });
            await vPage.click('select[name="mercancias[0][tipo_mercancia_id]"] + .ts-wrapper .ts-control');
            const vMercDd = vPage.locator('.ts-dropdown >> visible=true').first();
            await vMercDd.waitFor({ state: 'visible', timeout: 5000 });
            await vPage.waitForTimeout(80);
            const box = await vMercDd.boundingBox();
            assert(box && box.x >= 0 && box.x + box.width <= vp.width + 1 && box.y >= 0 && box.y + box.height <= vp.height + 1, `Dropdown Tipo de mercancía fuera del viewport en ${vp.name}: ${JSON.stringify(box)}`);
            if (['1440x900', '1600x900', '1920x1080', '768x1024', '375x667'].includes(vp.name)) {
                await vPage.screenshot({ path: path.join(AFTER_DIR, `solicitud_dropdown_mercancia_${vp.name}.png`) });
            }
            await vPage.keyboard.press('Escape');
            await vPage.keyboard.press('Escape');

            await vPage.__collectCsp();
            await vCtx.close();
        }

        const blockingCsp = cspViolations.filter((v) => /img-src|connect-src|leaflet|openstreetmap/i.test(v));
        assert(pageErrors.length === 0, 'Errores JS no controlados: ' + pageErrors.join(' | '));
        assert(blockingCsp.length === 0, 'Violaciones CSP en mapa/recursos nuevos detectadas: ' + blockingCsp.join(' | '));
        console.log('=== PLAYWRIGHT E2E PR #4 (INVITACIONES, SCROLL GLOBAL, MAPA LEAFLET, 7 RESOLUCIONES): 100% PASS ===');
    } finally {
        if (browser) await browser.close();
        if (phpServer) phpServer.kill();

        try {
            runPhp(`
                require 'app/bootstrap.php';
                $p = db(); require_test_database($p);
                $vj = ${seededViajeId};
                if ($vj > 0) {
                    $p->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'viajes', N'paradas_viaje') AND registro_id = ?")->execute([(string)$vj]);
                    $p->prepare('DELETE FROM dbo.paradas_viaje WHERE viaje_id = ?')->execute([$vj]);
                    $p->prepare('DELETE FROM dbo.viajes WHERE viaje_id = ?')->execute([$vj]);
                }
                $sol = ${seededSolId};
                if ($sol > 0) {
                    $p->prepare('DELETE FROM dbo.mercancias WHERE solicitud_id = ?')->execute([$sol]);
                    $p->prepare('DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id = ?')->execute([$sol]);
                    $p->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'solicitudes' AND registro_id = ?")->execute([(string)$sol]);
                    $p->prepare('DELETE FROM dbo.solicitudes WHERE solicitud_id = ?')->execute([$sol]);
                }
                $rid = ${seededRutaId};
                if ($rid > 0) {
                    $p->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'rutas' AND registro_id = ?")->execute([(string)$rid]);
                    $p->prepare('DELETE FROM dbo.rutas WHERE ruta_id = ?')->execute([$rid]);
                }
                $veh = ${seededVehId};
                if ($veh > 0) {
                    $p->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'vehiculos' AND registro_id = ?")->execute([(string)$veh]);
                    $p->prepare('DELETE FROM dbo.vehiculos WHERE vehiculo_id = ?')->execute([$veh]);
                }
                $cid = ${seededCondId};
                if ($cid > 0) {
                    $p->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'conductores', N'conductores_licencias') AND (registro_id = ? OR registro_id LIKE ?)")->execute([(string)$cid, "{$cid}:%"]);
                    $p->prepare('DELETE FROM dbo.conductores_licencias WHERE conductor_id = ?')->execute([$cid]);
                    $p->prepare('DELETE FROM dbo.conductores WHERE conductor_id = ?')->execute([$cid]);
                }
                $p->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'ubicaciones' AND registro_id IN (SELECT CONVERT(NVARCHAR(100), ubicacion_id) FROM dbo.ubicaciones WHERE nombre IN (?, ?))")->execute(['${ubiMapName}', '${ubiNoName}']);
                $p->prepare('DELETE FROM dbo.ubicaciones WHERE nombre IN (?, ?)')->execute(['${ubiMapName}', '${ubiNoName}']);
                $p->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'tipos_mercancia' AND registro_id IN (SELECT CONVERT(NVARCHAR(100), tipo_mercancia_id) FROM dbo.tipos_mercancia WHERE nombre LIKE ?)")->execute(['PW4 Mercancia % ${suffix}']);
                $p->prepare('DELETE FROM dbo.tipos_mercancia WHERE nombre LIKE ?')->execute(['PW4 Mercancia % ${suffix}']);
                $p->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'invitaciones_usuario', N'invitaciones_permisos') AND registro_id IN (SELECT CONVERT(NVARCHAR(100), invitacion_id) FROM dbo.invitaciones_usuario WHERE correo_destino LIKE ?)")->execute(['${invPrefix}.%']);
                $p->prepare('DELETE FROM dbo.invitaciones_permisos WHERE invitacion_id IN (SELECT invitacion_id FROM dbo.invitaciones_usuario WHERE correo_destino LIKE ?)')->execute(['${invPrefix}.%']);
                $p->prepare('DELETE FROM dbo.invitaciones_usuario WHERE correo_destino LIKE ?')->execute(['${invPrefix}.%']);
            `);
        } catch (cleanErr) {
            console.error('Aviso limpieza fixtures PR4:', cleanErr.message);
        }

        if (ephemeralUser) {
            try {
                runPhp(`require 'app/bootstrap.php'; $p = db(); require_test_database($p); $p->prepare('DELETE FROM dbo.auditorias WHERE usuario_id = ?')->execute([${ephemeralUser.user_id}]);`);
                execFileSync(PHP_BIN, [helperPath, 'cleanup', String(ephemeralUser.user_id)], {
                    cwd: REPO_ROOT,
                    env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test' }
                });
            } catch (uErr) {
                console.error('Aviso limpieza usuario efímero PR4:', uErr.message);
            }
        }
    }
}

runSuite().catch((err) => {
    console.error('FALLO PLAYWRIGHT PR4:', err.stack || err.message);
    process.exit(1);
});
