# Instrucciones de desarrollo — Transportes Seguros S.A.

## Contexto

- Producto: plataforma administrativa de transporte y logística.
- Stack obligatorio: PHP 8.4, Microsoft SQL Server 2025 (PDO_SQLSRV), Bulma y HTMX. No introducir React, Vue, Laravel ni un framework adicional sin aprobación explícita.
- Base operativa: `TransportesSegurosSA`; base analítica: `TransportesSegurosSA_DW`.
- Modelo relacional existente: 24 tablas, 32 llaves foráneas, 29 restricciones CHECK, procedimientos, funciones, triggers y vistas.
- La aplicación inicial ya autentica, muestra indicadores y permite consultar/registrar clientes. Auditar el código actual: no asumir que todos los demás módulos están implementados.
- La replicación entre dos equipos sigue pendiente; no inventar evidencia de ella.
- No incluir mensajes académicos, referencias a grupos ni códigos internos G6 en la interfaz visible al usuario. Los nombres técnicos de Jobs de SQL Server no requieren modificación.

## Forma de trabajo

- **OpenSpec es obligatorio** para cambios no triviales: `proposal.md`, delta `specs`, `design.md` y `tasks.md`, revisión y validación, implementación, pruebas, archivo.
- Escribir artefactos de OpenSpec y la interfaz en español, de forma breve, precisa y verificable.
- Inspeccionar el código real y el esquema SQL antes de afirmar que un procedimiento, permiso o columna existe.
- Mantener cambios enfocados; no mezclar rediseño visual con cambios de esquema o reglas de negocio.
- Documentar las dependencias y riesgos, pero evitar refactorizar por refactorizar.
- Codex implementa; Antigravity puede validar UX/responsive en navegador. No editar simultáneamente los mismos archivos desde ambos agentes.
- No realizar operaciones destructivas, migraciones, commits, pushes o merges sin autorización.

## Seguridad y SQL

- No incorporar credenciales ni hashes en el código/repositorio. Configuración local y credenciales fuera de `public/` y fuera del control de versiones.
- Mantener autenticación, autorización por permiso, tokens CSRF, consultas preparadas, escape de HTML y sesiones seguras.
- No confiar en `SESSION_CONTEXT('usuario_id')` como autenticación independiente. Su valor solo debe derivar de una sesión PHP validada.
- No usar DELETE físico sin revisar reglas de negocio, relaciones y requisitos. Preferir desactivación cuando corresponda y exista un procedimiento autorizado.
- Utilizar procedimientos almacenados cuando proceda; si falta un procedimiento/permisos, documentar cambio SQL independiente antes de crearlo.
- No publicar la aplicación local actual directamente: usa Windows Integrated Auth, configuración TLS para entorno local y no se ha auditado su seguridad para producción.
- No alterar los scripts históricos, respaldos, Jobs ni Data Warehouse durante cambios exclusivamente visuales.

## Estándar UX/UI

- Identidad sobria, amable y profesional; paleta de pasteles desaturados en superficies y acentos, con texto/controles de alto contraste.
- Un conjunto central de tokens CSS; no dispersar códigos HEX ni tamaños arbitrarios en cada pantalla.
- Componentes consistentes: navegación, encabezado, botón, campo, validación, tabla, badge de estado, modal, alertas, KPI y estados vacío/carga/error.
- Etiquetas de botones cortas y concretas, p. ej. «Guardar», «Cancelar», «Editar», «Ver», «Asignar», «Nuevo cliente»; nunca ambigüedad, truncado o acciones sin significado.
- Evitar emojis o glifos Unicode improvisados como iconos; usar un juego coherente de SVG accesibles.
- Diseño responsive: desktop, tablet y móvil; teclado, foco visible, roles y etiquetas accesibles; tamaños táctiles adecuados.
- Mostrar fechas operativas en la zona horaria prevista del producto; preservar UTC en almacenamiento cuando aplique.
- No crear botones o navegación ficticios. Módulos no implementados deben ocultarse o presentarse como no disponibles sin aparentar funcionalidad.

## Criterios de cierre

- Verificar cambios funcionales con pruebas PHP/SQL y navegadores cuando aplique; como mínimo smoke test de inicio de sesión, dashboard y clientes.
- No marcar tareas como completas sin evidencia comprobable.
- Confirmar que el frontend existente sigue funcionando, especialmente HTMX, CSRF y controles de permisos.
- Documentar qué se probó, qué no se pudo probar y por qué.
