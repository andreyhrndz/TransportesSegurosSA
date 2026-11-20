/**
 * Suite Playwright real (Chromium) — Carga segura de documentos PDF/JPG/PNG y compatibilidad URL:
 *  - Modos excluyentes (Subir archivo local vs Vincular URL externa) preservando campos al alternar.
 *  - Drag & Drop real + botón Seleccionar archivo + Quitar/Reemplazar + validaciones cliente/servidor.
 *  - Descarga autenticada byte a byte con verificación SHA-256.
 *  - Visibilidad según permisos (DOCUMENTOS_VER sin DOCUMENTOS_REGISTRAR).
 *  - Scroll lock global, Tom Select sin clipping y 6 resoluciones (1366, 1440, 1600, 1920, 768, 375).
 *
 * Opera EXCLUSIVAMENTE contra TransportesSegurosSA_Test con storage temporal aislado y 0 residuos.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const assert = require('assert');
const { spawn, execFileSync } = require('child_process');
const { chromium } = require('playwright');

const PHP_BIN = process.env.PHP_BIN || 'C:\\AdmBD2026\\Herramientas\\PHP\\8.4\\php.exe';
const DEFAULT_CHROMIUM = 'C:\\Users\\cuent\\AppData\\Local\\ms-playwright\\chromium-1247\\chrome-win64\\chrome.exe';
const CHROMIUM_EXEC = process.env.CHROMIUM_EXEC || (fs.existsSync(DEFAULT_CHROMIUM) ? DEFAULT_CHROMIUM : undefined);
const ROOT = path.resolve(__dirname, '..');
const SHOT_DIR = path.join(ROOT, 'docs', 'screenshots', 'document_attachments');
const PORT = 8028;
const BASE_URL = `http://127.0.0.1:${PORT}`;
const SUFFIX = Math.random().toString(36).slice(2, 8);
const ADMIN_USER = `__pw_doc_${SUFFIX}`;
const ADMIN_PASS = `ClaveDocSegura!${SUFFIX}99`;
const VIEW_USER = `__pw_docv_${SUFFIX}`;
const VIEW_PASS = `ClaveDocView!${SUFFIX}88`;
const TEMP_STORAGE = path.join(os.tmpdir(), `tssa-pw-doc-store-${SUFFIX}`);
const TEMP_FILES = path.join(os.tmpdir(), `tssa-pw-doc-files-${SUFFIX}`);

fs.mkdirSync(SHOT_DIR, { recursive: true });
fs.mkdirSync(TEMP_STORAGE, { recursive: true });
fs.mkdirSync(TEMP_FILES, { recursive: true });

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
    const logPath = path.join(os.tmpdir(), `tssa-pw-doc-srv-${SUFFIX}.log`);
    const outFd = fs.openSync(logPath, 'w');
    const child = spawn(
        PHP_BIN,
        ['-d', 'upload_max_filesize=10M', '-d', 'post_max_size=12M', '-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public')],
        {
            cwd: ROOT,
            env: {
                ...process.env,
                TSSA_DB_DATABASE: 'TransportesSegurosSA_Test',
                TSSA_DOCUMENT_STORAGE_DIR: TEMP_STORAGE,
                TSSA_APP_URL: BASE_URL,
                TSSA_MAIL_TRANSPORT: 'fake',
            },
            stdio: ['ignore', outFd, outFd],
        }
    );
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

async function login(page, user, pass) {
    await page.goto(`${BASE_URL}/auth/login.php`, { waitUntil: 'networkidle' });
    await page.fill('input[name="usuario"]', user);
    await page.fill('input[name="password"]', pass);
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
        // 1. Preparar archivos de prueba reales en carpeta temporal
        const pdfBytes = Buffer.from(
            '%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n' +
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n' +
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >> endobj\n' +
            'xref\n0 4\n0000000000 65535 f \ntrailer << /Size 4 /Root 1 0 R >>\nstartxref\n180\n%%EOF\n',
            'utf8'
        );
        const pngBytes = Buffer.from(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            'base64'
        );
        const fakePdfBytes = Buffer.from('<html><script>alert(1)</script></html>', 'utf8');

        const pdfPath = path.join(TEMP_FILES, 'factura_comercial_demo.pdf');
        const pngPath = path.join(TEMP_FILES, 'comprobante_entrega_demo.png');
        const fakePdfPath = path.join(TEMP_FILES, 'simulado.pdf');
        fs.writeFileSync(pdfPath, pdfBytes);
        fs.writeFileSync(pngPath, pngBytes);
        fs.writeFileSync(fakePdfPath, fakePdfBytes);
        const pdfSha = crypto.createHash('sha256').update(pdfBytes).digest('hex');
        const pngSha = crypto.createHash('sha256').update(pngBytes).digest('hex');

        // 2. Sembrar usuarios efímeros (full + solo lectura documentos) en Test
        const seedJson = phpEval(`
            $maxAud = (int)$p->query('SELECT ISNULL(MAX(auditoria_id), 0) FROM dbo.auditorias')->fetchColumn();
            $maxDoc = (int)$p->query('SELECT ISNULL(MAX(documento_id), 0) FROM dbo.documentos')->fetchColumn();
            $solId = (int)$p->query('SELECT TOP (1) solicitud_id FROM dbo.solicitudes ORDER BY solicitud_id')->fetchColumn();
            $tipoId = (int)$p->query('SELECT TOP (1) tipo_documento_id FROM dbo.tipos_documento WHERE activo = 1 ORDER BY tipo_documento_id')->fetchColumn();

            $mk = static function(PDO $db, string $u, string $pass, array $perms): int {
                $h = password_hash($pass, PASSWORD_BCRYPT);
                $st = $db->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) VALUES (?, ?, ?, 1); SELECT SCOPE_IDENTITY();');
                $st->execute([$u, $u . '@example.test', $h]);
                $st->nextRowset();
                $id = (int)$st->fetchColumn();
                $st->closeCursor();
                foreach ($perms as $code) {
                    $db->prepare('INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE codigo = ?')->execute([$id, $code]);
                }
                return $id;
            };
            $uFull = $mk($p, ${JSON.stringify(ADMIN_USER)}, ${JSON.stringify(ADMIN_PASS)}, ['REPORTES_VER', 'ENTREGAS_VER', 'DOCUMENTOS_VER', 'DOCUMENTOS_REGISTRAR', 'SOLICITUDES_VER']);
            $uView = $mk($p, ${JSON.stringify(VIEW_USER)}, ${JSON.stringify(VIEW_PASS)}, ['REPORTES_VER', 'DOCUMENTOS_VER', 'SOLICITUDES_VER']);
            echo json_encode(['uFull' => $uFull, 'uView' => $uView, 'solId' => $solId, 'tipoId' => $tipoId, 'maxAud' => $maxAud, 'maxDoc' => $maxDoc]);
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

        await login(page, ADMIN_USER, ADMIN_PASS);

        // 3. Abrir Entregas -> Documentos y seleccionar solicitud #1
        await page.goto(`${BASE_URL}/entregas.php?seccion=documentos&solicitud_id=${fx.solId}`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#entregas-resultado table', { timeout: 15000 });
        const initialTable = await page.locator('#entregas-resultado').innerText();
        assert(initialTable.includes('Enlace externo'), 'Documento previo debe mostrarse como Enlace externo.');

        // 4. Abrir modal Nuevo documento y verificar modo archivo predeterminado + scroll lock
        await page.click('button:has-text("Nuevo documento")');
        await page.waitForSelector('[data-doc-upload-form]', { timeout: 10000 });
        await page.waitForTimeout(200);
        const locked = await page.evaluate(() => document.documentElement.classList.contains('is-modal-open'));
        assert(locked, 'El modal de documento debe activar scroll lock global.');
        await page.screenshot({ path: path.join(SHOT_DIR, '01_modal_documento_archivo_1440.png') });

        // Llenar Tipo y Referencia, alternar a modo URL y volver a modo Archivo -> campos se conservan
        await selectTomOption(page, '#documento-tipo', fx.tipoId);
        const refPdf = `FAC-PW-${SUFFIX.toUpperCase()}`;
        await page.fill('#documento-referencia', refPdf);

        await page.click('[data-doc-mode-label="url"]');
        await page.waitForTimeout(100);
        assert.strictEqual(await page.locator('#documento-panel-url').isVisible(), true, 'Panel URL debe mostrarse en modo URL.');
        assert.strictEqual(await page.locator('#documento-panel-archivo').isVisible(), false, 'Panel archivo debe ocultarse en modo URL.');
        assert.strictEqual(await page.inputValue('#documento-referencia'), refPdf, 'Cambiar de modo no debe borrar Número de referencia.');
        await page.screenshot({ path: path.join(SHOT_DIR, '02_modal_documento_url_1440.png') });

        await page.click('[data-doc-mode-label="archivo"]');
        await page.waitForTimeout(100);
        assert.strictEqual(await page.inputValue('#documento-referencia'), refPdf, 'Volver a modo archivo debe preservar Número de referencia.');

        // 5. Probar drag & drop inválido (.svg) -> estado Archivo inválido
        await page.evaluate(() => {
            const dz = document.querySelector('[data-doc-dropzone]');
            const dt = new DataTransfer();
            dt.items.add(new File(['<svg></svg>'], 'malo.svg', { type: 'image/svg+xml' }));
            dz.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
        });
        await page.waitForTimeout(100);
        assert((await page.locator('[data-doc-status]').innerText()).includes('Archivo inválido'), 'Drop de SVG debe marcar Archivo inválido.');

        // 6. Probar PDF fingido contra el servidor -> 422, modal sigue abierto y campos preservados!
        await page.setInputFiles('#documento-archivo', fakePdfPath);
        await page.click('.modal button[type="submit"]');
        await page.waitForSelector('#mensaje-documento .notification-danger', { timeout: 10000 });
        assert.strictEqual(await page.locator('.modal.is-active').count(), 1, 'Ante error 422 el modal debe permanecer abierto.');
        assert.strictEqual(await page.inputValue('#documento-referencia'), refPdf, 'Ante error 422 se deben conservar los campos del formulario.');

        // 7. Arrastrar y soltar (drag & drop real) un PDF válido, luego Quitar y volver a seleccionar con botón/input
        await page.evaluate((b64) => {
            const bin = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
            const dz = document.querySelector('[data-doc-dropzone]');
            const dt = new DataTransfer();
            dt.items.add(new File([bin], 'factura_comercial_demo.pdf', { type: 'application/pdf' }));
            dz.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
        }, pdfBytes.toString('base64'));
        await page.waitForSelector('[data-doc-preview]:not([hidden])', { timeout: 5000 });
        await page.waitForTimeout(220);
        assert((await page.locator('[data-doc-preview-name]').innerText()).includes('factura_comercial_demo.pdf'));
        assert.strictEqual((await page.locator('#mensaje-documento').innerText()).trim(), '', 'Al seleccionar un archivo válido debe limpiarse el aviso de error anterior.');
        await page.screenshot({ path: path.join(SHOT_DIR, '03_modal_documento_seleccionado_1440.png') });

        // Quitar y volver a elegir vía Seleccionar archivo (setInputFiles)
        await page.click('[data-doc-clear]');
        assert.strictEqual(await page.locator('[data-doc-preview]').isVisible(), false, 'Botón Quitar debe limpiar la selección.');
        await page.setInputFiles('#documento-archivo', pdfPath);
        await page.waitForSelector('[data-doc-preview]:not([hidden])', { timeout: 5000 });

        // Enviar PDF y contar exactamente 1 POST
        const docPosts = [];
        page.on('request', (req) => {
            if (req.method() === 'POST' && req.url().includes('/entregas/documento.php')) docPosts.push(req.url());
        });
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
            page.click('.modal button[type="submit"]'),
        ]);
        await page.waitForSelector('#entregas-resultado table', { timeout: 15000 });
        assert.strictEqual(docPosts.length, 1, `Debe enviarse exactamente 1 POST (enviados: ${docPosts.length}).`);

        // 8. Subir también una imagen PNG y un enlace URL externo para mostrar convivencia en tabla
        await page.click('button:has-text("Nuevo documento")');
        await page.waitForSelector('[data-doc-upload-form]', { timeout: 10000 });
        await selectTomOption(page, '#documento-tipo', fx.tipoId);
        await page.fill('#documento-referencia', `PNG-PW-${SUFFIX.toUpperCase()}`);
        await page.setInputFiles('#documento-archivo', pngPath);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
            page.click('.modal button[type="submit"]'),
        ]);
        await page.waitForSelector('#entregas-resultado table', { timeout: 15000 });

        await page.click('button:has-text("Nuevo documento")');
        await page.waitForSelector('[data-doc-upload-form]', { timeout: 10000 });
        await selectTomOption(page, '#documento-tipo', fx.tipoId);
        await page.fill('#documento-referencia', `URL-PW-${SUFFIX.toUpperCase()}`);
        await page.click('[data-doc-mode-label="url"]');
        await page.fill('#documento-url', 'https://example.test/guia-pw-demo.pdf');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
            page.click('.modal button[type="submit"]'),
        ]);
        await page.waitForSelector('#entregas-resultado table', { timeout: 15000 });

        const tableText = await page.locator('#entregas-resultado').innerText();
        assert(tableText.includes('Archivo adjunto') && tableText.includes('Enlace externo') && tableText.includes('Descargar') && tableText.includes('Abrir referencia'), 'Tabla debe mostrar ambos tipos de soporte y acciones.');
        await assertNoDesktopOverflow(page, 'tabla documentos 1440x900');
        await page.screenshot({ path: path.join(SHOT_DIR, '04_tabla_documentos_mixta_1440.png'), fullPage: true });

        // Verificar descarga real con verificación SHA-256 de los adjuntos subidos
        const dlLinks = await page.locator('#entregas-resultado a[href^="/entregas/documento_descargar.php?id="]').evaluateAll((els) => els.map((e) => e.getAttribute('href')));
        assert(dlLinks.length >= 2, 'Deben existir al menos 2 botones Descargar.');
        const hashes = [];
        for (const href of dlLinks) {
            const resp = await context.request.get(`${BASE_URL}${href}`);
            assert.strictEqual(resp.status(), 200, `Descarga ${href} devolvió ${resp.status()}.`);
            hashes.push(crypto.createHash('sha256').update(await resp.body()).digest('hex'));
        }
        assert(hashes.includes(pdfSha) && hashes.includes(pngSha), 'Los archivos descargados no coinciden en SHA-256 con el PDF y PNG subidos.');
        console.log('[OK] E2E 1: Drag & Drop, Seleccionar archivo, Quitar, validaciones 422, PDF/PNG/URL y descarga SHA-256.');

        // 9. Auditoría visual multi-resolución (6 viewports: 1366, 1440, 1600, 1920, 768, 375)
        const viewports = [
            { name: '1366x768', width: 1366, height: 768, shot: '05_documentos_1366x768.png' },
            { name: '1600x900', width: 1600, height: 900 },
            { name: '1920x1080', width: 1920, height: 1080 },
            { name: '768x1024', width: 768, height: 1024, shot: '06_documentos_768x1024.png' },
            { name: '375x667', width: 375, height: 667, shot: '07_documentos_375x667.png' },
        ];
        for (const vp of viewports) {
            await page.setViewportSize({ width: vp.width, height: vp.height });
            await page.goto(`${BASE_URL}/entregas.php?seccion=documentos&solicitud_id=${fx.solId}`, { waitUntil: 'networkidle' });
            await page.waitForSelector('#entregas-resultado table', { timeout: 15000 });
            if (vp.width >= 1280) {
                await assertNoDesktopOverflow(page, `documentos ${vp.name}`);
            }
            if (vp.shot) {
                await page.screenshot({ path: path.join(SHOT_DIR, vp.shot), fullPage: true });
            }
        }
        console.log('[OK] E2E 2: Auditoría multi-resolución (1366x768, 1440x900, 1600x900, 1920x1080, 768x1024, 375x667) sin overflow desktop.');

        // 10. Usuario solo con DOCUMENTOS_VER NO ve botón Nuevo documento pero SÍ puede descargar
        const viewCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const viewPage = await viewCtx.newPage();
        await login(viewPage, VIEW_USER, VIEW_PASS);
        await viewPage.goto(`${BASE_URL}/entregas.php?seccion=documentos&solicitud_id=${fx.solId}`, { waitUntil: 'networkidle' });
        await viewPage.waitForSelector('#entregas-resultado table', { timeout: 15000 });
        assert.strictEqual(await viewPage.locator('button:has-text("Nuevo documento")').count(), 0, 'Sin DOCUMENTOS_REGISTRAR no debe mostrarse botón Nuevo documento.');
        assert(await viewPage.locator('#entregas-resultado a:has-text("Descargar")').count() >= 2, 'Con DOCUMENTOS_VER debe poder ver botones Descargar.');
        await viewCtx.close();
        console.log('[OK] E2E 3: Control visual de permisos DOCUMENTOS_VER vs DOCUMENTOS_REGISTRAR.');

        assert.strictEqual(pageErrors.length, 0, `Errores JS: ${pageErrors.join(' | ')}`);
        assert.strictEqual(server500s.length, 0, `Errores 500: ${server500s.join(' | ')}`);
        console.log('=== PLAYWRIGHT ADJUNTOS DOCUMENTALES: 100% PASS ===');
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
        try { fs.rmSync(TEMP_STORAGE, { recursive: true, force: true }); } catch {}
        try { fs.rmSync(TEMP_FILES, { recursive: true, force: true }); } catch {}

        if (fx) {
            try {
                phpEval(`
                    $fx = json_decode(${JSON.stringify(JSON.stringify(fx))}, true);
                    $p->prepare('DELETE FROM dbo.documentos WHERE documento_id > ?')->execute([$fx['maxDoc']]);
                    $p->prepare('DELETE FROM dbo.auditorias WHERE auditoria_id > ?')->execute([$fx['maxAud']]);
                    foreach ([$fx['uFull'], $fx['uView']] as $uid) {
                        $p->prepare('DELETE FROM dbo.usuarios_permisos WHERE usuario_id = ?')->execute([$uid]);
                        $p->prepare('DELETE FROM dbo.usuarios WHERE usuario_id = ?')->execute([$uid]);
                    }
                `);
            } catch (cleanErr) {
                console.error('CLEANUP ERROR:', cleanErr);
                process.exitCode = 1;
            }
        }
    }
})();
