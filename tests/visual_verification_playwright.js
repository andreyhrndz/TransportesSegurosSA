const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

async function runVisualVerification() {
    console.log('=== INICIO DE VERIFICACIÓN VISUAL PLAYWRIGHT ===');

    const screenshotDir = path.join(__dirname, '..', 'docs', 'screenshots');
    if (!fs.existsSync(screenshotDir)) {
        fs.mkdirSync(screenshotDir, { recursive: true });
    }

    const browser = await chromium.launch({ headless: true });

    // Test en resoluciones de laptop solicitadas (1366x768 y 1440x900)
    for (const resolution of [{ width: 1366, height: 768 }, { width: 1440, height: 900 }]) {
        console.log(`\n--- Probando resolución ${resolution.width}x${resolution.height} ---`);
        const context = await browser.newContext({ viewport: resolution });
        const page = await context.newPage();

        // 1. Autenticación
        await page.goto('http://127.0.0.1:8085/auth/login.php');
        await page.fill('input[name="usuario"]', 'admin_proyecto');
        await page.fill('input[name="password"]', 'Admin2026*');
        await page.click('button[type="submit"]');
        await page.waitForLoadState('networkidle');

        // 2. Verificar Entregas: selector de estado
        await page.goto('http://127.0.0.1:8085/entregas.php');
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
        await page.goto('http://127.0.0.1:8085/flota.php?seccion=catalogos');
        await page.waitForSelector('#flota-resultado .catalog-grid', { timeout: 10000 });

        const catalogEvaluation = await page.evaluate(() => {
            const panels = document.querySelectorAll('.catalog-card');
            const primaryBtns = document.querySelectorAll('.catalog-card .button.is-primary');
            const secondaryBtns = document.querySelectorAll('.catalog-card .button-secondary');

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
            // Validar que el texto sea blanco o contraste alto
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

        const modalMetrics = await page.evaluate(() => {
            const modal = document.querySelector('#modal-contenido .modal-card');
            const submitBtn = modal.querySelector('button[type="submit"]');
            const cancelBtn = modal.querySelector('button[data-close-modal]');
            const submitCs = window.getComputedStyle(submitBtn);
            const cancelCs = window.getComputedStyle(cancelBtn);

            return {
                modalVisible: Boolean(modal),
                submitText: submitBtn.textContent.trim(),
                submitBg: submitCs.backgroundColor,
                submitColor: submitCs.color,
                cancelBg: cancelCs.backgroundColor,
                cancelColor: cancelCs.color
            };
        });

        console.log('Métricas modal Catálogo:', JSON.stringify(modalMetrics, null, 2));
        await page.screenshot({ path: path.join(screenshotDir, `modal_nuevo_catalogo_${resolution.width}x${resolution.height}.png`) });

        // Cerrar modal
        await page.click('#modal-contenido button[data-close-modal]');
        await page.waitForFunction(() => {
            const m = document.querySelector('#modal');
            return !m || m.classList.contains('is-hidden') || m.innerHTML.trim() === '';
        });

        // 5. Verificar Flota - Vehículos: selector y botón "Nuevo vehículo"
        await page.goto('http://127.0.0.1:8085/flota.php?seccion=vehiculos');
        await page.waitForSelector('#flota-resultado', { timeout: 10000 });

        const vehiculosSelects = await page.evaluate(() => {
            const selects = document.querySelectorAll('.filter-form .select');
            return Array.from(selects).map(s => {
                const sel = s.querySelector('select');
                const sR = s.getBoundingClientRect();
                const selR = sel.getBoundingClientRect();
                return {
                    sWidth: sR.width,
                    selWidth: selR.width,
                    diff: Math.abs(sR.width - selR.width)
                };
            });
        });

        console.log('Selects en Vehículos:', JSON.stringify(vehiculosSelects, null, 2));
        for (const s of vehiculosSelects) {
            if (s.diff > 1) {
                throw new Error(`Select desalineado en vehículos: diferencia ${s.diff}px`);
            }
        }

        await page.screenshot({ path: path.join(screenshotDir, `flota_vehiculos_${resolution.width}x${resolution.height}.png`) });

        // 6. Verificar Comboboxes buscables (Tom Select) en modal Nuevo Viaje
        await page.goto('http://127.0.0.1:8085/viajes.php');
        await page.waitForSelector('#viajes-filtros', { timeout: 10000 });
        const newTripBtn = await page.$('.toolbar-panel .button.is-primary');
        if (newTripBtn) {
            await newTripBtn.click();
            await page.waitForSelector('#modal-contenido .modal-card', { timeout: 5000 });
            await page.waitForSelector('.ts-wrapper.single', { timeout: 5000 });

            const comboboxMetrics = await page.evaluate(() => {
                const combobox = document.querySelector('.ts-wrapper.single');
                if (!combobox) return null;
                const control = combobox.querySelector('.ts-control');
                const cs = window.getComputedStyle(control);
                const afterCs = window.getComputedStyle(control, '::after');
                const cRect = control.getBoundingClientRect();

                return {
                    width: cRect.width,
                    controlPaddingRight: parseFloat(cs.paddingRight),
                    afterRight: parseFloat(afterCs.right),
                    hasChevron: afterCs.content !== 'none' && afterCs.content !== '',
                    afterTransform: afterCs.transform
                };
            });

            console.log('Tom Select combobox métricas (en modal Viajes):', JSON.stringify(comboboxMetrics, null, 2));
            if (comboboxMetrics) {
                if (comboboxMetrics.controlPaddingRight < 30) {
                    throw new Error(`Padding derecho insuficiente en Tom Select (${comboboxMetrics.controlPaddingRight}px < 30px)`);
                }
                if (!comboboxMetrics.hasChevron) {
                    throw new Error('Tom Select no tiene flecha chevron ::after definida');
                }
            }

            await page.screenshot({ path: path.join(screenshotDir, `viajes_modal_combobox_${resolution.width}x${resolution.height}.png`) });

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
}

runVisualVerification().catch(err => {
    console.error('ERROR EN VERIFICACIÓN:', err);
    process.exit(1);
});
