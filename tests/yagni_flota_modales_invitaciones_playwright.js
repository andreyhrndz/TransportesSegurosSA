/**
 * Suite E2E y Visual Real (Playwright + Chromium) — Cierre YAGNI
 * Verifica contra servidor PHP 8.4 aislado apuntando a TransportesSegurosSA_Test:
 * - Vehículos sin odómetro ni selector ordinario de homologación, Tom Select, Escape, 422, guardado y edición.
 * - Conductores con licencia única, sin B4 por defecto, limpieza inmediata de vencimiento al cambiar categoría
 *   (teclado, mouse y API), conservación al re-seleccionar misma opción, validación inline, guardado y edición.
 * - Protección contra doble envío ante petición lenta.
 * - Modales de Clientes y Viajes (cierre solo al éxito, diagnóstico y rechazo B1 vs 7500 kg).
 * - Invitaciones: cierre automático modo prueba, revocar pendiente, cancelar expirada, actualización HTMX sin reload.
 * - Inspección responsive y capturas sanitizadas en 1366x768, 1440x900, 768x1024 y 375x667.
 */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const http = require('http');
const crypto = require('crypto');
const { execSync, execFileSync, spawn } = require('child_process');

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
        throw new Error('E2E ASSERTION FAILED: ' + msg);
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

