document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-print-sheet]').forEach((btn) => {
        btn.addEventListener('click', () => window.print());
    });
});
