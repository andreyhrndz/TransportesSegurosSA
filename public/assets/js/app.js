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
                                tipo_cliente: item.tipo_cliente ? String(item.tipo_cliente) : '',
                                nombre: item.nombre ? String(item.nombre) : '',
                                identificacion: item.identificacion ? String(item.identificacion) : '',
                                telefono: item.telefono ? String(item.telefono) : '',
                                pma: item.pma !== undefined ? String(item.pma) : '',
                                articulado: item.articulado !== undefined ? String(item.articulado) : '',
                                clase: item.clase ? String(item.clase) : '',
                                licencias: item.licencias ? String(item.licencias) : '',
                                vencimiento: item.vencimiento ? String(item.vencimiento) : '',
                            })));
                        })
                        .catch(() => callback([]));
                };
            }

            if (select.dataset.licenseSelect === '1') {
                config.onChange = () => {
                    handleLicenseCategoryChange(select);
                };
            }

            try {
                const instance = new window.TomSelect(select, config);
                const modalBody = select.closest('.modal-card-body');
                const isInModal = Boolean(select.closest('.modal'));

                const syncDropdownPosition = () => {
                    if (!instance.isOpen) return;
                    const dd = instance.dropdown;
                    const ctrl = instance.control;
                    if (!dd || !ctrl) return;

                    const ctrlRect = ctrl.getBoundingClientRect();
                    if (modalBody) {
                        const bodyRect = modalBody.getBoundingClientRect();
                        if (ctrlRect.bottom < bodyRect.top + 4 || ctrlRect.top > bodyRect.bottom - 4) {
                            instance.close();
                            return;
                        }
                    }

                    const margin = 10;
                    const content = instance.dropdown_content || dd.querySelector('.ts-dropdown-content');
                    const ddWidth = Math.min(Math.max(ctrlRect.width, 190), window.innerWidth - margin * 2);
                    const clampedLeft = Math.min(Math.max(margin, ctrlRect.left), window.innerWidth - ddWidth - margin);

                    const spaceBelow = Math.max(0, window.innerHeight - ctrlRect.bottom - margin);
                    const spaceAbove = Math.max(0, ctrlRect.top - margin);
                    const placeAbove = spaceBelow < 230 && spaceAbove > spaceBelow;
                    const availableVertical = placeAbove ? spaceAbove : spaceBelow;
                    const maxContentHeight = Math.max(96, Math.min(250, availableVertical - 14));

                    if (content) {
                        content.style.maxHeight = maxContentHeight + 'px';
                    }

                    dd.style.width = ddWidth + 'px';
                    dd.classList.toggle('is-modal-dropdown', isInModal);
                    dd.classList.toggle('is-flipped-up', placeAbove);

                    if (isInModal) {
                        dd.style.position = 'fixed';
                        dd.style.left = clampedLeft + 'px';
                        const ddHeight = Math.min(dd.offsetHeight || (maxContentHeight + 12), availableVertical);
                        const topPx = placeAbove
                            ? Math.max(margin, ctrlRect.top - ddHeight - 4)
                            : Math.min(window.innerHeight - ddHeight - margin, ctrlRect.bottom + 4);
                        dd.style.top = Math.max(margin, topPx) + 'px';
                    } else {
                        instance.positionDropdown();
                        dd.style.left = (clampedLeft + window.scrollX) + 'px';
                        if (placeAbove) {
                            const ddHeight = Math.min(dd.offsetHeight || (maxContentHeight + 12), availableVertical);
                            dd.style.top = Math.max(window.scrollY + margin, ctrlRect.top + window.scrollY - ddHeight - 4) + 'px';
                        } else {
                            dd.style.top = (ctrlRect.bottom + window.scrollY + 4) + 'px';
                        }
                    }
                };

                const onDropdownWheel = (wheelEvent) => {
                    const content = instance.dropdown_content || instance.dropdown?.querySelector('.ts-dropdown-content');
                    wheelEvent.preventDefault();
                    wheelEvent.stopPropagation();
                    if (!content) return;
                    const canScroll = content.scrollHeight > content.clientHeight + 1;
                    if (!canScroll) return;
                    const delta = wheelEvent.deltaMode === 1
                        ? wheelEvent.deltaY * 32
                        : (wheelEvent.deltaMode === 2 ? wheelEvent.deltaY * content.clientHeight : wheelEvent.deltaY);
                    content.scrollTop = Math.max(0, Math.min(content.scrollHeight - content.clientHeight, content.scrollTop + delta));
                };

                instance.dropdown?.addEventListener('wheel', onDropdownWheel, { passive: false });
                instance.on('dropdown_open', () => {
                    requestAnimationFrame(syncDropdownPosition);
                    setTimeout(syncDropdownPosition, 30);
                });
                instance.on('type', () => requestAnimationFrame(syncDropdownPosition));
                if (modalBody) {
                    modalBody.addEventListener('scroll', syncDropdownPosition, { passive: true });
                }
                window.addEventListener('resize', syncDropdownPosition, { passive: true });
                window.addEventListener('scroll', syncDropdownPosition, { passive: true });
                instance._modalBodyScrollCleanup = () => {
                    instance.dropdown?.removeEventListener('wheel', onDropdownWheel);
                    if (modalBody) modalBody.removeEventListener('scroll', syncDropdownPosition);
                    window.removeEventListener('resize', syncDropdownPosition);
                    window.removeEventListener('scroll', syncDropdownPosition);
                };
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
                        todayBtn.textContent = isDateTime ? 'Ahora (+5m)' : 'Hoy';
                        todayBtn.addEventListener('click', () => {
                            const targetDate = isDateTime ? new Date(Date.now() + 5 * 60 * 1000) : new Date();
                            instance.setDate(targetDate, true);
                            if (!isDateTime) {
                                instance.close();
                            }
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

                        if (isDateTime) {
                            const applyBtn = document.createElement('button');
                            applyBtn.type = 'button';
                            applyBtn.className = 'flatpickr-footer-btn is-apply';
                            applyBtn.textContent = 'Aplicar';
                            applyBtn.addEventListener('click', () => {
                                if (instance.selectedDates.length === 0) {
                                    instance.setDate(new Date(Date.now() + 5 * 60 * 1000), true);
                                } else {
                                    // Sincronizar hora editada antes de cerrar
                                    instance.setDate(instance.selectedDates[0], true);
                                }
                                instance.close();
                            });
                            footer.appendChild(applyBtn);
                        }

                        cal.appendChild(footer);
                    }
                },
                onChange(selectedDates, dateStr, instance) {
                    // Evitar doble resalte visual por foco residual en días previos
                    if (instance?.calendarContainer) {
                        const focused = instance.calendarContainer.querySelector('.flatpickr-day:focus:not(.selected)');
                        if (focused && typeof focused.blur === 'function') {
                            focused.blur();
                        }
                    }
                    if (input.id === 'cond-vencimiento') {
                        clearInlineFieldError(input, document.getElementById('cond-vencimiento-error'));
                    }
                    if (!isDateTime && selectedDates.length > 0 && instance?.isOpen) {
                        instance.close();
                    }
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
                    if (form.id === 'reportes-filtros') {
                        syncReportExportLinks();
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

    /* ======================================================================
       MAPA INTERACTIVO DE UBICACIONES (LEAFLET + OPENSTREETMAP + FALLBACK)
       Factory único compartido por Flota > Ubicaciones y Nueva solicitud.
       ====================================================================== */

    const MAP_PIN_SVG = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg>';
    const DEFAULT_MAP_CENTER = [9.9281, -84.0907];
    const DEFAULT_MAP_ZOOM = 8;

    function parseValidCoordPair(latRaw, lonRaw) {
        const latStr = String(latRaw ?? '').trim().replace(',', '.');
        const lonStr = String(lonRaw ?? '').trim().replace(',', '.');
        if (latStr === '' || lonStr === '') return null;
        const lat = Number(latStr);
        const lon = Number(lonStr);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) return null;
        if (lat < -90 || lat > 90 || lon < -180 || lon > 180) return null;
        return { lat, lon };
    }

    function createInteractiveLocationMap({
        canvas,
        shell,
        latInput,
        lonInput,
        resetBtn,
        clearBtn,
        initialPair = null,
        onCoordsChange = () => {},
        onFallback = () => {},
    }) {
        if (!canvas || !latInput || !lonInput) return null;

        if (typeof window.L !== 'object' || typeof window.L.map !== 'function') {
            onFallback(true);
            return null;
        }

        let map = null;
        let marker = null;
        let resizeObserver = null;
        let tilesLoaded = 0;
        let tileErrors = 0;
        let fallbackTriggered = false;

        try {
            map = window.L.map(canvas, {
                center: initialPair ? [initialPair.lat, initialPair.lon] : DEFAULT_MAP_CENTER,
                zoom: initialPair ? 15 : DEFAULT_MAP_ZOOM,
                scrollWheelZoom: false,
                attributionControl: true,
            });

            // Habilitar wheel-zoom solo tras interacción explícita con el mapa (evita zoom involuntario al hacer scroll en el modal)
            const enableWheelZoom = () => {
                canvas.dataset.wheelZoomActive = '1';
                map?.scrollWheelZoom?.enable();
            };
            const disableWheelZoom = () => {
                delete canvas.dataset.wheelZoomActive;
                map?.scrollWheelZoom?.disable();
            };
            canvas.addEventListener('click', enableWheelZoom);
            canvas.addEventListener('mouseleave', disableWheelZoom);
            canvas.addEventListener('blur', disableWheelZoom);

            const tiles = window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
            });
            tiles.on('tileload', () => {
                tilesLoaded += 1;
            });
            tiles.on('tileerror', () => {
                tileErrors += 1;
                if (!fallbackTriggered && tileErrors >= 3 && tilesLoaded === 0) {
                    fallbackTriggered = true;
                    onFallback(false);
                }
            });
            tiles.addTo(map);

            const pinIcon = window.L.divIcon({
                className: 'tssa-map-marker',
                html: '<span class="tssa-map-marker-pin" aria-hidden="true"></span>',
                iconSize: [28, 28],
                iconAnchor: [14, 28],
            });

            const setPoint = (lat, lon, syncInputs = true, panToPoint = false) => {
                const latFixed = Number(lat.toFixed(6));
                const lonFixed = Number(lon.toFixed(6));
                if (!marker) {
                    marker = window.L.marker([latFixed, lonFixed], {
                        draggable: true,
                        icon: pinIcon,
                        title: 'Arrastra para ajustar la ubicación',
                    }).addTo(map);
                    marker.on('dragend', () => {
                        const pos = marker.getLatLng();
                        latInput.value = pos.lat.toFixed(6);
                        lonInput.value = pos.lng.toFixed(6);
                        onCoordsChange(pos.lat, pos.lng);
                    });
                } else {
                    marker.setLatLng([latFixed, lonFixed]);
                }
                if (syncInputs) {
                    latInput.value = latFixed.toFixed(6);
                    lonInput.value = lonFixed.toFixed(6);
                }
                if (panToPoint) {
                    map.setView([latFixed, lonFixed], Math.max(map.getZoom(), 14));
                }
                onCoordsChange(latFixed, lonFixed);
            };

            const clearPoint = (syncInputs = true) => {
                if (marker) {
                    map.removeLayer(marker);
                    marker = null;
                }
                if (syncInputs) {
                    latInput.value = '';
                    lonInput.value = '';
                }
                onCoordsChange(null, null);
            };

            if (initialPair) {
                setPoint(initialPair.lat, initialPair.lon, false, false);
            }

            map.on('click', (e) => {
                enableWheelZoom();
                setPoint(e.latlng.lat, e.latlng.lng, true, false);
            });

            const onManualCoordChange = () => {
                const latStr = latInput.value.trim();
                const lonStr = lonInput.value.trim();
                if (latStr === '' && lonStr === '') {
                    clearPoint(false);
                    return;
                }
                const parsed = parseValidCoordPair(latStr, lonStr);
                if (parsed) {
                    setPoint(parsed.lat, parsed.lon, false, true);
                }
            };

            const onResetClick = () => {
                if (marker) {
                    const pos = marker.getLatLng();
                    map.setView([pos.lat, pos.lng], 15);
                } else {
                    map.setView(DEFAULT_MAP_CENTER, DEFAULT_MAP_ZOOM);
                }
                map.invalidateSize(false);
            };

            const onClearClick = () => clearPoint(true);

            latInput.addEventListener('change', onManualCoordChange);
            lonInput.addEventListener('change', onManualCoordChange);
            latInput.addEventListener('input', onManualCoordChange);
            lonInput.addEventListener('input', onManualCoordChange);
            resetBtn?.addEventListener('click', onResetClick);
            clearBtn?.addEventListener('click', onClearClick);

            const invalidate = () => {
                try {
                    map?.invalidateSize(false);
                } catch {
                    // Ignorar si ya fue desmontado
                }
            };
            requestAnimationFrame(invalidate);
            const t1 = setTimeout(invalidate, 140);
            const t2 = setTimeout(invalidate, 350);
            window.addEventListener('resize', invalidate, { passive: true });

            if (typeof window.ResizeObserver === 'function') {
                resizeObserver = new window.ResizeObserver(invalidate);
                resizeObserver.observe(canvas);
            }

            const destroy = () => {
                clearTimeout(t1);
                clearTimeout(t2);
                window.removeEventListener('resize', invalidate);
                canvas.removeEventListener('click', enableWheelZoom);
                canvas.removeEventListener('mouseleave', disableWheelZoom);
                canvas.removeEventListener('blur', disableWheelZoom);
                delete canvas.dataset.wheelZoomActive;
                latInput.removeEventListener('change', onManualCoordChange);
                lonInput.removeEventListener('change', onManualCoordChange);
                latInput.removeEventListener('input', onManualCoordChange);
                lonInput.removeEventListener('input', onManualCoordChange);
                resetBtn?.removeEventListener('click', onResetClick);
                clearBtn?.removeEventListener('click', onClearClick);
                resizeObserver?.disconnect();
                try {
                    map?.remove();
                } catch {
                    // Ignorar error al destruir mapa
                }
            };

            const controller = {
                map,
                setPoint,
                clearPoint,
                resetView: onResetClick,
                invalidate,
                destroy,
            };

            if (shell) {
                shell._tssaMapInstance = map;
                shell._tssaMapController = controller;
            }

            return controller;
        } catch {
            onFallback(true);
            return null;
        }
    }

    function initLocationMaps(root = document) {
        if (!root || !root.querySelectorAll) return;
        const shells = root.querySelectorAll('[data-location-map-shell]');
        shells.forEach((shell) => {
            if (shell.dataset.mapInitialized === '1') return;
            shell.dataset.mapInitialized = '1';

            const canvas = shell.querySelector('[data-location-map]');
            const form = shell.closest('form') || root;
            const latInput = form.querySelector('#ubi-latitud');
            const lonInput = form.querySelector('#ubi-longitud');
            const statusEl = form.querySelector('#ubi-mapa-estado');
            const fallbackEl = form.querySelector('#ubi-mapa-fallback');
            const detailsEl = form.querySelector('#ubi-coords-avanzadas');
            const resetBtn = form.querySelector('[data-map-reset]');
            const clearBtn = form.querySelector('[data-map-clear]');
            if (!canvas || !latInput || !lonInput) return;

            const activateFallback = (hideCanvas = false) => {
                if (fallbackEl) fallbackEl.hidden = false;
                if (detailsEl) detailsEl.open = true;
                if (hideCanvas) shell.hidden = true;
            };

            const updateStatus = (lat, lon) => {
                if (!statusEl) return;
                if (lat === null || lon === null) {
                    statusEl.innerHTML = MAP_PIN_SVG + '<span>Sin punto seleccionado (opcional). Haz clic en el mapa para marcar la ubicación.</span>';
                    if (clearBtn) clearBtn.hidden = true;
                } else {
                    const fmt = lat.toFixed(6) + ', ' + lon.toFixed(6);
                    statusEl.innerHTML = MAP_PIN_SVG + '<span>Punto seleccionado: <code>' + escapeHtml(fmt) + '</code> (haz clic o arrastra el marcador para ajustar).</span>';
                    if (clearBtn) clearBtn.hidden = false;
                }
            };

            const initialPair = parseValidCoordPair(shell.dataset.initialLat || latInput.value, shell.dataset.initialLon || lonInput.value);
            const controller = createInteractiveLocationMap({
                canvas,
                shell,
                latInput,
                lonInput,
                resetBtn,
                clearBtn,
                initialPair,
                onCoordsChange: updateStatus,
                onFallback: activateFallback,
            });

            shell._tssaMapCleanup = () => {
                controller?.destroy();
                delete shell._tssaMapInstance;
                delete shell._tssaMapCleanup;
            };
        });
    }

    function destroyLocationMaps(root = document) {
        if (!root || !root.querySelectorAll) return;
        root.querySelectorAll('[data-location-map-shell]').forEach((shell) => {
            if (typeof shell._tssaMapCleanup === 'function') {
                shell._tssaMapCleanup();
            }
            delete shell.dataset.mapInitialized;
        });
    }

    /* ======================================================================
       UBICACIONES HÍBRIDAS EN NUEVA SOLICITUD (COMBOBOX + MAPA COMPARTIDO)
       ====================================================================== */

    function initHybridRequestLocations(root = document) {
        if (!root || !root.querySelectorAll) return;
        const sections = root.querySelectorAll('[data-hybrid-request-locations="1"]');
        sections.forEach((section) => {
            if (section.dataset.hybridInitialized === '1') return;
            section.dataset.hybridInitialized = '1';

            const canSaveFrequent = section.dataset.canSaveFrequent === '1';
            const panel = section.querySelector('#solicitud-mapa-panel');
            const shell = section.querySelector('#solicitud-mapa-shell');
            const canvas = section.querySelector('#solicitud-mapa-canvas');
            const titleEl = section.querySelector('#solicitud-mapa-titulo');
            const statusEl = section.querySelector('#solicitud-mapa-estado');
            const errorEl = section.querySelector('#solicitud-mapa-error');
            const fallbackEl = section.querySelector('#solicitud-mapa-fallback');
            const detailsEl = section.querySelector('#solicitud-mapa-coords-avanzadas');
            const refInput = section.querySelector('#solicitud-mapa-referencia');
            const nameInput = section.querySelector('#solicitud-mapa-nombre');
            const nameReqEl = section.querySelector('#solicitud-mapa-nombre-req');
            const freqCheck = section.querySelector('#solicitud-mapa-frecuente');
            const latInput = section.querySelector('#solicitud-mapa-lat');
            const lonInput = section.querySelector('#solicitud-mapa-lon');
            const resetBtn = section.querySelector('[data-req-map-reset]');
            const clearBtn = section.querySelector('[data-req-map-clear]');
            const confirmBtn = section.querySelector('#btn-confirmar-punto-mapa');
            const confirmTextEl = section.querySelector('#btn-confirmar-punto-mapa-texto');

            if (!panel || !shell || !canvas || !refInput || !latInput || !lonInput) return;

            const readSlotFromDom = (slotKey) => {
                const card = section.querySelector('[data-location-slot="' + slotKey + '"]');
                if (!card) {
                    return { mode: 'guardada', lat: '', lon: '', direccion: '', nombre: '', guardarFrecuente: false };
                }
                const modeVal = card.querySelector('[data-slot-input="modo"]')?.value === 'mapa' ? 'mapa' : 'guardada';
                return {
                    mode: modeVal,
                    lat: card.querySelector('[data-slot-input="latitud"]')?.value || '',
                    lon: card.querySelector('[data-slot-input="longitud"]')?.value || '',
                    direccion: card.querySelector('[data-slot-input="direccion"]')?.value || '',
                    nombre: card.querySelector('[data-slot-input="nombre"]')?.value || '',
                    guardarFrecuente: (card.querySelector('[data-slot-input="guardar_frecuente"]')?.value || '0') === '1',
                };
            };

            const slotState = {
                origen: readSlotFromDom('origen'),
                destino: readSlotFromDom('destino'),
            };
            let activeSlot = null;
            let lastTriggerBtn = null;
            let mapController = null;
            let mapAttempted = false;

            const showDrawerError = (message) => {
                if (!errorEl) return;
                if (!message) {
                    errorEl.hidden = true;
                    errorEl.textContent = '';
                    return;
                }
                errorEl.textContent = message;
                errorEl.hidden = false;
                errorEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            };

            const updateDrawerStatus = (lat, lon) => {
                if (!statusEl) return;
                if (lat === null || lon === null) {
                    statusEl.innerHTML = MAP_PIN_SVG + '<span>Haz clic en el mapa para marcar el punto exacto. Puedes arrastrar el marcador para ajustarlo.</span>';
                    if (clearBtn) clearBtn.hidden = true;
                } else {
                    statusEl.innerHTML = MAP_PIN_SVG + '<span>Punto marcado en el mapa. Arrastra el marcador si necesitas ajustar su posición.</span>';
                    if (clearBtn) clearBtn.hidden = false;
                }
            };

            const activateFallback = (hideCanvas = false) => {
                if (fallbackEl) fallbackEl.hidden = false;
                if (detailsEl) detailsEl.open = true;
                if (hideCanvas) shell.hidden = true;
            };

            const ensureMapReady = () => {
                if (mapController) {
                    requestAnimationFrame(() => mapController.invalidate());
                    setTimeout(() => mapController?.invalidate(), 180);
                    return mapController;
                }
                if (mapAttempted) return null;
                mapAttempted = true;
                mapController = createInteractiveLocationMap({
                    canvas,
                    shell,
                    latInput,
                    lonInput,
                    resetBtn,
                    clearBtn,
                    initialPair: null,
                    onCoordsChange: updateDrawerStatus,
                    onFallback: activateFallback,
                });
                return mapController;
            };

            const syncFrequentUi = () => {
                const isFreq = Boolean(canSaveFrequent && freqCheck?.checked);
                if (nameReqEl) nameReqEl.hidden = !isFreq;
            };

            const renderSlot = (slotKey) => {
                const card = section.querySelector('[data-location-slot="' + slotKey + '"]');
                if (!card) return;
                const state = slotState[slotKey];
                const isMap = state.mode === 'mapa';
                const badge = card.querySelector('[data-slot-mode-badge]');
                const savedBox = card.querySelector('[data-slot-saved-box]');
                const summaryBox = card.querySelector('[data-slot-map-summary]');
                const selectEl = savedBox?.querySelector('select');
                const openHeaderBtn = card.querySelector('.hybrid-slot-header [data-open-slot-map]');

                const inpModo = card.querySelector('[data-slot-input="modo"]');
                const inpNombre = card.querySelector('[data-slot-input="nombre"]');
                const inpDir = card.querySelector('[data-slot-input="direccion"]');
                const inpLat = card.querySelector('[data-slot-input="latitud"]');
                const inpLon = card.querySelector('[data-slot-input="longitud"]');
                const inpFreq = card.querySelector('[data-slot-input="guardar_frecuente"]');

                if (badge) {
                    badge.textContent = isMap ? 'Punto en mapa' : 'Guardada';
                    badge.classList.toggle('is-map-mode', isMap);
                }
                card.classList.toggle('is-map-selected', isMap);
                if (openHeaderBtn) openHeaderBtn.hidden = isMap;

                if (isMap) {
                    if (savedBox) savedBox.hidden = true;
                    if (selectEl) {
                        selectEl.required = false;
                        selectEl.disabled = true;
                        if (selectEl.tomselect) {
                            selectEl.tomselect.clear(true);
                            selectEl.tomselect.disable();
                        } else {
                            selectEl.value = '';
                        }
                    }
                    if (inpModo) inpModo.value = 'mapa';
                    if (inpNombre) { inpNombre.value = state.nombre; inpNombre.disabled = false; }
                    if (inpDir) { inpDir.value = state.direccion; inpDir.disabled = false; }
                    if (inpLat) { inpLat.value = state.lat; inpLat.disabled = false; }
                    if (inpLon) { inpLon.value = state.lon; inpLon.disabled = false; }
                    if (inpFreq) { inpFreq.value = (canSaveFrequent && state.guardarFrecuente) ? '1' : '0'; inpFreq.disabled = false; }

                    if (summaryBox) {
                        const prefix = card.dataset.summaryPrefix || (slotKey === 'origen' ? 'Punto de recogida' : 'Punto de entrega');
                        const displayName = state.nombre || (state.direccion.length > 72 ? state.direccion.slice(0, 72) + '…' : state.direccion);
                        const titleTarget = summaryBox.querySelector('[data-slot-summary-title]');
                        const refTarget = summaryBox.querySelector('[data-slot-summary-ref]');
                        const metaTarget = summaryBox.querySelector('[data-slot-summary-meta]');
                        if (titleTarget) titleTarget.textContent = prefix + ': ' + displayName;
                        if (refTarget) refTarget.textContent = state.direccion;
                        if (metaTarget) {
                            metaTarget.textContent = (canSaveFrequent && state.guardarFrecuente)
                                ? 'Se guardará también en ubicaciones frecuentes'
                                : 'Solo para esta solicitud (no se agrega al catálogo)';
                        }
                        summaryBox.hidden = false;
                    }
                } else {
                    if (summaryBox) summaryBox.hidden = true;
                    if (savedBox) savedBox.hidden = false;
                    if (selectEl) {
                        selectEl.disabled = false;
                        selectEl.required = true;
                        if (selectEl.tomselect) {
                            selectEl.tomselect.enable();
                        }
                    }
                    if (inpModo) inpModo.value = 'guardada';
                    [inpNombre, inpDir, inpLat, inpLon, inpFreq].forEach((el) => {
                        if (!el) return;
                        el.value = el === inpFreq ? '0' : '';
                        el.disabled = true;
                    });
                }
            };

            const closeDrawer = (restoreFocus = true) => {
                if (panel.hidden) return false;
                panel.hidden = true;
                showDrawerError('');
                section.querySelectorAll('.hybrid-location-card.is-editing-map').forEach((c) => c.classList.remove('is-editing-map'));
                const slotClosed = activeSlot;
                activeSlot = null;
                if (restoreFocus) {
                    const card = slotClosed ? section.querySelector('[data-location-slot="' + slotClosed + '"]') : null;
                    const focusTarget = (slotClosed && slotState[slotClosed].mode === 'mapa')
                        ? card?.querySelector('[data-slot-map-summary] [data-open-slot-map]')
                        : (lastTriggerBtn && lastTriggerBtn.offsetParent !== null ? lastTriggerBtn : card?.querySelector('[data-open-slot-map]'));
                    focusTarget?.focus({ preventScroll: true });
                }
                return true;
            };

            const openDrawerForSlot = (slotKey, triggerBtn = null) => {
                if (slotKey !== 'origen' && slotKey !== 'destino') return;
                closeOpenFloatingControls(modal || section);
                activeSlot = slotKey;
                lastTriggerBtn = triggerBtn;
                const state = slotState[slotKey];

                section.querySelectorAll('.hybrid-location-card').forEach((c) => {
                    c.classList.toggle('is-editing-map', c.dataset.locationSlot === slotKey);
                });

                const isOrigin = slotKey === 'origen';
                if (titleEl) {
                    titleEl.textContent = isOrigin ? 'Marcar ubicación de origen en el mapa' : 'Marcar ubicación de destino en el mapa';
                }
                if (confirmTextEl) {
                    confirmTextEl.textContent = isOrigin ? 'Usar este punto como origen' : 'Usar este punto como destino';
                }

                showDrawerError('');
                refInput.value = state.direccion || '';
                if (nameInput) nameInput.value = state.nombre || '';
                if (freqCheck) {
                    freqCheck.checked = Boolean(canSaveFrequent && state.guardarFrecuente);
                }
                syncFrequentUi();
                latInput.value = state.lat || '';
                lonInput.value = state.lon || '';

                panel.hidden = false;
                const ctrl = ensureMapReady();
                const parsed = parseValidCoordPair(state.lat, state.lon);
                if (ctrl) {
                    if (parsed) {
                        ctrl.setPoint(parsed.lat, parsed.lon, false, true);
                    } else {
                        ctrl.clearPoint(false);
                        ctrl.resetView();
                    }
                    requestAnimationFrame(() => ctrl.invalidate());
                    setTimeout(() => ctrl.invalidate(), 160);
                } else {
                    updateDrawerStatus(parsed ? parsed.lat : null, parsed ? parsed.lon : null);
                }

                requestAnimationFrame(() => {
                    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    if (fallbackEl && !fallbackEl.hidden) {
                        refInput.focus({ preventScroll: true });
                    } else {
                        canvas.focus({ preventScroll: true });
                    }
                });
            };

            const onConfirmClick = () => {
                if (!activeSlot) return;
                showDrawerError('');

                const parsed = parseValidCoordPair(latInput.value, lonInput.value);
                if (!parsed) {
                    showDrawerError('Haz clic en el mapa para marcar un punto (o ingresa latitud y longitud válidas en opciones avanzadas).');
                    return;
                }

                const refVal = refInput.value.trim();
                if (refVal === '') {
                    showDrawerError('Ingresa una referencia humana breve para identificar el sitio exacto.');
                    refInput.focus({ preventScroll: true });
                    return;
                }
                if (refVal.length > 250) {
                    showDrawerError('La referencia humana no puede superar 250 caracteres.');
                    refInput.focus({ preventScroll: true });
                    return;
                }

                const wantsFreq = Boolean(canSaveFrequent && freqCheck?.checked);
                const nameVal = (nameInput?.value || '').trim();
                if (wantsFreq && nameVal.length < 3) {
                    showDrawerError('Indica un nombre o alias de al menos 3 caracteres para guardar en ubicaciones frecuentes.');
                    nameInput?.focus({ preventScroll: true });
                    return;
                }
                if (nameVal.length > 150) {
                    showDrawerError('El nombre o alias no puede superar 150 caracteres.');
                    nameInput?.focus({ preventScroll: true });
                    return;
                }

                slotState[activeSlot] = {
                    mode: 'mapa',
                    lat: parsed.lat.toFixed(6),
                    lon: parsed.lon.toFixed(6),
                    direccion: refVal,
                    nombre: nameVal,
                    guardarFrecuente: wantsFreq,
                };
                renderSlot(activeSlot);
                closeDrawer(true);
            };

            const onSectionClick = (event) => {
                const openBtn = event.target.closest('[data-open-slot-map]');
                if (openBtn && section.contains(openBtn)) {
                    event.preventDefault();
                    openDrawerForSlot(openBtn.dataset.openSlotMap, openBtn);
                    return;
                }

                const clearSlotBtn = event.target.closest('[data-clear-slot-map]');
                if (clearSlotBtn && section.contains(clearSlotBtn)) {
                    event.preventDefault();
                    const slotKey = clearSlotBtn.dataset.clearSlotMap;
                    if (slotKey === 'origen' || slotKey === 'destino') {
                        slotState[slotKey] = { mode: 'guardada', lat: '', lon: '', direccion: '', nombre: '', guardarFrecuente: false };
                        renderSlot(slotKey);
                        if (activeSlot === slotKey) {
                            closeDrawer(false);
                        }
                        const card = section.querySelector('[data-location-slot="' + slotKey + '"]');
                        const sel = card?.querySelector('select');
                        (sel?.tomselect?.control_input || sel)?.focus({ preventScroll: true });
                    }
                    return;
                }

                const closeBtn = event.target.closest('[data-close-slot-map]');
                if (closeBtn && section.contains(closeBtn)) {
                    event.preventDefault();
                    closeDrawer(true);
                    return;
                }

                const fallbackBtn = event.target.closest('[data-fallback-use-saved]');
                if (fallbackBtn && section.contains(fallbackBtn)) {
                    event.preventDefault();
                    const targetSlot = activeSlot;
                    if (targetSlot) {
                        slotState[targetSlot] = { mode: 'guardada', lat: '', lon: '', direccion: '', nombre: '', guardarFrecuente: false };
                        renderSlot(targetSlot);
                    }
                    closeDrawer(false);
                    if (targetSlot) {
                        const card = section.querySelector('[data-location-slot="' + targetSlot + '"]');
                        const sel = card?.querySelector('select');
                        (sel?.tomselect?.control_input || sel)?.focus({ preventScroll: true });
                    }
                }
            };

            const clearDrawerErrorOnInput = () => showDrawerError('');
            refInput.addEventListener('input', clearDrawerErrorOnInput);
            nameInput?.addEventListener('input', clearDrawerErrorOnInput);
            section.addEventListener('click', onSectionClick);
            freqCheck?.addEventListener('change', syncFrequentUi);
            confirmBtn?.addEventListener('click', onConfirmClick);

            panel._tssaCloseDrawer = () => closeDrawer(true);

            section._tssaHybridCleanup = () => {
                refInput.removeEventListener('input', clearDrawerErrorOnInput);
                nameInput?.removeEventListener('input', clearDrawerErrorOnInput);
                section.removeEventListener('click', onSectionClick);
                freqCheck?.removeEventListener('change', syncFrequentUi);
                confirmBtn?.removeEventListener('click', onConfirmClick);
                mapController?.destroy();
                mapController = null;
                delete panel._tssaCloseDrawer;
                delete section._tssaHybridCleanup;
            };
        });
    }

    function destroyHybridRequestLocations(root = document) {
        if (!root || !root.querySelectorAll) return;
        root.querySelectorAll('[data-hybrid-request-locations="1"]').forEach((section) => {
            if (typeof section._tssaHybridCleanup === 'function') {
                section._tssaHybridCleanup();
            }
            delete section.dataset.hybridInitialized;
        });
    }

    /* ======================================================================
       SELECTOR INTELIGENTE DE PARADAS AL ASIGNAR SOLICITUD A UN VIAJE
       ====================================================================== */

    function initAssignStopsModal(root = document) {
        if (!root || !root.querySelectorAll) return;
        const containers = root.querySelectorAll('[data-assign-stops-root]');
        containers.forEach((container) => {
            if (container.dataset.assignStopsInit === '1') return;
            container.dataset.assignStopsInit = '1';

            const form = container.closest('form');
            const selSol = container.querySelector('#asignar-solicitud');
            const selRec = container.querySelector('#parada-recogida');
            const selEnt = container.querySelector('#parada-entrega');
            const autoToggle = container.querySelector('[data-auto-stops-toggle]');
            const aviso = container.querySelector('#asignar-validacion-aviso');
            const ayuda = container.querySelector('#asignar-paradas-ayuda');
            const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
            if (!selSol || !selRec || !selEnt) return;

            let allStops = [];
            try {
                const parsed = JSON.parse(container.dataset.tripStops || '[]');
                if (Array.isArray(parsed)) {
                    allStops = parsed.map((s) => ({
                        id: parseInt(s.id, 10),
                        ubicacion_id: parseInt(s.ubicacion_id, 10),
                        orden: parseInt(s.orden, 10),
                        tipo_parada: String(s.tipo_parada || '').toUpperCase().trim(),
                        ubicacion_nombre: String(s.ubicacion_nombre || ''),
                        label: String(s.label || ''),
                        subtext: String(s.subtext || ''),
                    })).filter((s) => Number.isFinite(s.id) && s.id > 0);
                }
            } catch {
                allStops = [];
            }

            const getSolicitudMeta = () => {
                const val = String(selSol.value || '').trim();
                if (!val) return null;
                const opt = Array.from(selSol.options || []).find((o) => String(o.value) === val);
                const tsOpt = selSol.tomselect?.options?.[val];
                const rawOri = opt?.dataset?.origen ?? tsOpt?.$option?.dataset?.origen ?? '';
                const rawDes = opt?.dataset?.destino ?? tsOpt?.$option?.dataset?.destino ?? '';
                const origenId = parseInt(rawOri, 10);
                const destinoId = parseInt(rawDes, 10);
                if (!Number.isFinite(origenId) || !Number.isFinite(destinoId) || origenId <= 0 || destinoId <= 0) {
                    return null;
                }
                return { solicitudId: parseInt(val, 10), origenId, destinoId };
            };

            const setSelectOptions = (selectEl, stopsList, selectedId, enabled, placeholderText) => {
                const ts = selectEl.tomselect || null;
                const wantedVal = selectedId > 0 && stopsList.some((s) => s.id === selectedId) ? String(selectedId) : '';

                if (ts) {
                    ts.settings.placeholder = placeholderText;
                    if (ts.control_input) {
                        ts.control_input.setAttribute('placeholder', placeholderText);
                    }
                    ts.clear(true);
                    ts.clearOptions();
                    stopsList.forEach((stop) => {
                        ts.addOption({
                            value: String(stop.id),
                            text: stop.label,
                            subtext: stop.subtext,
                        });
                    });
                    if (wantedVal !== '') {
                        ts.setValue(wantedVal, true);
                    }
                    ts.refreshOptions(false);
                    if (enabled) {
                        ts.enable();
                        selectEl.disabled = false;
                    } else {
                        ts.disable();
                        selectEl.disabled = true;
                    }
                    if (ts.control_input) {
                        ts.control_input.setAttribute('placeholder', wantedVal === '' ? placeholderText : '');
                    }
                    return;
                }

                // Fallback sin Tom Select
                selectEl.innerHTML = '';
                const emptyOpt = document.createElement('option');
                emptyOpt.value = '';
                emptyOpt.textContent = placeholderText;
                selectEl.appendChild(emptyOpt);
                stopsList.forEach((stop) => {
                    const opt = document.createElement('option');
                    opt.value = String(stop.id);
                    opt.textContent = stop.label;
                    if (wantedVal === opt.value) opt.selected = true;
                    selectEl.appendChild(opt);
                });
                selectEl.value = wantedVal;
                selectEl.disabled = !enabled;
            };

            const showNotice = (msg) => {
                if (!aviso) return;
                if (!msg) {
                    aviso.hidden = true;
                    aviso.textContent = '';
                } else {
                    aviso.hidden = false;
                    aviso.textContent = msg;
                }
            };

            const setHelp = (msg) => {
                if (ayuda && msg) {
                    ayuda.textContent = msg;
                }
            };

            let isUpdating = false;
            let lastValidPairs = [];

            const recompute = (source = 'init') => {
                if (isUpdating) return;
                isUpdating = true;
                try {
                    const meta = getSolicitudMeta();
                    if (!meta) {
                        lastValidPairs = [];
                        setSelectOptions(selRec, [], 0, false, 'Selecciona primero una solicitud…');
                        setSelectOptions(selEnt, [], 0, false, 'Selecciona primero una solicitud…');
                        showNotice('');
                        setHelp('Selecciona primero una solicitud para mostrar únicamente las paradas compatibles en origen, destino y secuencia del itinerario.');
                        if (submitBtn) submitBtn.disabled = true;
                        return;
                    }

                    const recCandidates = allStops.filter(
                        (s) => (s.tipo_parada === 'RECOGIDA' || s.tipo_parada === 'MIXTA') && s.ubicacion_id === meta.origenId
                    );
                    const entCandidates = allStops.filter(
                        (s) => (s.tipo_parada === 'ENTREGA' || s.tipo_parada === 'MIXTA') && s.ubicacion_id === meta.destinoId
                    );

                    const validPairs = [];
                    recCandidates.forEach((r) => {
                        entCandidates.forEach((e) => {
                            if (r.id !== e.id && r.orden < e.orden) {
                                validPairs.push({ rec: r, ent: e });
                            }
                        });
                    });
                    lastValidPairs = validPairs;

                    const autoEnabled = Boolean(autoToggle && autoToggle.checked);

                    if (validPairs.length === 0 && autoEnabled) {
                        const existingRec = recCandidates[0] || null;
                        if (existingRec) {
                            setSelectOptions(selRec, recCandidates, existingRec.id, true, 'Se reutilizará parada existente…');
                        } else {
                            setSelectOptions(selRec, [], 0, false, 'Se creará automáticamente al asignar');
                        }
                        setSelectOptions(selEnt, [], 0, false, 'Se creará automáticamente al asignar');
                        selRec.required = false;
                        selEnt.required = false;

                        if (aviso) {
                            aviso.classList.remove('notification-warning');
                            aviso.classList.add('notification-info');
                        }
                        if (existingRec) {
                            showNotice('Se reutilizará la parada de recogida #' + existingRec.orden + ' y se creará automáticamente la parada de entrega al final del itinerario.');
                        } else {
                            showNotice('Se crearán automáticamente las paradas de recogida y entrega al final del itinerario en la misma transacción.');
                        }
                        setHelp('Después de asignar puedes usar «Reordenar paradas» o «Sugerir orden óptimo» si deseas ajustar la secuencia.');
                        if (submitBtn) submitBtn.disabled = false;
                        return;
                    }

                    if (aviso) {
                        aviso.classList.remove('notification-info');
                        aviso.classList.add('notification-warning');
                    }
                    selRec.required = !autoEnabled;
                    selEnt.required = !autoEnabled;

                    if (recCandidates.length === 0 && entCandidates.length === 0) {
                        setSelectOptions(selRec, [], 0, false, 'Sin parada compatible en origen');
                        setSelectOptions(selEnt, [], 0, false, 'Sin parada compatible en destino');
                        showNotice('No hay paradas compatibles en el itinerario para el origen ni el destino de esta solicitud. Agrégalas o corrígelas en el itinerario.');
                        setHelp('Se requiere una parada de tipo Recogida o Mixta en el origen y una de tipo Entrega o Mixta en el destino.');
                        if (submitBtn) submitBtn.disabled = true;
                        return;
                    }

                    if (recCandidates.length === 0) {
                        setSelectOptions(selRec, [], 0, false, 'Sin parada compatible en origen');
                        setSelectOptions(selEnt, [], 0, false, 'Requiere parada de recogida compatible');
                        showNotice('No hay una parada de recogida en el origen de esta solicitud. Agrégala o corrígela en el itinerario.');
                        setHelp('Solo se admiten paradas de tipo Recogida o Mixta cuya ubicación coincida exactamente con el origen de la solicitud.');
                        if (submitBtn) submitBtn.disabled = true;
                        return;
                    }

                    if (entCandidates.length === 0) {
                        setSelectOptions(selRec, recCandidates, 0, true, 'Selecciona parada de recogida…');
                        setSelectOptions(selEnt, [], 0, false, 'Sin parada compatible en destino');
                        showNotice('No hay una parada de entrega en su destino. Agrégala o corrígela en el itinerario.');
                        setHelp('Solo se admiten paradas de tipo Entrega o Mixta cuya ubicación coincida exactamente con el destino de la solicitud.');
                        if (submitBtn) submitBtn.disabled = true;
                        return;
                    }

                    if (validPairs.length === 0) {
                        setSelectOptions(selRec, recCandidates, 0, false, 'Orden incompatible en el itinerario');
                        setSelectOptions(selEnt, entCandidates, 0, false, 'Orden incompatible en el itinerario');
                        showNotice('La parada de recogida en el origen aparece después o en la misma posición que la entrega en el destino. Reordena las paradas del itinerario antes de asignar.');
                        setHelp('El orden de la parada de recogida debe ser estrictamente menor que el orden de la parada de entrega.');
                        if (submitBtn) submitBtn.disabled = true;
                        return;
                    }

                    let curRecId = parseInt(selRec.value || '0', 10) || 0;
                    let curEntId = parseInt(selEnt.value || '0', 10) || 0;

                    if (source === 'solicitud') {
                        if (validPairs.length === 1) {
                            curRecId = validPairs[0].rec.id;
                            curEntId = validPairs[0].ent.id;
                        } else {
                            const stillValidPair = validPairs.some((p) => p.rec.id === curRecId && p.ent.id === curEntId);
                            if (!stillValidPair) {
                                curRecId = 0;
                                curEntId = 0;
                            }
                        }
                    }

                    const allValidRecIds = new Set(validPairs.map((p) => p.rec.id));
                    const allValidEntIds = new Set(validPairs.map((p) => p.ent.id));

                    let recAllowedIds = allValidRecIds;
                    let entAllowedIds = allValidEntIds;

                    if (source === 'recogida') {
                        if (curRecId > 0 && allValidRecIds.has(curRecId)) {
                            entAllowedIds = new Set(validPairs.filter((p) => p.rec.id === curRecId).map((p) => p.ent.id));
                            if (curEntId > 0 && !entAllowedIds.has(curEntId)) {
                                curEntId = 0;
                            }
                        } else {
                            curRecId = 0;
                        }
                    } else if (source === 'entrega') {
                        if (curEntId > 0 && allValidEntIds.has(curEntId)) {
                            recAllowedIds = new Set(validPairs.filter((p) => p.ent.id === curEntId).map((p) => p.rec.id));
                            if (curRecId > 0 && !recAllowedIds.has(curRecId)) {
                                curRecId = 0;
                            }
                        } else {
                            curEntId = 0;
                        }
                    } else {
                        if (curRecId > 0 && !allValidRecIds.has(curRecId)) curRecId = 0;
                        if (curEntId > 0 && !allValidEntIds.has(curEntId)) curEntId = 0;
                        if (curRecId > 0) {
                            entAllowedIds = new Set(validPairs.filter((p) => p.rec.id === curRecId).map((p) => p.ent.id));
                            if (curEntId > 0 && !entAllowedIds.has(curEntId)) curEntId = 0;
                        }
                        if (curEntId > 0) {
                            recAllowedIds = new Set(validPairs.filter((p) => p.ent.id === curEntId).map((p) => p.rec.id));
                            if (curRecId > 0 && !recAllowedIds.has(curRecId)) curRecId = 0;
                        }
                    }

                    const recList = recCandidates.filter((s) => recAllowedIds.has(s.id));
                    const entList = entCandidates.filter((s) => entAllowedIds.has(s.id));

                    setSelectOptions(selRec, recList, curRecId, true, 'Selecciona parada de recogida…');
                    setSelectOptions(selEnt, entList, curEntId, true, 'Selecciona parada de entrega…');

                    const hasValidSelection = validPairs.some((p) => p.rec.id === curRecId && p.ent.id === curEntId);
                    showNotice('');
                    if (hasValidSelection) {
                        if (submitBtn) submitBtn.disabled = false;
                        setHelp(
                            validPairs.length === 1
                                ? 'Se seleccionó la única combinación compatible de recogida y entrega en este itinerario.'
                                : 'Combinación compatible seleccionada (origen, destino, tipo y secuencia verificados).'
                        );
                    } else {
                        if (submitBtn) submitBtn.disabled = true;
                        setHelp('Elige una parada de recogida y una parada de entrega compatibles (recogida antes que entrega).');
                    }
                } finally {
                    isUpdating = false;
                }
            };

            const onSolChange = () => recompute('solicitud');
            const onRecChange = () => recompute('recogida');
            const onEntChange = () => recompute('entrega');
            const onAutoChange = () => recompute('auto');
            const onFormSubmit = (ev) => {
                const solId = parseInt(selSol.value || '0', 10) || 0;
                if (solId <= 0) {
                    ev.preventDefault();
                    ev.stopImmediatePropagation();
                    showNotice('Selecciona una solicitud antes de asignar.');
                    return;
                }
                if (autoToggle && autoToggle.checked && lastValidPairs.length === 0) {
                    return;
                }
                const recId = parseInt(selRec.value || '0', 10) || 0;
                const entId = parseInt(selEnt.value || '0', 10) || 0;
                const ok = lastValidPairs.some((p) => p.rec.id === recId && p.ent.id === entId);
                if (!ok) {
                    ev.preventDefault();
                    ev.stopImmediatePropagation();
                    showNotice('Selecciona una solicitud y una pareja válida de paradas de recogida y entrega antes de asignar.');
                }
            };

            selSol.addEventListener('change', onSolChange);
            selRec.addEventListener('change', onRecChange);
            selEnt.addEventListener('change', onEntChange);
            autoToggle?.addEventListener('change', onAutoChange);
            form?.addEventListener('submit', onFormSubmit, true);

            container._tssaAssignStopsCleanup = () => {
                selSol.removeEventListener('change', onSolChange);
                selRec.removeEventListener('change', onRecChange);
                selEnt.removeEventListener('change', onEntChange);
                autoToggle?.removeEventListener('change', onAutoChange);
                form?.removeEventListener('submit', onFormSubmit, true);
                delete container._tssaAssignStopsCleanup;
            };

            recompute(selSol.value ? 'solicitud' : 'init');
        });
    }

    function destroyAssignStopsModal(root = document) {
        if (!root || !root.querySelectorAll) return;
        root.querySelectorAll('[data-assign-stops-root]').forEach((container) => {
            if (typeof container._tssaAssignStopsCleanup === 'function') {
                container._tssaAssignStopsCleanup();
            }
            delete container.dataset.assignStopsInit;
        });
    }

    function initComponents(root = document) {
        initComboboxes(root);
        initDatepickers(root);
        initCharts(root);
        initLocationMaps(root);
        initHybridRequestLocations(root);
        initAssignStopsModal(root);
    }

    function destroyComponents(root = document) {
        destroyAssignStopsModal(root);
        destroyComboboxes(root);
        destroyDatepickers(root);
        destroyCharts(root);
        destroyLocationMaps(root);
        destroyHybridRequestLocations(root);
    }

    /* ======================================================================
       MODALES, BLOQUEO GLOBAL DE SCROLL Y TOASTS
       ====================================================================== */

    let modalScrollLocked = false;
    let lockedScrollY = 0;

    function lockPageScroll() {
        if (modalScrollLocked) return;
        lockedScrollY = window.scrollY || window.pageYOffset || 0;
        const scrollbarWidth = Math.max(0, window.innerWidth - document.documentElement.clientWidth);
        document.documentElement.style.setProperty('--modal-scrollbar-comp', scrollbarWidth + 'px');
        document.documentElement.classList.add('is-modal-open');
        document.body.classList.add('is-modal-open');
        modalScrollLocked = true;
    }

    function unlockPageScroll() {
        if (!modalScrollLocked) return;
        document.documentElement.classList.remove('is-modal-open');
        document.body.classList.remove('is-modal-open');
        document.documentElement.style.removeProperty('--modal-scrollbar-comp');
        modalScrollLocked = false;
        if ((window.scrollY || window.pageYOffset || 0) !== lockedScrollY) {
            window.scrollTo({ top: lockedScrollY, behavior: 'instant' });
        }
    }

    function findScrollableAncestorInsideModal(target) {
        if (!(target instanceof Element)) return null;
        let el = target;
        while (el && el !== document.body && el !== document.documentElement) {
            const style = window.getComputedStyle(el);
            const overflowY = style.overflowY;
            const isScrollableStyle = overflowY === 'auto' || overflowY === 'scroll';
            if (isScrollableStyle && el.scrollHeight > el.clientHeight + 1) {
                if (modal?.contains(el) || el.classList.contains('ts-dropdown-content') || el.closest('.ts-dropdown')) {
                    return el;
                }
            }
            if (el === modal) break;
            el = el.parentElement;
        }
        return null;
    }

    // Bloqueo global de scroll de fondo por rueda cuando existe un modal abierto
    window.addEventListener('wheel', (event) => {
        if (!modalScrollLocked) return;
        const target = event.target;
        const mapCanvas = target instanceof Element ? target.closest('.location-map-canvas') : null;
        if (mapCanvas && mapCanvas.dataset.wheelZoomActive === '1') {
            // Permitir zoom interactivo de Leaflet solo cuando el usuario activó el mapa, sin mover el fondo
            return;
        }
        const scrollable = findScrollableAncestorInsideModal(target);
        if (!scrollable) {
            event.preventDefault();
            return;
        }
        const atTop = scrollable.scrollTop <= 0 && event.deltaY < 0;
        const atBottom = scrollable.scrollTop + scrollable.clientHeight >= scrollable.scrollHeight - 1 && event.deltaY > 0;
        if (atTop || atBottom) {
            event.preventDefault();
        }
    }, { passive: false });

    // Bloqueo de arrastre táctil sobre el backdrop/encabezado/pie del modal
    window.addEventListener('touchmove', (event) => {
        if (!modalScrollLocked) return;
        const target = event.target;
        if (!(target instanceof Element)) {
            event.preventDefault();
            return;
        }
        if (target.closest('.location-map-canvas, .ts-dropdown-content, .flatpickr-calendar')) {
            return;
        }
        const scrollable = findScrollableAncestorInsideModal(target);
        if (!scrollable) {
            event.preventDefault();
        }
    }, { passive: false });

    window.addEventListener('pagehide', unlockPageScroll);

    function cerrarModal() {
        if (!modal || modal.innerHTML.trim() === '') {
            unlockPageScroll();
            return;
        }
        destroyComponents(modal);
        modal.innerHTML = '';
        if (appShell) appShell.removeAttribute('aria-hidden');
        unlockPageScroll();
        previousFocus?.focus({ preventScroll: true });
        previousFocus = null;
    }

    function abrirModal() {
        if (!modal) return;
        const hasDialog = Boolean(modal.querySelector('.modal.is-active, .modal-card'));
        if (!hasDialog) {
            unlockPageScroll();
            return;
        }
        if (!modalScrollLocked) {
            previousFocus = document.activeElement;
        }
        lockPageScroll();
        if (appShell) appShell.setAttribute('aria-hidden', 'true');
        initComponents(modal);
        requestAnimationFrame(() => {
            const autofocusEl = modal.querySelector('[autofocus]');
            if (autofocusEl && typeof autofocusEl.focus === 'function') {
                autofocusEl.focus({ preventScroll: true });
            } else {
                firstFocusable(modal)?.focus({ preventScroll: true });
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

    function hasSubmittingModalForm() {
        return Boolean(modal?.querySelector('form[data-submitting="1"]'));
    }

    function closeOpenFloatingControls(root) {
        if (!root || !root.querySelectorAll) return false;
        let closed = false;
        root.querySelectorAll('select[data-combobox]').forEach((select) => {
            if (select.tomselect?.isOpen) {
                select.tomselect.close();
                closed = true;
            }
        });
        root.querySelectorAll('input[data-datepicker]').forEach((input) => {
            if (input._flatpickr?.isOpen) {
                input._flatpickr.close();
                closed = true;
            }
        });
        if (!closed) {
            const mapPanel = root.querySelector('#solicitud-mapa-panel:not([hidden])');
            if (mapPanel && typeof mapPanel._tssaCloseDrawer === 'function') {
                if (mapPanel._tssaCloseDrawer()) {
                    closed = true;
                }
            }
        }
        return closed;
    }

    function showInlineFieldError(controlEl, errorEl, message) {
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.hidden = false;
        }
        if (controlEl) {
            controlEl.setAttribute('aria-invalid', 'true');
            if (controlEl.tomselect?.wrapper) {
                controlEl.tomselect.wrapper.classList.add('has-error');
            } else if (controlEl._flatpickr?.altInput) {
                controlEl._flatpickr.altInput.classList.add('is-danger');
                controlEl._flatpickr.altInput.setAttribute('aria-invalid', 'true');
            } else {
                controlEl.classList.add('is-danger');
            }
        }
    }

    function clearInlineFieldError(controlEl, errorEl) {
        if (errorEl) {
            errorEl.textContent = '';
            errorEl.hidden = true;
        }
        if (controlEl) {
            controlEl.removeAttribute('aria-invalid');
            if (controlEl.tomselect?.wrapper) {
                controlEl.tomselect.wrapper.classList.remove('has-error');
            }
            if (controlEl._flatpickr?.altInput) {
                controlEl._flatpickr.altInput.classList.remove('is-danger');
                controlEl._flatpickr.altInput.removeAttribute('aria-invalid');
            }
            controlEl.classList.remove('is-danger');
        }
    }

    function isValidIsoDateString(raw) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw);
        if (!match) return false;
        const y = Number(match[1]);
        const m = Number(match[2]);
        const d = Number(match[3]);
        const dt = new Date(Date.UTC(y, m - 1, d));
        return dt.getUTCFullYear() === y && dt.getUTCMonth() === m - 1 && dt.getUTCDate() === d;
    }

    function handleLicenseCategoryChange(select) {
        if (!select || select.dataset.licenseSelect !== '1') return;
        const nextVal = String(select.value || '').trim().toUpperCase();
        const prevVal = String(select.dataset.prevLicenseCode || '').trim().toUpperCase();

        if (nextVal !== '') {
            clearInlineFieldError(select, document.getElementById('cond-tipo-licencia-error'));
        }

        // Si se vuelve a elegir la misma categoría sin cambio real, conservar la fecha existente.
        if (nextVal === prevVal) {
            return;
        }

        select.dataset.prevLicenseCode = nextVal;

        // Al cambiar desde una categoría previa a otra distinta, limpiar de inmediato el vencimiento anterior.
        if (prevVal !== '') {
            const dateInput = document.getElementById('cond-vencimiento');
            if (dateInput) {
                if (dateInput._flatpickr) {
                    dateInput._flatpickr.clear(false);
                    if (dateInput._flatpickr.altInput) {
                        dateInput._flatpickr.altInput.value = '';
                    }
                }
                dateInput.value = '';
                clearInlineFieldError(dateInput, document.getElementById('cond-vencimiento-error'));
                const helpEl = document.getElementById('cond-vencimiento-help');
                if (helpEl) {
                    helpEl.textContent = nextVal
                        ? 'Categoría cambiada a ' + nextVal + ': ingresa la fecha de vencimiento correspondiente.'
                        : 'Ingresa la fecha de vencimiento vigente de la licencia.';
                }
            }
        }
    }

    function validateConductorForm(form) {
        const msgBox = form.querySelector('#mensaje-form-maestro');
        if (msgBox) msgBox.innerHTML = '';

        const textRequired = [
            { id: 'cond-id', label: 'Ingresa la identificación del conductor.' },
            { id: 'cond-nombre', label: 'Ingresa el nombre del conductor.' },
            { id: 'cond-ap1', label: 'Ingresa el primer apellido del conductor.' },
            { id: 'cond-licencia', label: 'Ingresa el número de licencia del conductor.' },
        ];
        for (const item of textRequired) {
            const el = form.querySelector('#' + item.id);
            if (el && !el.disabled && el.value.trim() === '') {
                el.classList.add('is-danger');
                el.focus();
                if (msgBox) {
                    msgBox.innerHTML = '<div class="notification notification-danger" role="alert"><span>' + escapeHtml(item.label) + '</span></div>';
                }
                return false;
            }
            if (el) el.classList.remove('is-danger');
        }

        const emailEl = form.querySelector('#cond-correo');
        if (emailEl && emailEl.value.trim() !== '' && !emailEl.checkValidity()) {
            emailEl.classList.add('is-danger');
            emailEl.focus();
            if (msgBox) {
                msgBox.innerHTML = '<div class="notification notification-danger" role="alert"><span>Ingresa un correo electrónico válido.</span></div>';
            }
            return false;
        }
        if (emailEl) emailEl.classList.remove('is-danger');

        const licSelect = form.querySelector('#cond-tipo-licencia');
        const licErr = form.querySelector('#cond-tipo-licencia-error');
        const licVal = String(licSelect?.value || '').trim();
        if (!licVal) {
            showInlineFieldError(licSelect, licErr, 'Selecciona el tipo de licencia del conductor.');
            (licSelect?.tomselect?.control_input || licSelect)?.focus();
            return false;
        }
        clearInlineFieldError(licSelect, licErr);

        const dateInput = form.querySelector('#cond-vencimiento');
        const dateErr = form.querySelector('#cond-vencimiento-error');
        const rawDate = String(dateInput?.value || '').trim();
        const todayIso = dateInput?.getAttribute('min') || new Date().toISOString().slice(0, 10);

        if (!rawDate) {
            showInlineFieldError(dateInput, dateErr, 'Indica la fecha de vencimiento de la licencia.');
            (dateInput?._flatpickr?.altInput || dateInput)?.focus();
            return false;
        }
        if (!isValidIsoDateString(rawDate)) {
            showInlineFieldError(dateInput, dateErr, 'Ingresa una fecha de vencimiento de licencia válida.');
            (dateInput?._flatpickr?.altInput || dateInput)?.focus();
            return false;
        }
        if (rawDate < todayIso) {
            showInlineFieldError(dateInput, dateErr, 'La licencia del conductor no puede estar vencida.');
            (dateInput?._flatpickr?.altInput || dateInput)?.focus();
            return false;
        }
        clearInlineFieldError(dateInput, dateErr);
        return true;
    }

    function syncVehicleFormFields(changedEl) {
        const form = changedEl?.closest('form[data-vehiculo-form]');
        if (!form) return;
        const pmaInput = form.querySelector('#veh-pma');
        const claseSelect = form.querySelector('#veh-clase');
        const artCheck = form.querySelector('#veh-articulado');
        if (!claseSelect || !artCheck) return;

        const setClase = (val) => {
            if (claseSelect.value === val) return;
            if (claseSelect.tomselect) {
                claseSelect.tomselect.setValue(val, true);
            } else {
                claseSelect.value = val;
            }
        };

        const inferRigidClass = () => {
            const pmaVal = parseFloat(String(pmaInput?.value || '').replace(',', '.'));
            if (!Number.isFinite(pmaVal) || pmaVal <= 4000) return 'LIVIANO';
            if (pmaVal <= 8000) return 'MEDIANO_RIGIDO';
            return 'PESADO_RIGIDO';
        };

        if (changedEl === artCheck) {
            if (artCheck.checked) {
                setClase('PESADO_ARTICULADO');
            } else if (claseSelect.value === 'PESADO_ARTICULADO') {
                setClase(inferRigidClass());
            }
        } else if (changedEl === claseSelect) {
            artCheck.checked = (claseSelect.value === 'PESADO_ARTICULADO');
        } else if (changedEl === pmaInput && !artCheck.checked) {
            const pmaVal = parseFloat(String(pmaInput.value || '').replace(',', '.'));
            if (Number.isFinite(pmaVal) && pmaVal > 0) {
                setClase(inferRigidClass());
            }
        }
    }

    /* ======================================================================
       DELEGACIÓN GLOBAL DE EVENTOS
       ====================================================================== */

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-close-modal]')) {
            if (hasSubmittingModalForm()) return;
            const isBackdrop = event.target.classList?.contains('modal-background');
            if (isBackdrop && document.querySelector('.flatpickr-calendar.open')) {
                document.querySelectorAll('input[data-datepicker]').forEach((inp) => {
                    if (inp._flatpickr?.isOpen) inp._flatpickr.close();
                });
                return;
            }
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
            if (modal?.innerHTML.trim()) {
                if (closeOpenFloatingControls(modal)) {
                    return;
                }
                if (!hasSubmittingModalForm()) {
                    cerrarModal();
                }
                return;
            }
            if (userDropdown && !userDropdown.hidden) {
                toggleUserMenu(false);
                userMenuToggle?.focus();
                return;
            }
            toggleSidebar(false);
            return;
        }

        if (modalScrollLocked && ['PageDown', 'PageUp', 'Space', ' '].includes(event.key)) {
            const active = document.activeElement;
            const isEditable = active && (['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'A'].includes(active.tagName) || active.isContentEditable);
            if (!isEditable && !(active instanceof Element && active.closest('.modal-card-body, .ts-dropdown-content, .location-map-canvas'))) {
                event.preventDefault();
                return;
            }
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
            field.required = delivered && field.name === 'receptor_nombre';
        });
        failureFields?.querySelectorAll('textarea').forEach((field) => {
            field.required = !delivered && field.name === 'motivo_fallo';
        });
    });

    /* ======================================================================
       CICLO DE VIDA HTMX
       ====================================================================== */

    function showInitialFlashNotice() {
        if (!aviso) return;
        const flashMsg = aviso.dataset.flashMessage || '';
        if (flashMsg !== '') {
            mostrarAviso(flashMsg, aviso.dataset.flashError === '1');
            delete aviso.dataset.flashMessage;
            delete aviso.dataset.flashError;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initComponents(document);
            showInitialFlashNotice();
        });
    } else {
        initComponents(document);
        showInitialFlashNotice();
    }

    // Validación previa al envío HTMX para el formulario simplificado de conductor
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.dataset.conductorForm === '1') {
            if (!validateConductorForm(form)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }
        if (form.dataset.solicitudForm === '1') {
            const openMapPanel = form.querySelector('#solicitud-mapa-panel:not([hidden])');
            if (openMapPanel) {
                event.preventDefault();
                event.stopImmediatePropagation();
                const errMap = openMapPanel.querySelector('#solicitud-mapa-error');
                if (errMap) {
                    errMap.textContent = 'Confirma el punto seleccionado en el mapa o cierra el selector antes de crear la solicitud.';
                    errMap.hidden = false;
                    errMap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        }
    }, true);

    document.body.addEventListener('htmx:beforeRequest', (event) => {
        const elt = event.detail?.elt;
        const form = elt instanceof HTMLFormElement ? elt : elt?.closest?.('form');
        if (!form || !modal?.contains(form)) return;
        if (form.dataset.submitting === '1') {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = '1';
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.classList.add('is-loading');
            submitBtn.disabled = true;
        }
    });

    function releaseModalFormSubmitting(elt) {
        const form = elt instanceof HTMLFormElement ? elt : elt?.closest?.('form');
        if (!form) return;
        delete form.dataset.submitting;
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.classList.remove('is-loading');
            if (form.dataset.parentLocked !== '1') {
                submitBtn.disabled = false;
            }
        }
    }

    document.body.addEventListener('htmx:afterRequest', (event) => {
        releaseModalFormSubmitting(event.detail?.elt);
    });

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
        if ([400, 403, 404, 405, 409, 422, 429, 500, 502, 503].includes(status)) {
            if (status >= 500 && !String(event.detail.serverResponse || '').includes('notification-')) {
                event.detail.serverResponse = '<div class="notification notification-danger" role="alert"><span>Ocurrió un error en el servidor al procesar la solicitud. Inténtalo nuevamente.</span></div>';
            }
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
            if (modal?.contains(target)) {
                if (target.querySelector('[data-disable-parent-form="true"]')) {
                    const parentForm = target.closest('form');
                    if (parentForm) {
                        parentForm.dataset.parentLocked = '1';
                        const submitBtn = parentForm.querySelector('button[type="submit"]');
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.classList.remove('is-loading');
                        }
                        parentForm.querySelectorAll('input[name="correo"], input[name="nuevo_correo"]').forEach((inp) => {
                            inp.readOnly = true;
                        });
                    }
                }
                const errNotice = target.querySelector('.notification-danger, .notification-warning');
                if (errNotice) {
                    errNotice.setAttribute('tabindex', '-1');
                    errNotice.focus({ preventScroll: true });
                    errNotice.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        }
    });

    document.body.addEventListener('tssaNotice', (event) => {
        const detail = event.detail || {};
        const isError = Boolean(detail.error);
        const shouldCloseModal = !isError && detail.closeModal !== false;
        if (shouldCloseModal) {
            cerrarModal();
        }
        if (detail.message) {
            mostrarAviso(String(detail.message), isError);
        }
    });

    function showModalTransportError(elt, message) {
        releaseModalFormSubmitting(elt);
        mostrarAviso(message, true);
        if (modal?.innerHTML.trim()) {
            const form = elt instanceof HTMLFormElement ? elt : elt?.closest?.('form');
            const alertTarget = form?.querySelector('[role="alert"]') || modal.querySelector('[role="alert"]');
            if (alertTarget) {
                alertTarget.innerHTML = '<div class="notification notification-danger" role="alert"><span>' + escapeHtml(message) + '</span></div>';
            }
        }
    }

    document.body.addEventListener('htmx:sendError', (event) => showModalTransportError(event.detail?.elt, 'No se pudo contactar al servidor. Verifica tu conexión e inténtalo nuevamente.'));
    document.body.addEventListener('htmx:timeout', (event) => showModalTransportError(event.detail?.elt, 'La solicitud tardó demasiado. Inténtalo nuevamente.'));

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
        const limpio = String(nombreCompleto || '').trim().replace(/\s+/g, ' ');
        if (!limpio) {
            return { nombre: '', primerApellido: '', segundoApellido: '', ambiguo: false };
        }

        const partes = limpio.split(' ').filter(Boolean);

        // En Costa Rica el formato oficial es: NOMBRE(S) PRIMER_APELLIDO SEGUNDO_APELLIDO
        // Para 3 o más componentes: los dos últimos son los apellidos y los anteriores son el nombre
        if (partes.length >= 3) {
            const segundoApellido = partes[partes.length - 1];
            const primerApellido = partes[partes.length - 2];
            const nombre = partes.slice(0, partes.length - 2).join(' ');
            return {
                nombre,
                primerApellido,
                segundoApellido,
                ambiguo: false
            };
        }

        // Si sólo hay 2 componentes: Ej. "MARIA MORA" -> nombre: "MARIA", primerApellido: "MORA"
        // Se preserva completo y se marca como ambiguo para requerir revisión si el usuario lo desea
        if (partes.length === 2) {
            return {
                nombre: partes[0],
                primerApellido: partes[1],
                segundoApellido: '',
                ambiguo: true
            };
        }

        // Si sólo hay 1 componente: se preserva en nombre y se marca ambiguo
        return {
            nombre: partes[0],
            primerApellido: '',
            segundoApellido: '',
            ambiguo: true
        };
    }

    function setHaciendaAviso(el, tipo, texto) {
        if (!el) return;
        // UX silenciosa en éxito: no mostrar banner llamativo verde, sólo limpiar o emitir aria-label
        if (!texto || tipo === 'success') {
            el.hidden = true;
            el.innerHTML = '';
            el.textContent = '';
            if (tipo === 'success' && texto) {
                el.setAttribute('aria-label', texto);
            }
            return;
        }
        el.hidden = false;
        const alertClass = tipo === 'warning'
            ? 'notification-warning'
            : (tipo === 'danger' ? 'notification-danger' : 'notification-info');
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

                        if (parsed.ambiguo && avisoEl) {
                            avisoEl.textContent = 'Nombre con formato inusual. Por favor revisa y confirma la distribución de nombres y apellidos.';
                            avisoEl.className = 'help has-text-warning';
                        } else if (avisoEl) {
                            avisoEl.textContent = '';
                            avisoEl.className = 'help';
                        }
                    } else if (isCli) {
                        const inputRazon = document.getElementById('razon_social');
                        const inputComercial = document.getElementById('nombre_comercial');
                        const inputNom = document.getElementById('nombre');
                        const inputAp1 = document.getElementById('primer_apellido');
                        const inputAp2 = document.getElementById('segundo_apellido');

                        if (inputRazon) {
                            // Persona Jurídica: preservar razón social intacta sin descomponer
                            inputRazon.value = rawNombre;
                            if (inputComercial && !inputComercial.value) inputComercial.value = rawNombre;
                            setHaciendaAviso(avisoEl, '', '');
                        } else if (inputNom) {
                            // Persona Física: descomponer apellidos al final
                            const parsed = descomponerNombreCR(rawNombre);
                            inputNom.value = parsed.nombre;
                            if (inputAp1) inputAp1.value = parsed.primerApellido;
                            if (inputAp2) inputAp2.value = parsed.segundoApellido;
                            if (parsed.ambiguo) {
                                setHaciendaAviso(avisoEl, 'warning', 'Nombre con formato inusual. Por favor revisa y confirma la distribución de nombres y apellidos antes de guardar.');
                            } else {
                                setHaciendaAviso(avisoEl, '', '');
                            }
                        }
                    } else if (isEdit) {
                        const inputRazon = document.getElementById('edit-razon-social');
                        const inputComercial = document.getElementById('edit-nombre-comercial');
                        const inputNom = document.getElementById('edit-nombre');
                        const inputAp1 = document.getElementById('edit-primer-apellido');
                        const inputAp2 = document.getElementById('edit-segundo-apellido');

                        if (inputRazon) {
                            inputRazon.value = rawNombre;
                            if (inputComercial && !inputComercial.value) inputComercial.value = rawNombre;
                            setHaciendaAviso(avisoEl, '', '');
                        } else if (inputNom) {
                            const parsed = descomponerNombreCR(rawNombre);
                            inputNom.value = parsed.nombre;
                            if (inputAp1) inputAp1.value = parsed.primerApellido;
                            if (inputAp2) inputAp2.value = parsed.segundoApellido;
                            if (parsed.ambiguo) {
                                setHaciendaAviso(avisoEl, 'warning', 'Nombre con formato inusual. Por favor revisa y confirma la distribución de nombres y apellidos antes de guardar.');
                            } else {
                                setHaciendaAviso(avisoEl, '', '');
                            }
                        }
                    } else if (isDest) {
                        const inputNom = document.getElementById('destinatario-nombre');
                        const inputAp1 = document.getElementById('destinatario-apellido1');
                        const inputAp2 = document.getElementById('destinatario-apellido2');

                        if (data.tipo === '02') {
                            if (inputNom) inputNom.value = rawNombre;
                            setHaciendaAviso(avisoEl, 'warning', 'La cédula corresponde a una persona jurídica. Por favor ingresa el nombre de la persona física autorizada para recibir la entrega.');
                        } else {
                            const parsed = descomponerNombreCR(rawNombre);
                            if (inputNom) inputNom.value = parsed.nombre || '';
                            if (inputAp1) inputAp1.value = parsed.primerApellido || '';
                            if (inputAp2) inputAp2.value = parsed.segundoApellido || '';
                            if (parsed.ambiguo) {
                                setHaciendaAviso(avisoEl, 'warning', 'Nombre con formato inusual. Por favor revisa y confirma la distribución de nombres y apellidos.');
                            } else {
                                setHaciendaAviso(avisoEl, '', '');
                            }
                        }
                    }
                } else if (data.status === 'no_encontrado') {
                    const msg = 'Identificación no registrada en Hacienda. Puedes ingresar los datos manualmente.';
                    if (isOnb && avisoEl) {
                        avisoEl.textContent = msg;
                        avisoEl.className = 'help has-text-warning';
                    } else {
                        setHaciendaAviso(avisoEl, 'warning', msg);
                    }
                } else if (data.status === 'limite_excedido') {
                    const msg = 'Límite de consultas excedido temporalmente. Puedes ingresar los datos manualmente.';
                    if (isOnb && avisoEl) {
                        avisoEl.textContent = msg;
                        avisoEl.className = 'help has-text-warning';
                    } else {
                        setHaciendaAviso(avisoEl, 'warning', msg);
                    }
                } else {
                    const msg = data.mensaje || 'Servicio de Hacienda no disponible temporalmente. Puedes ingresar los datos manualmente.';
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
                const msg = 'No fue posible conectar con Hacienda. Puedes ingresar los datos manualmente.';
                if (isOnb && avisoEl) {
                    avisoEl.textContent = msg;
                    avisoEl.className = 'help has-text-danger';
                } else {
                    setHaciendaAviso(avisoEl, 'warning', msg);
                }
            });
    });

    /* ======================================================================
       SOLICITUD: DESTINATARIO Y CAMBIO DE CLIENTE CONDICIONAL
       ====================================================================== */

    let clienteSolicitudCache = null;
    let clienteFetchSeq = 0;
    let modoForzadoPorJuridico = false;

    function actualizarModoDestinatario() {
        const radioMismo = document.getElementById('modo-mismo-cliente');
        const radioTercero = document.getElementById('modo-tercero');
        const labelMismo = document.getElementById('label-mismo-cliente');
        const ayudaModo = document.getElementById('ayuda-modo-destinatario');
        const seccionDest = document.getElementById('seccion-destinatario');

        const inputId = document.getElementById('destinatario-id');
        const inputNom = document.getElementById('destinatario-nombre');
        const inputAp1 = document.getElementById('destinatario-apellido1');
        const inputAp2 = document.getElementById('destinatario-apellido2');
        const inputTel = document.getElementById('destinatario-telefono');
        const btnHacienda = document.getElementById('btn-consultar-hacienda-dest');
        const avisoHacienda = document.getElementById('hacienda-dest-aviso');

        if (!seccionDest || !radioMismo || !radioTercero) return;

        const camposTercero = [inputId, inputNom, inputAp1, inputAp2, inputTel];

        // 1. Estado sin cliente seleccionado: los radios deben ser interactivos
        if (!clienteSolicitudCache || !clienteSolicitudCache.tipo_cliente) {
            if (labelMismo) labelMismo.style.display = 'inline-flex';
            radioMismo.disabled = false;
            radioTercero.disabled = false;

            if (!radioMismo.checked && !radioTercero.checked) {
                radioMismo.checked = true;
            }

            if (radioMismo.checked) {
                if (ayudaModo) ayudaModo.textContent = 'Por defecto recibe el cliente registrado, o puedes indicar una persona autorizada.';
                seccionDest.style.display = 'none';
                camposTercero.forEach((el) => {
                    if (el) {
                        el.disabled = true;
                        el.required = false;
                    }
                });
                if (btnHacienda) btnHacienda.disabled = true;
                setHaciendaAviso(avisoHacienda, '', '');
            } else {
                if (ayudaModo) ayudaModo.textContent = 'Indica los datos de la persona física autorizada para recibir la entrega.';
                seccionDest.style.display = 'block';
                camposTercero.forEach((el) => {
                    if (el) el.disabled = false;
                });
                if (inputNom) inputNom.required = true;
                if (inputAp1) inputAp1.required = true;
                if (inputAp2) inputAp2.required = false;
                if (btnHacienda) btnHacienda.disabled = false;
            }
            return;
        }

        // 2. Cliente Jurídico (Empresa): obligatorio designar persona física autorizada
        if (clienteSolicitudCache.tipo_cliente === 'JURIDICA') {
            if (labelMismo) labelMismo.style.display = 'none';
            radioMismo.disabled = true;
            radioMismo.checked = false;
            radioTercero.disabled = false;
            radioTercero.checked = true;
            modoForzadoPorJuridico = true;

            if (ayudaModo) ayudaModo.textContent = 'Para clientes jurídicos (empresas), es obligatorio registrar la persona física autorizada para recibir la entrega.';
            seccionDest.style.display = 'block';
            camposTercero.forEach((el) => {
                if (el) el.disabled = false;
            });
            if (inputNom) inputNom.required = true;
            if (inputAp1) inputAp1.required = true;
            if (inputAp2) inputAp2.required = false;
            if (btnHacienda) btnHacienda.disabled = false;
            return;
        }

        // 3. Cliente Físico
        if (labelMismo) labelMismo.style.display = 'inline-flex';
        radioMismo.disabled = false;
        radioTercero.disabled = false;

        // Si venía de forzado por jurídica, restaurar mismo_cliente por defecto
        if (modoForzadoPorJuridico) {
            radioMismo.checked = true;
            radioTercero.checked = false;
            modoForzadoPorJuridico = false;
        } else if (!radioMismo.checked && !radioTercero.checked) {
            radioMismo.checked = true;
        }

        if (radioMismo.checked) {
            // Modo "El mismo cliente": recepción propia, sección oculta y campos deshabilitados
            if (ayudaModo) ayudaModo.textContent = 'Para clientes físicos, la recepción por defecto es directa sin requerir datos adicionales.';
            seccionDest.style.display = 'none';
            camposTercero.forEach((el) => {
                if (el) {
                    el.disabled = true;
                    el.required = false;
                }
            });
            if (btnHacienda) btnHacienda.disabled = true;
            setHaciendaAviso(avisoHacienda, '', '');
        } else {
            // Modo "Otra persona autorizada": mostrar campos y habilitar validación
            if (ayudaModo) ayudaModo.textContent = 'Indica los datos de la persona física autorizada por el cliente para recibir la entrega.';
            seccionDest.style.display = 'block';
            camposTercero.forEach((el) => {
                if (el) el.disabled = false;
            });
            if (inputNom) inputNom.required = true;
            if (inputAp1) inputAp1.required = true;
            if (inputAp2) inputAp2.required = false;
            if (btnHacienda) btnHacienda.disabled = false;
        }
    }

    function actualizarContadorPermisosInvitacion() {
        const cont = document.getElementById('contador-permisos-invitacion');
        const aviso = document.getElementById('aviso-cero-permisos');
        const chks = document.querySelectorAll('.chk-permiso:checked');
        const count = chks.length;
        if (cont) {
            cont.textContent = count === 1 ? '1 permiso seleccionado' : `${count} permisos seleccionados`;
            if (count > 0) {
                cont.className = 'tag is-primary is-light';
            } else {
                cont.className = 'tag is-info is-light';
            }
        }
        if (aviso) {
            aviso.style.display = count === 0 ? 'block' : 'none';
        }
    }

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!target) return;

        if (target.classList.contains('chk-permiso')) {
            actualizarContadorPermisosInvitacion();
            return;
        }

        if (target.id === 'solicitud-cliente') {
            const clienteId = target.value;
            clienteFetchSeq++;
            const seq = clienteFetchSeq;

            if (!clienteId) {
                clienteSolicitudCache = null;
                modoForzadoPorJuridico = false;
                actualizarModoDestinatario();
                return;
            }

            // Sincronización inmediata con metadatos de option estática o de Tom Select (si existen)
            const opt = target.options ? target.options[target.selectedIndex] : null;
            const tsOpt = target.tomselect?.options ? target.tomselect.options[clienteId] : null;
            const tipoInmediato = opt?.dataset?.tipo || tsOpt?.tipo_cliente || null;

            if (tipoInmediato) {
                clienteSolicitudCache = {
                    cliente_id: parseInt(clienteId, 10),
                    tipo_cliente: tipoInmediato,
                    nombre: opt?.dataset?.nombre || tsOpt?.nombre || '',
                    identificacion: opt?.dataset?.identificacion || tsOpt?.identificacion || '',
                    telefono: opt?.dataset?.telefono || tsOpt?.telefono || ''
                };
                actualizarModoDestinatario();
            }

            // Confirmación asíncrona autoritativa desde /clientes/info.php (sin asumir FISICA)
            fetch('/clientes/info.php?cliente_id=' + encodeURIComponent(clienteId))
                .then((res) => (res.ok ? res.json() : null))
                .then((data) => {
                    if (data && seq === clienteFetchSeq && parseInt(target.value, 10) === data.cliente_id) {
                        clienteSolicitudCache = data;
                        actualizarModoDestinatario();
                    }
                })
                .catch(() => {
                    if (seq === clienteFetchSeq) {
                        const ayudaModo = document.getElementById('ayuda-modo-destinatario');
                        if (ayudaModo) {
                            ayudaModo.textContent = 'No se pudo verificar el tipo de cliente. Por favor reintenta la selección.';
                            ayudaModo.classList.add('has-text-danger');
                        }
                    }
                });
            return;
        }

        if (target.name === 'modo_destinatario' || target.id === 'modo-mismo-cliente' || target.id === 'modo-tercero') {
            actualizarModoDestinatario();
            return;
        }

        // La consistencia del modal de asignación de paradas se gestiona en initAssignStopsModal().

        if (target.id === 'cond-tipo-licencia' || target.dataset?.licenseSelect === '1') {
            handleLicenseCategoryChange(target);
            return;
        }

        if (['veh-articulado', 'veh-clase', 'veh-pma'].includes(target.id)) {
            syncVehicleFormFields(target);
            return;
        }

        if (['viaje-vehiculo', 'viaje-conductor', 'viaje-fecha'].includes(target.id)) {
            actualizarDiagnosticoHabilitacionViaje();
        }
    });

    function actualizarDiagnosticoHabilitacionViaje() {
        const selVeh = document.getElementById('viaje-vehiculo');
        const selCond = document.getElementById('viaje-conductor');
        const inputFecha = document.getElementById('viaje-fecha');
        const aviso = document.getElementById('aviso-diagnostico-habilitacion');
        if (!selVeh || !selCond || !aviso) return;

        const vehVal = selVeh.value || '';
        const condVal = selCond.value || '';
        if (!vehVal || !condVal) {
            aviso.style.display = 'none';
            aviso.textContent = '';
            return;
        }

        const optVeh = selVeh.options ? selVeh.options[selVeh.selectedIndex] : null;
        const optCond = selCond.options ? selCond.options[selCond.selectedIndex] : null;
        const tsVeh = selVeh.tomselect?.options?.[vehVal] || null;
        const tsCond = selCond.tomselect?.options?.[condVal] || null;

        const pma = parseFloat(optVeh?.dataset?.pma || tsVeh?.pma || '0');
        const esArticulado = (optVeh?.dataset?.articulado || tsVeh?.articulado || '0') === '1';
        const licenciasRaw = optCond?.dataset?.licencias || tsCond?.licencias || '';
        const vencimientoRaw = String(optCond?.dataset?.vencimiento || tsCond?.vencimiento || '').slice(0, 10);
        const lics = licenciasRaw.split(',').map((s) => s.trim().toUpperCase()).filter(Boolean);

        if (lics.length === 0 || lics.includes('PENDIENTE')) {
            aviso.style.display = 'block';
            aviso.className = 'notification notification-warning py-2 px-3 mb-3';
            aviso.textContent = 'Advertencia: el conductor seleccionado no cuenta con una categoría de licencia habilitada.';
            return;
        }

        const fechaViajeIso = String(inputFecha?.value || '').slice(0, 10);
        if (vencimientoRaw && fechaViajeIso && vencimientoRaw < fechaViajeIso) {
            aviso.style.display = 'block';
            aviso.className = 'notification notification-danger py-2 px-3 mb-3';
            aviso.textContent = 'Licencia vencida para la salida programada: la vigencia del conductor vence antes de la fecha del viaje.';
            return;
        }

        let habilitado = false;
        let requerida = '';

        if (esArticulado) {
            requerida = 'B4 o E2 (vehículo articulado)';
            habilitado = lics.includes('B4') || lics.includes('E2');
        } else if (pma > 8000) {
            requerida = 'B3, B4, E1 o E2 (pesado rígido > 8.000 kg)';
            habilitado = lics.includes('B3') || lics.includes('B4') || lics.includes('E1') || lics.includes('E2');
        } else if (pma > 4000) {
            requerida = 'B2, B3, B4, E1 o E2 (carga hasta 8.000 kg)';
            habilitado = lics.includes('B2') || lics.includes('B3') || lics.includes('B4') || lics.includes('E1') || lics.includes('E2');
        } else {
            requerida = 'B1, B2, B3, B4, E1 o E2 (livianos hasta 4.000 kg)';
            habilitado = lics.includes('B1') || lics.includes('B2') || lics.includes('B3') || lics.includes('B4') || lics.includes('E1') || lics.includes('E2');
        }

        if (!habilitado) {
            aviso.style.display = 'block';
            aviso.className = 'notification notification-danger py-2 px-3 mb-3';
            aviso.textContent = `Incompatibilidad de licencia: la unidad (PMA ${pma.toLocaleString('es-CR')} kg${esArticulado ? ', articulada' : ''}) requiere ${requerida}. El conductor posee: ${lics.join(', ')}.`;
        } else {
            aviso.style.display = 'block';
            aviso.className = 'notification notification-success py-2 px-3 mb-3';
            aviso.textContent = `Habilitación compatible: conductor con categoría ${lics.join(', ')} para unidad de PMA ${pma.toLocaleString('es-CR')} kg.`;
        }
    }

    /* ======================================================================
       ONBOARDING: WIZARD DE 2 PASOS (DATOS PERSONALES -> ACCESO)
       ====================================================================== */

    function inicializarOnboardingWizard() {
        const formOnb = document.getElementById('form-onboarding');
        if (!formOnb) return;

        const paso1 = document.getElementById('onb-paso-1');
        const paso2 = document.getElementById('onb-paso-2');
        const btnContinuar = document.getElementById('btn-onb-continuar');
        const btnVolver = document.getElementById('btn-onb-volver');
        const btnSubmit = document.getElementById('btn-submit-onboarding');
        const pill1 = document.getElementById('pill-step-1');
        const pill2 = document.getElementById('pill-step-2');
        const badge1 = document.getElementById('badge-step-1');
        const errorBox = document.getElementById('onb-general-error');

        const inputEmail = document.getElementById('onb-email');
        const inputId = document.getElementById('onb-identificacion');
        const errId = document.getElementById('onb-identificacion-error');
        const inputNom = document.getElementById('onb-nombre');
        const errNom = document.getElementById('onb-nombre-error');
        const inputAp1 = document.getElementById('onb-primer-apellido');
        const errAp1 = document.getElementById('onb-primer-apellido-error');
        const inputAp2 = document.getElementById('onb-segundo-apellido');

        const inputUsuario = document.getElementById('onb-usuario');
        const errUsuario = document.getElementById('onb-usuario-error');
        const inputPass = document.getElementById('onb-password');
        const errPass = document.getElementById('onb-password-error');
        const inputPassConf = document.getElementById('onb-password-confirm');
        const errPassConf = document.getElementById('onb-password-confirm-error');

        const camposLista = [
            { input: inputId, err: errId },
            { input: inputNom, err: errNom },
            { input: inputAp1, err: errAp1 },
            { input: inputUsuario, err: errUsuario },
            { input: inputPass, err: errPass },
            { input: inputPassConf, err: errPassConf },
        ];

        function limpiarErrores() {
            if (errorBox) {
                errorBox.hidden = true;
                errorBox.textContent = '';
            }
            camposLista.forEach(({ input, err }) => {
                if (input) {
                    input.classList.remove('is-danger');
                    input.removeAttribute('aria-invalid');
                }
                if (err) {
                    err.hidden = true;
                    err.textContent = '';
                }
            });
        }

        function mostrarErrorCampo(input, errEl, mensaje) {
            if (input) {
                input.classList.add('is-danger');
                input.setAttribute('aria-invalid', 'true');
            }
            if (errEl) {
                errEl.hidden = false;
                errEl.textContent = mensaje;
            }
            input?.focus();
            input?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function mostrarErrorGeneral(msg) {
            if (!errorBox) return;
            if (!msg) {
                errorBox.hidden = true;
                errorBox.textContent = '';
                return;
            }
            errorBox.hidden = false;
            errorBox.textContent = msg;
            errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function restaurarBotones() {
            if (btnSubmit) {
                btnSubmit.disabled = false;
                btnSubmit.classList.remove('is-loading');
            }
            if (btnVolver) {
                btnVolver.disabled = false;
            }
            if (btnContinuar) {
                btnContinuar.disabled = false;
                btnContinuar.classList.remove('is-loading');
            }
        }

        function irAPaso(paso) {
            if (paso === 1) {
                if (paso2) paso2.hidden = true;
                if (paso1) paso1.hidden = false;

                pill2?.classList.remove('is-active');
                pill1?.classList.add('is-active');
                pill1?.classList.remove('is-completed');
                if (badge1) badge1.textContent = '1';
            } else if (paso === 2) {
                if (paso1) paso1.hidden = true;
                if (paso2) paso2.hidden = false;

                pill1?.classList.remove('is-active');
                pill1?.classList.add('is-completed');
                if (badge1) badge1.textContent = '✓';

                pill2?.classList.add('is-active');
                pill2?.classList.remove('is-completed');
            }
        }

        // Limpieza de error en tiempo real al escribir en cada campo
        camposLista.forEach(({ input, err }) => {
            if (!input) return;
            input.addEventListener('input', () => {
                if (input.classList.contains('is-danger')) {
                    input.classList.remove('is-danger');
                    input.removeAttribute('aria-invalid');
                }
                if (err && !err.hidden) {
                    err.hidden = true;
                    err.textContent = '';
                }
            });
        });

        // Validaciones síncronas idénticas a las reglas del servidor
        function validarUsername(usr) {
            const val = (usr || '').trim();
            if (val === '') {
                return 'El nombre de usuario es obligatorio.';
            }
            if (val.length < 3 || val.length > 80 || !/^[A-Za-z0-9._-]+$/.test(val)) {
                return 'El nombre de usuario debe tener entre 3 y 80 caracteres (letras, números, punto, guion o guion bajo).';
            }
            return null;
        }

        function contarBytesUtf8(str) {
            if (typeof TextEncoder !== 'undefined') {
                return new TextEncoder().encode(str).length;
            }
            let b = 0;
            for (let i = 0; i < str.length; i++) {
                const code = str.charCodeAt(i);
                if (code <= 0x7f) b += 1;
                else if (code <= 0x7ff) b += 2;
                else if (code >= 0xd800 && code <= 0xdbff) { b += 4; i++; }
                else b += 3;
            }
            return b;
        }

        function validarPassword(pass, user, email) {
            // ASVS 6.2.8: No aplicar trim() a la contraseña original
            if (!pass) {
                return 'La contraseña es obligatoria.';
            }
            const charCount = Array.from(pass).length;
            if (charCount < 12) {
                return 'La contraseña debe tener al menos 12 caracteres.';
            }
            const byteLen = contarBytesUtf8(pass);
            if (byteLen > 72) {
                return 'La contraseña no puede superar los 72 bytes para preservar su integridad.';
            }

            const norm = pass.toLowerCase().trim();
            const blockedExact = [
                '123456789012', '1234567890123', '12345678901234',
                'password1234', 'password12345', 'qwertyuiop12', 'qwerty123456',
                'administrador', 'administrador1', 'changeme1234', 'welcome12345',
                'transportes1', 'transportes2026', 'transportesseguros'
            ];
            if (blockedExact.includes(norm)) {
                return 'Elige una contraseña menos predecible.';
            }

            const contextWords = [user, email, (email || '').split('@')[0]];
            for (const cw of contextWords) {
                const w = (cw || '').toLowerCase().trim();
                if (w.length >= 3 && norm.includes(w)) {
                    return 'La contraseña no debe contener el usuario ni el correo electrónico.';
                }
            }
            if (norm.includes('transportes')) {
                return 'La contraseña no debe contener el nombre de la empresa.';
            }

            return null;
        }

        function validarConfirmacion(pass, conf) {
            if (!conf) {
                return 'Por favor confirma tu contraseña.';
            }
            if (pass !== conf) {
                return 'La confirmación de la contraseña no coincide.';
            }
            return null;
        }

        // Paso 1 -> Paso 2
        btnContinuar?.addEventListener('click', (e) => {
            e.preventDefault();
            limpiarErrores();

            const idVal = (inputId?.value || '').trim();
            const nomVal = (inputNom?.value || '').trim();
            const ap1Val = (inputAp1?.value || '').trim();

            if (!idVal) {
                mostrarErrorCampo(inputId, errId, 'El número de cédula o identificación es obligatorio.');
                return;
            }
            if (idVal.length > 30) {
                mostrarErrorCampo(inputId, errId, 'El número de identificación no puede superar 30 caracteres.');
                return;
            }
            if (!nomVal) {
                mostrarErrorCampo(inputNom, errNom, 'El nombre es obligatorio.');
                return;
            }
            if (nomVal.length > 100) {
                mostrarErrorCampo(inputNom, errNom, 'El nombre no puede superar 100 caracteres.');
                return;
            }
            if (!ap1Val) {
                mostrarErrorCampo(inputAp1, errAp1, 'El primer apellido es obligatorio.');
                return;
            }
            if (ap1Val.length > 100) {
                mostrarErrorCampo(inputAp1, errAp1, 'El primer apellido no puede superar 100 caracteres.');
                return;
            }

            // Avanzar al paso 2 conservando todos los datos en el DOM
            irAPaso(2);
            inputUsuario?.focus();
        });

        // Paso 2 -> Paso 1 (Volver)
        btnVolver?.addEventListener('click', (e) => {
            e.preventDefault();
            limpiarErrores();

            // Regresar al paso 1 conservando todos los datos intactos en el DOM
            irAPaso(1);
            inputNom?.focus();
        });

        // Envío asíncrono del wizard con conservación total del estado
        formOnb.addEventListener('submit', (e) => {
            e.preventDefault();
            limpiarErrores();

            // Re-validación del paso 1
            const idVal = (inputId?.value || '').trim();
            const nomVal = (inputNom?.value || '').trim();
            const ap1Val = (inputAp1?.value || '').trim();
            if (!idVal || !nomVal || !ap1Val) {
                irAPaso(1);
                if (!idVal) mostrarErrorCampo(inputId, errId, 'El número de cédula o identificación es obligatorio.');
                else if (!nomVal) mostrarErrorCampo(inputNom, errNom, 'El nombre es obligatorio.');
                else mostrarErrorCampo(inputAp1, errAp1, 'El primer apellido es obligatorio.');
                return;
            }

            // Validación del paso 2
            const usrVal = (inputUsuario?.value || '').trim();
            const errUsr = validarUsername(usrVal);
            if (errUsr) {
                irAPaso(2);
                mostrarErrorCampo(inputUsuario, errUsuario, errUsr);
                return;
            }

            const passVal = inputPass?.value || '';
            const emailVal = inputEmail?.value || '';
            const errPassMsg = validarPassword(passVal, usrVal, emailVal);
            if (errPassMsg) {
                irAPaso(2);
                mostrarErrorCampo(inputPass, errPass, errPassMsg);
                return;
            }

            const passConfVal = inputPassConf?.value || '';
            const errConfMsg = validarConfirmacion(passVal, passConfVal);
            if (errConfMsg) {
                irAPaso(2);
                mostrarErrorCampo(inputPassConf, errPassConf, errConfMsg);
                return;
            }

            // Estado de carga y bloqueo de reenvíos
            if (btnSubmit) {
                btnSubmit.disabled = true;
                btnSubmit.classList.add('is-loading');
            }
            if (btnVolver) {
                btnVolver.disabled = true;
            }

            const formData = new FormData(formOnb);

            fetch('/auth/onboarding_guardar.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(async (response) => {
                let data = null;
                try {
                    data = await response.json();
                } catch {
                    throw new Error('Respuesta no válida del servidor. Por favor intenta de nuevo.');
                }

                if (response.ok && data?.ok) {
                    window.location.href = data.redirect || '/auth/onboarding_guardar.php?exito=1';
                    return;
                }

                // Fallo de validación o rechazo de negocio del servidor
                restaurarBotones();
                const errorMsg = data?.error || 'Ocurrió un error al procesar tu solicitud.';

                if (data?.paso === 1) {
                    irAPaso(1);
                }

                if (data?.field) {
                    const fieldMap = {
                        'identificacion': { input: inputId, err: errId },
                        'nombre': { input: inputNom, err: errNom },
                        'primer_apellido': { input: inputAp1, err: errAp1 },
                        'nombre_usuario': { input: inputUsuario, err: errUsuario },
                        'password': { input: inputPass, err: errPass },
                        'password_confirmacion': { input: inputPassConf, err: errPassConf },
                    };
                    const target = fieldMap[data.field];
                    if (target) {
                        mostrarErrorCampo(target.input, target.err, errorMsg);
                    } else {
                        mostrarErrorGeneral(errorMsg);
                    }
                } else {
                    mostrarErrorGeneral(errorMsg);
                }
            })
            .catch((err) => {
                restaurarBotones();
                mostrarErrorGeneral(err.message || 'Error de comunicación con el servidor. Revisa tu conexión a internet.');
            });
        });
    }

    // Interacción al hacer clic sobre texto o círculo de modo de destinatario
    document.addEventListener('click', (event) => {
        const radioLabel = event.target.closest('#label-mismo-cliente, #label-tercero');
        if (radioLabel) {
            const radio = radioLabel.querySelector('input[type="radio"]');
            if (radio && !radio.disabled) {
                radio.checked = true;
                actualizarModoDestinatario();
            }
        }
    });

    // Filtro interactivo de permisos en modal de invitar usuario
    document.addEventListener('input', (event) => {
        if (event.target.id === 'filtro-permisos-invitacion') {
            const term = (event.target.value || '').toLowerCase().trim();
            const filas = document.querySelectorAll('.item-permiso-fila');
            filas.forEach((fila) => {
                const cod = fila.dataset.codigo ? fila.dataset.codigo.toLowerCase() : '';
                const nom = fila.dataset.nombre ? fila.dataset.nombre.toLowerCase() : '';
                const match = !term || cod.includes(term) || nom.includes(term);
                fila.style.display = match ? 'block' : 'none';
            });
            document.querySelectorAll('.grupo-permisos-seccion').forEach((sec) => {
                const visibles = sec.querySelectorAll('.item-permiso-fila:not([style*="display: none"])');
                sec.style.display = visibles.length > 0 ? 'block' : 'none';
            });
        }
    });

    // Inicializar wizard de onboarding si estamos en la página
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializarOnboardingWizard);
    } else {
        inicializarOnboardingWizard();
    }

    // Limpieza e inicialización al abrir o intercambiar contenido HTMX en modales
    document.addEventListener('htmx:afterSwap', (event) => {
        if (event.detail?.target?.id === 'modal-contenido') {
            const selCli = document.getElementById('solicitud-cliente');
            if (selCli) {
                clienteSolicitudCache = null;
                modoForzadoPorJuridico = false;
                if (selCli.value) {
                    const opt = selCli.options ? selCli.options[selCli.selectedIndex] : null;
                    const tsOpt = selCli.tomselect?.options ? selCli.tomselect.options[selCli.value] : null;
                    const tipo = opt?.dataset?.tipo || tsOpt?.tipo_cliente || null;
                    if (tipo) {
                        clienteSolicitudCache = {
                            cliente_id: parseInt(selCli.value, 10),
                            tipo_cliente: tipo,
                            nombre: opt?.dataset?.nombre || tsOpt?.nombre || '',
                            identificacion: opt?.dataset?.identificacion || tsOpt?.identificacion || '',
                            telefono: opt?.dataset?.telefono || tsOpt?.telefono || ''
                        };
                    }
                }
                actualizarModoDestinatario();
            }

            if (document.getElementById('contador-permisos-invitacion')) {
                actualizarContadorPermisosInvitacion();
            }
        }
    });

    /* ======================================================================
       REORDENAMIENTO DE PARADAS DE VIAJE, EXPORTACIÓN E IMPRESIÓN DE REPORTES
       ====================================================================== */

    function refreshStopReorderState(listEl) {
        if (!listEl) return;
        const items = Array.from(listEl.querySelectorAll('[data-stop-item]'));
        const total = items.length;
        items.forEach((item, index) => {
            const badge = item.querySelector('[data-stop-order-badge]');
            if (badge) badge.textContent = '#' + (index + 1);
            const upBtn = item.querySelector('[data-stop-move="up"]');
            const downBtn = item.querySelector('[data-stop-move="down"]');
            if (upBtn) upBtn.disabled = index === 0;
            if (downBtn) downBtn.disabled = index === total - 1;
        });
    }

    function syncReportExportLinks() {
        const links = document.querySelectorAll('[data-report-export-link]');
        if (!links.length) return;
        links.forEach((link) => {
            const base = link.dataset.exportBase || '/reportes/exportar.php';
            const format = link.dataset.exportFormat || 'csv';
            const formSelector = link.dataset.exportForm || '#reportes-filtros';
            const form = document.querySelector(formSelector);
            const params = new URLSearchParams();
            if (form) {
                const fd = new FormData(form);
                fd.forEach((value, key) => {
                    const str = String(value ?? '').trim();
                    if (str !== '') params.set(key, str);
                });
            }
            params.set('formato', format);
            link.setAttribute('href', base + '?' + params.toString());
        });
    }

    document.addEventListener('click', (event) => {
        const moveBtn = event.target.closest('[data-stop-move]');
        if (moveBtn && !moveBtn.disabled) {
            const dir = moveBtn.dataset.stopMove;
            const item = moveBtn.closest('[data-stop-item]');
            const listEl = item ? item.closest('[data-stop-reorder-list]') : null;
            if (item && listEl) {
                if (dir === 'up' && item.previousElementSibling) {
                    listEl.insertBefore(item, item.previousElementSibling);
                } else if (dir === 'down' && item.nextElementSibling) {
                    listEl.insertBefore(item.nextElementSibling, item);
                }
                refreshStopReorderState(listEl);
                if (!moveBtn.disabled) {
                    moveBtn.focus();
                } else {
                    const fallback = item.querySelector(dir === 'up' ? '[data-stop-move="down"]' : '[data-stop-move="up"]');
                    fallback?.focus();
                }
            }
            return;
        }

        const suggestBtn = event.target.closest('[data-stop-suggest-order]');
        if (suggestBtn) {
            event.preventDefault();
            const form = suggestBtn.closest('form');
            const listEl = form ? form.querySelector('[data-stop-reorder-list]') : null;
            const avisoEl = form ? form.querySelector('#orden-optimo-aviso') : null;
            if (!listEl) return;

            const items = Array.from(listEl.querySelectorAll('[data-stop-item]'));
            if (items.length < 2) return;

            let pairs = [];
            try {
                const parsed = JSON.parse(listEl.dataset.precedencePairs || '[]');
                if (Array.isArray(parsed)) pairs = parsed;
            } catch {
                pairs = [];
            }

            const toRad = (deg) => (deg * Math.PI) / 180;
            const haversineKm = (a, b) => {
                if (!a.hasCoords || !b.hasCoords) return null;
                const R = 6371;
                const dLat = toRad(b.lat - a.lat);
                const dLon = toRad(b.lon - a.lon);
                const s1 = Math.sin(dLat / 2);
                const s2 = Math.sin(dLon / 2);
                const h = s1 * s1 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * s2 * s2;
                return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
            };

            const nodes = items.map((el, idx) => {
                const lat = parseFloat(el.dataset.lat || '');
                const lon = parseFloat(el.dataset.lon || '');
                return {
                    id: parseInt(el.dataset.paradaId || '0', 10),
                    el,
                    idx,
                    tipo: (el.dataset.tipo || '').toUpperCase(),
                    lat,
                    lon,
                    hasCoords: Number.isFinite(lat) && Number.isFinite(lon),
                };
            });

            const totalDist = (seq) => {
                let sum = 0;
                let prev = null;
                seq.forEach((n) => {
                    if (n.hasCoords) {
                        if (prev) {
                            const d = haversineKm(prev, n);
                            if (d !== null) sum += d;
                        }
                        prev = n;
                    }
                });
                return sum;
            };

            const beforeKm = totalDist(nodes);
            const prereqs = new Map();
            nodes.forEach((n) => prereqs.set(n.id, new Set()));
            pairs.forEach(([recId, entId]) => {
                if (prereqs.has(entId) && prereqs.has(recId) && recId !== entId) {
                    prereqs.get(entId).add(recId);
                }
            });

            const remaining = new Set(nodes);
            const visitedIds = new Set();
            const ordered = [];
            let current = null;

            while (remaining.size > 0) {
                const eligible = Array.from(remaining).filter((n) => {
                    const reqs = prereqs.get(n.id);
                    if (!reqs || reqs.size === 0) return true;
                    for (const rId of reqs) {
                        if (!visitedIds.has(rId)) return false;
                    }
                    return true;
                });

                const pool = eligible.length > 0 ? eligible : Array.from(remaining);
                let chosen = null;

                if (current === null) {
                    // Preferir la primera parada de recogida/mixta con coordenadas; si no, la primera elegible
                    chosen = pool.find((n) => n.hasCoords && (n.tipo === 'RECOGIDA' || n.tipo === 'MIXTA'))
                        || pool.find((n) => n.hasCoords)
                        || pool[0];
                } else if (current.hasCoords) {
                    let bestDist = Infinity;
                    pool.forEach((cand) => {
                        if (cand.hasCoords) {
                            const d = haversineKm(current, cand);
                            if (d !== null && d < bestDist) {
                                bestDist = d;
                                chosen = cand;
                            }
                        }
                    });
                    if (!chosen) chosen = pool[0];
                } else {
                    chosen = pool.find((n) => n.hasCoords) || pool[0];
                }

                ordered.push(chosen);
                visitedIds.add(chosen.id);
                remaining.delete(chosen);
                if (chosen.hasCoords) current = chosen;
            }

            ordered.forEach((n) => listEl.appendChild(n.el));
            refreshStopReorderState(listEl);

            const afterKm = totalDist(ordered);
            const sinCoords = nodes.filter((n) => !n.hasCoords).length;
            if (avisoEl) {
                let text = 'Orden sugerido aplicado en vista previa respetando recogida antes que entrega.';
                if (nodes.length - sinCoords >= 2) {
                    text += ' Recorrido estimado: ' + beforeKm.toFixed(1) + ' km → ' + afterKm.toFixed(1) + ' km.';
                }
                if (sinCoords > 0) {
                    text += ' (' + sinCoords + ' parada(s) sin coordenadas no se optimizaron por distancia).';
                }
                text += ' Revisa la secuencia y pulsa «Guardar orden» para confirmar.';
                avisoEl.textContent = text;
                avisoEl.hidden = false;
            }
            return;
        }

        const searchBtn = event.target.closest('[data-map-search-btn]');
        if (searchBtn) {
            event.preventDefault();
            const box = searchBtn.closest('[data-map-search-box]');
            runMapGeocodeSearch(box);
            return;
        }

        const pickBtn = event.target.closest('[data-map-search-pick]');
        if (pickBtn) {
            event.preventDefault();
            const box = pickBtn.closest('[data-map-search-box]');
            const lat = parseFloat(pickBtn.dataset.lat || '');
            const lon = parseFloat(pickBtn.dataset.lon || '');
            const label = pickBtn.dataset.label || '';
            if (box && Number.isFinite(lat) && Number.isFinite(lon)) {
                const scope = box.closest('form') || document;
                const shellSel = box.dataset.mapTargetShell || '.location-map-shell';
                const shell = scope.querySelector(shellSel) || document.querySelector(shellSel);
                const ctrl = shell ? shell._tssaMapController : null;
                if (ctrl && typeof ctrl.setPoint === 'function') {
                    ctrl.setPoint(lat, lon, true);
                }
                const addrSel = box.dataset.addressTarget || '';
                if (addrSel && label) {
                    const addrEl = scope.querySelector(addrSel) || document.querySelector(addrSel);
                    if (addrEl && String(addrEl.value || '').trim() === '') {
                        addrEl.value = label.slice(0, 240);
                    }
                }
                const resultsEl = box.querySelector('[data-map-search-results]');
                if (resultsEl) {
                    resultsEl.hidden = true;
                    resultsEl.innerHTML = '';
                }
            }
            return;
        }

        const printBtn = event.target.closest('[data-print-report]');
        if (printBtn) {
            event.preventDefault();
            window.print();
        }
    });

    async function runMapGeocodeSearch(box) {
        if (!box) return;
        const input = box.querySelector('[data-map-search-input]');
        const resultsEl = box.querySelector('[data-map-search-results]');
        const btn = box.querySelector('[data-map-search-btn]');
        if (!input || !resultsEl) return;

        const q = String(input.value || '').trim();
        if (q.length < 3) {
            resultsEl.innerHTML = '<p class="map-search-empty">Escribe al menos 3 caracteres para buscar.</p>';
            resultsEl.hidden = false;
            return;
        }

        if (btn) btn.classList.add('is-loading');
        resultsEl.innerHTML = '<p class="map-search-empty">Buscando en Costa Rica…</p>';
        resultsEl.hidden = false;

        try {
            const resp = await fetch('/api/geocodificar.php?q=' + encodeURIComponent(q), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await resp.json();
            if (!resp.ok || !data || data.ok === false) {
                resultsEl.innerHTML = '<p class="map-search-empty">' + escapeHtml(data?.error || 'No se pudo conectar al buscador. Marca el punto manualmente en el mapa.') + '</p>';
                return;
            }
            const items = Array.isArray(data.results) ? data.results : [];
            if (items.length === 0) {
                resultsEl.innerHTML = '<p class="map-search-empty">Sin coincidencias en Costa Rica. Prueba con otro cantón o haz clic en el mapa.</p>';
                return;
            }
            resultsEl.innerHTML = items.map((item) => (
                '<button type="button" class="map-search-item" data-map-search-pick data-lat="' + escapeHtml(String(item.lat)) + '" data-lon="' + escapeHtml(String(item.lon)) + '" data-label="' + escapeHtml(String(item.label || '')) + '">'
                + '<strong>' + escapeHtml(String(item.short_name || item.label || '')) + '</strong>'
                + '<span>' + escapeHtml(String(item.label || '')) + '</span>'
                + '</button>'
            )).join('');
        } catch {
            resultsEl.innerHTML = '<p class="map-search-empty">Buscador sin conexión. Puedes marcar el punto directamente en el mapa.</p>';
        } finally {
            if (btn) btn.classList.remove('is-loading');
        }
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.target && event.target.matches && event.target.matches('[data-map-search-input]')) {
            event.preventDefault();
            runMapGeocodeSearch(event.target.closest('[data-map-search-box]'));
        }
    });

    function updateMercanciasTotales(root = document) {
        const badge = root.querySelector ? root.querySelector('[data-mercancias-totales]') : null;
        const lista = root.querySelector ? root.querySelector('[data-mercancias-lista]') : null;
        if (!badge || !lista) return;
        let peso = 0;
        let vol = 0;
        lista.querySelectorAll('input[name$="[peso_kg]"]').forEach((inp) => {
            const v = parseFloat(String(inp.value || '').replace(',', '.'));
            if (Number.isFinite(v) && v > 0) peso += v;
        });
        lista.querySelectorAll('input[name$="[volumen_m3]"]').forEach((inp) => {
            const v = parseFloat(String(inp.value || '').replace(',', '.'));
            if (Number.isFinite(v) && v > 0) vol += v;
        });
        const pesoFmt = peso.toLocaleString('es-CR', { maximumFractionDigits: 2 });
        const volFmt = vol.toLocaleString('es-CR', { maximumFractionDigits: 3 });
        badge.textContent = 'Total declarado: ' + pesoFmt + ' kg' + (vol > 0 ? (' · ' + volFmt + ' m³') : '');
    }

    document.addEventListener('input', (event) => {
        if (event.target && event.target.closest && event.target.closest('[data-mercancias-lista]')) {
            updateMercanciasTotales(event.target.closest('form') || document);
        }
        if (event.target && event.target.closest && event.target.closest('#reportes-filtros')) {
            syncReportExportLinks();
        }
    });
    function updateBatchAssignSummary(root) {
        const box = root && root.matches && root.matches('[data-batch-assign-root]')
            ? root
            : root?.querySelector?.('[data-batch-assign-root]');
        if (!box) return;
        const form = box.closest('form');
        const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
        const summaryEl = box.querySelector('[data-batch-summary]');
        const overEl = box.querySelector('[data-batch-overweight]');
        const freeKg = parseFloat(box.dataset.freeKg || '0') || 0;

        let count = 0;
        let totalKg = 0;
        box.querySelectorAll('[data-batch-check]:checked').forEach((chk) => {
            count++;
            totalKg += parseFloat(chk.dataset.peso || '0') || 0;
        });

        const over = totalKg > freeKg + 0.001;
        if (summaryEl) {
            const rem = Math.max(0, freeKg - totalKg);
            summaryEl.textContent = count + ' seleccionada' + (count === 1 ? '' : 's')
                + ' (' + totalKg.toLocaleString('es-CR', { maximumFractionDigits: 2 }) + ' kg · quedan '
                + rem.toLocaleString('es-CR', { maximumFractionDigits: 2 }) + ' kg libres)';
        }
        if (overEl) {
            if (over) {
                overEl.textContent = 'El peso seleccionado (' + totalKg.toLocaleString('es-CR', { maximumFractionDigits: 2 })
                    + ' kg) excede la capacidad libre de la unidad (' + freeKg.toLocaleString('es-CR', { maximumFractionDigits: 2 }) + ' kg). Desmarca alguna solicitud.';
                overEl.hidden = false;
            } else {
                overEl.hidden = true;
                overEl.textContent = '';
            }
        }
        if (submitBtn) {
            submitBtn.disabled = (count === 0 || over);
        }
    }

    document.addEventListener('click', (event) => {
        const selCompatBtn = event.target.closest('[data-batch-select-compatible]');
        if (selCompatBtn) {
            event.preventDefault();
            const box = selCompatBtn.closest('[data-batch-assign-root]');
            if (!box) return;
            const freeKg = parseFloat(box.dataset.freeKg || '0') || 0;
            let acc = 0;
            box.querySelectorAll('[data-batch-check]').forEach((chk) => {
                if (chk.disabled) return;
                const nivel = chk.dataset.nivel || '';
                const peso = parseFloat(chk.dataset.peso || '0') || 0;
                if ((nivel === 'optimo' || nivel === 'compatible') && (acc + peso <= freeKg + 0.001)) {
                    chk.checked = true;
                    acc += peso;
                } else {
                    chk.checked = false;
                }
            });
            updateBatchAssignSummary(box);
            return;
        }

        if (event.target && event.target.closest && event.target.closest('[data-add-mercancia], [data-remove-mercancia]')) {
            const scope = event.target.closest('form') || document;
            setTimeout(() => updateMercanciasTotales(scope), 30);
        }

        const openQcBtn = event.target.closest('[data-open-quick-client]');
        if (openQcBtn) {
            event.preventDefault();
            const panel = document.querySelector('[data-quick-client-panel]');
            if (panel) {
                panel.hidden = !panel.hidden;
                if (!panel.hidden) {
                    panel.querySelector('#qc-identificacion')?.focus();
                }
            }
            return;
        }

        const closeQcBtn = event.target.closest('[data-close-quick-client]');
        if (closeQcBtn) {
            event.preventDefault();
            const panel = closeQcBtn.closest('[data-quick-client-panel]');
            if (panel) panel.hidden = true;
            return;
        }

        const qcHaciendaBtn = event.target.closest('[data-qc-hacienda]');
        if (qcHaciendaBtn) {
            event.preventDefault();
            const panel = qcHaciendaBtn.closest('[data-quick-client-panel]');
            if (!panel) return;
            const idInput = panel.querySelector('#qc-identificacion');
            const avisoEl = panel.querySelector('#qc-hacienda-aviso');
            const idVal = String(idInput?.value || '').replace(/\D/g, '');
            if (idVal.length < 9 || idVal.length > 12) {
                if (avisoEl) {
                    avisoEl.textContent = 'Ingresa entre 9 y 12 dígitos para consultar en Hacienda.';
                    avisoEl.className = 'help has-text-warning';
                    avisoEl.hidden = false;
                }
                return;
            }
            qcHaciendaBtn.classList.add('is-loading');
            qcHaciendaBtn.disabled = true;
            if (avisoEl) {
                avisoEl.textContent = 'Consultando Ministerio de Hacienda…';
                avisoEl.className = 'help';
                avisoEl.hidden = false;
            }
            fetch('/api/hacienda.php?identificacion=' + encodeURIComponent(idVal), { credentials: 'same-origin' })
                .then((res) => res.json())
                .then((data) => {
                    const rawNombre = data?.nombre || data?.datos?.nombre || '';
                    if (data?.status === 'encontrado' && rawNombre) {
                        const tipoChecked = panel.querySelector('[data-qc-tipo]:checked')?.value || 'fisico';
                        if (tipoChecked === 'juridico') {
                            const rEl = panel.querySelector('#qc-razon');
                            const cEl = panel.querySelector('#qc-comercial');
                            if (rEl) rEl.value = rawNombre;
                            if (cEl && !cEl.value) cEl.value = rawNombre;
                        } else {
                            const parsed = descomponerNombreCR(rawNombre);
                            const nEl = panel.querySelector('#qc-nombre');
                            const a1El = panel.querySelector('#qc-apellido1');
                            const a2El = panel.querySelector('#qc-apellido2');
                            if (nEl) nEl.value = parsed.nombre;
                            if (a1El) a1El.value = parsed.primerApellido;
                            if (a2El) a2El.value = parsed.segundoApellido;
                        }
                        if (avisoEl) {
                            avisoEl.textContent = 'Datos encontrados en Hacienda y precargados.';
                            avisoEl.className = 'help has-text-success';
                            avisoEl.hidden = false;
                        }
                    } else if (avisoEl) {
                        avisoEl.textContent = data?.mensaje || 'No se encontró en Hacienda. Completa los datos manualmente.';
                        avisoEl.className = 'help has-text-warning';
                        avisoEl.hidden = false;
                    }
                })
                .catch(() => {
                    if (avisoEl) {
                        avisoEl.textContent = 'No fue posible conectar con Hacienda. Completa los datos manualmente.';
                        avisoEl.className = 'help has-text-warning';
                        avisoEl.hidden = false;
                    }
                })
                .finally(() => {
                    qcHaciendaBtn.classList.remove('is-loading');
                    qcHaciendaBtn.disabled = false;
                });
            return;
        }

        const qcSaveBtn = event.target.closest('[data-qc-save]');
        if (qcSaveBtn) {
            event.preventDefault();
            const panel = qcSaveBtn.closest('[data-quick-client-panel]');
            const form = qcSaveBtn.closest('form');
            if (!panel || !form) return;
            const errEl = panel.querySelector('#qc-error');
            const showErr = (m) => {
                if (!errEl) return;
                errEl.textContent = m || '';
                errEl.hidden = !m;
            };
            showErr('');

            const tipo = panel.querySelector('[data-qc-tipo]:checked')?.value || 'fisico';
            const csrf = form.querySelector('input[name="csrf_token"]')?.value || '';
            const payload = new URLSearchParams();
            payload.set('csrf_token', csrf);
            payload.set('tipo', tipo);
            payload.set('identificacion', (panel.querySelector('#qc-identificacion')?.value || '').trim());
            payload.set('correo', (panel.querySelector('#qc-correo')?.value || '').trim());
            payload.set('telefono', (panel.querySelector('#qc-telefono')?.value || '').trim());
            if (tipo === 'fisico') {
                payload.set('nombre', (panel.querySelector('#qc-nombre')?.value || '').trim());
                payload.set('primer_apellido', (panel.querySelector('#qc-apellido1')?.value || '').trim());
                payload.set('segundo_apellido', (panel.querySelector('#qc-apellido2')?.value || '').trim());
            } else {
                payload.set('razon_social', (panel.querySelector('#qc-razon')?.value || '').trim());
                payload.set('nombre_comercial', (panel.querySelector('#qc-comercial')?.value || '').trim());
            }

            qcSaveBtn.classList.add('is-loading');
            qcSaveBtn.disabled = true;

            fetch('/clientes/crear_rapido.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', Accept: 'application/json' },
                credentials: 'same-origin',
                body: payload.toString(),
            })
                .then(async (res) => ({ ok: res.ok, data: await res.json().catch(() => null) }))
                .then(({ ok, data }) => {
                    if (!ok || !data || !data.ok || !data.cliente) {
                        showErr(data?.error || 'Revisa los datos obligatorios del cliente e inténtalo nuevamente.');
                        return;
                    }
                    const cli = data.cliente;
                    const selCli = form.querySelector('#solicitud-cliente');
                    if (selCli) {
                        const valStr = String(cli.cliente_id);
                        const labelStr = cli.nombre + ' · ' + cli.identificacion;
                        let opt = Array.from(selCli.options).find((o) => o.value === valStr);
                        if (!opt) {
                            opt = document.createElement('option');
                            opt.value = valStr;
                            opt.textContent = labelStr;
                            opt.dataset.tipo = cli.tipo_cliente;
                            opt.dataset.nombre = cli.nombre;
                            opt.dataset.identificacion = cli.identificacion;
                            opt.dataset.telefono = cli.telefono || '';
                            opt.dataset.subtext = cli.correo || '';
                            selCli.appendChild(opt);
                        }
                        if (selCli.tomselect) {
                            selCli.tomselect.addOption({
                                value: valStr,
                                text: labelStr,
                                subtext: cli.correo || '',
                                tipo_cliente: cli.tipo_cliente,
                                nombre: cli.nombre,
                                identificacion: cli.identificacion,
                                telefono: cli.telefono || '',
                            });
                            selCli.tomselect.setValue(valStr, false);
                        } else {
                            selCli.value = valStr;
                            selCli.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                    // Limpiar y cerrar solo el panel rápido
                    panel.querySelectorAll('input[type="text"], input[type="email"]').forEach((i) => { i.value = ''; });
                    const avH = panel.querySelector('#qc-hacienda-aviso');
                    if (avH) avH.hidden = true;
                    panel.hidden = true;
                    mostrarAviso('Cliente registrado y seleccionado en la solicitud.', false);
                })
                .catch(() => {
                    showErr('No se pudo conectar con el servidor para registrar el cliente.');
                })
                .finally(() => {
                    qcSaveBtn.classList.remove('is-loading');
                    qcSaveBtn.disabled = false;
                });
        }
    });
    document.addEventListener('change', (event) => {
        if (event.target && event.target.matches && event.target.matches('[data-auto-route-toggle]')) {
            const form = event.target.closest('[data-trip-create-form]');
            if (form) {
                const autoOn = Boolean(event.target.checked);
                const manualBox = form.querySelector('[data-manual-route-box]');
                const rutaSel = form.querySelector('#viaje-ruta');
                if (manualBox) manualBox.hidden = autoOn;
                if (rutaSel) {
                    rutaSel.required = !autoOn;
                    rutaSel.disabled = autoOn;
                    if (rutaSel.tomselect) {
                        autoOn ? rutaSel.tomselect.disable() : rutaSel.tomselect.enable();
                    }
                }
            }
            return;
        }
        if (event.target && event.target.id === 'viaje-solicitud-inicial') {
            const form = event.target.closest('[data-trip-create-form]');
            if (form) {
                const solSel = event.target;
                const val = String(solSel.value || '').trim();
                const autoWrap = form.querySelector('[data-auto-route-wrap]');
                const autoChk = form.querySelector('[data-auto-route-toggle]');
                const autoHelp = form.querySelector('[data-auto-route-help]');
                const manualBox = form.querySelector('[data-manual-route-box]');
                const rutaSel = form.querySelector('#viaje-ruta');

                if (!val) {
                    if (autoWrap) autoWrap.hidden = true;
                    if (autoChk) autoChk.checked = false;
                    if (manualBox) manualBox.hidden = false;
                    if (rutaSel) {
                        rutaSel.disabled = false;
                        rutaSel.required = true;
                        rutaSel.tomselect?.enable();
                    }
                    return;
                }

                const opt = Array.from(solSel.options || []).find((o) => o.value === val);
                const oriId = opt?.dataset?.origen || '';
                const desId = opt?.dataset?.destino || '';
                const trayecto = opt?.dataset?.trayecto || '';
                if (autoWrap) autoWrap.hidden = false;

                // Buscar si ya existe una ruta exacta en #viaje-ruta
                let exactRutaVal = '';
                if (rutaSel && oriId && desId) {
                    const matchOpt = Array.from(rutaSel.options || []).find(
                        (rOpt) => rOpt.value && rOpt.dataset?.origen === oriId && rOpt.dataset?.destino === desId
                    );
                    if (matchOpt) exactRutaVal = matchOpt.value;
                }

                if (exactRutaVal !== '' && rutaSel) {
                    if (autoChk) autoChk.checked = false;
                    if (manualBox) manualBox.hidden = false;
                    rutaSel.disabled = false;
                    rutaSel.required = true;
                    if (rutaSel.tomselect) {
                        rutaSel.tomselect.enable();
                        rutaSel.tomselect.setValue(exactRutaVal, true);
                    } else {
                        rutaSel.value = exactRutaVal;
                    }
                    if (autoHelp) {
                        autoHelp.textContent = 'Se encontró y seleccionó una ruta exacta existente para ' + trayecto + '.';
                    }
                } else if (autoChk) {
                    autoChk.checked = true;
                    if (manualBox) manualBox.hidden = true;
                    if (rutaSel) {
                        rutaSel.disabled = true;
                        rutaSel.required = false;
                        rutaSel.tomselect?.disable();
                    }
                    if (autoHelp) {
                        autoHelp.textContent = 'No existe una ruta exacta para ' + (trayecto || 'esta solicitud') + '; se creará automáticamente con distancia estimada (Haversine).';
                    }
                }
            }
            return;
        }
        if (event.target && event.target.matches && event.target.matches('[data-batch-check]')) {
            updateBatchAssignSummary(event.target.closest('[data-batch-assign-root]'));
        }
        if (event.target && event.target.matches && event.target.matches('[data-qc-tipo]')) {
            const panel = event.target.closest('[data-quick-client-panel]');
            if (panel) {
                const isJur = event.target.value === 'juridico';
                const fisBox = panel.querySelector('[data-qc-fisico-fields]');
                const jurBox = panel.querySelector('[data-qc-juridico-fields]');
                if (fisBox) fisBox.hidden = isJur;
                if (jurBox) jurBox.hidden = !isJur;
            }
        }
        if (event.target && event.target.closest && event.target.closest('#reportes-filtros')) {
            syncReportExportLinks();
        }
    });
    document.addEventListener('htmx:afterSwap', () => {
        syncReportExportLinks();
        updateMercanciasTotales(document);
        updateBatchAssignSummary(document);
        const autoSuggestList = document.querySelector('#modal-contenido [data-stop-reorder-list][data-auto-suggest="1"]');
        if (autoSuggestList && autoSuggestList.dataset.autoSuggestedDone !== '1') {
            autoSuggestList.dataset.autoSuggestedDone = '1';
            const btn = autoSuggestList.closest('form')?.querySelector('[data-stop-suggest-order]');
            btn?.click();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncReportExportLinks);
    } else {
        syncReportExportLinks();
    }
})();
