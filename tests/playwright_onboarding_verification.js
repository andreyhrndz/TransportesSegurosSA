/**
 * Verificación Visual y Funcional de Onboarding con Playwright
 * Transportes Seguros S.A.
 * Ejecuta contra TransportesSegurosSA_Test con cuentas efímeras.
 */

const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');

let chromium;
try {
    chromium = require('playwright').chromium;
} catch {
    try {
        const npmRoot = execSync('npm root -g').toString().trim();
        chromium = require(path.join(npmRoot, '@playwright', 'cli', 'node_modules', 'playwright')).chromium;
    } catch {
        chromium = require('@playwright/test').chromium;
    }
}

function resolvePhpBin() {
    if (process.env.PHP_BIN && fs.existsSync(process.env.PHP_BIN)) {
        return process.env.PHP_BIN;
    }
    const standardPaths = [
        path.resolve(__dirname, '..', 'tools', 'local', 'php84', 'php.exe'),
        'C:\\AdmBD2026\\Herramientas\\PHP\\8.4\\php.exe',
        'C:\\tools\\php84\\php.exe',
        'C:\\php84\\php.exe',
        'php'
    ];
    for (const p of standardPaths) {
        try {
            const ver = execSync(`"${p}" -r "echo PHP_VERSION_ID;"`, { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim();
            if (parseInt(ver, 10) >= 80400) {
                return p;
            }
        } catch {}
    }
    return 'C:\\AdmBD2026\\Herramientas\\PHP\\8.4\\php.exe';
}

const PHP_BIN = resolvePhpBin();

function crearInvitacionEfimera() {
    const helperPath = path.resolve(__dirname, 'onboarding_playwright_helper.php');
    const res = execSync(`"${PHP_BIN}" "${helperPath}" crear`, { cwd: path.resolve(__dirname, '..') });
    return JSON.parse(res.toString().trim());
}

function limpiarInvitacion(invId) {
    if (!invId) return;
    const helperPath = path.resolve(__dirname, 'onboarding_playwright_helper.php');
    try {
        execSync(`"${PHP_BIN}" "${helperPath}" limpiar_invitacion ${invId}`, { cwd: path.resolve(__dirname, '..') });
    } catch {}
}

function limpiarUsuario(username) {
    if (!username) return;
    const helperPath = path.resolve(__dirname, 'onboarding_playwright_helper.php');
    try {
        execSync(`"${PHP_BIN}" "${helperPath}" limpiar_usuario "${username}"`, { cwd: path.resolve(__dirname, '..') });
    } catch {}
}

async function runTest() {
    console.log('=== INICIO DE PRUEBAS FUNCIONALES EN NAVEGADOR (PLAYWRIGHT) ===');

    const screenshotsDir = path.resolve(__dirname, '..', 'docs', 'screenshots');
    if (!fs.existsSync(screenshotsDir)) {
        fs.mkdirSync(screenshotsDir, { recursive: true });
    }

    const inv = crearInvitacionEfimera();
    console.log(`Invitación efímera creada (ID: ${inv.invId})`);

    const browser = await chromium.launch({ headless: true });
    let createdUsername = null;

    try {
        // 1. Desktop 1366x768
        const context1366 = await browser.newContext({
            viewport: { width: 1366, height: 768 },
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
        });
        const page = await context1366.newPage();
        page.on('console', (msg) => console.log('BROWSER CONSOLE:', msg.text()));
        page.on('pageerror', (err) => console.log('BROWSER UNCAUGHT:', err));
        page.on('response', (res) => {
            if (res.url().includes('onboarding_guardar.php')) {
                console.log(`RESPONSE ${res.status()} ${res.url()}`);
                res.text().then((t) => console.log('RESPONSE BODY:', t)).catch(() => {});
            }
        });

        const url = `http://127.0.0.1:8001/auth/onboarding.php?token=${inv.token}`;
        await page.goto(url, { waitUntil: 'networkidle' });

        // PRUEBA 1: Verificar que NO existe franja roja vacía
        const generalError = page.locator('#onb-general-error');
        const isHidden = await generalError.evaluate((el) => {
            const style = window.getComputedStyle(el);
            return el.hidden || style.display === 'none' || el.offsetHeight === 0;
        });
        if (!isHidden) {
            throw new Error('FALLO: #onb-general-error es visible en la carga inicial (franja roja vacía detectada)');
        }
        console.log('  [PASS] Sin franja roja vacía en carga inicial');

        await page.screenshot({ path: path.join(screenshotsDir, 'onboarding_paso1_inicial_1366.png') });

        // PRUEBA 2: Validación de Paso 1 (campos obligatorios)
        await page.click('#btn-onb-continuar');
        const cedulaError = page.locator('#onb-identificacion-error');
        const isCedErrVisible = await cedulaError.isVisible();
        if (!isCedErrVisible) {
            throw new Error('FALLO: El error de cédula obligatoria no es visible tras pulsar continuar');
        }
        console.log('  [PASS] Validación inline de cédula en Paso 1 funciona');

        // Ingresar datos válidos de Paso 1
        const randomCedula = '1' + Math.floor(10000000 + Math.random() * 90000000);
        await page.fill('#onb-identificacion', randomCedula);
        await page.fill('#onb-nombre', 'Carlos Andrey');
        await page.fill('#onb-primer-apellido', 'Mora');
        await page.fill('#onb-segundo-apellido', 'Hernández');

        // PRUEBA 3: Navegación al Paso 2
        await page.click('#btn-onb-continuar');
        await page.waitForSelector('#onb-paso-2:not([hidden])');
        console.log('  [PASS] Transición a Paso 2 exitosa');

        // Verificar ubicación de ayudas debajo de los inputs
        await page.screenshot({ path: path.join(screenshotsDir, 'onboarding_paso2_ayudas_1366.png') });

        // PRUEBA 4: Volver a Paso 1 y verificar conservación de datos
        await page.click('#btn-onb-volver');
        await page.waitForSelector('#onb-paso-1:not([hidden])');
        const cedVal = await page.inputValue('#onb-identificacion');
        const nomVal = await page.inputValue('#onb-nombre');
        const ap1Val = await page.inputValue('#onb-primer-apellido');
        const ap2Val = await page.inputValue('#onb-segundo-apellido');
        if (cedVal !== randomCedula || nomVal !== 'Carlos Andrey' || ap1Val !== 'Mora' || ap2Val !== 'Hernández') {
            throw new Error('FALLO: Los datos del Paso 1 se perdieron al volver desde el Paso 2');
        }
        console.log('  [PASS] Conservación de datos intactos al navegar hacia atrás y adelante');

        // Volver a avanzar al Paso 2
        await page.click('#btn-onb-continuar');
        await page.waitForSelector('#onb-paso-2:not([hidden])');

        // PRUEBA 5: Validación inline de usuario inválido (con espacios o caracteres ilegales)
        await page.fill('#onb-usuario', 'usr con espacios');
        await page.fill('#onb-password', 'ClaveSegura2026!#');
        await page.fill('#onb-password-confirm', 'ClaveSegura2026!#');
        await page.click('#btn-submit-onboarding');

        // Debe mantenerse en Paso 2 y mostrar error debajo del campo de usuario
        const usrError = page.locator('#onb-usuario-error');
        await usrError.waitFor({ state: 'visible' });
        const usrErrText = await usrError.textContent();
        if (!usrErrText.includes('entre 3 y 80')) {
            throw new Error(`FALLO: Mensaje de error de usuario inesperado: ${usrErrText}`);
        }
        console.log('  [PASS] Usuario con espacios bloqueado en frontend con error contextual debajo del campo');

        // PRUEBA 6: Validación inline de contraseña corta (< 12 caracteres)
        await page.fill('#onb-usuario', 'carlos.mora');
        await page.fill('#onb-password', 'Corta123!');
        await page.fill('#onb-password-confirm', 'Corta123!');
        await page.click('#btn-submit-onboarding');

        const passError = page.locator('#onb-password-error');
        await passError.waitFor({ state: 'visible' });
        const passErrText = await passError.textContent();
        if (!passErrText.includes('12 caracteres')) {
            throw new Error(`FALLO: Mensaje de error de contraseña inesperado: ${passErrText}`);
        }
        console.log('  [PASS] Contraseña corta bloqueada en frontend con mensaje debajo del campo');

        // PRUEBA 7: Validación de confirmación no coincidente
        await page.fill('#onb-password', 'ClaveSegura2026!#');
        await page.fill('#onb-password-confirm', 'ClaveSegura2026!#DIFERENTE');
        await page.click('#btn-submit-onboarding');

        const confError = page.locator('#onb-password-confirm-error');
        await confError.waitFor({ state: 'visible' });
        console.log('  [PASS] Confirmación no coincidente reportada bajo el campo de confirmación');

        // Captura con los errores visibles
        await page.screenshot({ path: path.join(screenshotsDir, 'onboarding_paso2_errores_inline_1366.png') });

        // PRUEBA 8: Activación exitosa vía fetch asíncrono
        createdUsername = 'user.pw.' + Date.now();
        await page.fill('#onb-usuario', createdUsername);
        await page.fill('#onb-password', 'ClaveSeguraOnboarding2026!#');
        await page.fill('#onb-password-confirm', 'ClaveSeguraOnboarding2026!#');

        await page.click('#btn-submit-onboarding');

        // Esperar navegación exitosa
        await page.waitForURL(/exito=1/, { timeout: 10000 });
        const exitoTitle = await page.locator('h1.title').textContent();
        if (!exitoTitle.includes('Cuenta activada')) {
            throw new Error(`FALLO: Título de página de éxito no coincide: ${exitoTitle}`);
        }
        console.log('  [PASS] Activación exitosa con navegación suave a página de éxito');

        await page.screenshot({ path: path.join(screenshotsDir, 'onboarding_exito_1366.png') });
        await context1366.close();

        // PRUEBA 9: Capturas responsivas (1440x900, Tablet 768x1024, Móvil 375x667)
        // Crear segunda invitación para capturas responsivas
        const inv2 = crearInvitacionEfimera();

        // 1440x900
        const ctx1440 = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page1440 = await ctx1440.newPage();
        await page1440.goto(`http://127.0.0.1:8001/auth/onboarding.php?token=${inv2.token}`, { waitUntil: 'networkidle' });
        await page1440.screenshot({ path: path.join(screenshotsDir, 'onboarding_paso1_1440.png') });
        await ctx1440.close();

        // Tablet 768x1024
        const ctxTablet = await browser.newContext({ viewport: { width: 768, height: 1024 } });
        const pageTab = await ctxTablet.newPage();
        await pageTab.goto(`http://127.0.0.1:8001/auth/onboarding.php?token=${inv2.token}`, { waitUntil: 'networkidle' });
        await pageTab.fill('#onb-identificacion', '118880777');
        await pageTab.fill('#onb-nombre', 'Carlos Andrey');
        await pageTab.fill('#onb-primer-apellido', 'Mora');
        await pageTab.click('#btn-onb-continuar');
        await pageTab.waitForSelector('#onb-paso-2:not([hidden])');
        await pageTab.screenshot({ path: path.join(screenshotsDir, 'onboarding_paso2_tablet_768.png') });
        await ctxTablet.close();

        // Móvil 375x667
        const ctxMobile = await browser.newContext({ viewport: { width: 375, height: 667 }, isMobile: true });
        const pageMob = await ctxMobile.newPage();
        await pageMob.goto(`http://127.0.0.1:8001/auth/onboarding.php?token=${inv2.token}`, { waitUntil: 'networkidle' });
        await pageMob.fill('#onb-identificacion', '118880777');
        await pageMob.fill('#onb-nombre', 'Carlos Andrey');
        await pageMob.fill('#onb-primer-apellido', 'Mora');
        await pageMob.click('#btn-onb-continuar');
        await pageMob.waitForSelector('#onb-paso-2:not([hidden])');
        await pageMob.screenshot({ path: path.join(screenshotsDir, 'onboarding_paso2_mobile_375.png') });
        await ctxMobile.close();

        limpiarInvitacion(inv2.invId);
        console.log('  [PASS] Capturas responsivas en todas las resoluciones completadas');

        console.log('\nRESULTADO PLAYWRIGHT: TODAS LAS PRUEBAS EN NAVEGADOR REAL APROBADAS AL 100%');

    } finally {
        await browser.close();
        limpiarInvitacion(inv.invId);
        limpiarUsuario(createdUsername);
    }
}

runTest().catch((err) => {
    console.error('ERROR EN PRUEBA PLAYWRIGHT:', err);
    process.exit(1);
});
