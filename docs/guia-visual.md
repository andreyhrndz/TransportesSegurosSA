# Guía visual inicial — tonos suaves

**Dirección:** logística moderna, confiable, sobria y de bajo cansancio visual. Los tonos pastel sirven como superficies y acentos; usar colores más oscuros para texto y acciones primarias para asegurar contraste. Propuesta a auditar visualmente, no paleta irreversible.

## Tokens iniciales propuestos

| Token | Hex | Uso |
|---|---|---|
| `--color-ink` | `#243746` | Texto y títulos principales |
| `--color-sidebar` | `#2E4451` | Barra lateral / áreas de navegación |
| `--color-primary` | `#3C716E` | Botones primarios, enlaces activos |
| `--color-primary-soft` | `#E2EEEB` | Fondo de selección y badges neutros |
| `--color-accent-soft` | `#EDE6D9` | Acentos cálidos discretos |
| `--color-page` | `#F6F8F7` | Fondo de página |
| `--color-surface` | `#FFFFFF` | Tarjetas, modales, tablas |
| `--color-border` | `#DCE5E3` | Bordes/divisores |
| `--color-muted` | `#526776` | Texto secundario legible |
| `--color-danger` | `#963F4A` | Acción destructiva / errores, no usar de forma decorativa |
| `--color-danger-soft` | `#F7E8E9` | Fondo de alertas de error |

No usar texto de baja opacidad sobre fondos pastel. Validar WCAG AA: contraste de texto normal 4.5:1 y texto grande 3:1, controles claramente distinguibles y foco visible. Los colores de estado deben ir acompañados de texto.

## Componentes

- `app/components/` o patrón compatible que el agente confirme con el repo: botón, campo de formulario, etiquetas de estado, tabla+paginación, modal, alertas/toast, card KPI, breadcrumb/encabezado.
- Mantener responsabilidades de datos en PHP y reutilizar plantillas de componentes.
- HTMX debe retornar fragmentos controlados, restaurar foco cuando sea pertinente y comunicar los errores/éxitos por regiones accesibles.
- El modo móvil debe tener navegación usable; evitar menú oculto sin botón visible.

## Convención de lenguaje

- `Guardar`, `Cancelar`, `Volver`, `Editar`, `Ver`, `Desactivar`, `Asignar`.
- `Nuevo cliente` o `Nueva solicitud` cuando la entidad necesita distinguirse.
- Formularios: indicar qué campos son obligatorios y errores concretos.
- No usar textos técnicos, académicos ni placeholders innecesarios en producción.
- Fechas: ofrecer formato de lectura local, preservando el dato UTC original.
