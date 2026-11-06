# Diseño técnico: sistema visual y componentes

## Punto de partida (verificar en el repositorio actual)

La instantánea inicial compartida incluye `app/layout.php`, `app/bootstrap.php`, `public/index.php`, `public/clientes.php`, `public/clientes/*`, `public/assets/css/estilos.css` y `public/assets/js/app.js`. El CSS inicial está centralizado, pero comprimido y con valores de estilo repartidos. No asumir que el repositorio local coincide exactamente.

## Goals / Non-Goals

**Goals:** una base visual accesible que conserve login, dashboard y clientes.

**Non-Goals:** crear módulos, permisos o contratos SQL.

## Decisions

1. Auditar UI actual y extraer inventario de controles, estados, eventos HTMX y variantes.
2. Definir tokens semánticos para color, tipografía, spacing, radios, sombras, focos y breakpoints en una hoja CSS mantenible; preservar Bulma.
3. Introducir funciones/plantillas PHP de presentación únicamente cuando haya reutilización real y sin cambiar contratos de backend.
4. Rediseñar layout y navegación. Mantener `public/` como raíz y `app/` como código privado; no agregar dependencias por defecto.
5. Sustituir iconos improvisados por SVG homogéneos, con texto accesible o etiqueta cuando solo exista icono.
6. Mantener eventos HTMX, objetivos de swap, confirmaciones, modal, CSRF y mensajes de resultado. Los cambios DOM no deben romper los identificadores usados por JS.
7. Formatear fechas visibles de forma local, mostrando UTC cuando sea imprescindible; no modificar SQL.

La alternativa de mantener estilos por página queda descartada: impediría tokens, contraste y consistencia entre módulos.

## Diseño visual

Consultar `docs/guia-visual.md` y verificar contraste real de la paleta. Evitar bloques grandes de color saturado. Botón primario oscuro sobre fondo claro y estados de foco perceptibles. Colores de estado con etiqueta textual.

## Responsive y accesibilidad

- Sidebar persistente en escritorio y navegación compacta, explícita y operable en móvil/tablet.
- Formularios y tablas sin desbordamientos críticos; tabulación lógica.
- Mensajes anunciados con `role=status`/`aria-live` cuando corresponda, errores asociados a campos, foco restaurado tras modal.
- Soporte de interacciones teclado y tacto; respeto por preferencias de movimiento reducido.

## Integraciones y límites

No alterar `app/bootstrap.php` salvo ajustes estrictamente necesarios y justificados. Sin cambios a conexión SQL, datos ni SP. El contenido del sidebar no puede prometer módulos aún inoperativos.

## Risks / Trade-offs

- [Regresión de IDs/eventos HTMX] → conservar contratos DOM y probar swaps/CSRF.
- [Contraste insuficiente] → validar los tokens antes de aplicarlos.
- [Navegación móvil oculta] → control explícito, foco y pruebas táctiles.

## Migration Plan

Aplicar tokens y componentes antes de rediseñar vistas; revertir el commit de frontend si falla smoke. No hay migración SQL.

## Estrategia de validación

Smoke manual/automatizado de autenticación, dashboard, listado/búsqueda/paginación, alta de clientes y cierre de sesión; vista desktop, tablet y móvil. Informar entorno y resultados reales. Realizar pruebas de contraste, foco y errores.
