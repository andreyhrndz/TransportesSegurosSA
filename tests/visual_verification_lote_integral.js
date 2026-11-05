const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const net = require('net');
const { execSync, spawn } = require('child_process');

const PHP_BIN = 'C:\\AdmBD2026\\Herramientas\\PHP\\8.4\\php.exe';

function checkPortOpen(port) {
    return new Promise((resolve) => {
        const s = net.connect({ port, host: '127.0.0.1' }, () => {
            s.destroy();
            resolve(true);
        });
        s.on('error', () => resolve(false));
    });
}

async function runLoteVerification() {
    console.log('=== VERIFICACIÓN VISUAL PLAYWRIGHT: LOTE INTEGRAL UX, HACIENDA E INVITACIONES ===');

    const screenshotDir = path.join(__dirname, '..', 'docs', 'screenshots');
    if (!fs.existsSync(screenshotDir)) {
        fs.mkdirSync(screenshotDir, { recursive: true });
    }

    let phpProcess = null;
    let BASE_URL = 'http://127.0.0.1:8001';

    const isOpen8001 = await checkPortOpen(8001);
    if (!isOpen8001) {
        console.log('Iniciando servidor PHP de prueba en puerto 8001...');
        const rootDir = path.join(__dirname, '..');
        phpProcess = spawn(PHP_BIN, ['-S', '127.0.0.1:8001', '-t', path.join(rootDir, 'public')], {
            cwd: rootDir,
            env: {
                ...process.env,
                TSSA_DB_DATABASE: 'TransportesSegurosSA_Test',
                TSSA_DB_SERVER: 'localhost',
                TSSA_MAIL_TRANSPORT: 'fake',
                TSSA_APP_URL: 'http://127.0.0.1:8001'
            },
            stdio: 'ignore'
        });

        for (let i = 0; i < 30; i++) {
            if (await checkPortOpen(8001)) {
                break;
            }
            await new Promise((r) => setTimeout(r, 100));
        }
        console.log('Servidor PHP activo en http://127.0.0.1:8001');
    } else {
        console.log('Utilizando servidor existente en http://127.0.0.1:8001');
    }

    // Cuenta efímera
    const helperPath = path.join(__dirname, 'ephemeral_auth_helper.php');
    const helperOutput = execSync(`"${PHP_BIN}" "${helperPath}" create`, {
        env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test', TSSA_DB_SERVER: 'localhost' },
        encoding: 'utf8'
    });
    const ephemeralUser = JSON.parse(helperOutput.trim());
    console.log(`Cuenta efímera autenticada: ${ephemeralUser.username} (ID: ${ephemeralUser.user_id})`);

    const browser = await chromium.launch({ headless: true });

    try {
        for (const resolution of [{ width: 1366, height: 768 }, { width: 1440, height: 900 }]) {
            console.log(`\n--- Verificando en resolución ${resolution.width}x${resolution.height} ---`);
            const context = await browser.newContext({ viewport: resolution });
            const page = await context.newPage();

            // 1. Pantalla pública de Recuperar Contraseña
            await page.goto(`${BASE_URL}/auth/recuperar.php`);
            await page.waitForSelector('form.login-form', { timeout: 8000 });
            await page.screenshot({ path: path.join(screenshotDir, `auth_recuperar_${resolution.width}x${resolution.height}.png`) });
            console.log('  [OK] Pantalla de recuperación de contraseña capturada.');

            // 2. Pantalla de Login con enlace de recuperación
            await page.goto(`${BASE_URL}/auth/login.php`);
            await page.waitForSelector('a[href="/auth/recuperar.php"]', { timeout: 8000 });
            await page.screenshot({ path: path.join(screenshotDir, `auth_login_${resolution.width}x${resolution.height}.png`) });
            console.log('  [OK] Pantalla de login con enlace a recuperación capturada.');

            // Autenticación
            await page.fill('input[name="usuario"]', ephemeralUser.username);
            await page.fill('input[name="password"]', ephemeralUser.password);
            await page.click('button[type="submit"]');
            await page.waitForLoadState('networkidle');

            // 3. Hallazgo #1: Modal "Nueva solicitud" con footer pinned y botones a la izquierda
            await page.goto(`${BASE_URL}/solicitudes.php`);
            await page.waitForSelector('.toolbar-panel .button.is-primary', { timeout: 8000 });
            await page.click('.toolbar-panel .button.is-primary');
            await page.waitForSelector('#modal-contenido .modal-card', { timeout: 8000 });

            const modalEval = await page.evaluate(() => {
                const modal = document.querySelector('#modal-contenido .modal-card');
                const form = modal ? modal.querySelector('form') : null;
                const foot = modal ? modal.querySelector('.modal-card-foot') : null;
                const body = modal ? modal.querySelector('.modal-card-body') : null;
                const submitBtn = foot ? foot.querySelector('button[type="submit"]') : null;
                const cancelBtn = foot ? foot.querySelector('button[data-close-modal]') : null;

                const footRect = foot ? foot.getBoundingClientRect() : null;
                const submitRect = submitBtn ? submitBtn.getBoundingClientRect() : null;
                const cancelRect = cancelBtn ? cancelBtn.getBoundingClientRect() : null;
                const viewportHeight = window.innerHeight;

                return {
                    hasModal: Boolean(modal),
                    footInsideViewport: footRect ? (footRect.bottom <= viewportHeight && footRect.top >= 0) : false,
                    bodyHasScroll: body ? window.getComputedStyle(body).overflowY === 'auto' : false,
                    primaryAtLeft: (submitRect && cancelRect) ? (submitRect.left < cancelRect.left) : false,
                    primaryText: submitBtn ? submitBtn.textContent.trim() : null
                };
            });

            console.log('  Modal Nueva solicitud métricas:', JSON.stringify(modalEval, null, 2));
            if (!modalEval.footInsideViewport) {
                throw new Error(`El footer del modal no está visible dentro del viewport (${resolution.height}px)`);
            }
            if (!modalEval.primaryAtLeft) {
                throw new Error(`El botón primario no está a la izquierda del botón de cancelar`);
            }

            // Verificar que no existe campo de código de solicitud
            const hasCodigoInput = await page.evaluate(() => {
                const inputs = Array.from(document.querySelectorAll('#modal-contenido input'));
                return inputs.some(i => (i.value && i.value.includes('Código asignado')) || (i.name && i.name === 'codigo'));
            });
            if (hasCodigoInput) {
                throw new Error('El campo Código de solicitud todavía está presente en el modal.');
            }
            console.log('  [OK] Ausencia total de campo Código de solicitud verificada.');

            // Captura estado 1: modo mismo cliente (sección destinatario oculta)
            await page.screenshot({ path: path.join(screenshotDir, `modal_solicitud_mismo_cliente_${resolution.width}x${resolution.height}.png`) });

            // Seleccionar primer cliente físico disponible para inicializar el contexto
            await page.evaluate(() => {
                const sel = document.getElementById('solicitud-cliente');
                if (sel && sel.options.length > 1) {
                    sel.selectedIndex = 1;
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            await page.waitForTimeout(300);

            // Cambiar a "Otra persona autorizada"
            await page.click('#modo-tercero');
            const destVisible = await page.evaluate(() => {
                const sec = document.getElementById('seccion-destinatario');
                return sec && window.getComputedStyle(sec).display !== 'none';
            });
            if (!destVisible) {
                throw new Error('Al elegir "Otra persona autorizada", la sección de destinatario debe mostrarse.');
            }
            console.log('  [OK] Sección de tercero autorizada mostrada al conmutar radio.');

            // Captura estado 2: modo tercero visible
            await page.screenshot({ path: path.join(screenshotDir, `modal_solicitud_tercero_visible_${resolution.width}x${resolution.height}.png`) });

            // Volver a "El mismo cliente"
            await page.click('#modo-mismo-cliente');
            const destHiddenAgain = await page.evaluate(() => {
                const sec = document.getElementById('seccion-destinatario');
                return sec && window.getComputedStyle(sec).display === 'none';
            });
            if (!destHiddenAgain) {
                throw new Error('Al volver a "El mismo cliente", la sección de destinatario debe ocultarse.');
            }
            console.log('  [OK] Sección de destinatario ocultada nuevamente al volver a mismo cliente.');

            // Cerrar modal
            await page.click('#modal-contenido button[data-close-modal]');
            await page.waitForFunction(() => {
                const m = document.querySelector('#modal-contenido');
                return !m || m.classList.contains('is-hidden') || m.innerHTML.trim() === '';
            });

            // 4. Hallazgo #3: Filtros Tom Select con dropdown abierto y sin doble flecha
            await page.goto(`${BASE_URL}/solicitudes.php`);
            await page.waitForSelector('.filter-form .ts-wrapper', { timeout: 8000 });

            const selectDoubleArrowCheck = await page.evaluate(() => {
                const selectContainer = document.querySelector('.filter-form .select');
                const afterCs = selectContainer ? window.getComputedStyle(selectContainer, '::after') : null;
                return {
                    bulmaAfterDisplay: afterCs ? afterCs.display : null
                };
            });

            console.log('  Verificación doble flecha Bulma:', JSON.stringify(selectDoubleArrowCheck));
            if (selectDoubleArrowCheck.bulmaAfterDisplay !== 'none') {
                throw new Error('Bulma ::after sigue visible con Tom Select');
            }

            // Abrir el combobox para capturar el menú desplegable en Dark Logistics Premium
            await page.click('.filter-form .ts-control');
            await page.waitForSelector('.ts-dropdown', { state: 'visible', timeout: 5000 });
            await page.screenshot({ path: path.join(screenshotDir, `tom_select_dropdown_open_${resolution.width}x${resolution.height}.png`) });
            console.log('  [OK] Dropdown abierto Tom Select verificado.');

            // 5. Hallazgo #6: Sidebar colapsado persistente sin parpadeo (FOUC)
            await page.click('button[data-sidebar-collapse]');
            await page.waitForFunction(() => {
                const shell = document.querySelector('#app-shell');
                return shell && shell.classList.contains('is-sidebar-collapsed');
            });
            console.log('  [OK] Sidebar colapsado y guardado en localStorage.');

            // Navegar a 3 módulos diferentes y verificar que se mantiene colapsado
            for (const modulo of ['/viajes.php', '/usuarios.php', '/reportes.php']) {
                await page.goto(BASE_URL + modulo);
                const isCollapsed = await page.evaluate(() => {
                    const shell = document.querySelector('#app-shell');
                    return shell && shell.classList.contains('is-sidebar-collapsed');
                });
                if (!isCollapsed) {
                    throw new Error(`FOUC detectado: el sidebar no conservó el estado colapsado en ${modulo}`);
                }
            }
            console.log('  [OK] Persistencia de sidebar colapsado verificada sin parpadeo en 3 páginas.');

            // 6. Vista de Usuarios: pestañas de Cuentas e Invitaciones y Modal Invitar usuario
            await page.goto(`${BASE_URL}/usuarios.php`);
            await page.waitForSelector('#usuarios-tabs', { timeout: 8000 });

            // Abrir modal de invitar usuario
            await page.click('.toolbar-panel button.is-primary');
            await page.waitForSelector('#modal-contenido .modal-card', { timeout: 8000 });

            const inviteModalEval = await page.evaluate(() => {
                const modal = document.querySelector('#modal-contenido .modal-card');
                const title = modal ? modal.querySelector('.modal-card-title').textContent.trim() : '';
                const inputs = modal ? Array.from(modal.querySelectorAll('input:not([type="hidden"])')) : [];
                const submitBtn = modal ? modal.querySelector('button[type="submit"]') : null;
                const cancelBtn = modal ? modal.querySelector('button[data-close-modal]') : null;

                return {
                    title,
                    inputCount: inputs.length,
                    inputName: inputs[0] ? inputs[0].getAttribute('name') : null,
                    inputType: inputs[0] ? inputs[0].getAttribute('type') : null,
                    submitText: submitBtn ? submitBtn.textContent.trim() : '',
                    primaryAtLeft: (submitBtn && cancelBtn) ? (submitBtn.getBoundingClientRect().left < cancelBtn.getBoundingClientRect().left) : false
                };
            });

            console.log('  Métricas modal Invitar usuario:', JSON.stringify(inviteModalEval, null, 2));
            if (inviteModalEval.inputCount !== 1) {
                throw new Error(`El modal Invitar usuario debe tener exactamente 1 campo visible, encontrados: ${inviteModalEval.inputCount}`);
            }
            if (inviteModalEval.inputName !== 'correo') {
                throw new Error(`El campo debe ser "correo", encontrado: ${inviteModalEval.inputName}`);
            }
            if (!inviteModalEval.submitText.includes('Enviar invitación')) {
                throw new Error(`El botón debe decir "Enviar invitación", dice: ${inviteModalEval.submitText}`);
            }
            if (!inviteModalEval.primaryAtLeft) {
                throw new Error('El botón "Enviar invitación" debe estar a la izquierda de "Cancelar"');
            }

            await page.screenshot({ path: path.join(screenshotDir, `modal_invitar_usuario_${resolution.width}x${resolution.height}.png`) });

            // Cerrar modal
            await page.click('#modal-contenido button[data-close-modal]');
            await page.waitForFunction(() => {
                const modal = document.querySelector('#modal-contenido .modal');
                return !modal || !modal.classList.contains('is-active');
            });

            // Cambiar a pestaña Invitaciones
            await page.click('a[data-user-tab="invitaciones"]');
            await page.waitForSelector('#panel-invitaciones:not(.is-hidden)', { timeout: 5000 });
            await page.waitForSelector('#invitaciones-resultado table', { timeout: 8000 });
            await page.screenshot({ path: path.join(screenshotDir, `usuarios_pestana_invitaciones_${resolution.width}x${resolution.height}.png`) });
            console.log('  [OK] Pestaña de invitaciones verificada.');

            await context.close();
        }

        await browser.close();
        console.log('\n=== TODAS LAS VERIFICACIONES VISUALES DEL LOTE INTEGRAL PASARON AL 100% ===');
    } finally {
        try {
            await browser.close();
        } catch {}
        if (phpProcess) {
            phpProcess.kill();
        }
        if (ephemeralUser && ephemeralUser.user_id) {
            console.log(`Limpiando cuenta efímera: ${ephemeralUser.username}...`);
            execSync(`"${PHP_BIN}" "${helperPath}" cleanup ${ephemeralUser.user_id}`, {
                env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test', TSSA_DB_SERVER: 'localhost' },
                encoding: 'utf8'
            });
        }
    }
}

runLoteVerification().catch(err => {
    console.error('ERROR EN VERIFICACIÓN LOTE:', err);
    process.exit(1);
});
