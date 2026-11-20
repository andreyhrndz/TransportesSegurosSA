/**
 * Suite Playwright real (Chromium): Ubicaciones híbridas en Nueva solicitud,
 * scroll global en modales/Tom Select/Leaflet, jerarquía Escape, fallback offline,
 * manejo de errores 422/500, conservación de estado y verificación visual multi-resolución.
 *
 * Opera EXCLUSIVAMENTE contra TransportesSegurosSA_Test en servidor PHP aislado.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');
const { spawn, execFileSync } = require('child_process');
const { chromium } = require('playwright');

const PHP_BIN = process.env.PHP_BIN || 'C:\\AdmBD2026\\Herramientas\\PHP\\8.4\\php.exe';
const CHROMIUM_EXEC = process.env.CHROMIUM_EXEC || 'C:\\Users\\cuent\\AppData\\Local\\ms-playwright\\chromium-1247\\chrome-win64\\chrome.exe';
const ROOT = path.resolve(__dirname, '..');
const AFTER_DIR = path.join(ROOT, 'docs', 'screenshots', 'yagni', 'after');
const PORT = 8021;
const BASE_URL = `http://127.0.0.1:${PORT}`;
const SUFFIX = Math.random().toString(36).slice(2, 8);
const ADMIN_USER = `__pw_hyb_${SUFFIX}`;
const ADMIN_PASS = `ClaveSeguraHyb!${SUFFIX}99`;

fs.mkdirSync(AFTER_DIR, { recursive: true });

function phpEval(code) {
    const wrapped = `putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test'); require '${ROOT.replace(/\\/g, '/')}/app/bootstrap.php'; $p = db(); require_test_database($p); ${code}`;
    return execFileSync(PHP_BIN, ['-r', wrapped], {
        cwd: ROOT,
        env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test' },
        encoding: 'utf8',
        timeout: 20000,
    }).trim();
}

function startPhpServer() {
    const logPath = path.join(require('os').tmpdir(), `tssa-pw-hyb-${SUFFIX}.log`);
    const outFd = fs.openSync(logPath, 'a');
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
        await new Promise((r) => setTimeout(r, 200));
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

(async () => {
    let srv = null;
    let browser = null;
    let adminUid = 0;
    const createdSolicitudIds = [];
    const createdUbicacionIds = [];
    const pageErrors = [];
    const cspViolations = [];

    try {
        // 1. Preflight DB_NAME() estricto
        const dbName = phpEval(`echo (string)$p->query('SELECT DB_NAME()')->fetchColumn();`);
        assert.strictEqual(dbName, 'TransportesSegurosSA_Test', `DB efectiva inesperada: ${dbName}`);
        console.log(`Preflight DB_NAME() verificado: ${dbName}`);

        // 2. Crear usuario efímero con todos los permisos de prueba
        adminUid = Number(phpEval(`
            $h = password_hash('${ADMIN_PASS}', PASSWORD_DEFAULT);
            $st = $p->prepare("INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) VALUES (?, ?, ?, 1); SELECT CAST(SCOPE_IDENTITY() AS INT);");
            $st->execute(['${ADMIN_USER}', '${ADMIN_USER}@example.test', $h]);
            $st->nextRowset();
            $uid = (int)$st->fetchColumn();
            $p->prepare("INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos")->execute([$uid]);
            echo $uid;
        `));
        assert(adminUid > 0, 'No se pudo crear usuario efímero Playwright.');

        // 3. Levantar servidor PHP aislado contra Test
        srv = startPhpServer();
        await waitForServer(`${BASE_URL}/auth/login.php`);

        browser = await chromium.launch({
            executablePath: fs.existsSync(CHROMIUM_EXEC) ? CHROMIUM_EXEC : undefined,
            headless: true,
        });

        const attachErrorCollectors = (page) => {
            page.on('pageerror', (err) => pageErrors.push(String(err)));
            page.on('console', (msg) => {
                if (msg.type() === 'error') {
                    const txt = msg.text();
                    if (txt.includes('Content Security Policy') && (txt.includes('openstreetmap') || txt.includes('leaflet'))) {
                        cspViolations.push(txt);
                    }
                }
            });
        };

        // =====================================================================
        // BLOQUE 1 (1366×768): FLUJOS COMPLETOS DE UBICACIONES HÍBRIDAS + SCROLL
        // =====================================================================
        const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
        const page = await ctx.newPage();
        attachErrorCollectors(page);
        await login(page);

        await page.goto(`${BASE_URL}/solicitudes.php`, { waitUntil: 'networkidle' });

        // Forzar scroll en página de fondo antes de abrir modal para medir bloqueo real de scroll
        await page.evaluate(() => {
            document.body.style.minHeight = '2400px';
            window.scrollTo(0, 140);
        });
        const bgBefore = await page.evaluate(() => Math.round(window.scrollY));
        assert(bgBefore >= 120, `La página de fondo debe tener scroll inicial (actual ${bgBefore}).`);

        // Abrir Nueva solicitud
        await page.click('button[hx-get="/solicitudes/form.php"]');
        await page.waitForSelector('#solicitud-cliente', { timeout: 10000 });
        await page.waitForSelector('.hybrid-location-card[data-location-slot="origen"]', { timeout: 8000 });
        await page.waitForTimeout(250);

        // Captura 1: Comboboxes existentes + botones Elegir en el mapa
        await page.screenshot({ path: path.join(AFTER_DIR, 'solicitud_hibrida_combobox_1366x768.png') });

        // Verificar scroll lock en Tipo de mercancía (Tom Select) y en combobox de origen
        const scrollCheck = await page.evaluate(async () => {
            const modalBody = document.querySelector('.modal-card-body');
            modalBody.scrollTop = modalBody.scrollHeight;
            const mercSel = document.querySelector('select[name="mercancias[0][tipo_mercancia_id]"]');
            mercSel.tomselect.open();
            await new Promise((r) => setTimeout(r, 120));
            const dd = mercSel.tomselect.dropdown;
            const content = mercSel.tomselect.dropdown_content;
            const ddRect = dd.getBoundingClientRect();
            return {
                bgAfterOpen: Math.round(window.scrollY),
                ddVisible: ddRect.height > 20,
                ddTop: Math.round(ddRect.top),
                ddBottom: Math.round(ddRect.bottom),
                contentScrollHeight: content.scrollHeight,
                contentClientHeight: content.clientHeight,
                centerX: Math.round(ddRect.left + ddRect.width / 2),
                centerY: Math.round(ddRect.top + ddRect.height / 2),
            };
        });
        assert(scrollCheck.ddVisible, 'Dropdown Tipo de mercancía debe estar visible.');
        assert(scrollCheck.ddBottom <= 768 && scrollCheck.ddTop >= 0, `Dropdown Tipo de mercancía fuera de viewport: top=${scrollCheck.ddTop}, bottom=${scrollCheck.ddBottom}`);

        await page.mouse.move(scrollCheck.centerX, scrollCheck.centerY);
        await page.mouse.wheel(0, 600);
        await page.waitForTimeout(150);
        const afterWheel = await page.evaluate(() => {
            const mercSel = document.querySelector('select[name="mercancias[0][tipo_mercancia_id]"]');
            const content = mercSel.tomselect.dropdown_content;
            const st = Math.round(content.scrollTop);
            mercSel.tomselect.close();
            return { bgAfterWheel: Math.round(window.scrollY), ddScrollTop: st };
        });
        assert.strictEqual(afterWheel.bgAfterWheel, bgBefore, `Scroll de fondo se movió al girar rueda sobre Tipo de mercancía (${bgBefore} -> ${afterWheel.bgAfterWheel}).`);
        console.log(`   [OK] Scroll lock Solicitud/Tipo mercancía: bgBefore=${bgBefore}, bgAfter=${afterWheel.bgAfterWheel}, delta=0, ddScrollTop=${afterWheel.ddScrollTop}`);

        // Llenar cliente, fecha y mercancía ANTES de abrir mapas (para probar conservación de estado!)
        const seededData = await page.evaluate(() => {
            const cliSel = document.getElementById('solicitud-cliente');
            const cliOpt = Array.from(cliSel.options).find((o) => o.value && o.dataset.tipo === 'FISICA')
                || Array.from(cliSel.options).find((o) => o.value);
            cliSel.tomselect.setValue(cliOpt.value);

            const fechaInp = document.getElementById('solicitud-fecha');
            const futureDate = new Date(Date.now() + 72 * 3600 * 1000);
            const pad = (n) => String(n).padStart(2, '0');
            const iso = `${futureDate.getUTCFullYear()}-${pad(futureDate.getUTCMonth() + 1)}-${pad(futureDate.getUTCDate())}T14:30`;
            if (fechaInp._flatpickr) {
                fechaInp._flatpickr.setDate(iso, true);
            } else {
                fechaInp.value = iso;
            }

            const mercSel = document.querySelector('select[name="mercancias[0][tipo_mercancia_id]"]');
            const firstMerc = Array.from(mercSel.options).find((o) => o.value);
            mercSel.tomselect.setValue(firstMerc.value);
            document.querySelector('input[name="mercancias[0][descripcion]"]').value = 'Equipos de precisión prueba híbrida';
            document.querySelector('input[name="mercancias[0][peso_kg]"]').value = '180';
            document.querySelector('input[name="mercancias[0][volumen_m3]"]').value = '1.8';

            return { clienteId: cliOpt.value, tipoCli: cliOpt.dataset.tipo || 'FISICA', tipoMerc: firstMerc.value };
        });
        if (seededData.tipoCli === 'JURIDICA') {
            await page.waitForTimeout(200);
            await page.fill('#destinatario-nombre', 'Carlos');
            await page.fill('#destinatario-apellido1', 'Vega');
        }

        // Volver arriba del modal y abrir mapa para ORIGEN
        await page.evaluate(() => { document.querySelector('.modal-card-body').scrollTop = 0; });
        await page.click('.hybrid-location-card[data-location-slot="origen"] [data-open-slot-map="origen"]');
        await page.waitForSelector('#solicitud-mapa-panel:not([hidden])', { timeout: 8000 });
        await page.waitForSelector('#solicitud-mapa-canvas.leaflet-container', { timeout: 8000 });
        await page.waitForTimeout(500);

        // Probar rueda sobre el mapa ANTES de hacer clic (no debe hacer zoom involuntario ni mover el fondo!)
        const canvasBox = await page.locator('#solicitud-mapa-canvas').boundingBox();
        assert(canvasBox && canvasBox.width > 200 && canvasBox.height >= 200, 'Canvas de mapa en Nueva solicitud tiene tamaño insuficiente.');
        const zoomBeforeWheel = await page.evaluate(() => document.getElementById('solicitud-mapa-shell')._tssaMapInstance.getZoom());
        await page.mouse.move(canvasBox.x + canvasBox.width / 2, canvasBox.y + canvasBox.height / 2);
        await page.mouse.wheel(0, -300);
        await page.waitForTimeout(200);
        const afterMapWheel = await page.evaluate(() => ({
            bgY: Math.round(window.scrollY),
            zoom: document.getElementById('solicitud-mapa-shell')._tssaMapInstance.getZoom(),
        }));
        assert.strictEqual(afterMapWheel.bgY, bgBefore, 'Scroll de fondo se movió al usar rueda sobre el panel mapa.');
        assert.strictEqual(afterMapWheel.zoom, zoomBeforeWheel, 'El mapa no debe hacer zoom involuntario antes de activarlo con clic.');

        // Hacer clic en el mapa para marcar ORIGEN
        await page.mouse.click(canvasBox.x + canvasBox.width * 0.42, canvasBox.y + canvasBox.height * 0.48);
        await page.waitForTimeout(250);
        const origLat = await page.inputValue('#solicitud-mapa-lat');
        const origLon = await page.inputValue('#solicitud-mapa-lon');
        assert(/^-?\d+\.\d{6}$/.test(origLat) && /^-?\d+\.\d{6}$/.test(origLon), `Coordenadas de origen inválidas: ${origLat}, ${origLon}`);

        // Probar error inline dentro del panel mapa (confirmar sin referencia humana)
        await page.fill('#solicitud-mapa-referencia', '');
        await page.click('#btn-confirmar-punto-mapa');
        await page.waitForSelector('#solicitud-mapa-error:not([hidden])', { timeout: 4000 });
        await page.screenshot({ path: path.join(AFTER_DIR, 'solicitud_hibrida_error_inline_1366x768.png') });

        // Completar referencia humana (sin marcar frecuente -> puntual!)
        const origRef = `Bodega norte portón 2 (${SUFFIX})`;
        const origAlias = `Portón Norte ${SUFFIX}`;
        await page.fill('#solicitud-mapa-referencia', origRef);
        await page.fill('#solicitud-mapa-nombre', origAlias);
        assert.strictEqual(await page.isChecked('#solicitud-mapa-frecuente'), false, 'Guardar en ubicaciones frecuentes debe iniciar DESACTIVADO.');
        await page.evaluate(() => { document.getElementById('solicitud-mapa-panel')?.scrollIntoView({ behavior: 'instant', block: 'start' }); });
        await page.waitForTimeout(350);
        await page.screenshot({ path: path.join(AFTER_DIR, 'solicitud_hibrida_mapa_origen_1366x768.png') });

        // Probar Escape: cierra SOLO el panel del mapa, NO el modal principal!
        await page.keyboard.press('Escape');
        await page.waitForTimeout(150);
        assert.strictEqual(await page.locator('#solicitud-mapa-panel').isHidden(), true, 'Escape debe cerrar primero el panel de mapa.');
        assert.strictEqual(await page.locator('#solicitud-cliente').isVisible(), true, 'Escape con mapa abierto NO debe cerrar el modal principal.');

        // Reabrir mapa de origen, confirmar punto y verificar que cliente/mercancías siguen intactos
        await page.click('.hybrid-location-card[data-location-slot="origen"] [data-open-slot-map="origen"]');
        await page.waitForSelector('#solicitud-mapa-panel:not([hidden])', { timeout: 6000 });
        await page.waitForTimeout(250);
        const box2 = await page.locator('#solicitud-mapa-canvas').boundingBox();
        await page.mouse.click(box2.x + box2.width * 0.42, box2.y + box2.height * 0.48);
        await page.fill('#solicitud-mapa-referencia', origRef);
        await page.fill('#solicitud-mapa-nombre', origAlias);
        await page.click('#btn-confirmar-punto-mapa');
        await page.waitForSelector('.hybrid-location-card[data-location-slot="origen"] [data-slot-map-summary]:not([hidden])', { timeout: 5000 });

        // Confirmar que cliente y mercancía se conservaron intactos
        const preservedState = await page.evaluate(() => ({
            cliente: document.getElementById('solicitud-cliente').value,
            desc: document.querySelector('input[name="mercancias[0][descripcion]"]').value,
            peso: document.querySelector('input[name="mercancias[0][peso_kg]"]').value,
            origSelectDisabled: document.getElementById('solicitud-origen').disabled,
            origModo: document.querySelector('input[name="origen_modo"]').value,
        }));
        assert.strictEqual(preservedState.cliente, seededData.clienteId, 'Se perdió el cliente seleccionado.');
        assert.strictEqual(preservedState.desc, 'Equipos de precisión prueba híbrida', 'Se perdió la descripción de mercancía.');
        assert.strictEqual(preservedState.origSelectDisabled, true, 'Select oculto de origen debe quedar deshabilitado en modo mapa.');
        assert.strictEqual(preservedState.origModo, 'mapa', 'origen_modo debe ser mapa.');

        // Ahora abrir mapa para DESTINO (verificar que no borra origen y que el checkbox frecuente sí funciona)
        await page.click('.hybrid-location-card[data-location-slot="destino"] [data-open-slot-map="destino"]');
        await page.waitForSelector('#solicitud-mapa-panel:not([hidden])', { timeout: 6000 });
        await page.waitForTimeout(350);
        const boxDest = await page.locator('#solicitud-mapa-canvas').boundingBox();
        await page.mouse.click(boxDest.x + boxDest.width * 0.68, boxDest.y + boxDest.height * 0.62);
        await page.waitForTimeout(200);
        const destRef = `Local comercial sur (${SUFFIX})`;
        const destAlias = `PW Frecuente ${SUFFIX}`;
        await page.fill('#solicitud-mapa-referencia', destRef);
        await page.fill('#solicitud-mapa-nombre', destAlias);
        await page.check('#solicitud-mapa-frecuente');
        await page.screenshot({ path: path.join(AFTER_DIR, 'solicitud_hibrida_mapa_destino_1366x768.png') });

        await page.click('#btn-confirmar-punto-mapa');
        await page.waitForSelector('.hybrid-location-card[data-location-slot="destino"] [data-slot-map-summary]:not([hidden])', { timeout: 5000 });

        // Verificar alternancia: quitar destino -> vuelve a guardada limpia -> volver a confirmar mapa
        await page.click('.hybrid-location-card[data-location-slot="destino"] [data-clear-slot-map="destino"]');
        const afterClearDest = await page.evaluate(() => ({
            modo: document.querySelector('input[name="destino_modo"]').value,
            selDisabled: document.getElementById('solicitud-destino').disabled,
            selRequired: document.getElementById('solicitud-destino').required,
            latDisabled: document.querySelector('input[name="destino_latitud"]').disabled,
        }));
        assert.strictEqual(afterClearDest.modo, 'guardada');
        assert.strictEqual(afterClearDest.selDisabled, false);
        assert.strictEqual(afterClearDest.selRequired, true);
        assert.strictEqual(afterClearDest.latDisabled, true);

        // Volver a poner destino en mapa para la captura de resumen y envío real
        await page.click('.hybrid-location-card[data-location-slot="destino"] [data-open-slot-map="destino"]');
        await page.waitForSelector('#solicitud-mapa-panel:not([hidden])', { timeout: 6000 });
        await page.waitForTimeout(250);
        const boxDest2 = await page.locator('#solicitud-mapa-canvas').boundingBox();
        await page.mouse.click(boxDest2.x + boxDest2.width * 0.68, boxDest2.y + boxDest2.height * 0.62);
        await page.fill('#solicitud-mapa-referencia', destRef);
        await page.fill('#solicitud-mapa-nombre', destAlias);
        await page.check('#solicitud-mapa-frecuente');
        await page.click('#btn-confirmar-punto-mapa');
        await page.waitForSelector('.hybrid-location-card[data-location-slot="destino"] [data-slot-map-summary]:not([hidden])', { timeout: 5000 });
        await page.evaluate(() => { document.querySelector('.modal-card-body').scrollTop = 0; });
        await page.waitForTimeout(200);

        // Captura 4: Resumen limpio de ambos puntos antes de guardar
        await page.screenshot({ path: path.join(AFTER_DIR, 'solicitud_hibrida_resumen_1366x768.png') });

        // Verificar que error 500 simulado NO cierra el modal y conserva todos los datos
        let failOnce = true;
        await page.route('**/solicitudes/guardar.php', async (route) => {
            if (failOnce) {
                failOnce = false;
                await route.fulfill({
                    status: 500,
                    contentType: 'text/html; charset=UTF-8',
                    body: '<div class="notification notification-danger" role="alert"><span>Error temporal simulado para prueba.</span></div>',
                });
            } else {
                await route.continue();
            }
        });
        await page.click('button[type="submit"]:has-text("Crear solicitud")');
        await page.waitForSelector('#mensaje-solicitud .notification-danger', { timeout: 8000 });
        assert.strictEqual(await page.locator('.hybrid-location-card[data-location-slot="origen"] [data-slot-map-summary]').isVisible(), true, 'Error 500 no debe cerrar modal ni perder resumen de origen.');
        assert.strictEqual(await page.locator('button[type="submit"]:has-text("Crear solicitud")').isDisabled(), false, 'El botón submit debe habilitarse de nuevo tras error 500.');

        // Ahora sí guardar de verdad (la ruta ya hace continue())
        await Promise.all([
            page.waitForURL(/\/solicitudes\/detalle\.php\?id=\d+/, { timeout: 15000 }),
            page.click('button[type="submit"]:has-text("Crear solicitud")'),
        ]);
        await page.waitForLoadState('networkidle');
        const createdSolId = Number(new URL(page.url()).searchParams.get('id'));
        assert(createdSolId > 0, 'Debe redirigir al detalle de la solicitud creada.');
        createdSolicitudIds.push(createdSolId);

        const solUbis = JSON.parse(phpEval(`
            $r = $p->query("SELECT origen_id, destino_id FROM dbo.solicitudes WHERE solicitud_id = ${createdSolId}")->fetch();
            echo json_encode($r);
        `));
        createdUbicacionIds.push(Number(solUbis.origen_id), Number(solUbis.destino_id));

        const mapsCount = await page.locator('a.maps-external-link').count();
        assert.strictEqual(mapsCount, 2, `Detalle de solicitud híbrida debe mostrar exactamente 2 enlaces Abrir en Google Maps (obtenido ${mapsCount}).`);
        assert((await page.locator('body').innerText()).includes('Punto puntual de esta solicitud'), 'Origen puntual debe indicarse discretamente en detalle.');
        await page.screenshot({ path: path.join(AFTER_DIR, 'solicitud_hibrida_detalle_maps_1366x768.png') });

        // Verificar en Flota > Ubicaciones: el puntual NO está, el frecuente SÍ está, y no hay columna numérica de coordenadas
        await page.goto(`${BASE_URL}/flota.php?seccion=ubicaciones`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#flota-resultado table', { timeout: 10000 });
        const flotaHeaders = await page.locator('#flota-resultado thead th').allInnerTexts();
        assert(!flotaHeaders.some((h) => /coordenadas/i.test(h)), `La tabla ordinaria de ubicaciones no debe tener columna Coordenadas: ${JSON.stringify(flotaHeaders)}`);
        await page.fill('#flota-buscar', SUFFIX);
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/flota/lista.php') && r.status() === 200),
            page.locator('#flota-buscar').press('Enter'),
        ]);
        await page.waitForTimeout(200);
        const flotaText = await page.locator('#flota-resultado').innerText();
        assert(flotaText.includes(destAlias), 'El punto marcado como frecuente SÍ debe aparecer en Flota > Ubicaciones.');
        assert(!flotaText.includes(origAlias), 'El punto puntual NO debe aparecer en Flota > Ubicaciones.');
        await page.screenshot({ path: path.join(AFTER_DIR, 'flota_ubicaciones_maps_1366x768.png') });

        console.log('   [OK] Bloque 1 (Híbrida E2E, conservación de estado, Escape, 500, detalle Maps y limpieza de catálogo) aprobado.');

        // =====================================================================
        // BLOQUE 2: FALLBACK OFFLINE EN NUEVA SOLICITUD + REAPERTURA SIN FUGAS
        // =====================================================================
        await page.goto(`${BASE_URL}/solicitudes.php`, { waitUntil: 'networkidle' });
        await page.route('https://tile.openstreetmap.org/**', (route) => route.abort('failed'));
        await page.route('https://*.tile.openstreetmap.org/**', (route) => route.abort('failed'));

        await page.click('button[hx-get="/solicitudes/form.php"]');
        await page.waitForSelector('.hybrid-location-card[data-location-slot="origen"]', { timeout: 8000 });
        await page.click('.hybrid-location-card[data-location-slot="origen"] [data-open-slot-map="origen"]');
        await page.waitForSelector('#solicitud-mapa-fallback:not([hidden])', { timeout: 10000 });
        const fallbackCheck = await page.evaluate(() => ({
            detailsOpen: Boolean(document.getElementById('solicitud-mapa-coords-avanzadas')?.open),
            hasSavedBtn: Boolean(document.querySelector('[data-fallback-use-saved]')),
        }));
        assert(fallbackCheck.detailsOpen && fallbackCheck.hasSavedBtn, 'Fallback offline debe abrir opciones avanzadas y ofrecer volver a ubicación guardada.');

        // Usar botón del fallback para volver al combobox guardado
        await page.click('[data-fallback-use-saved]');
        assert.strictEqual(await page.locator('#solicitud-mapa-panel').isHidden(), true, 'Botón Usar ubicación guardada debe cerrar el panel de mapa.');
        assert.strictEqual(await page.locator('#solicitud-origen').isDisabled(), false, 'Combobox de origen debe quedar habilitado.');

        // Reabrir/cerrar modal 3 veces verificando cero errores ni fugas de scroll lock
        for (let i = 0; i < 3; i++) {
            await page.click('button.modal-close[data-close-modal]');
            await page.waitForTimeout(120);
            const lockedAfterClose = await page.evaluate(() => document.documentElement.classList.contains('is-modal-open'));
            assert.strictEqual(lockedAfterClose, false, 'Cerrar modal debe liberar html.is-modal-open.');
            await page.click('button[hx-get="/solicitudes/form.php"]');
            await page.waitForSelector('#solicitud-cliente', { timeout: 8000 });
        }
        await page.click('button.modal-close[data-close-modal]');
        await ctx.close();
        console.log('   [OK] Bloque 2 (Fallback offline en Nueva solicitud y reapertura limpia sin fugas) aprobado.');

        // =====================================================================
        // BLOQUE 3: VERIFICACIÓN VISUAL MULTI-RESOLUCIÓN (DESKTOP PRIORITARIO + TABLET + MÓVIL)
        // =====================================================================
        const viewports = [
            { name: '1440x900', width: 1440, height: 900, desktop: true },
            { name: '1600x900', width: 1600, height: 900, desktop: true },
            { name: '1920x1080', width: 1920, height: 1080, desktop: true },
            { name: '768x1024', width: 768, height: 1024, desktop: false },
            { name: '375x667', width: 375, height: 667, desktop: false },
        ];

        for (const vp of viewports) {
            const vCtx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
            const vPage = await vCtx.newPage();
            attachErrorCollectors(vPage);
            await login(vPage);
            await vPage.goto(`${BASE_URL}/solicitudes.php`, { waitUntil: 'networkidle' });
            await vPage.click('button[hx-get="/solicitudes/form.php"]');
            await vPage.waitForSelector('.hybrid-location-card[data-location-slot="origen"]', { timeout: 8000 });
            await vPage.click('.hybrid-location-card[data-location-slot="origen"] [data-open-slot-map="origen"]');
            await vPage.waitForSelector('#solicitud-mapa-panel:not([hidden])', { timeout: 8000 });
            await vPage.waitForTimeout(350);

            const box = await vPage.locator('#solicitud-mapa-canvas').boundingBox();
            assert(box && box.width > 180 && box.height >= 180, `Canvas mapa en ${vp.name} demasiado pequeño.`);
            await vPage.mouse.click(box.x + box.width * 0.5, box.y + box.height * 0.5);
            await vPage.fill('#solicitud-mapa-referencia', `Bodega norte acceso principal (${vp.name})`);
            await vPage.fill('#solicitud-mapa-nombre', 'Bodega Norte');
            await vPage.click('#btn-confirmar-punto-mapa');
            await vPage.waitForSelector('.hybrid-location-card[data-location-slot="origen"] [data-slot-map-summary]:not([hidden])', { timeout: 5000 });
            await vPage.evaluate(() => { document.querySelector('.modal-card-body').scrollTop = 0; });
            await vPage.waitForTimeout(200);

            const overflow = await vPage.evaluate(() => ({
                pageOverflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                modalOverflowX: Math.round((document.querySelector('.modal-card-body')?.scrollWidth || 0) - (document.querySelector('.modal-card-body')?.clientWidth || 0)),
            }));
            if (vp.desktop) {
                assert(overflow.pageOverflowX <= 2, `Desborde horizontal de página en desktop ${vp.name}: ${overflow.pageOverflowX}px`);
                assert(overflow.modalOverflowX <= 2, `Desborde horizontal interno del modal en desktop ${vp.name}: ${overflow.modalOverflowX}px`);
            }

            await vPage.screenshot({ path: path.join(AFTER_DIR, `solicitud_hibrida_resumen_${vp.name}.png`) });
            await vCtx.close();
            console.log(`   [OK] Resolución ${vp.name}: pageOverflowX=${overflow.pageOverflowX}px, modalOverflowX=${overflow.modalOverflowX}px`);
        }

        assert.strictEqual(pageErrors.length, 0, `Errores JS no capturados: ${pageErrors.join(' | ')}`);
        assert.strictEqual(cspViolations.length, 0, `Violaciones CSP de mapas: ${cspViolations.join(' | ')}`);
        console.log('=== SUITE PLAYWRIGHT UBICACIONES HÍBRIDAS (6 RESOLUCIONES): 100% PASS ===');
    } finally {
        if (browser) {
            await browser.close().catch(() => {});
        }
        if (srv) {
            try {
                execFileSync('taskkill', ['/F', '/T', '/PID', String(srv.child.pid)], { stdio: 'ignore' });
            } catch {
                try { srv.child.kill('SIGKILL'); } catch {}
            }
            try { fs.closeSync(srv.outFd); } catch {}
            try { fs.unlinkSync(srv.logPath); } catch {}
        }
        // Limpieza estricta de entidades creadas en TransportesSegurosSA_Test
        const solCsv = createdSolicitudIds.filter((n) => n > 0).join(',');
        const ubiCsv = createdUbicacionIds.filter((n) => n > 0).join(',');
        try {
            phpEval(`
                $solIds = array_filter([${solCsv}]);
                foreach ($solIds as $sid) {
                    $p->prepare('DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id = ?')->execute([$sid]);
                    $p->prepare('DELETE FROM dbo.mercancias WHERE solicitud_id = ?')->execute([$sid]);
                    $p->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'solicitudes' AND registro_id = ?")->execute([(string)$sid]);
                    $p->prepare('DELETE FROM dbo.solicitudes WHERE solicitud_id = ?')->execute([$sid]);
                }
                $ubiIds = array_filter([${ubiCsv}]);
                foreach ($ubiIds as $uid) {
                    $p->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'ubicaciones' AND registro_id = ?")->execute([(string)$uid]);
                    $p->prepare('DELETE FROM dbo.ubicaciones WHERE ubicacion_id = ?')->execute([$uid]);
                }
                if (${adminUid} > 0) {
                    $p->prepare('DELETE FROM dbo.auditorias WHERE usuario_id = ?')->execute([${adminUid}]);
                    $p->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?')->execute([${adminUid}]);
                    $p->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?')->execute([${adminUid}]);
                }
            `);
        } catch (cleanErr) {
            console.error('Aviso limpieza Playwright:', cleanErr.message);
        }
    }
})();
