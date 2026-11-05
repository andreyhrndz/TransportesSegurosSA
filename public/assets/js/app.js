(() => {
    'use strict';

    const modal = document.getElementById('modal-contenido');
    const aviso = document.getElementById('aviso-temporal');
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const sidebarCollapseBtn = document.querySelector('[data-sidebar-collapse]');
    const backdrop = document.getElementById('sidebar-backdrop');
    const appShell = document.getElementById('app-shell');
    const userMenu = document.querySelector('[data-user-menu]');
    const userMenuToggle = document.querySelector('[data-user-menu-toggle]');
    const userDropdown = document.getElementById('user-account-dropdown');

    const SIDEBAR_STORAGE_KEY = 'tssa_sidebar_collapsed';
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    let timer;
    let previousFocus = null;

    const FOCUSABLE_SELECTOR = 'button:not([disabled]), [href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function getFocusableElements(container) {
        if (!container) return [];
        return Array.from(container.querySelectorAll(FOCUSABLE_SELECTOR))
            .filter((el) => el.offsetParent !== null || el.getClientRects().length > 0);
    }

    function firstFocusable(container) {
        const list = getFocusableElements(container);
        return list.length ? list[0] : null;
    }

    /* ======================================================================
       SIDEBAR (COLAPSABLE EN DESKTOP + DRAWER EN MÓVIL)
       ====================================================================== */

    function applySidebarCollapsed(collapsed) {
        if (!appShell) return;
        appShell.classList.toggle('is-sidebar-collapsed', collapsed);
        document.documentElement.classList.toggle('preload-sidebar-collapsed', collapsed);
        if (sidebarCollapseBtn) {
            sidebarCollapseBtn.setAttribute('aria-expanded', String(!collapsed));
            sidebarCollapseBtn.setAttribute('data-tooltip', collapsed ? 'Expandir menú' : 'Contraer menú');
        }
    }

    try {
        if (localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1') {
            applySidebarCollapsed(true);
        }
    } catch {
        // Ignorar restricciones de almacenamiento del navegador
    }

    function toggleDesktopSidebarCollapse() {
        if (!appShell) return;
        const nextCollapsed = !appShell.classList.contains('is-sidebar-collapsed');
        applySidebarCollapsed(nextCollapsed);
        try {
            localStorage.setItem(SIDEBAR_STORAGE_KEY, nextCollapsed ? '1' : '0');
        } catch {
            // Ignorar restricciones de almacenamiento
        }
    }

    function toggleSidebar(force) {
        if (!sidebar || !sidebarToggle) return;
        const open = typeof force === 'boolean' ? force : !sidebar.classList.contains('is-open');
        sidebar.classList.toggle('is-open', open);
        sidebarToggle.setAttribute('aria-expanded', String(open));
        if (backdrop) {
            backdrop.hidden = !open;
            backdrop.setAttribute('aria-hidden', String(!open));
        }
        document.body.classList.toggle('sidebar-locked', open);
    }

    /* ======================================================================
       MENÚ DE CUENTA EN TOPBAR
       ====================================================================== */

    function toggleUserMenu(force) {
        if (!userMenuToggle || !userDropdown) return;
        const isOpen = !userDropdown.hidden;
        const nextOpen = typeof force === 'boolean' ? force : !isOpen;
        userDropdown.hidden = !nextOpen;
        userMenuToggle.setAttribute('aria-expanded', String(nextOpen));
    }

    /* ======================================================================
       COMBOBOX BUSCABLES (TOM SELECT)
       ====================================================================== */

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function initComboboxes(root = document) {
        if (typeof window.TomSelect !== 'function') return;
        const selects = root.querySelectorAll ? root.querySelectorAll('select[data-combobox]') : [];
        selects.forEach((select) => {
            if (select.tomselect || select.dataset.tsInitialized === '1') return;
            select.dataset.tsInitialized = '1';
            select.closest('.select')?.classList.add('has-tomselect');

            const remoteUrl = select.dataset.remoteUrl || '';
            const placeholder = select.dataset.placeholder
                || select.querySelector('option[value=""]')?.textContent?.trim()
                || 'Seleccionar o buscar…';
            const hasEmptyOption = Boolean(select.querySelector('option[value=""]'));
            const allowClear = select.dataset.allowClear === 'true' || (select.dataset.allowClear !== 'false' && hasEmptyOption && !select.required);

            const plugins = {};
            if (allowClear) {
                plugins.clear_button = { title: 'Limpiar selección' };
            }

            const config = {
                create: false,
                allowEmptyOption: hasEmptyOption,
                placeholder,
                dropdownParent: 'body',
                maxOptions: 80,
                plugins,
                score(search) {
                    const scoreFn = this.getScoreFunction(search);
                    return (item) => {
                        if (item.value === '') return 0;
                        return scoreFn(item);
                    };
                },
                render: {
                    option(data, escape) {
                        if (data.value === '') {
                            return `<div class="option is-placeholder-option">${escape(data.text)}</div>`;
                        }
                        const sub = data.subtext || data.$option?.dataset?.subtext || '';
                        if (sub) {
                            return `<div class="option"><div class="cell-stack"><strong>${escape(data.text)}</strong><span>${escape(sub)}</span></div></div>`;
                        }
                        return `<div class="option">${escape(data.text)}</div>`;
                    },
                    item(data, escape) {
                        return `<div class="item">${escape(data.text)}</div>`;
                    },
                    no_results() {
                        return '<div class="no-results">No se encontraron coincidencias</div>';
                    },
                    loading() {
                        return '<div class="loading">Buscando opciones disponibles…</div>';
                    },
                },
            };

            if (remoteUrl !== '') {
                config.valueField = 'value';
                config.labelField = 'text';
                config.searchField = ['text', 'subtext'];
                config.preload = 'focus';
                config.load = (query, callback) => {
                    const separator = remoteUrl.includes('?') ? '&' : '?';
                    const url = `${remoteUrl}${separator}formato=json&buscar=${encodeURIComponent(query || '')}&pagina=1`;
                    fetch(url, {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    })
                        .then((res) => (res.ok ? res.json() : []))
                        .then((items) => {
                            if (!Array.isArray(items)) {
                                callback([]);
                                return;
                            }
                            callback(items.map((item) => ({
                                value: String(item.value ?? ''),
                                text: String(item.text ?? ''),
                                subtext: String(item.subtext ?? ''),
                            })));
                        })
                        .catch(() => callback([]));
                };
            }

            try {
                const instance = new window.TomSelect(select, config);
                const syncDropdownPosition = () => {
                    if (instance.isOpen) {
                        instance.positionDropdown();
                    }
                };
                const modalBody = select.closest('.modal-card-body');
                if (modalBody) {
                    modalBody.addEventListener('scroll', syncDropdownPosition, { passive: true });
                    instance._modalBodyScrollCleanup = () => modalBody.removeEventListener('scroll', syncDropdownPosition);
                }
            } catch {
                delete select.dataset.tsInitialized;
            }
        });
    }

    function destroyComboboxes(root = document) {
        if (!root || !root.querySelectorAll) return;
        root.querySelectorAll('select[data-combobox]').forEach((select) => {
            if (select.tomselect) {
                try {
                    select.tomselect._modalBodyScrollCleanup?.();
                    select.tomselect.destroy();
                } catch {
                    // Ignorar errores al limpiar nodo removido
                }
            }
            delete select.dataset.tsInitialized;
        });
    }

    /* ======================================================================
       CALENDARIOS EN ESPAÑOL (FLATPICKR)
       ====================================================================== */

    function initDatepickers(root = document) {
        if (typeof window.flatpickr !== 'function') return;
        const inputs = root.querySelectorAll ? root.querySelectorAll('input[data-datepicker]') : [];
        inputs.forEach((input) => {
            if (input._flatpickr || input.dataset.fpInitialized === '1') return;
            input.dataset.fpInitialized = '1';

            const isDateTime = input.dataset.datepicker === 'datetime';
            const spanishLocale = window.flatpickr.l10ns?.es
                ? { ...window.flatpickr.l10ns.es, firstDayOfWeek: 1 }
                : { firstDayOfWeek: 1 };

            const fpConfig = {
                locale: spanishLocale,
                allowInput: false,
                disableMobile: true,
                altInput: true,
                altFormat: isDateTime ? 'd/m/Y, h:i K' : 'd/m/Y',
                dateFormat: isDateTime ? 'Y-m-d\\TH:i' : 'Y-m-d',
                enableTime: isDateTime,
                time_24hr: false,
                onReady(selectedDates, dateStr, instance) {
                    if (input.placeholder && instance.altInput) {
                        instance.altInput.placeholder = input.placeholder;
                    } else if (instance.altInput) {
                        instance.altInput.placeholder = isDateTime ? 'dd/mm/aaaa, hh:mm' : 'dd/mm/aaaa';
                    }

                    const cal = instance.calendarContainer;
                    if (cal && !cal.querySelector('.flatpickr-custom-footer')) {
                        const footer = document.createElement('div');
                        footer.className = 'flatpickr-custom-footer';

                        const todayBtn = document.createElement('button');
                        todayBtn.type = 'button';
                        todayBtn.className = 'flatpickr-footer-btn';
                        todayBtn.textContent = 'Hoy';
                        todayBtn.addEventListener('click', () => {
                            instance.setDate(new Date(), true);
                            instance.close();
                        });

                        const clearBtn = document.createElement('button');
                        clearBtn.type = 'button';
                        clearBtn.className = 'flatpickr-footer-btn';
                        clearBtn.textContent = 'Limpiar';
                        clearBtn.addEventListener('click', () => {
                            instance.clear(true);
                            instance.close();
                        });

                        footer.appendChild(todayBtn);
                        footer.appendChild(clearBtn);
                        cal.appendChild(footer);
                    }
                },
                onChange(selectedDates, dateStr) {
                    const form = input.closest('form');
                    if (!form) return;
                    if (input.name === 'desde') {
                        const hastaInput = form.querySelector('input[name="hasta"]');
                        if (hastaInput?._flatpickr) {
                            hastaInput._flatpickr.set('minDate', dateStr || null);
                        }
                    } else if (input.name === 'hasta') {
                        const desdeInput = form.querySelector('input[name="desde"]');
                        if (desdeInput?._flatpickr) {
                            desdeInput._flatpickr.set('maxDate', dateStr || null);
                        }
                    }
                },
            };

            if (input.min) fpConfig.minDate = input.min;
            if (input.max) fpConfig.maxDate = input.max;

            try {
                window.flatpickr(input, fpConfig);
            } catch {
                delete input.dataset.fpInitialized;
            }
        });
    }

    function destroyDatepickers(root = document) {
        if (!root || !root.querySelectorAll) return;
        root.querySelectorAll('input[data-datepicker]').forEach((input) => {
            if (input._flatpickr) {
                try {
                    input._flatpickr.destroy();
                } catch {
                    // Ignorar errores al limpiar instancia
                }
            }
            delete input.dataset.fpInitialized;
        });
    }

    /* ======================================================================
       GRÁFICAS ESTADÍSTICAS (CHART.JS)
       ====================================================================== */

    function initCharts(root = document) {
        if (typeof window.Chart !== 'function') return;
        const canvases = root.querySelectorAll ? root.querySelectorAll('canvas[data-chart-config]') : [];
        canvases.forEach((canvas) => {
            if (canvas._tssaChart || canvas.dataset.chartInitialized === '1') return;
            const rawConfig = canvas.dataset.chartConfig;
            if (!rawConfig) return;

            try {
                const parsed = JSON.parse(rawConfig);
                canvas.dataset.chartInitialized = '1';

                const isCircular = ['doughnut', 'pie', 'polarArea'].includes(parsed.type);
                const chartConfig = {
                    type: parsed.type || 'bar',
                    data: parsed.data || { labels: [], datasets: [] },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: reducedMotion ? false : { duration: 450 },
                        plugins: {
                            legend: {
                                display: parsed.options?.plugins?.legend?.display ?? isCircular,
                                position: parsed.options?.plugins?.legend?.position || 'bottom',
                                labels: {
                                    color: '#CBD5E1',
                                    padding: 14,
                                    usePointStyle: true,
                                    pointStyleWidth: 10,
                                    font: {
                                        family: '"Inter", "Segoe UI", sans-serif',
                                        size: 12,
                                        weight: '500',
                                    },
                                },
                            },
                            tooltip: {
                                backgroundColor: '#213044',
                                titleColor: '#F1F5F9',
                                bodyColor: '#CBD5E1',
                                borderColor: '#354962',
                                borderWidth: 1,
                                padding: 10,
                                cornerRadius: 8,
                            },
                        },
                        ...(isCircular ? { cutout: parsed.type === 'doughnut' ? '66%' : undefined } : {
                            scales: {
                                x: {
                                    grid: { color: 'rgba(148, 163, 184, 0.08)' },
                                    ticks: { color: '#94A3B8', font: { size: 11 } },
                                },
                                y: {
                                    beginAtZero: true,
                                    grid: { color: 'rgba(148, 163, 184, 0.10)' },
                                    ticks: { color: '#94A3B8', precision: 0, font: { size: 11 } },
                                },
                            },
                        }),
                    },
                };

                canvas._tssaChart = new window.Chart(canvas, chartConfig);
            } catch {
                delete canvas.dataset.chartInitialized;
            }
        });
    }

    function destroyCharts(root = document) {
        if (!root || !root.querySelectorAll) return;
        root.querySelectorAll('canvas[data-chart-config]').forEach((canvas) => {
            if (canvas._tssaChart) {
                try {
                    canvas._tssaChart.destroy();
                } catch {
                    // Ignorar error de limpieza
                }
                delete canvas._tssaChart;
            }
            delete canvas.dataset.chartInitialized;
        });
    }

    function initComponents(root = document) {
        initComboboxes(root);
        initDatepickers(root);
        initCharts(root);
    }

    function destroyComponents(root = document) {
        destroyComboboxes(root);
        destroyDatepickers(root);
        destroyCharts(root);
    }

    /* ======================================================================
       MODALES Y TOASTS
       ====================================================================== */

    function cerrarModal() {
        if (!modal || modal.innerHTML.trim() === '') return;
        destroyComponents(modal);
        modal.innerHTML = '';
        if (appShell) appShell.removeAttribute('aria-hidden');
        previousFocus?.focus();
        previousFocus = null;
    }

    function abrirModal() {
        if (!modal) return;
        previousFocus = document.activeElement;
        if (appShell) appShell.setAttribute('aria-hidden', 'true');
        initComponents(modal);
        requestAnimationFrame(() => {
            const autofocusEl = modal.querySelector('[autofocus]');
            if (autofocusEl && typeof autofocusEl.focus === 'function') {
                autofocusEl.focus();
            } else {
                firstFocusable(modal)?.focus();
            }
        });
    }

    function mostrarAviso(texto, error = false) {
        if (!aviso) return;
        clearTimeout(timer);
        aviso.textContent = texto;
        aviso.classList.toggle('toast-error', error);
        aviso.hidden = false;
        timer = setTimeout(() => { aviso.hidden = true; }, 4500);
    }

    /* ======================================================================
       DELEGACIÓN GLOBAL DE EVENTOS
       ====================================================================== */

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-close-modal]')) {
            cerrarModal();
            return;
        }
        if (event.target.closest('[data-sidebar-collapse]')) {
            toggleDesktopSidebarCollapse();
            return;
        }
        if (event.target.closest('[data-sidebar-toggle]')) {
            toggleSidebar();
            return;
        }
        if (event.target.closest('[data-sidebar-close]')) {
            toggleSidebar(false);
            return;
        }

        if (event.target.closest('[data-user-menu-toggle]')) {
            toggleUserMenu();
            return;
        }
        if (userMenu && !userMenu.contains(event.target)) {
            toggleUserMenu(false);
        }

        const addMerchandise = event.target.closest('[data-add-mercancia]');
        if (addMerchandise) {
            const list = document.querySelector('[data-mercancias-lista]');
            const template = document.getElementById('mercancia-row-template');
            const max = Number(addMerchandise.dataset.maxItems || 20);
            if (!list || !template || list.querySelectorAll('[data-mercancia-row]').length >= max) return;
            const fragment = template.content.cloneNode(true);
            const index = list.querySelectorAll('[data-mercancia-row]').length;
            fragment.querySelectorAll('[name]').forEach((field) => {
                field.name = field.name.replace('__index__', String(index));
            });
            list.appendChild(fragment);
            const rows = list.querySelectorAll('[data-mercancia-row]');
            const lastRow = rows[rows.length - 1];
            if (lastRow) initComponents(lastRow);
        }

        const removeMerchandise = event.target.closest('[data-remove-mercancia]');
        if (removeMerchandise) {
            const list = document.querySelector('[data-mercancias-lista]');
            if (list?.querySelectorAll('[data-mercancia-row]').length > 1) {
                const row = removeMerchandise.closest('[data-mercancia-row]');
                if (row) {
                    destroyComponents(row);
                    row.remove();
                }
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (userDropdown && !userDropdown.hidden) {
                toggleUserMenu(false);
                userMenuToggle?.focus();
                return;
            }
            if (modal?.innerHTML.trim()) {
                cerrarModal();
            } else {
                toggleSidebar(false);
            }
            return;
        }

        if (event.key === 'Tab' && modal && modal.innerHTML.trim() !== '') {
            const focusables = getFocusableElements(modal);
            if (focusables.length === 0) {
                event.preventDefault();
                return;
            }
            const firstEl = focusables[0];
            const lastEl = focusables[focusables.length - 1];
            if (event.shiftKey) {
                if (document.activeElement === firstEl || !modal.contains(document.activeElement)) {
                    lastEl.focus();
                    event.preventDefault();
                }
            } else if (document.activeElement === lastEl || !modal.contains(document.activeElement)) {
                firstEl.focus();
                event.preventDefault();
            }
        }
    });

    document.addEventListener('change', (event) => {
        const state = event.target.closest('[data-entrega-estado]');
        if (!state) return;
        const form = state.closest('form');
        const successFields = form?.querySelector('[data-entrega-exito]');
        const failureFields = form?.querySelector('[data-entrega-fallo]');
        const delivered = state.value === 'ENTREGADA';
        if (successFields) successFields.hidden = !delivered;
        if (failureFields) failureFields.hidden = delivered;
        successFields?.querySelectorAll('input').forEach((field) => {
            field.required = delivered && ['receptor_nombre', 'comprobante_url'].includes(field.name);
        });
        failureFields?.querySelectorAll('textarea').forEach((field) => {
            field.required = !delivered && field.name === 'motivo_fallo';
        });
    });

    /* ======================================================================
       CICLO DE VIDA HTMX
       ====================================================================== */

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initComponents(document));
    } else {
        initComponents(document);
    }

    document.body.addEventListener('htmx:beforeCleanupElement', (event) => {
        if (event.detail?.elt) {
            destroyComponents(event.detail.elt);
        }
    });

    document.body.addEventListener('htmx:beforeSwap', (event) => {
        const status = event.detail.xhr.status;
        if (status === 401) {
            window.location.assign('/auth/login.php');
            event.detail.shouldSwap = false;
            return;
        }
        if ([403, 404, 409, 422].includes(status)) {
            event.detail.shouldSwap = true;
            event.detail.isError = false;
        }
    });

    document.body.addEventListener('htmx:afterSwap', (event) => {
        const target = event.detail.target;
        if (target?.id === 'modal-contenido') {
            abrirModal();
        } else if (target) {
            initComponents(target);
        }
    });

    document.body.addEventListener('clienteCreado', () => {
        cerrarModal();
        mostrarAviso('Cliente registrado correctamente.');
    });

    document.body.addEventListener('tssaNotice', (event) => {
        cerrarModal();
        if (event.detail?.message) {
            mostrarAviso(String(event.detail.message), Boolean(event.detail.error));
        }
    });

    document.body.addEventListener('htmx:sendError', () => mostrarAviso('No se pudo contactar al servidor.', true));
    document.body.addEventListener('htmx:timeout', () => mostrarAviso('La solicitud tardó demasiado.', true));

    /* Pestañas de administración de usuarios e invitaciones */
    document.addEventListener('click', (event) => {
        const tabLink = event.target.closest('[data-user-tab]');
        if (!tabLink) return;
        event.preventDefault();
        const tab = tabLink.dataset.userTab;
        const cuentasLi = document.getElementById('tab-btn-cuentas');
        const invLi = document.getElementById('tab-btn-invitaciones');
        const cuentasPanel = document.getElementById('panel-cuentas');
        const invPanel = document.getElementById('panel-invitaciones');
        const cuentasA = document.getElementById('tab-cuentas');
        const invA = document.getElementById('tab-invitaciones');

        if (tab === 'cuentas') {
            cuentasLi?.classList.add('is-active');
            invLi?.classList.remove('is-active');
            cuentasA?.setAttribute('aria-selected', 'true');
            invA?.setAttribute('aria-selected', 'false');
            cuentasPanel?.classList.remove('is-hidden');
            invPanel?.classList.add('is-hidden');
        } else if (tab === 'invitaciones') {
            cuentasLi?.classList.remove('is-active');
            invLi?.classList.add('is-active');
            cuentasA?.setAttribute('aria-selected', 'false');
            invA?.setAttribute('aria-selected', 'true');
            cuentasPanel?.classList.add('is-hidden');
            invPanel?.classList.remove('is-hidden');
        }
    });

    /* ======================================================================
       HACIENDA & FORMULARIOS (DELEGACIÓN CENTRALIZADA SIN INLINE SCRIPTS)
       ====================================================================== */

    function descomponerNombreCR(nombreCompleto) {
        const partes = String(nombreCompleto || '').trim().split(/\s+/).filter(Boolean);
        if (partes.length >= 3) {
            return {
                primerApellido: partes[0],
                segundoApellido: partes[1],
                nombre: partes.slice(2).join(' ')
            };
        } else if (partes.length === 2) {
            return {
                primerApellido: partes[0],
                segundoApellido: '',
                nombre: partes[1]
            };
        } else if (partes.length === 1) {
            return {
                primerApellido: '',
                segundoApellido: '',
                nombre: partes[0]
            };
        }
        return { primerApellido: '', segundoApellido: '', nombre: '' };
    }

    function setHaciendaAviso(el, tipo, texto) {
        if (!el) return;
        if (!texto) {
            el.hidden = true;
            el.innerHTML = '';
            el.textContent = '';
            return;
        }
        el.hidden = false;
        const alertClass = tipo === 'success'
            ? 'notification-success'
            : (tipo === 'warning' ? 'notification-warning' : (tipo === 'danger' ? 'notification-danger' : 'notification-info'));
        el.className = 'notification ' + alertClass + ' p-2 mt-2';
        el.textContent = texto;
    }

    // Delegación de clic para botones de consulta a Hacienda
    document.addEventListener('click', (event) => {
        const btn = event.target.closest('#btn-consultar-hacienda-cli, #btn-consultar-hacienda-edit, #btn-consultar-hacienda-dest, #btn-onb-hacienda, [data-hacienda-btn]');
        if (!btn) return;
        event.preventDefault();

        // Determinar contexto según el ID del botón o atributos
        const isCli = btn.id === 'btn-consultar-hacienda-cli';
        const isEdit = btn.id === 'btn-consultar-hacienda-edit';
        const isDest = btn.id === 'btn-consultar-hacienda-dest';
        const isOnb = btn.id === 'btn-onb-hacienda';

        let idVal = '';
        let avisoEl = null;

        if (isCli) {
            idVal = (document.getElementById('identificacion')?.value || '').trim();
            avisoEl = document.getElementById('hacienda-cli-aviso');
        } else if (isEdit) {
            idVal = (document.getElementById('edit-identificacion-val')?.textContent || document.getElementById('edit-identificacion')?.value || '').trim();
            avisoEl = document.getElementById('hacienda-edit-aviso');
        } else if (isDest) {
            idVal = (document.getElementById('destinatario-id')?.value || '').trim();
            avisoEl = document.getElementById('hacienda-dest-aviso');
        } else if (isOnb) {
            idVal = (document.getElementById('onb-identificacion')?.value || '').trim();
            avisoEl = document.getElementById('onb-hacienda-help');
        } else {
            const form = btn.closest('form');
            idVal = (form?.querySelector('[name="identificacion"]')?.value || '').trim();
            avisoEl = form?.querySelector('[data-hacienda-aviso]');
        }

        if (!idVal) {
            if (isOnb && avisoEl) {
                avisoEl.textContent = 'Ingresa una identificación antes de consultar.';
                avisoEl.className = 'help has-text-warning';
            } else {
                setHaciendaAviso(avisoEl, 'warning', 'Ingresa una identificación antes de consultar.');
            }
            return;
        }

        // Estado visual de consulta en progreso
        btn.disabled = true;
        btn.classList.add('is-loading');

        if (isOnb && avisoEl) {
            avisoEl.textContent = 'Consultando Ministerio de Hacienda de Costa Rica…';
            avisoEl.className = 'help has-text-info';
        } else {
            setHaciendaAviso(avisoEl, 'info', 'Consultando Ministerio de Hacienda…');
        }

        let url = '/api/hacienda.php?identificacion=' + encodeURIComponent(idVal);
        if (isOnb) {
            const tokenInput = document.querySelector('form#form-onboarding input[name="token"]');
            if (tokenInput?.value) {
                url += '&token=' + encodeURIComponent(tokenInput.value);
            }
        }

        fetch(url)
            .then((res) => res.json())
            .then((data) => {
                btn.disabled = false;
                btn.classList.remove('is-loading');

                const rawNombre = data.nombre || data.datos?.nombre || '';

                if (data.status === 'encontrado' && rawNombre) {
                    if (isOnb) {
                        const parsed = descomponerNombreCR(rawNombre);
                        const inputNom = document.getElementById('onb-nombre');
                        const inputAp1 = document.getElementById('onb-primer-apellido');
                        const inputAp2 = document.getElementById('onb-segundo-apellido');
                        if (inputNom) inputNom.value = parsed.nombre;
                        if (inputAp1) inputAp1.value = parsed.primerApellido;
                        if (inputAp2) inputAp2.value = parsed.segundoApellido;
                        if (avisoEl) {
                            avisoEl.textContent = 'Registro oficial encontrado: ' + rawNombre;
                            avisoEl.className = 'help has-text-success';
                        }
                    } else if (isCli) {
                        const inputRazon = document.getElementById('razon_social');
                        const inputComercial = document.getElementById('nombre_comercial');
                        const inputNom = document.getElementById('nombre');
                        const inputAp1 = document.getElementById('primer_apellido');
                        const inputAp2 = document.getElementById('segundo_apellido');

                        if (inputRazon) {
                            inputRazon.value = rawNombre;
                            if (inputComercial && !inputComercial.value) inputComercial.value = rawNombre;
                        } else if (inputNom) {
                            const parsed = descomponerNombreCR(rawNombre);
                            inputNom.value = parsed.nombre;
                            if (inputAp1) inputAp1.value = parsed.primerApellido;
                            if (inputAp2) inputAp2.value = parsed.segundoApellido;
                        }
                        setHaciendaAviso(avisoEl, 'success', 'Registro oficial encontrado: ' + rawNombre);
                    } else if (isEdit) {
                        const inputRazon = document.getElementById('edit-razon-social');
                        const inputComercial = document.getElementById('edit-nombre-comercial');
                        const inputNom = document.getElementById('edit-nombre');
                        const inputAp1 = document.getElementById('edit-primer-apellido');
                        const inputAp2 = document.getElementById('edit-segundo-apellido');

                        if (inputRazon) {
                            inputRazon.value = rawNombre;
                            if (inputComercial && !inputComercial.value) inputComercial.value = rawNombre;
                        } else if (inputNom) {
                            const parsed = descomponerNombreCR(rawNombre);
                            inputNom.value = parsed.nombre;
                            if (inputAp1) inputAp1.value = parsed.primerApellido;
                            if (inputAp2) inputAp2.value = parsed.segundoApellido;
                        }
                        setHaciendaAviso(avisoEl, 'success', 'Registro oficial encontrado: ' + rawNombre);
                    } else if (isDest) {
                        const inputNom = document.getElementById('destinatario-nombre');
                        const inputAp1 = document.getElementById('destinatario-apellido1');
                        const inputAp2 = document.getElementById('destinatario-apellido2');
                        const parsed = descomponerNombreCR(rawNombre);

                        if (inputNom) inputNom.value = parsed.nombre;
                        if (inputAp1) inputAp1.value = parsed.primerApellido || 'Cliente';
                        if (inputAp2) inputAp2.value = parsed.segundoApellido || '-';
                        setHaciendaAviso(avisoEl, 'success', 'Registro oficial encontrado: ' + rawNombre);
                    }
                } else if (data.status === 'no_encontrado') {
                    const msg = 'Identificación no registrada en Hacienda. Completa los datos manualmente.';
                    if (isOnb && avisoEl) {
                        avisoEl.textContent = msg;
                        avisoEl.className = 'help has-text-warning';
                    } else {
                        setHaciendaAviso(avisoEl, 'warning', msg);
                    }
                } else if (data.status === 'limite_excedido') {
                    const msg = 'Límite de consultas excedido temporalmente. Completa los datos manualmente.';
                    if (isOnb && avisoEl) {
                        avisoEl.textContent = msg;
                        avisoEl.className = 'help has-text-warning';
                    } else {
                        setHaciendaAviso(avisoEl, 'warning', msg);
                    }
                } else {
                    const msg = data.mensaje || 'Servicio de Hacienda no disponible. Completa los datos manualmente.';
                    if (isOnb && avisoEl) {
                        avisoEl.textContent = msg;
                        avisoEl.className = 'help has-text-warning';
                    } else {
                        setHaciendaAviso(avisoEl, 'warning', msg);
                    }
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.classList.remove('is-loading');
                const msg = 'No fue posible conectar con Hacienda. Completa los datos manualmente.';
                if (isOnb && avisoEl) {
                    avisoEl.textContent = msg;
                    avisoEl.className = 'help has-text-danger';
                } else {
                    setHaciendaAviso(avisoEl, 'warning', msg);
                }
            });
    });

    /* ======================================================================
       SOLICITUD: DESTINATARIO Y CAMBIO DE CLIENTE
       ====================================================================== */

    let clienteSolicitudCache = null;

    function rellenarDestinatarioDesdeCliente() {
        const radioMismo = document.getElementById('modo-mismo-cliente');
        const radioTercero = document.getElementById('modo-tercero');
        const labelMismo = document.getElementById('label-mismo-cliente');
        const textoTercero = document.getElementById('texto-modo-tercero');
        const inputId = document.getElementById('destinatario-id');
        const inputNom = document.getElementById('destinatario-nombre');
        const inputAp1 = document.getElementById('destinatario-apellido1');
        const inputAp2 = document.getElementById('destinatario-apellido2');
        const inputTel = document.getElementById('destinatario-telefono');
        const btnHacienda = document.getElementById('btn-consultar-hacienda-dest');
        const avisoHacienda = document.getElementById('hacienda-dest-aviso');

        if (!clienteSolicitudCache) return;

        if (clienteSolicitudCache.tipo_cliente === 'JURIDICA') {
            if (labelMismo) labelMismo.style.display = 'none';
            if (radioTercero) radioTercero.checked = true;
            if (textoTercero) textoTercero.textContent = 'Persona física autorizada por la empresa';
            if (inputNom) inputNom.value = '';
            if (inputAp1) inputAp1.value = '';
            if (inputAp2) inputAp2.value = '';
            if (inputId) inputId.value = '';
            if (inputTel) inputTel.value = clienteSolicitudCache.telefono || '';
            [inputNom, inputAp1, inputAp2, inputId, inputTel].forEach((el) => { if (el) el.readOnly = false; });
            if (btnHacienda) btnHacienda.disabled = false;
            setHaciendaAviso(avisoHacienda, 'info', 'Para empresas jurídicas, registra la persona física autorizada para recibir.');
        } else {
            if (labelMismo) labelMismo.style.display = 'inline-flex';
            if (textoTercero) textoTercero.textContent = 'Otra persona autorizada';
            if (radioMismo && radioMismo.checked) {
                if (inputId) inputId.value = clienteSolicitudCache.identificacion || '';
                if (inputTel) inputTel.value = clienteSolicitudCache.telefono || '';
                if (inputNom) inputNom.value = clienteSolicitudCache.nombre || '';
                if (inputAp1) inputAp1.value = clienteSolicitudCache.primer_apellido || 'Cliente';
                if (inputAp2) inputAp2.value = clienteSolicitudCache.segundo_apellido || 'Titular';
                [inputNom, inputAp1, inputAp2, inputId, inputTel].forEach((el) => { if (el) el.readOnly = true; });
                if (btnHacienda) btnHacienda.disabled = true;
                setHaciendaAviso(avisoHacienda, '', '');
            } else {
                [inputNom, inputAp1, inputAp2, inputId, inputTel].forEach((el) => { if (el) el.readOnly = false; });
                if (btnHacienda) btnHacienda.disabled = false;
                setHaciendaAviso(avisoHacienda, '', '');
            }
        }
    }

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!target) return;

        if (target.id === 'solicitud-cliente') {
            const clienteId = target.value;
            if (!clienteId) {
                clienteSolicitudCache = null;
                return;
            }
            fetch('/clientes/info.php?cliente_id=' + encodeURIComponent(clienteId))
                .then((res) => (res.ok ? res.json() : null))
                .then((data) => {
                    if (data) {
                        clienteSolicitudCache = data;
                        rellenarDestinatarioDesdeCliente();
                    }
                })
                .catch(() => {});
            return;
        }

        if (target.id === 'modo-mismo-cliente') {
            rellenarDestinatarioDesdeCliente();
            return;
        }

        if (target.id === 'modo-tercero') {
            const inputNom = document.getElementById('destinatario-nombre');
            const inputAp1 = document.getElementById('destinatario-apellido1');
            const inputAp2 = document.getElementById('destinatario-apellido2');
            const inputId = document.getElementById('destinatario-id');
            const inputTel = document.getElementById('destinatario-telefono');
            const btnHacienda = document.getElementById('btn-consultar-hacienda-dest');
            const avisoHacienda = document.getElementById('hacienda-dest-aviso');

            [inputNom, inputAp1, inputAp2, inputId, inputTel].forEach((el) => { if (el) el.readOnly = false; });
            if (btnHacienda) btnHacienda.disabled = false;
            setHaciendaAviso(avisoHacienda, '', '');
            return;
        }

        // Validación de consistencia en modal de asignación de paradas de viajes
        if (['asignar-solicitud', 'parada-recogida', 'parada-entrega'].includes(target.id)) {
            const selSol = document.getElementById('asignar-solicitud');
            const selRec = document.getElementById('parada-recogida');
            const selEnt = document.getElementById('parada-entrega');
            const aviso = document.getElementById('asignar-validacion-aviso');

            if (!selSol || !selRec || !selEnt || !aviso) return;
            const optSol = selSol.options[selSol.selectedIndex];
            const optRec = selRec.options[selRec.selectedIndex];
            const optEnt = selEnt.options[selEnt.selectedIndex];

            if (!optSol?.value || !optRec?.value || !optEnt?.value) {
                aviso.hidden = true;
                return;
            }

            const origenId = optSol.dataset.origen;
            const destinoId = optSol.dataset.destino;
            const ubicRecId = optRec.dataset.ubicacion;
            const ubicEntId = optEnt.dataset.ubicacion;
            const ordenRec = parseInt(optRec.dataset.orden || '0', 10);
            const ordenEnt = parseInt(optEnt.dataset.orden || '0', 10);

            if (ordenRec >= ordenEnt) {
                aviso.hidden = false;
                aviso.textContent = 'Inconsistencia de ruta: la parada de recogida debe ser anterior en el orden a la parada de entrega.';
                return;
            }
            if (origenId && ubicRecId && origenId !== ubicRecId) {
                aviso.hidden = false;
                aviso.textContent = 'La ubicación de la parada de recogida no coincide con el origen de la solicitud.';
                return;
            }
            if (destinoId && ubicEntId && destinoId !== ubicEntId) {
                aviso.hidden = false;
                aviso.textContent = 'La ubicación de la parada de entrega no coincide con el destino de la solicitud.';
                return;
            }
            aviso.hidden = true;
        }
    });
})();
