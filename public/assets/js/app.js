(() => {
    'use strict';
    const modal = document.getElementById('modal-contenido');
    const aviso = document.getElementById('aviso-temporal');
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const backdrop = document.getElementById('sidebar-backdrop');
    const appShell = document.getElementById('app-shell');
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

    function cerrarModal() {
        if (!modal || modal.innerHTML.trim() === '') return;
        modal.innerHTML = '';
        if (appShell) appShell.removeAttribute('aria-hidden');
        previousFocus?.focus();
        previousFocus = null;
    }

    function abrirModal() {
        if (!modal) return;
        previousFocus = document.activeElement;
        if (appShell) appShell.setAttribute('aria-hidden', 'true');
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

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-close-modal]')) cerrarModal();
        if (event.target.closest('[data-sidebar-toggle]')) toggleSidebar();
        if (event.target.closest('[data-sidebar-close]')) toggleSidebar(false);
        const addMerchandise = event.target.closest('[data-add-mercancia]');
        if (addMerchandise) {
            const list = document.querySelector('[data-mercancias-lista]');
            const template = document.getElementById('mercancia-row-template');
            const max = Number(addMerchandise.dataset.maxItems || 20);
            if (!list || !template || list.querySelectorAll('[data-mercancia-row]').length >= max) return;
            const fragment = template.content.cloneNode(true);
            const index = list.querySelectorAll('[data-mercancia-row]').length;
            fragment.querySelectorAll('[name]').forEach((field) => { field.name = field.name.replace('__index__', String(index)); });
            list.appendChild(fragment);
        }
        const removeMerchandise = event.target.closest('[data-remove-mercancia]');
        if (removeMerchandise) {
            const list = document.querySelector('[data-mercancias-lista]');
            if (list?.querySelectorAll('[data-mercancia-row]').length > 1) removeMerchandise.closest('[data-mercancia-row]')?.remove();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (modal?.innerHTML.trim()) cerrarModal();
            else toggleSidebar(false);
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
            } else {
                if (document.activeElement === lastEl || !modal.contains(document.activeElement)) {
                    firstEl.focus();
                    event.preventDefault();
                }
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
        successFields?.querySelectorAll('input').forEach((field) => { field.required = delivered && ['receptor_nombre', 'comprobante_url'].includes(field.name); });
        failureFields?.querySelectorAll('textarea').forEach((field) => { field.required = !delivered && field.name === 'motivo_fallo'; });
    });

    // HTMX no intercambia 4xx por defecto. CSRF y autorización muestran un aviso seguro.
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
        if (event.detail.target?.id === 'modal-contenido') abrirModal();
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
})();
