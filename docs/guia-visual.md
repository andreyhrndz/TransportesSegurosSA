# Guía visual inicial — tonos suaves

**Dirección:** logística moderna, confiable, sobria y de bajo cansancio visual. Los tonos pastel sirven como superficies y acentos; usar colores más oscuros para texto y acciones primarias para asegurar contraste.

## Tokens iniciales propuestos

| Token | Hex | Uso |
|---|---|---|
| `--color-ink` | `#253640` | Texto y títulos principales |
| `--color-primary` | `#426C76` | Botones primarios, enlaces activos |
| `--color-primary-soft` | `#DCEBED` | Fondo de selección y badges neutros |
| `--color-success` | `#356953` | Éxito y confirmación |
| `--color-warning` | `#8A6428` | Advertencias con texto |
| `--color-page` | `#F5F7F8` | Fondo de página |
| `--color-surface` | `#FFFFFF` | Tarjetas, modales, tablas |
| `--color-border` | `#D7E0E3` | Bordes/divisores |
| `--color-muted` | `#5C6C74` | Texto secundario legible |
| `--color-danger` | `#9A4350` | Acción destructiva / errores, no usar de forma decorativa |
| `--color-danger-soft` | `#F9E6E8` | Fondo de alertas de error |

No usar texto de baja opacidad sobre fondos pastel. La comprobación matemática contra blanco realizada con los tokens finales obtiene 12.50:1 para tinta, 5.77:1 para primario, 6.37:1 para éxito, 5.34:1 para advertencia y 6.38:1 para error; todos superan WCAG AA para texto normal. Los colores de estado deben ir acompañados de texto.

## Componentes

- `app/components.php`: iconos SVG, alertas y estado vacío. Los estilos reutilizables para botón, campo de formulario, tabla+paginación, modal, toast y card KPI se centralizan en `public/assets/css/estilos.css`.
- Mantener responsabilidades de datos en PHP y reutilizar plantillas de componentes.
- HTMX debe retornar fragmentos controlados, restaurar foco cuando sea pertinente y comunicar los errores/éxitos por regiones accesibles.
- El modo móvil debe tener navegación usable; evitar menú oculto sin botón visible.

## Convención de lenguaje

- `Guardar`, `Cancelar`, `Volver`, `Editar`, `Ver`, `Desactivar`, `Asignar`.
- `Nuevo cliente` o `Nueva solicitud` cuando la entidad necesita distinguirse.
- Formularios: indicar qué campos son obligatorios y errores concretos.
- No usar textos técnicos, académicos ni placeholders innecesarios en producción.
- Fechas: ofrecer formato de lectura local, preservando el dato UTC original.
