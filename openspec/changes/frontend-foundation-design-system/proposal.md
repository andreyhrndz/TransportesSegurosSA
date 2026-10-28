# Propuesta: estandarización del sistema visual del frontend

## Why

El frontend ya inicia sesión, muestra dashboard y consulta/registra clientes, pero el CSS monolítico, iconos de caracteres, textos de demostración y patrones UI no centralizados dificultan ampliar módulos sin inconsistencias. Queremos una experiencia sobria, confiable y fácil de usar.

## What Changes

- Crear tokens CSS de identidad visual desaturada y tipografía/espaciados/estados de interacción coherentes.
- Introducir componentes de presentación PHP reutilizables, sin framework adicional.
- Unificar encabezados, acciones, campos, tablas, modal, feedback y estados vacío/carga/error.
- Mejorar la navegación y los puntos de quiebre para escritorio, tablet y móvil.
- Sustituir símbolos Unicode improvisados por iconos SVG accesibles y coherentes.
- Eliminar referencias académicas o de grupo en textos visibles.
- Simplificar etiquetas de botones sin perder significado.
- Presentar fechas operativas en formato comprensible para el usuario, sin alterar el almacenamiento.

## Alcance, exclusiones y riesgos

- Incluye tokens, componentes de presentación, navegación y estados reutilizables para login, dashboard y clientes reales.
- Excluye CRUD de módulos nuevos, cambios SQL/permisos, despliegue público y reescritura de autenticación.
- Riesgos: regresión HTMX/CSRF, contraste o navegación móvil. Mitigación: conservar contratos, probar flujos y revisar teclado/contraste.

## Capabilities

### New Capabilities
- `ui-foundations`: Sistema visual reutilizable, accesible y responsive para capacidades implementadas.

### Modified Capabilities
- Ninguna.

## Impact

Cambios posteriores en CSS, layout, JavaScript y vistas PHP. No debe exhibir módulos pendientes como navegables ni alterar SQL Server. Reversión por commit de frontend sin migraciones.
