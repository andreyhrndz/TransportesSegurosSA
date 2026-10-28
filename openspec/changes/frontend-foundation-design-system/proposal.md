# Propuesta: estandarización del sistema visual del frontend

## Por qué

El frontend ya inicia sesión, muestra dashboard y consulta/registra clientes, pero el CSS monolítico, iconos de caracteres, textos de demostración y patrones UI no centralizados dificultan ampliar módulos sin inconsistencias. Queremos una experiencia sobria, confiable y fácil de usar.

## Qué cambia

- Crear tokens CSS de identidad visual desaturada y tipografía/espaciados/estados de interacción coherentes.
- Introducir componentes de presentación PHP reutilizables, sin framework adicional.
- Unificar encabezados, acciones, campos, tablas, modal, feedback y estados vacío/carga/error.
- Mejorar la navegación y los puntos de quiebre para escritorio, tablet y móvil.
- Sustituir símbolos Unicode improvisados por iconos SVG accesibles y coherentes.
- Eliminar referencias académicas o de grupo en textos visibles.
- Simplificar etiquetas de botones sin perder significado.
- Presentar fechas operativas en formato comprensible para el usuario, sin alterar el almacenamiento.

## Fuera del alcance

- CRUD de nuevos módulos, cambios de esquema o SP SQL, permisos adicionales y despliegue público.
- Reescritura de autenticación, alteración de Jobs/DW o cambio de stack.

## Riesgos y mitigación

- Riesgo de regresión en formularios HTMX y CSRF: conservar contratos y probar flujos antes/después.
- Contraste insuficiente por usar pasteles: aplicar pasteles a superficies, conservar texto y botones de alto contraste.
- Cambios móviles que oculten navegación: incorporar estado/activación accesibles y probar teclado/tacto.

## Reversión

Cambio aislado en frontend; restaurar archivos del commit anterior si fallan criterios de aceptación. No hay migraciones SQL.
