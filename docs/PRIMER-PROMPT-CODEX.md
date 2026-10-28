# Primer prompt para Codex

Lee `AGENTS.md`, `openspec/config.yaml`, `docs/frontend-roadmap.md`, `docs/guia-visual.md` y el change `openspec/changes/frontend-foundation-design-system/`.

Primero AUDITA, sin implementar aún: inspecciona el frontend PHP real y su comportamiento, identifica rutas, dependencias, componentes existentes, Bootstrap/HTMX/Bulma, seguridad/CSRF y posibles regresiones; compara las declaraciones del change con el código actual.

Después ajusta **los artefactos del change** para que correspondan a la realidad del repositorio. Preserva la idea central: un sistema visual sobrio de tonos suaves, controles accesibles y reutilizables, botones breves, navegación usable en desktop/tablet/móvil, eliminar textos académicos y los símbolos improvisados como iconos. No convertir el cambio visual en una reescritura del backend ni tocar SQL Server.

Presenta el resumen de auditoría, lista de archivos previstos, riesgos, y ejecuta `openspec validate frontend-foundation-design-system --strict`. Deja `tasks.md` listo para ser implementado en una segunda ejecución con el workflow apply. NO implementes todavía, NO cambies SQL, NO hagas commits ni push.

Cuando el plan esté revisado y validado, ejecutar por separado `$openspec-apply-change` (o la invocación que muestre la herramienta instalada) para este change. La revisión visual posterior se hará con Antigravity, sin edición concurrente del mismo archivo.