async function runSuite() {
    fs.mkdirSync(AFTER_DIR, { recursive: true });

    // 0. Preflight DB_NAME() = TransportesSegurosSA_Test
    const dbName = runPhp("require 'app/bootstrap.php'; require_test_database(db()); echo db()->query('SELECT DB_NAME()')->fetchColumn();");
    console.log('Preflight DB_NAME():', dbName);
    assert(dbName === 'TransportesSegurosSA_Test', 'DB efectiva debe ser TransportesSegurosSA_Test, recibida: ' + dbName);

    const suffix = crypto.randomBytes(3).toString('hex').toUpperCase();
    const plate = `PW${suffix}`;
    const condIdent = `PW-ID-${suffix}`;
    const condLic = `PW-LIC-${suffix}`;
    const clientIdent = `9-9${suffix.slice(0, 3)}-${suffix.slice(3, 6)}`;
    const invEmail1 = `pw.inv1.${suffix.toLowerCase()}@example.test`;
    const invEmailExp = `pw.exp.${suffix.toLowerCase()}@example.test`;

    const helperPath = path.join(__dirname, 'ephemeral_auth_helper.php');
    let ephemeralUser = null;
    let phpServer = null;
    let browser = null;
    const pageErrors = [];

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

        ephemeralUser = JSON.parse(execSync(`"${PHP_BIN}" "${helperPath}" create`, {
            cwd: REPO_ROOT,
            env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test' },
            encoding: 'utf8'
        }).trim());

        browser = await chromium.launch(resolveChromiumLaunchOptions());

        // =====================================================================
        // BLOQUE 1: ESCENARIOS E2E FUNCIONALES EN DESKTOP (1366x768)
        // =====================================================================
        const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });
        const page = await context.newPage();
        page.on('pageerror', (err) => pageErrors.push(err.message));
        page.on('dialog', async (dialog) => { await dialog.accept(); });

        await loginEphemeral(page, ephemeralUser);

        // --- ESCENARIO A: VEHÍCULOS ---
        console.log('--- Escenario A: Vehículos (modal YAGNI, Tom Select, Escape, 422, crear y editar) ---');
        await page.goto(`${BASE_URL}/flota.php?seccion=vehiculos`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#flota-resultado table');

        const vehTableText = await page.locator('#flota-resultado').innerText();
        assert(vehTableText.includes('CAPACIDAD DE CARGA') || vehTableText.includes('Capacidad de carga'), 'Tabla vehículos debe tener encabezado Capacidad de carga.');
        assert(!vehTableText.includes('Capacidad y odómetro') && !vehTableText.includes('CAPACIDAD Y ODÓMETRO'), 'No debe existir encabezado Capacidad y odómetro.');
        assert(!vehTableText.includes('Homol:'), 'Tabla vehículos no debe mostrar etiqueta Homol.');
        assert(!/\s\.0\s*km/i.test(vehTableText), 'No debe aparecer formato aberrante .0 km.');

        await page.click('.toolbar-panel .button.is-primary');
        await page.waitForSelector('#modal-contenido .modal-card');

        const vehFormMeta = await page.evaluate(() => ({
            hasKm: Boolean(document.querySelector('input[name="kilometraje_actual"]')),
            hasHomol: Boolean(document.querySelector('select[name="estado_homologacion"]')),
            calloutCount: document.querySelectorAll('.pma-help-callout').length,
            claseHasTs: Boolean(document.querySelector('#veh-clase')?.tomselect),
            estadoHasTs: Boolean(document.querySelector('#veh-estado')?.tomselect),
        }));
        assert(!vehFormMeta.hasKm, 'Formulario vehículo no debe incluir kilometraje_actual.');
        assert(!vehFormMeta.hasHomol, 'Formulario vehículo no debe incluir selector de homologación.');
        assert(vehFormMeta.calloutCount === 1, 'Debe haber exactamente un recuadro discreto de ayuda PMA.');
        assert(vehFormMeta.claseHasTs && vehFormMeta.estadoHasTs, 'Clasificación legal y estado operativo deben usar Tom Select.');

        // Probar Escape con dropdown Tom Select abierto -> cierra dropdown y MANTIENE modal abierto
        await page.click('#veh-clase + .ts-wrapper .ts-control');
        const visibleVehDd = page.locator('.ts-dropdown >> visible=true').first();
        await visibleVehDd.waitFor({ state: 'visible', timeout: 5000 });
        const ddBox = await visibleVehDd.boundingBox();
        assert(ddBox && ddBox.x >= 0 && ddBox.x + ddBox.width <= 1366, 'Dropdown Tom Select de vehículo no debe salirse del viewport.');
        await page.keyboard.press('Escape');
        await page.waitForTimeout(150);
        const modalStillOpenAfterEsc = await page.locator('#modal-contenido .modal-card').isVisible();
        assert(modalStillOpenAfterEsc, 'Pulsar Escape con dropdown abierto debe cerrar solo el dropdown y mantener el modal abierto.');

        // Error 422 (carga útil > PMA): modal permanece abierto, datos intactos, botón usable
        await page.fill('#veh-placa', plate);
        await page.fill('#veh-marca', 'Isuzu');
        await page.fill('#veh-modelo', 'NPR YAGNI');
        await page.fill('#veh-anio', '2025');
        await page.fill('#veh-carga', '9000');
        await page.fill('#veh-pma', '7500');
        await page.locator('#veh-pma').dispatchEvent('change');
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForSelector('#mensaje-form-maestro .notification-danger', { timeout: 5000 });
        assert(await page.locator('#modal-contenido .modal-card').isVisible(), 'Ante error 422 el modal de vehículo debe permanecer abierto.');
        assert((await page.inputValue('#veh-placa')) === plate, 'Ante error 422 los campos del formulario deben conservarse intactos.');
        assert(!(await page.locator('#modal-contenido button[type="submit"]').isDisabled()), 'Tras error 422 el botón Guardar debe quedar nuevamente habilitado.');

        // Corregir carga útil y probar protección de doble envío ante respuesta lenta
        await page.fill('#veh-carga', '4000');
        let vehPostCount = 0;
        const delayHandler = async (route) => {
            vehPostCount++;
            await new Promise((r) => setTimeout(r, 350));
            await route.continue();
        };
        await page.route('**/flota/guardar.php', delayHandler);
        const submitBtn = page.locator('#modal-contenido button[type="submit"]');
        await submitBtn.click();
        await page.evaluate(() => {
            const btn = document.querySelector('#modal-contenido button[type="submit"]');
            if (btn) btn.click();
        });
        await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '', undefined, { timeout: 8000 });
        await page.unroute('**/flota/guardar.php', delayHandler);
        assert(vehPostCount === 1, `Doble clic ante petición lenta debe emitir exactamente 1 POST (emitidos: ${vehPostCount}).`);

        await page.waitForSelector('#aviso-temporal:not([hidden])', { timeout: 5000 });
        await page.fill('#flota-buscar', plate);
        await page.locator('#flota-buscar').dispatchEvent('change');
        const vehRow = page.locator(`#flota-resultado tr:has-text("${plate}")`).first();
        await vehRow.waitFor({ state: 'visible', timeout: 6000 });
        const rowVehText = await vehRow.innerText();
        assert(rowVehText.includes(plate) && rowVehText.includes('4.000 kg') && rowVehText.includes('7.500 kg'), 'Listado de vehículos debe mostrar la unidad recién creada con Útil y PMA.');

        // Abrir edición y confirmar que no muestra odómetro
        await vehRow.locator('button:has-text("Editar")').click();
        await page.waitForSelector('#modal-contenido .modal-card');
        const editVehText = await page.locator('#modal-contenido').innerText();
        assert(!editVehText.includes('Odómetro registrado'), 'Resumen de edición de vehículo no debe mostrar Odómetro registrado.');
        await page.click('#modal-contenido button[data-close-modal]');
        await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '');
        console.log('   [OK] Escenario A (Vehículos) superado.');

        // --- ESCENARIO B: CONDUCTORES ---
        console.log('--- Escenario B: Conductores (licencia única, sin B4 default, limpieza al cambiar categoría, inline validation) ---');
        await page.goto(`${BASE_URL}/flota.php?seccion=conductores`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#flota-resultado table');
        await page.click('.toolbar-panel .button.is-primary');
        await page.waitForSelector('#modal-contenido .modal-card');

        const condInit = await page.evaluate(() => ({
            hasLegacyTable: Boolean(document.querySelector('.license-selector-table')),
            hasVerifSelect: Boolean(document.querySelector('select[name^="licencia_estado"]')),
            licSelectVal: document.querySelector('#cond-tipo-licencia')?.value ?? null,
            licHasTs: Boolean(document.querySelector('#cond-tipo-licencia')?.tomselect),
            dateVal: document.querySelector('#cond-vencimiento')?.value ?? null,
            estadoHasTs: Boolean(document.querySelector('#cond-estado')?.tomselect),
        }));
        assert(!condInit.hasLegacyTable && !condInit.hasVerifSelect, 'No debe existir tabla legacy ni columna Verificación.');
        assert(condInit.licSelectVal === '' && condInit.dateVal === '', 'Al crear conductor la licencia y fecha deben iniciar vacías (jamás B4 por defecto).');
        assert(condInit.licHasTs && condInit.estadoHasTs, 'Tipo de licencia y estado operativo deben usar Tom Select.');

        // Intentar guardar sin licencia -> error inline cercano al campo, modal abierto
        await page.fill('#cond-id', condIdent);
        await page.fill('#cond-nombre', 'Valeria');
        await page.fill('#cond-ap1', 'Salazar');
        await page.fill('#cond-licencia', condLic);
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForSelector('#cond-tipo-licencia-error:not([hidden])', { timeout: 3000 });
        assert(await page.locator('#modal-contenido .modal-card').isVisible(), 'Falta de licencia debe bloquear guardado manteniendo modal abierto.');
        assert((await page.inputValue('#cond-nombre')) === 'Valeria', 'Los datos ingresados deben conservarse intactos.');

        // Elegir B2 mediante teclado en Tom Select (confirmando que el foco ya quedó en el control tras el error inline)
        const licDropdownOpen = await page.evaluate(() => Boolean(document.querySelector('#cond-tipo-licencia')?.tomselect?.isOpen));
        if (!licDropdownOpen) {
            await page.click('#cond-tipo-licencia + .ts-wrapper .ts-control');
        }
        await page.locator('.ts-dropdown >> visible=true').first().waitFor({ state: 'visible', timeout: 5000 });
        await page.keyboard.type('B2');
        await page.locator('.ts-dropdown >> visible=true >> .option.active:has-text("B2")').first().waitFor({ state: 'visible', timeout: 3000 });
        await page.keyboard.press('Enter');
        assert((await page.evaluate(() => document.querySelector('#cond-tipo-licencia').value)) === 'B2', 'Selección por teclado en Tom Select debe establecer B2.');

        // Guardar sin fecha -> error inline en #cond-vencimiento-error
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForSelector('#cond-vencimiento-error:not([hidden])', { timeout: 3000 });
        assert(await page.locator('#modal-contenido .modal-card').isVisible(), 'Falta de fecha debe bloquear guardado manteniendo modal abierto.');

        // Establecer fecha futura y guardar
        await page.evaluate(() => {
            const el = document.querySelector('#cond-vencimiento');
            el._flatpickr.setDate('2029-12-31', true);
        });
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '', undefined, { timeout: 8000 });
        await page.waitForSelector('#aviso-temporal:not([hidden])');

        // Reabrir edición del conductor recién creado
        await page.fill('#flota-buscar', condIdent);
        await page.locator('#flota-buscar').dispatchEvent('change');
        const condRow = page.locator(`#flota-resultado tr:has-text("${condIdent}")`).first();
        await condRow.waitFor({ state: 'visible', timeout: 6000 });
        const condListText = await condRow.innerText();
        assert(condListText.includes('Valeria Salazar') && condListText.includes('B2') && condListText.includes('31/12/2029'), 'Conductor debe aparecer con B2 y 31/12/2029.');

        await condRow.locator('button:has-text("Editar")').click();
        await page.waitForSelector('#modal-contenido .modal-card');
        const editCondState = await page.evaluate(() => ({
            code: document.querySelector('#cond-tipo-licencia')?.value,
            date: document.querySelector('#cond-vencimiento')?.value,
        }));
        assert(editCondState.code === 'B2' && editCondState.date === '2029-12-31', 'Al editar debe cargar B2 y 2029-12-31.');

        // Re-seleccionar la MISMA categoría B2 -> NO debe borrar fecha
        await page.evaluate(() => {
            document.querySelector('#cond-tipo-licencia').tomselect.setValue('B2');
        });
        assert((await page.evaluate(() => document.querySelector('#cond-vencimiento').value)) === '2029-12-31', 'Re-seleccionar la misma categoría B2 NO debe borrar la fecha.');

        // Cambiar B2 -> B1 con ratón -> debe limpiar la fecha de inmediato
        await page.click('#cond-tipo-licencia + .ts-wrapper .ts-control');
        const b1Option = page.locator('.ts-dropdown >> visible=true >> .option:has-text("B1")').first();
        await b1Option.waitFor({ state: 'visible', timeout: 5000 });
        await b1Option.click();
        const clearedAfterMouse = await page.evaluate(() => ({
            code: document.querySelector('#cond-tipo-licencia').value,
            date: document.querySelector('#cond-vencimiento').value,
            altVal: document.querySelector('#cond-vencimiento')._flatpickr?.altInput?.value ?? '',
        }));
        assert(clearedAfterMouse.code === 'B1' && clearedAfterMouse.date === '' && clearedAfterMouse.altVal === '', 'Cambiar B2->B1 con mouse debe limpiar inmediatamente la fecha.');

        // Intentar guardar sin nueva fecha -> bloqueado con error cercano
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForSelector('#cond-vencimiento-error:not([hidden])', { timeout: 3000 });
        assert(await page.locator('#modal-contenido .modal-card').isVisible(), 'Tras limpiar vencimiento no debe permitir guardar sin fecha.');

        // Ingresar nueva fecha y luego cambiar vía API (B1 -> B3) para verificar limpieza también vía API
        await page.evaluate(() => {
            document.querySelector('#cond-vencimiento')._flatpickr.setDate('2030-06-15', true);
            document.querySelector('#cond-tipo-licencia').tomselect.setValue('B3');
        });
        assert((await page.evaluate(() => document.querySelector('#cond-vencimiento').value)) === '', 'Cambiar categoría vía API TomSelect también debe limpiar la fecha.');

        // Dejar B1 + 2030-06-15 y guardar
        await page.evaluate(() => {
            document.querySelector('#cond-tipo-licencia').tomselect.setValue('B1');
            document.querySelector('#cond-vencimiento')._flatpickr.setDate('2030-06-15', true);
        });
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '', undefined, { timeout: 8000 });
        await page.waitForTimeout(400);
        const condListAfter = await page.locator('#flota-resultado').innerText();
        assert(condListAfter.includes('B1') && condListAfter.includes('15/06/2030'), 'Conductor editado debe mostrar B1 y 15/06/2030.');
        console.log('   [OK] Escenario B (Conductores y limpieza de fecha) superado.');

        // --- ESCENARIO C: VIAJES (Incompatibilidad B1 vs 7500 kg en navegador) ---
        console.log('--- Escenario C: Viajes (diagnóstico y rechazo B1 vs camión 7500 kg) ---');
        await page.goto(`${BASE_URL}/viajes.php`, { waitUntil: 'networkidle' });
        await page.click('.toolbar-panel .button.is-primary');
        await page.waitForSelector('#modal-contenido .modal-card');

        const tripIds = await page.evaluate(({ p, cName }) => {
            const vehSel = document.querySelector('#viaje-vehiculo');
            const condSel = document.querySelector('#viaje-conductor');
            const rutaSel = document.querySelector('#viaje-ruta');
            const vehOpt = Array.from(vehSel.options).find((o) => o.textContent.includes(p));
            const condOpt = Array.from(condSel.options).find((o) => o.textContent.includes(cName));
            const rutaOpt = Array.from(rutaSel.options).find((o) => o.value !== '');
            if (vehOpt) vehSel.tomselect.setValue(vehOpt.value);
            if (condOpt) condSel.tomselect.setValue(condOpt.value);
            if (rutaOpt) rutaSel.tomselect.setValue(rutaOpt.value);
            const dt = new Date(Date.now() + 86400000 * 2);
            const isoDt = dt.toISOString().slice(0, 16);
            document.querySelector('#viaje-fecha')._flatpickr.setDate(isoDt, true);
            return { v: vehOpt?.value, c: condOpt?.value, r: rutaOpt?.value };
        }, { p: plate, cName: 'Valeria Salazar' });
        assert(tripIds.v && tripIds.c && tripIds.r, 'Vehículo, conductor B1 y ruta deben encontrarse en modal de viaje.');

        await page.waitForSelector('#aviso-diagnostico-habilitacion.notification-danger', { timeout: 3000 });
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForSelector('#mensaje-viaje .notification-danger', { timeout: 5000 });
        assert(await page.locator('#modal-contenido .modal-card').isVisible(), 'Viaje incompatible B1->7500kg debe mantener modal abierto con error.');
        await page.click('#modal-contenido button[data-close-modal]');
        await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '');
        console.log('   [OK] Escenario C (Viajes B1 vs 7500 kg) superado.');

        // --- ESCENARIO D: CLIENTES MODAL (cierre solo tras éxito) ---
        console.log('--- Escenario D: Modal Clientes (error 422 mantiene abierto, éxito cierra con toast) ---');
        await page.goto(`${BASE_URL}/clientes.php`, { waitUntil: 'networkidle' });
        await page.click('button:has-text("Nuevo cliente")');
        await page.waitForSelector('#modal-contenido .modal-card');
        await page.fill('#identificacion', clientIdent);
        await page.fill('#nombre', 'Esteban');
        await page.fill('#primer_apellido', 'Madrigal');
        await page.fill('#correo', 'correo-invalido');
        await page.evaluate(() => { document.querySelector('#modal-contenido form').setAttribute('novalidate', 'novalidate'); });
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForSelector('#mensaje-form .notification-danger', { timeout: 5000 });
        assert(await page.locator('#modal-contenido .modal-card').isVisible(), 'Modal cliente con 422 debe mantenerse abierto.');
        await page.fill('#correo', `esteban.${suffix.toLowerCase()}@example.test`);
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '', undefined, { timeout: 8000 });
        await page.waitForSelector('#aviso-temporal:not([hidden])');
        console.log('   [OK] Escenario D (Modal Clientes) superado.');

        // --- ESCENARIO E: INVITACIONES (Auto-cierre Fake + Revocar pendiente + Cancelar expirada) ---
        console.log('--- Escenario E: Invitaciones (auto-cierre Fake, revocar pendiente, cancelar expirada) ---');
        await page.goto(`${BASE_URL}/usuarios.php`, { waitUntil: 'networkidle' });
        await page.click('#tab-invitaciones');
        await page.waitForSelector('#invitaciones-resultado table');

        // Invitar usuario en modo Fake -> debe cerrar modal automáticamente sin pantalla "Entendido"
        await page.click('#panel-invitaciones button:has-text("Invitar usuario")');
        await page.waitForSelector('#modal-contenido .modal-card');
        await page.fill('#usr-correo-invitacion', invEmail1);
        await page.locator('#modal-contenido input.chk-permiso[value="FLOTAS_VER"]').check();
        await page.click('#modal-contenido button[type="submit"]');
        await page.waitForFunction(() => document.getElementById('modal-contenido')?.innerHTML.trim() === '', undefined, { timeout: 8000 });
        const toastText = await page.locator('#aviso-temporal').innerText();
        assert(toastText.includes('modo prueba') && !toastText.toLowerCase().includes('correo enviado'), 'Toast en modo Fake debe decir modo prueba, recibido: ' + toastText);

        // Verificar fila pendiente en tabla y revocarla
        const pendRow = page.locator(`#invitaciones-resultado tr:has-text("${invEmail1}")`).first();
        await pendRow.waitFor({ state: 'visible', timeout: 6000 });
        assert(await pendRow.locator('button:has-text("Revocar")').isVisible(), 'Invitación pendiente debe mostrar Revocar.');
        assert(await pendRow.locator('button:has-text("Reenviar")').isVisible(), 'Invitación pendiente debe mostrar Reenviar.');
        await pendRow.locator('button:has-text("Revocar")').click();
        await page.waitForSelector(`#invitaciones-resultado tr[data-invitacion-estado="REVOCADA"]:has-text("${invEmail1}")`, { timeout: 6000 });

        // Sembrar invitación expirada y descartarla/cancelarla en navegador
        runPhp(`require 'app/bootstrap.php'; $p = db(); require_test_database($p); $p->exec("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=${ephemeralUser.user_id}"); $h = hash('sha256', 'exp_pw_${suffix}'); $st = $p->prepare("INSERT INTO dbo.invitaciones_usuario (correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id, estado_envio) VALUES (?, ?, 'EXPIRADA', DATEADD(HOUR, -4, SYSUTCDATETIME()), ?, 'SIMULADO')"); $st->execute(['${invEmailExp}', $h, ${ephemeralUser.user_id}]);`);

        await page.reload({ waitUntil: 'networkidle' });
        await page.click('#tab-invitaciones');
        const expRow = page.locator(`#invitaciones-resultado tr[data-invitacion-estado="EXPIRADA"]:has-text("${invEmailExp}")`).first();
        await expRow.waitFor({ state: 'visible', timeout: 6000 });
        assert(await expRow.locator('button:has-text("Cancelar")').isVisible(), 'Invitación expirada debe mostrar Cancelar.');
        assert(await expRow.locator('button:has-text("Reenviar")').isVisible(), 'Invitación expirada debe mostrar Reenviar.');
        await expRow.locator('button:has-text("Cancelar")').click();
        await page.waitForSelector(`#invitaciones-resultado tr[data-invitacion-estado="CANCELADA"]:has-text("${invEmailExp}")`, { timeout: 6000 });
        console.log('   [OK] Escenario E (Invitaciones) superado.');

        await context.close();

        // =====================================================================
        // BLOQUE 2: VERIFICACIÓN VISUAL Y RESPONSIVE EN 4 RESOLUCIONES
        // =====================================================================
        const viewports = [
            { name: '1366x768', width: 1366, height: 768 },
            { name: '1440x900', width: 1440, height: 900 },
            { name: '768x1024', width: 768, height: 1024 },
            { name: '375x667', width: 375, height: 667 },
        ];

        for (const vp of viewports) {
            console.log(`--- Verificación visual & overflow en ${vp.name} ---`);
            const vCtx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
            const vPage = await vCtx.newPage();
            vPage.on('pageerror', (err) => pageErrors.push(`[${vp.name}] ${err.message}`));
            await loginEphemeral(vPage, ephemeralUser);

            // 1. Flota Vehículos + modal + Tom Select abierto
            await vPage.goto(`${BASE_URL}/flota.php?seccion=vehiculos`, { waitUntil: 'networkidle' });
            await vPage.waitForSelector('#flota-resultado table');
            const overflowVeh = await vPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
            assert(overflowVeh, `Overflow horizontal detectado en Flota Vehículos (${vp.name})`);
            await vPage.screenshot({ path: path.join(AFTER_DIR, `flota_vehiculos_${vp.name}.png`) });
            if (vp.name === '1366x768' || vp.name === '1440x900') {
                await vPage.screenshot({ path: path.join(MAIN_SHOT_DIR, `flota_vehiculos_${vp.name}.png`) });
            }

            await vPage.click('.toolbar-panel .button.is-primary');
            await vPage.waitForSelector('#modal-contenido .modal-card');
            await vPage.click('#veh-clase + .ts-wrapper .ts-control');
            const vVehDd = vPage.locator('.ts-dropdown >> visible=true').first();
            await vVehDd.waitFor({ state: 'visible', timeout: 5000 });
            const vehDdRect = await vVehDd.boundingBox();
            assert(vehDdRect && vehDdRect.x >= 0 && vehDdRect.x + vehDdRect.width <= vp.width + 1, `Dropdown vehículo desbordado en ${vp.name}`);
            await vPage.screenshot({ path: path.join(AFTER_DIR, `modal_vehiculo_selector_${vp.name}.png`) });
            if (vp.name === '1366x768' || vp.name === '1440x900') {
                await vPage.screenshot({ path: path.join(MAIN_SHOT_DIR, `modal_nuevo_vehiculo_pma_${vp.name}.png`) });
            }
            await vPage.keyboard.press('Escape'); // cierra dropdown
            await vPage.keyboard.press('Escape'); // cierra modal

            // 2. Flota Conductores + modal + Tom Select licencia abierto
            await vPage.goto(`${BASE_URL}/flota.php?seccion=conductores`, { waitUntil: 'networkidle' });
            await vPage.waitForSelector('#flota-resultado table');
            const overflowCond = await vPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
            assert(overflowCond, `Overflow horizontal detectado en Flota Conductores (${vp.name})`);
            await vPage.click('.toolbar-panel .button.is-primary');
            await vPage.waitForSelector('#modal-contenido .modal-card');
            await vPage.click('#cond-tipo-licencia + .ts-wrapper .ts-control');
            const vCondDd = vPage.locator('.ts-dropdown >> visible=true').first();
            await vCondDd.waitFor({ state: 'visible', timeout: 5000 });
            const condDdRect = await vCondDd.boundingBox();
            assert(condDdRect && condDdRect.x >= 0 && condDdRect.x + condDdRect.width <= vp.width + 1, `Dropdown licencias desbordado en ${vp.name}`);
            await vPage.screenshot({ path: path.join(AFTER_DIR, `modal_conductor_licencia_${vp.name}.png`) });
            if (vp.name === '1366x768' || vp.name === '1440x900') {
                await vPage.screenshot({ path: path.join(MAIN_SHOT_DIR, `modal_nuevo_conductor_licencias_${vp.name}.png`) });
            }
            await vPage.keyboard.press('Escape');
            await vPage.keyboard.press('Escape');

            // 3. Invitaciones tab (sanitizado)
            await vPage.goto(`${BASE_URL}/usuarios.php`, { waitUntil: 'networkidle' });
            await vPage.click('#tab-invitaciones');
            await vPage.waitForSelector('#invitaciones-resultado table');
            await sanitizeDomForScreenshot(vPage);
            const overflowInv = await vPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
            assert(overflowInv, `Overflow horizontal detectado en Invitaciones (${vp.name})`);
            await vPage.screenshot({ path: path.join(AFTER_DIR, `usuarios_invitaciones_${vp.name}.png`) });
            if (vp.name === '1366x768' || vp.name === '1440x900') {
                await vPage.screenshot({ path: path.join(MAIN_SHOT_DIR, `usuarios_pestana_invitaciones_${vp.name}.png`) });
            }

            await vCtx.close();
        }

        assert(pageErrors.length === 0, 'Errores JS no controlados en navegador: ' + pageErrors.join(' | '));
        console.log('=== PLAYWRIGHT E2E & VISUAL 4 RESOLUCIONES: 100% PASS ===');
    } finally {
        if (browser) await browser.close();
        if (phpServer) phpServer.kill();

        // Limpiar exclusivamente registros efímeros creados en Test
        try {
            runPhp(`
                require 'app/bootstrap.php';
                $p = db();
                require_test_database($p);
                $p->prepare("DELETE FROM dbo.auditorias WHERE entidad = N'vehiculos' AND registro_id IN (SELECT CONVERT(NVARCHAR(100), vehiculo_id) FROM dbo.vehiculos WHERE placa = ?)")->execute(['${plate}']);
                $p->prepare('DELETE FROM dbo.vehiculos WHERE placa = ?')->execute(['${plate}']);
                $p->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'conductores', N'conductores_licencias') AND (registro_id IN (SELECT CONVERT(NVARCHAR(100), conductor_id) FROM dbo.conductores WHERE identificacion = ?) OR registro_id LIKE (SELECT TOP 1 CONCAT(conductor_id, ':%') FROM dbo.conductores WHERE identificacion = ?))")->execute(['${condIdent}', '${condIdent}']);
                $p->prepare('DELETE FROM dbo.conductores_licencias WHERE conductor_id IN (SELECT conductor_id FROM dbo.conductores WHERE identificacion = ?)')->execute(['${condIdent}']);
                $p->prepare('DELETE FROM dbo.conductores WHERE identificacion = ?')->execute(['${condIdent}']);
                $p->prepare('DELETE FROM dbo.clientes_fisicos WHERE cliente_id IN (SELECT cliente_id FROM dbo.clientes WHERE identificacion = ?)')->execute(['${clientIdent}']);
                $p->prepare('DELETE FROM dbo.clientes WHERE identificacion = ?')->execute(['${clientIdent}']);
                $p->prepare("DELETE FROM dbo.auditorias WHERE entidad IN (N'invitaciones_usuario', N'invitaciones_permisos') AND registro_id IN (SELECT CONVERT(NVARCHAR(100), invitacion_id) FROM dbo.invitaciones_usuario WHERE correo_destino IN (?, ?))")->execute(['${invEmail1}', '${invEmailExp}']);
                $p->prepare('DELETE FROM dbo.invitaciones_permisos WHERE invitacion_id IN (SELECT invitacion_id FROM dbo.invitaciones_usuario WHERE correo_destino IN (?, ?))')->execute(['${invEmail1}', '${invEmailExp}']);
                $p->prepare('DELETE FROM dbo.invitaciones_usuario WHERE correo_destino IN (?, ?)')->execute(['${invEmail1}', '${invEmailExp}']);
            `);
        } catch (cleanErr) {
            console.error('Aviso de limpieza fixtures:', cleanErr.message);
        }

        if (ephemeralUser) {
            try {
                runPhp(`require 'app/bootstrap.php'; $p = db(); require_test_database($p); $p->prepare('DELETE FROM dbo.auditorias WHERE usuario_id = ?')->execute([${ephemeralUser.user_id}]);`);
                execSync(`"${PHP_BIN}" "${helperPath}" cleanup ${ephemeralUser.user_id}`, {
                    cwd: REPO_ROOT,
                    env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test' }
                });
            } catch (uErr) {
                console.error('Aviso limpieza usuario efímero:', uErr.message);
            }
        }
    }
}

runSuite().catch((err) => {
    console.error('FALLO PLAYWRIGHT E2E:', err.stack || err.message);
    process.exit(1);
});
