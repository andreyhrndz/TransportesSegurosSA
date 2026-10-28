## Context

Los scripts disponibles contienen `CLIENTES_CREAR`, permisos operativos y `REPORTES_VER`, pero no permisos de lectura por dominio. Hay vistas `vw_solicitudes_activas`, `vw_estado_flota`, `vw_detalle_viajes`, `vw_entregas` y `vw_indicadores_operativos`; su existencia no concede acceso ni cubre todos los listados.

## Goals / Non-Goals

**Goals:** definir permisos `*_VER`, contratos de lista/detalle y filtros estrictamente parametrizados.

**Non-Goals:** crear roles, modificar datos, exponer hashes de usuarios o conceder SELECT amplio a la aplicación.

## Decisions

- Usar permisos individuales, por ejemplo `CLIENTES_VER`, `MAESTROS_VER`, `SOLICITUDES_VER`, `VIAJES_VER`, `ENTREGAS_VER`, `AUDITORIA_VER`; `REPORTES_VER` queda para agregados.
- Cada contrato comprobará `dbo.fn_tiene_permiso(TRY_CONVERT(INT, SESSION_CONTEXT(...)), codigo)` y devolverá sólo columnas necesarias.
- Listas usarán parámetros, orden permitido y paginación; detalles exigirán un identificador.

## Risks / Trade-offs

- [Cambio de catálogo] → aplicar idempotentemente y probar concesión/revocación.
- [Datos personales] → no retornar `password_hash`; limitar auditoría y destinatarios al caso de uso.

## Migration Plan

DBA revisa y despliega primero los contratos; frontend consume sólo contratos confirmados. Reversión: retirar la exposición nueva y conservar permisos existentes.
