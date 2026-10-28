(() => {
    'use strict';
    const modal = document.getElementById('modal-contenido');
    const aviso = document.getElementById('aviso-temporal');
    let timer;

    function cerrarModal() {
        if (modal) modal.innerHTML = '';
    }
    function mostrarAviso(texto, error = false) {
        if (!aviso) return;
        clearTimeout(timer);
        aviso.textContent = texto;
        aviso.classList.toggle('toast-error', error);
        aviso.hidden = false;
        timer = setTimeout(() => { aviso.hidden = true; }, 4500);
    }
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-close-modal]')) cerrarModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') cerrarModal();
    });
    // HTMX no reemplaza por defecto respuestas 4xx: permitimos mostrar
    // las validaciones dentro del formulario sin recargar la página.
    document.body.addEventListener('htmx:beforeSwap', (event) => {
        if ([403, 409, 422].includes(event.detail.xhr.status)) {
            event.detail.shouldSwap = true;
            event.detail.isError = false;
        }
    });
    document.body.addEventListener('clienteCreado', () => {
        cerrarModal();
        mostrarAviso('Cliente registrado correctamente.');
    });
    document.body.addEventListener('htmx:sendError', () => mostrarAviso('No se pudo contactar al servidor.', true));
    document.body.addEventListener('htmx:timeout', () => mostrarAviso('La solicitud tardó demasiado.', true));
})();
