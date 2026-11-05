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

function getFreePort() {
    return new Promise((resolve, reject) => {
        const s = net.createServer();
        s.listen(0, '127.0.0.1', () => {
            const port = s.address().port;
            s.close(() => resolve(port));
        });
        s.on('error', reject);
    });
}

async function runLoteVerification() {
    console.log('=== VERIFICACIÓN VISUAL PLAYWRIGHT: LOTE INTEGRAL UX, HACIENDA, DESTINATARIO, ONBOARDING Y VIAJES ===');

    const screenshotDir = path.join(__dirname, '..', 'docs', 'screenshots');
    if (!fs.existsSync(screenshotDir)) {
        fs.mkdirSync(screenshotDir, { recursive: true });
    }

    const testPort = await getFreePort();
    const BASE_URL = `http://127.0.0.1:${testPort}`;
    console.log(`Iniciando servidor PHP de prueba en puerto ${testPort}...`);
    const rootDir = path.join(__dirname, '..');
    const phpProcess = spawn(PHP_BIN, ['-S', `127.0.0.1:${testPort}`, '-t', path.join(rootDir, 'public')], {
        cwd: rootDir,
        env: {
            ...process.env,
            TSSA_DB_DATABASE: 'TransportesSegurosSA_Test',
            TSSA_DB_SERVER: 'localhost',
            TSSA_MAIL_TRANSPORT: 'fake',
            TSSA_APP_URL: BASE_URL
        },
        stdio: ['ignore', 'ignore', 'pipe']
    });

    for (let i = 0; i < 50; i++) {
        if (await checkPortOpen(testPort)) {
            break;
        }
        await new Promise((r) => setTimeout(r, 100));
    }
    console.log(`Servidor PHP activo en ${BASE_URL}`);

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
            console.log(`\n============================================================`);
            console.log(`VERIFICANDO RESOLUCIÓN ${resolution.width}x${resolution.height}`);
            console.log(`============================================================`);
            const context = await browser.newContext({ viewport: resolution });
            const page = await context.newPage();
            page.on('pageerror', err => console.log('  [BROWSER ERROR]:', err.message));
            page.on('console', msg => console.log('  [BROWSER CONSOLE]:', msg.text()));

            // -------------------------------------------------------------
            // 1. Pantalla pública de Recuperar Contraseña
            // -------------------------------------------------------------
            await page.goto(`${BASE_URL}/auth/recuperar.php`);
            await page.waitForSelector('form.login-form', { timeout: 8000 });
            await page.screenshot({ path: path.join(screenshotDir, `auth_recuperar_${resolution.width}x${resolution.height}.png`) });
            console.log('  [OK] Pantalla de recuperación capturada.');

            // -------------------------------------------------------------
            // 2. Pantalla de Login y Autenticación
            // -------------------------------------------------------------
            await page.goto(`${BASE_URL}/auth/login.php`);
            await page.waitForSelector('a[href="/auth/recuperar.php"]', { timeout: 8000 });
            await page.screenshot({ path: path.join(screenshotDir, `auth_login_${resolution.width}x${resolution.height}.png`) });
            console.log('  [OK] Pantalla de login capturada.');

            await page.fill('input[name="usuario"]', ephemeralUser.username);
            await page.fill('input[name="password"]', ephemeralUser.password);
            await page.click('button[type="submit"]');
            await page.waitForLoadState('networkidle');

            // -------------------------------------------------------------
            // 3. Solicitud: Destinatario condicional, radios reales y ausencia de código
            // -------------------------------------------------------------
            await page.goto(`${BASE_URL}/solicitudes.php`);
            await page.waitForSelector('.toolbar-panel .button.is-primary', { timeout: 8000 });
            await page.click('.toolbar-panel .button.is-primary');
            await page.waitForSelector('#modal-contenido .modal-card', { timeout: 8000 });

            // 3.1 Verificar ausencia total de campo Código de solicitud
            const hasCodigoInput = await page.evaluate(() => {
                const inputs = Array.from(document.querySelectorAll('#modal-contenido input'));
                return inputs.some(i => (i.value && i.value.includes('Código asignado')) || (i.name && i.name === 'codigo'));
            });
            if (hasCodigoInput) {
                throw new Error('El campo Código de solicitud todavía está presente en el modal.');
            }
            console.log('  [OK] Ausencia total de campo Código de solicitud verificada.');

            // 3.2 Verificar estado inicial antes de seleccionar cliente
            const initialRadioState = await page.evaluate(() => {
                const rMismo = document.getElementById('modo-mismo-cliente');
                const rTercero = document.getElementById('modo-tercero');
                const sec = document.getElementById('seccion-destinatario');
                const ayuda = document.getElementById('ayuda-modo-destinatario');
                return {
                    rMismoDisabled: rMismo ? rMismo.disabled : false,
                    rTerceroDisabled: rTercero ? rTercero.disabled : false,
                    secHidden: sec ? window.getComputedStyle(sec).display === 'none' : false,
                    ayudaTexto: ayuda ? ayuda.textContent.trim() : ''
                };
            });
            console.log('  Estado inicial sin cliente:', JSON.stringify(initialRadioState));
            if (!initialRadioState.rMismoDisabled || !initialRadioState.rTerceroDisabled) {
                throw new Error('Los radios de destinatario deben iniciar deshabilitados antes de elegir cliente.');
            }
            if (!initialRadioState.secHidden) {
                throw new Error('La sección de destinatario debe estar oculta antes de elegir cliente.');
            }
            console.log('  [OK] Estado inicial sin cliente verificado: radios deshabilitados y sección oculta.');

            // 3.3 Seleccionar cliente físico disponible
            await page.evaluate(() => {
                const sel = document.getElementById('solicitud-cliente');
                if (sel) {
                    for (let i = 0; i < sel.options.length; i++) {
                        if (sel.options[i].dataset.tipo === 'FISICA') {
                            sel.selectedIndex = i;
                            sel.dispatchEvent(new Event('change', { bubbles: true }));
                            break;
                        }
                    }
                }
            });
            await page.waitForTimeout(400);

            // Verificar cliente físico recién seleccionado: El mismo cliente checked, sección oculta, campos disabled
            const fisicaState = await page.evaluate(() => {
                const rMismo = document.getElementById('modo-mismo-cliente');
                const rTercero = document.getElementById('modo-tercero');
                const sec = document.getElementById('seccion-destinatario');
                const nom = document.getElementById('destinatario-nombre');
                return {
                    mismoChecked: rMismo ? rMismo.checked : false,
                    terceroChecked: rTercero ? rTercero.checked : false,
                    mismoEnabled: rMismo ? !rMismo.disabled : false,
                    terceroEnabled: rTercero ? !rTercero.disabled : false,
                    secHidden: sec ? window.getComputedStyle(sec).display === 'none' : false,
                    nomDisabled: nom ? nom.disabled : false
                };
            });
            console.log('  Estado con cliente físico:', JSON.stringify(fisicaState));
            if (!fisicaState.mismoChecked || fisicaState.terceroChecked || !fisicaState.secHidden || !fisicaState.nomDisabled) {
                throw new Error('Cliente físico debe activar modo "El mismo cliente" por defecto con sección oculta y campos disabled.');
            }
            console.log('  [OK] Cliente físico activa recepción propia por defecto.');

            // Captura estado 1: modo mismo cliente
            await page.screenshot({ path: path.join(screenshotDir, `modal_solicitud_mismo_cliente_${resolution.width}x${resolution.height}.png`) });

            // 3.4 Clic sobre el TEXTO del segundo radio ("Otra persona autorizada")
            await page.click('#texto-modo-tercero');
            await page.waitForTimeout(300);

            const terceroState = await page.evaluate(() => {
                const rMismo = document.getElementById('modo-mismo-cliente');
                const rTercero = document.getElementById('modo-tercero');
                const sec = document.getElementById('seccion-destinatario');
                const nom = document.getElementById('destinatario-nombre');
                const ap1 = document.getElementById('destinatario-apellido1');
                return {
                    mismoChecked: rMismo ? rMismo.checked : false,
                    terceroChecked: rTercero ? rTercero.checked : false,
                    secVisible: sec ? window.getComputedStyle(sec).display !== 'none' : false,
                    nomEnabled: nom ? !nom.disabled : false,
                    nomRequired: nom ? nom.required : false,
                    ap1Required: ap1 ? ap1.required : false
                };
            });
            console.log('  Estado tras clic en texto "Otra persona autorizada":', JSON.stringify(terceroState));
            if (terceroState.mismoChecked || !terceroState.terceroChecked || !terceroState.secVisible || !terceroState.nomEnabled || !terceroState.nomRequired) {
                throw new Error('Al hacer clic en texto de tercero, debe marcarse rTercero, desmarcarse rMismo y habilitarse los campos.');
            }
            console.log('  [OK] Clic sobre texto conmuta a tercero y despliega sección.');

            // Escribir datos de prueba para verificar persistencia tras conmutar
            await page.fill('#destinatario-nombre', 'Receptor Temporal');

            // Captura estado 2: modo tercero visible
            await page.screenshot({ path: path.join(screenshotDir, `modal_solicitud_tercero_visible_${resolution.width}x${resolution.height}.png`) });

            // 3.5 Clic sobre el CÍRCULO del primer radio (input#modo-mismo-cliente)
            await page.click('#modo-mismo-cliente');
            await page.waitForTimeout(200);

            const vueltoMismo = await page.evaluate(() => {
                const rMismo = document.getElementById('modo-mismo-cliente');
                const rTercero = document.getElementById('modo-tercero');
                const sec = document.getElementById('seccion-destinatario');
                const nom = document.getElementById('destinatario-nombre');
                return {
                    mismoChecked: rMismo ? rMismo.checked : false,
                    terceroChecked: rTercero ? rTercero.checked : false,
                    secHidden: sec ? window.getComputedStyle(sec).display === 'none' : false,
                    nomDisabled: nom ? nom.disabled : false,
                    nomPreservedValue: nom ? nom.value : ''
                };
            });
            if (!vueltoMismo.mismoChecked || vueltoMismo.terceroChecked || !vueltoMismo.secHidden || !vueltoMismo.nomDisabled) {
                throw new Error('Al hacer clic en círculo de mismo cliente, debe ocultarse la sección y deshabilitarse los campos.');
            }
            if (vueltoMismo.nomPreservedValue !== 'Receptor Temporal') {
                throw new Error('Los datos escritos no deben destruirse al volver a mismo cliente.');
            }
            console.log('  [OK] Conmutación inversa oculta campos sin destruir datos escritos.');

            // 3.6 Ejecutar alternancias repetidas mismo -> tercero -> mismo -> tercero
            for (let i = 0; i < 3; i++) {
                await page.click('#modo-tercero');
                await page.click('#modo-mismo-cliente');
            }
            console.log('  [OK] Alternancias rápidas mismo/tercero ejecutadas sin bloqueos de radio.');

            // 3.7 Cambiar a persona Jurídica
            await page.evaluate(() => {
                const sel = document.getElementById('solicitud-cliente');
                if (sel) {
                    for (let i = 0; i < sel.options.length; i++) {
                        if (sel.options[i].dataset.tipo === 'JURIDICA') {
                            sel.selectedIndex = i;
                            sel.dispatchEvent(new Event('change', { bubbles: true }));
                            break;
                        }
                    }
                }
            });
            await page.waitForTimeout(400);

            const juridicaCheck = await page.evaluate(() => {
                const rMismo = document.getElementById('modo-mismo-cliente');
                const rTercero = document.getElementById('modo-tercero');
                const labelMismo = document.getElementById('label-mismo-cliente');
                const sec = document.getElementById('seccion-destinatario');
                return {
                    labelMismoHidden: labelMismo ? labelMismo.style.display === 'none' : false,
                    terceroChecked: rTercero ? rTercero.checked : false,
                    secVisible: sec ? window.getComputedStyle(sec).display !== 'none' : false
                };
            });
            if (!juridicaCheck.labelMismoHidden || !juridicaCheck.terceroChecked || !juridicaCheck.secVisible) {
                throw new Error('Cliente jurídico debe ocultar opción de mismo cliente y forzar receptor físico autorizado.');
            }
            console.log('  [OK] Cliente jurídico fuerza obligatoriamente receptor físico autorizado.');

            // Cerrar modal
            await page.click('#modal-contenido button[data-close-modal]');
            await page.waitForFunction(() => {
                const m = document.querySelector('#modal-contenido');
                return !m || m.classList.contains('is-hidden') || m.innerHTML.trim() === '';
            });

            // -------------------------------------------------------------
            // 4. Viajes: Formulario de alta SIN campo de código de viaje
            // -------------------------------------------------------------
            await page.goto(`${BASE_URL}/viajes.php`);
            await page.waitForSelector('.toolbar-panel .button.is-primary', { timeout: 8000 });
            await page.click('.toolbar-panel .button.is-primary');
            await page.waitForSelector('#modal-contenido .modal-card', { timeout: 8000 });

            const viajeEval = await page.evaluate(() => {
                const modal = document.querySelector('#modal-contenido .modal-card');
                const inputs = Array.from(modal.querySelectorAll('input'));
                const hasCodigo = inputs.some(i => (i.value && i.value.includes('Código asignado')) || (i.name && i.name === 'codigo'));
                const firstLabel = modal.querySelector('.modal-card-body .field label');
                return {
                    hasCodigo,
                    firstLabelText: firstLabel ? firstLabel.textContent.trim() : ''
                };
            });
            console.log('  Evaluación modal Viaje:', JSON.stringify(viajeEval));
            if (viajeEval.hasCodigo) {
                throw new Error('El campo Código de viaje todavía está presente en el modal de alta de viaje.');
            }
            if (!viajeEval.firstLabelText.includes('Vehículo disponible')) {
                throw new Error(`El formulario de viaje debe iniciar directamente con Vehículo disponible, encontrado: "${viajeEval.firstLabelText}"`);
            }
            console.log('  [OK] Formulario de viaje inicia directamente con Vehículo disponible sin código.');
            await page.screenshot({ path: path.join(screenshotDir, `modal_viaje_sin_codigo_${resolution.width}x${resolution.height}.png`) });

            // Cerrar modal viaje
            await page.click('#modal-contenido button[data-close-modal]');
            await page.waitForFunction(() => {
                const m = document.querySelector('#modal-contenido');
                return !m || m.classList.contains('is-hidden') || m.innerHTML.trim() === '';
            });

            // -------------------------------------------------------------
            // 5. Onboarding: Diseño de escritorio centrado y Wizard de 2 Pasos
            // -------------------------------------------------------------
            const invOutput = execSync(`"${PHP_BIN}" "${helperPath}" create_invitation`, {
                env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test', TSSA_DB_SERVER: 'localhost' },
                encoding: 'utf8'
            });
            const ephemeralInv = JSON.parse(invOutput.trim());
            console.log(`Invitación efímera creada para onboarding: ID ${ephemeralInv.invitacion_id} (${ephemeralInv.correo})`);

            await page.goto(`${BASE_URL}/auth/onboarding.php?token=${ephemeralInv.token}`);
            await page.waitForSelector('#form-onboarding', { timeout: 8000 });

            // 5.1 Verificar centrado real en desktop (no 2 columnas, shell centrado)
            const layoutCheck = await page.evaluate(() => {
                const shell = document.querySelector('.onboarding-shell');
                const body = document.body;
                const rect = shell ? shell.getBoundingClientRect() : null;
                const bodyWidth = window.innerWidth;
                const isCentered = rect ? Math.abs((rect.left) - (bodyWidth - rect.right)) < 25 : false;
                const hasLoginPageClass = body.classList.contains('login-page');
                return {
                    hasLoginPageClass,
                    isCentered,
                    shellWidth: rect ? rect.width : 0,
                    bodyWidth
                };
            });
            console.log('  Evaluación layout onboarding desktop:', JSON.stringify(layoutCheck));
            if (layoutCheck.hasLoginPageClass) {
                throw new Error('Onboarding no debe usar la clase .login-page');
            }
            if (!layoutCheck.isCentered) {
                throw new Error('La tarjeta de onboarding debe estar centrada en la pantalla de escritorio.');
            }
            console.log('  [OK] Onboarding perfectamente centrado en pantalla de escritorio.');

            await page.screenshot({ path: path.join(screenshotDir, `onboarding_paso1_${resolution.width}x${resolution.height}.png`) });

            // 5.2 Intentar continuar con campos vacíos -> debe bloquear y mostrar aviso
            await page.click('#btn-onb-continuar');
            const validationBlocked = await page.evaluate(() => {
                const err = document.getElementById('onb-general-error');
                const paso2 = document.getElementById('onb-paso-2');
                return {
                    errVisible: err ? (!err.hidden && err.textContent.trim().length > 0) : false,
                    paso2Hidden: paso2 ? paso2.hidden : false
                };
            });
            if (!validationBlocked.errVisible || !validationBlocked.paso2Hidden) {
                throw new Error('No debe permitirse avanzar al paso 2 con campos obligatorios vacíos.');
            }
            console.log('  [OK] Validación de campos del paso 1 bloquea avance erróneo.');

            // 5.3 Probar autocompletado de Hacienda en Onboarding y verificar distribución de nombres
            await page.fill('#onb-identificacion', '208820984');
            await page.click('#btn-onb-hacienda');
            // Esperar resolución de Hacienda (llamada externa HTTPS)
            await page.waitForFunction(() => {
                const el = document.getElementById('onb-nombre');
                return el && el.value.trim().length > 0;
            }, { timeout: 10000 });

            const haciendaDecomp = await page.evaluate(() => {
                const nom = document.getElementById('onb-nombre');
                const ap1 = document.getElementById('onb-primer-apellido');
                const ap2 = document.getElementById('onb-segundo-apellido');
                const help = document.getElementById('onb-hacienda-help');
                return {
                    nombre: nom ? nom.value : '',
                    apellido1: ap1 ? ap1.value : '',
                    apellido2: ap2 ? ap2.value : '',
                    helpSuccessClass: help ? help.classList.contains('has-text-success') : false
                };
            });
            console.log('  Resultado autocompletado Hacienda Onboarding:', JSON.stringify(haciendaDecomp));
            if (haciendaDecomp.nombre !== 'ANDREY JOSUE' || haciendaDecomp.apellido1 !== 'TIJERINO' || haciendaDecomp.apellido2 !== 'HERNANDEZ') {
                throw new Error(`Distribución incorrecta de nombres desde Hacienda: recibido ${JSON.stringify(haciendaDecomp)}`);
            }
            if (haciendaDecomp.helpSuccessClass) {
                throw new Error('No debe mostrarse banner verde llamativo de éxito tras autocompletar.');
            }
            console.log('  [OK] Distribución de nombres de Hacienda verificada: ANDREY JOSUE / TIJERINO / HERNANDEZ sin banner intrusivo.');

            // 5.4 Continuar al Paso 2
            await page.click('#btn-onb-continuar');
            await page.waitForFunction(() => {
                const p2 = document.getElementById('onb-paso-2');
                return p2 && !p2.hidden;
            });
            console.log('  [OK] Navegación al Paso 2 completada.');

            // 5.5 Verificar botón primario a la izquierda de botón secundario en Paso 2
            const step2Buttons = await page.evaluate(() => {
                const submitBtn = document.getElementById('btn-submit-onboarding');
                const volverBtn = document.getElementById('btn-onb-volver');
                const subRect = submitBtn ? submitBtn.getBoundingClientRect() : null;
                const volRect = volverBtn ? volverBtn.getBoundingClientRect() : null;
                return {
                    primaryAtLeft: (subRect && volRect) ? (subRect.left < volRect.left) : false
                };
            });
            if (!step2Buttons.primaryAtLeft) {
                throw new Error('En el paso 2, el botón primario "Activar mi cuenta" debe estar a la izquierda de "Volver".');
            }
            console.log('  [OK] Botón primario a la izquierda del secundario verificado en paso 2.');

            await page.screenshot({ path: path.join(screenshotDir, `onboarding_paso2_${resolution.width}x${resolution.height}.png`) });

            // 5.6 Volver al Paso 1 y comprobar persistencia
            await page.click('#btn-onb-volver');
            await page.waitForFunction(() => {
                const p1 = document.getElementById('onb-paso-1');
                return p1 && !p1.hidden;
            });
            const preservedPaso1 = await page.evaluate(() => {
                const nom = document.getElementById('onb-nombre');
                return nom ? nom.value : '';
            });
            if (preservedPaso1 !== 'ANDREY JOSUE') {
                throw new Error('Los datos del paso 1 se perdieron al volver desde el paso 2.');
            }
            console.log('  [OK] Persistencia de datos al volver al paso 1 confirmada.');

            // 5.7 Volver a avanzar al Paso 2 y completar activación
            await page.click('#btn-onb-continuar');
            await page.waitForFunction(() => {
                const p2 = document.getElementById('onb-paso-2');
                return p2 && !p2.hidden;
            });

            const onbUser = 'onb.user.' + bin2hex(4);
            await page.fill('#onb-usuario', onbUser);
            await page.fill('#onb-password', 'ClaveSeguraOnboarding2026!');
            await page.fill('#onb-password-confirm', 'ClaveSeguraOnboarding2026!');
            await page.click('#btn-submit-onboarding');
            await page.waitForLoadState('networkidle');

            // Verificar resultado en onboarding_guardar.php
            const successOnboarding = await page.evaluate(() => {
                const h1 = document.querySelector('h1.title');
                const notice = document.querySelector('.notification-success');
                const danger = document.querySelector('.notification-danger');
                return {
                    title: h1 ? h1.textContent.trim() : '',
                    hasSuccessNotice: Boolean(notice),
                    dangerText: danger ? danger.textContent.trim() : ''
                };
            });
            console.log('  Resultado guardado de onboarding:', JSON.stringify(successOnboarding));
            if (!successOnboarding.title.includes('¡Cuenta activada!') || !successOnboarding.hasSuccessNotice) {
                throw new Error('La activación de cuenta no completó exitosamente.');
            }
            console.log('  [OK] Activación transaccional de cuenta efímera completada con éxito.');
            await page.screenshot({ path: path.join(screenshotDir, `onboarding_completado_${resolution.width}x${resolution.height}.png`) });

            // Limpiar cuenta efímera y perfil creados en onboarding
            try {
                execSync(`"${PHP_BIN}" "${helperPath}" cleanup_onboarding_user 208820984`, {
                    env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test', TSSA_DB_SERVER: 'localhost' },
                    encoding: 'utf8'
                });
                execSync(`"${PHP_BIN}" "${helperPath}" cleanup_onboarding_user ${onbUser}`, {
                    env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test', TSSA_DB_SERVER: 'localhost' },
                    encoding: 'utf8'
                });
                execSync(`"${PHP_BIN}" "${helperPath}" cleanup_invitation ${ephemeralInv.invitacion_id}`, {
                    env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test', TSSA_DB_SERVER: 'localhost' },
                    encoding: 'utf8'
                });
                console.log(`  [OK] Datos efímeros de onboarding limpiados.`);
            } catch {}

            await context.close();
        }

        await browser.close();
        console.log('\n=== TODAS LAS VERIFICACIONES VISUALES Y FUNCIONALES PASARON AL 100% ===');
    } finally {
        try {
            await browser.close();
        } catch {}
        if (phpProcess) {
            try {
                if (process.platform === 'win32' && phpProcess.pid) {
                    execSync(`taskkill /F /T /PID ${phpProcess.pid} 2>nul`);
                } else {
                    phpProcess.kill();
                }
            } catch {}
        }
        if (ephemeralUser && ephemeralUser.user_id) {
            console.log(`Limpiando cuenta efímera: ${ephemeralUser.username}...`);
            try {
                execSync(`"${PHP_BIN}" "${helperPath}" cleanup ${ephemeralUser.user_id}`, {
                    env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test', TSSA_DB_SERVER: 'localhost' },
                    encoding: 'utf8'
                });
            } catch {}
        }
    }
}

function bin2hex(len) {
    const crypto = require('crypto');
    return crypto.randomBytes(len).toString('hex');
}

runLoteVerification().catch(err => {
    console.error('ERROR EN VERIFICACIÓN LOTE:', err);
    process.exit(1);
});
