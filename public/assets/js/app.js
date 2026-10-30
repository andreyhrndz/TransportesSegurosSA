(() => {
    'use strict';
    const modal = document.getElementById('modal-contenido');
    const aviso = document.getElementById('aviso-temporal');
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    let timer;
    let previousFocus = null;

    function firstFocusable(container) {
        return container?.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    }

    function cerrarModal() {
        if (!modal || modal.innerHTML === '') return;
        modal.innerHTML = '';
        previousFocus?.focus();
        previousFocus = null;
    }

    function abrirModal() {
        if (!modal) return;
        previousFocus = document.activeElement;
        requestAnimationFrame(() => firstFocusable(modal)?.focus());
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
    }

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-close-modal]')) cerrarModal();
        if (event.target.closest('[data-sidebar-toggle]')) toggleSidebar();
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
            if (modal?.innerHTML) cerrarModal();
            else toggleSidebar(false);
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
        if ([403, 409, 422].includes(status)) {
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
    document.body.addEventListener('htmx:sendError', () => mostrarAviso('No se pudo contactar al servidor.', true));
    document.body.addEventListener('htmx:timeout', () => mostrarAviso('La solicitud tardó demasiado.', true));
})();
