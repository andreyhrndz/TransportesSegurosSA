const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const http = require('http');
const { execSync, spawn } = require('child_process');

const PHP_BIN = 'C:\\AdmBD2026\\Herramientas\\PHP\\8.4\\php.exe';

function checkServerReady(port) {
    return new Promise((resolve) => {
        const req = http.get(`http://127.0.0.1:${port}/auth/login.php`, (res) => {
            resolve(res.statusCode === 200);
        });
        req.on('error', () => resolve(false));
        req.setTimeout(1000, () => {
            req.destroy();
            resolve(false);
        });
    });
}

async function runVisualVerification() {
    console.log('=== INICIO DE VERIFICACIÓN VISUAL PLAYWRIGHT CON CUENTA EFÍMERA ===');

    const screenshotDir = path.join(__dirname, '..', 'docs', 'screenshots');
    if (!fs.existsSync(screenshotDir)) {
        fs.mkdirSync(screenshotDir, { recursive: true });
    }

    const port = 8085;
    let phpServer = null;
    const isRunning = await checkServerReady(port);

    if (!isRunning) {
        console.log(`Iniciando servidor local PHP 8.4 en puerto ${port}...`);
        const publicDir = path.join(__dirname, '..', 'public');
        phpServer = spawn(PHP_BIN, ['-S', `127.0.0.1:${port}`, '-t', publicDir], {
            env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test' },
            stdio: 'ignore'
        });

        for (let i = 0; i < 30; i++) {
            await new Promise(r => setTimeout(r, 200));
            if (await checkServerReady(port)) {
                console.log(`Servidor PHP activo en http://127.0.0.1:${port}`);
                break;
            }
        }
    } else {
        console.log(`Servidor PHP ya estaba activo en puerto ${port}`);
    }

    // Crear cuenta efímera controlada para no modificar admin_proyecto
    const helperPath = path.join(__dirname, 'ephemeral_auth_helper.php');
    const helperOutput = execSync(`"${PHP_BIN}" "${helperPath}" create`, {
        env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test' },
        encoding: 'utf8'
    });
    const ephemeralUser = JSON.parse(helperOutput.trim());
    console.log(`Cuenta efímera creada: ${ephemeralUser.username} (ID: ${ephemeralUser.user_id})`);

    const browser = await chromium.launch({ headless: true });

    try {
        // Test en resoluciones de laptop solicitadas (1366x768 y 1440x900)
        for (const resolution of [{ width: 1366, height: 768 }, { width: 1440, height: 900 }]) {
            console.log(`\n--- Probando resolución ${resolution.width}x${resolution.height} ---`);
            const context = await browser.newContext({ viewport: resolution });
            const page = await context.newPage();

            // 1. Autenticación con cuenta efímera
            await page.goto(`http://127.0.0.1:${port}/auth/login.php`);
            await page.fill('input[name="usuario"]', ephemeralUser.username);
            await page.fill('input[name="password"]', ephemeralUser.password);
            await page.click('button[type="submit"]');
            await page.waitForLoadState('networkidle');

            // 2. Verificar Entregas: selector de estado
            await page.goto(`http://127.0.0.1:${port}/entregas.php`);
            await page.waitForSelector('#entregas-filtros', { timeout: 10000 });

            const entregasSelect = await page.evaluate(() => {
                const selectDiv = document.querySelector('.filter-form .select');
                const selectEl = selectDiv ? selectDiv.querySelector('select') : null;
                if (!selectDiv || !selectEl) return null;

                const divRect = selectDiv.getBoundingClientRect();
                const elRect = selectEl.getBoundingClientRect();
                const elCs = window.getComputedStyle(selectEl);
                const afterCs = window.getComputedStyle(selectDiv, '::after');

                return {
                    divWidth: divRect.width,
                    elWidth: elRect.width,
                    widthDiff: Math.abs(divRect.width - elRect.width),
                    paddingRight: parseFloat(elCs.paddingRight),
                    afterRight: parseFloat(afterCs.right),
                    afterTransform: afterCs.transform,
                    chevronInsideBorder: elRect.right >= divRect.right - 2
                };
            });

            console.log('Entregas selector métricas:', JSON.stringify(entregasSelect, null, 2));
            if (entregasSelect) {
                if (entregasSelect.widthDiff > 1) {
                    throw new Error(`El elemento select no ocupa el ancho del contenedor (.select: ${entregasSelect.divWidth}px, select: ${entregasSelect.elWidth}px)`);
                }
                if (entregasSelect.paddingRight < 30) {
                    throw new Error(`Padding derecho insuficiente en select (${entregasSelect.paddingRight}px < 30px)`);
                }
            }

            await page.screenshot({ path: path.join(screenshotDir, `entregas_${resolution.width}x${resolution.height}.png`) });

            // 3. Verificar Flota - Catálogos: botones "Nuevo", tarjetas y contraste
            await page.goto(`http://127.0.0.1:${port}/flota.php?seccion=catalogos`);
            await page.waitForSelector('#flota-resultado .catalog-grid', { timeout: 10000 });

            const catalogEvaluation = await page.evaluate(() => {
                const panels = document.querySelectorAll('.catalog-card');
                const primaryBtns = document.querySelectorAll('.catalog-card .button.is-primary');

                const btnMetrics = Array.from(primaryBtns).map(btn => {
                    const cs = window.getComputedStyle(btn);
                    const span = btn.querySelector('span');
                    const spanCs = span ? window.getComputedStyle(span) : null;
                    const svg = btn.querySelector('svg');
                    const svgCs = svg ? window.getComputedStyle(svg) : null;

                    return {
                        btnText: btn.textContent.trim(),
                        btnBg: cs.backgroundColor,
                        btnColor: cs.color,
                        spanColor: spanCs ? spanCs.color : null,
                        spanFontSize: spanCs ? spanCs.fontSize : null,
                        svgStroke: svgCs ? svgCs.stroke : null
                    };
                });

                return {
                    panelCount: panels.length,
                    btnMetrics
                };
            });

            console.log('Evaluación de Catálogos:', JSON.stringify(catalogEvaluation, null, 2));
            if (catalogEvaluation.panelCount !== 3) {
                throw new Error(`Se esperaban 3 paneles de catálogo, encontrados: ${catalogEvaluation.panelCount}`);
            }
            for (const btn of catalogEvaluation.btnMetrics) {
                if (btn.spanColor !== 'rgb(255, 255, 255)') {
                    throw new Error(`Contraste insuficiente: el texto del botón "${btn.btnText}" es ${btn.spanColor}, se esperaba rgb(255, 255, 255)`);
                }
                if (btn.svgStroke !== 'rgb(255, 255, 255)') {
                    throw new Error(`Icono del botón "${btn.btnText}" no coincide en color: ${btn.svgStroke}`);
                }
            }

            await page.screenshot({ path: path.join(screenshotDir, `flota_catalogos_${resolution.width}x${resolution.height}.png`) });

            // 4. Probar apertura de modal en Catálogo ("Nuevo tipo de mercancía")
            const firstNewBtn = await page.$('.catalog-card .button.is-primary');
            await firstNewBtn.click();
            await page.waitForSelector('#modal-contenido .modal-card', { timeout: 5000 });

            await page.screenshot({ path: path.join(screenshotDir, `modal_nuevo_catalogo_${resolution.width}x${resolution.height}.png`) });

            // Cerrar modal
            await page.click('#modal-contenido button[data-close-modal]');
            await page.waitForFunction(() => {
                const m = document.querySelector('#modal');
                return !m || m.classList.contains('is-hidden') || m.innerHTML.trim() === '';
            });

            // 5. Verificar Flota - Vehículos: selector y modal "Nuevo vehículo" con campos PMA y clase legal
            await page.goto(`http://127.0.0.1:${port}/flota.php?seccion=vehiculos`);
            await page.waitForSelector('#flota-resultado', { timeout: 10000 });

            await page.screenshot({ path: path.join(screenshotDir, `flota_vehiculos_${resolution.width}x${resolution.height}.png`) });

            const newVehBtn = await page.$('.toolbar-panel .button.is-primary');
            if (newVehBtn) {
                await newVehBtn.click();
                await page.waitForSelector('#modal-contenido .modal-card', { timeout: 5000 });
                await page.waitForSelector('input[name="pma_kg"]', { timeout: 5000 });

                const vehFormCheck = await page.evaluate(() => {
                    const pmaInput = document.querySelector('input[name="pma_kg"]');
                    const claseSelect = document.querySelector('select[name="clase_legal"]');
                    const articuladoCheck = document.querySelector('input[name="es_articulado"]');
                    const homologacionSelect = document.querySelector('select[name="estado_homologacion"]');
                    const helpCallout = document.querySelector('.pma-help-callout');

                    return {
                        hasPma: Boolean(pmaInput),
                        hasClase: Boolean(claseSelect),
                        hasArticulado: Boolean(articuladoCheck),
                        hasHomologacion: Boolean(homologacionSelect),
                        hasCallout: Boolean(helpCallout)
                    };
                });

                console.log('Verificación campos Vehículo (PMA/Clase legal):', JSON.stringify(vehFormCheck, null, 2));
                if (!vehFormCheck.hasPma || !vehFormCheck.hasClase || !vehFormCheck.hasCallout) {
                    throw new Error('El modal de vehículo no incluye los campos normativos de PMA y clase legal');
                }

                await page.screenshot({ path: path.join(screenshotDir, `modal_nuevo_vehiculo_pma_${resolution.width}x${resolution.height}.png`) });

                // Cerrar modal
                await page.click('#modal-contenido button[data-close-modal]');
                await page.waitForFunction(() => {
                    const m = document.querySelector('#modal');
                    return !m || m.classList.contains('is-hidden') || m.innerHTML.trim() === '';
                });
            }

            // 6. Verificar Flota - Conductores: modal "Nuevo conductor" con catálogo de licencias oficiales
            await page.goto(`http://127.0.0.1:${port}/flota.php?seccion=conductores`);
            await page.waitForSelector('#flota-resultado', { timeout: 10000 });

            await page.screenshot({ path: path.join(screenshotDir, `flota_conductores_${resolution.width}x${resolution.height}.png`) });

            const newCondBtn = await page.$('.toolbar-panel .button.is-primary');
            if (newCondBtn) {
                await newCondBtn.click();
                await page.waitForSelector('#modal-contenido .modal-card', { timeout: 5000 });
                await page.waitForSelector('.license-selector-table', { timeout: 5000 });

                const condFormCheck = await page.evaluate(() => {
                    const table = document.querySelector('.license-selector-table');
                    const rows = table ? table.querySelectorAll('tbody tr') : [];
                    const checkboxes = table ? table.querySelectorAll('input[type="checkbox"][name="licencias_seleccionadas[]"]') : [];

                    return {
                        hasTable: Boolean(table),
                        categoryCount: rows.length,
                        checkboxCount: checkboxes.length
                    };
                });

                console.log('Verificación catálogo Licencias Conductor:', JSON.stringify(condFormCheck, null, 2));
                if (!condFormCheck.hasTable || condFormCheck.categoryCount < 10) {
                    throw new Error(`Se esperaban categorías oficiales de licencia en la tabla, encontradas: ${condFormCheck.categoryCount}`);
                }

                await page.screenshot({ path: path.join(screenshotDir, `modal_nuevo_conductor_licencias_${resolution.width}x${resolution.height}.png`) });

                // Cerrar modal
                await page.click('#modal-contenido button[data-close-modal]');
                await page.waitForFunction(() => {
                    const m = document.querySelector('#modal');
                    return !m || m.classList.contains('is-hidden') || m.innerHTML.trim() === '';
                });
            }

            // 7. Verificar Viajes: modal "Nuevo viaje" y diagnóstico interactivo de licencias/PMA
            await page.goto(`http://127.0.0.1:${port}/viajes.php`);
            await page.waitForSelector('#viajes-filtros', { timeout: 10000 });

            const newTripBtn = await page.$('.toolbar-panel .button.is-primary');
            if (newTripBtn) {
                await newTripBtn.click();
                await page.waitForSelector('#modal-contenido .modal-card', { timeout: 5000 });
                await page.waitForSelector('#aviso-diagnostico-habilitacion', { timeout: 5000, state: 'attached' });

                const tripDiagnosticoCheck = await page.evaluate(() => {
                    const diagBox = document.querySelector('#aviso-diagnostico-habilitacion');
                    const vehSelect = document.querySelector('select[name="vehiculo_id"]');
                    const condSelect = document.querySelector('select[name="conductor_id"]');

                    return {
                        hasDiagBox: Boolean(diagBox),
                        hasVehSelect: Boolean(vehSelect),
                        hasCondSelect: Boolean(condSelect),
                        diagContent: diagBox ? diagBox.textContent.trim() : ''
                    };
                });

                console.log('Verificación Diagnóstico Ley 9078 en Viajes:', JSON.stringify(tripDiagnosticoCheck, null, 2));
                if (!tripDiagnosticoCheck.hasDiagBox) {
                    throw new Error('El modal de viaje no incluye el contenedor de diagnóstico legal de habilitación');
                }

                await page.screenshot({ path: path.join(screenshotDir, `viajes_modal_diagnostico_pma_${resolution.width}x${resolution.height}.png`) });

                // Cerrar modal
                await page.click('#modal-contenido button[data-close-modal]');
                await page.waitForFunction(() => {
                    const m = document.querySelector('#modal');
                    return !m || m.classList.contains('is-hidden') || m.innerHTML.trim() === '';
                });
            }

            await context.close();
        }

        await browser.close();
        console.log('\n=== TODAS LAS VERIFICACIONES VISUALES PLAYWRIGHT COMPLETADAS CON ÉXITO ===');
    } finally {
        try {
            await browser.close();
        } catch {
            // Ignorar
        }
        if (phpServer) {
            console.log('Deteniendo servidor PHP local...');
            try {
                if (process.platform === 'win32') {
                    execSync(`taskkill /F /T /PID ${phpServer.pid} 2>nul`);
                } else {
                    phpServer.kill();
                }
            } catch {
                // Proceso ya cerrado
            }
        }
        if (ephemeralUser && ephemeralUser.user_id) {
            console.log(`Limpiando cuenta efímera: ${ephemeralUser.username}...`);
            try {
                execSync(`"${PHP_BIN}" "${helperPath}" cleanup ${ephemeralUser.user_id}`, {
                    env: { ...process.env, TSSA_DB_DATABASE: 'TransportesSegurosSA_Test' },
                    encoding: 'utf8'
                });
            } catch (cleanupErr) {
                console.error('Error al limpiar cuenta efímera:', cleanupErr.message);
            }
        }
    }
}

runVisualVerification().catch(err => {
    console.error('ERROR EN VERIFICACIÓN:', err);
    process.exit(1);
});
