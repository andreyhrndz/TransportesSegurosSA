## Context

Los scripts disponibles contienen `CLIENTES_CREAR`, permisos operativos y `REPORTES_VER`, pero no permisos de lectura por dominio. Hay vistas `vw_solicitudes_activas`, `vw_estado_flota`, `vw_detalle_viajes`, `vw_entregas` y `vw_indicadores_operativos`; su existencia no concede acceso ni cubre todos los listados.

## Goals / Non-Goals

**Goals:** definir permisos `*_VER`, contratos de lista/detalle y filtros estrictamente parametrizados.

**Non-Goals:** crear roles, modificar datos, exponer hashes de usuarios o conceder SELECT amplio a la aplicación.

## Decisions

- Usar permisos individuales: `CLIENTES_VER`, `UBICACIONES_VER`, `CATALOGOS_VER`, `FLOTAS_VER`, `RUTAS_VER`, `SOLICITUDES_VER`, `VIAJES_VER`, `ENTREGAS_VER`, `INCIDENCIAS_VER`, `DOCUMENTOS_VER`, `NOTIFICACIONES_VER`, `AUDITORIA_VER` y `USUARIOS_VER`; `REPORTES_VER` queda para agregados.
- Cada contrato comprobará `dbo.fn_tiene_permiso(TRY_CONVERT(INT, SESSION_CONTEXT(...)), codigo)` y devolverá sólo columnas necesarias.
- Listas usarán parámetros, orden permitido y paginación; detalles exigirán un identificador.

## Catálogo de contratos propuestos

Todos los listados reciben `@pagina int=1`, `@por_pagina int=20` (1–100), filtros opcionales tipados y `@total_registros int OUTPUT`; devuelven una sola cuadrícula con columnas mínimas. Todos rechazan página/tamaño inválido, permiso ausente y orden no permitido.

| Dominio | Procedimientos de lectura y filtros | Salida mínima |
|---|---|---|
| Clientes | `sp_listar_clientes(@buscar nvarchar(120)=NULL,...)`, `sp_obtener_cliente(@cliente_id int)` | identidad, subtipo, contacto, activo; sin historial no solicitado |
| Ubicaciones/catálogos | `sp_listar_ubicaciones(@solo_activos bit=1,@buscar nvarchar(120)=NULL,...)`, `sp_listar_tipos_*(@solo_activos bit=1,...)` | identificador, nombre, descripción/estado |
| Flota/rutas | `sp_listar_vehiculos(@estado varchar(20)=NULL,@buscar nvarchar(80)=NULL,...)`, `sp_listar_conductores(@estado varchar(20)=NULL,...)`, `sp_listar_rutas(@origen_id int=NULL,@destino_id int=NULL,...)` | recursos, estado, capacidad/validez y ruta |
| Solicitudes | `sp_listar_solicitudes(@estado varchar(30)=NULL,@cliente_id int=NULL,@desde datetime2=NULL,@hasta datetime2=NULL,...)`, `sp_obtener_solicitud(@solicitud_id int)` | código, estado, prioridad, fechas, destinatario previsto, mercancías y asignación |
| Viajes | `sp_listar_viajes(@estado varchar(20)=NULL,@desde datetime2=NULL,@hasta datetime2=NULL,...)`, `sp_obtener_viaje(@viaje_id int)` | recurso, ruta, paradas, capacidad agregada y solicitudes |
| Entrega/seguimiento | `sp_listar_entregas(@estado varchar(15)=NULL,@viaje_id int=NULL,@desde datetime2=NULL,@hasta datetime2=NULL,...)`, `sp_listar_incidencias(@solicitud_id int=NULL,@estado varchar(20)=NULL,...)`, `sp_listar_documentos(@solicitud_id int,...)`, `sp_listar_notificaciones(@solicitud_id int=NULL,@estado_envio varchar(20)=NULL,@desde datetime2=NULL,@hasta datetime2=NULL,...)` | resultado, receptor efectivo cuando aplique, incidencia/documento y estado de envío |
| Seguridad/auditoría | `sp_listar_usuarios(@buscar nvarchar(150)=NULL,@activo bit=NULL,...)`, `sp_obtener_usuario(@usuario_id int)`, `sp_listar_permisos(@solo_activos bit=1,...)`, `sp_listar_auditorias(@entidad nvarchar(100)=NULL,@registro_id nvarchar(100)=NULL,@desde datetime2=NULL,@hasta datetime2=NULL,...)` | cuenta sin hash, permisos individuales, auditoría limitada |

Los detalles devuelven 404/50001 documentado si no existe o no es visible; las listas usan 50002 para parámetro/paginación inválida y 50003 para permiso ausente, sujetos a revisión DBA para no colisionar con objetos reales.

## Risks / Trade-offs

- [Cambio de catálogo] → aplicar idempotentemente y probar concesión/revocación.
- [Datos personales] → no retornar `password_hash`; limitar auditoría y destinatarios al caso de uso.

## Migration Plan

DBA revisa y despliega primero los contratos; frontend consume sólo contratos confirmados. Reversión: retirar la exposición nueva y conservar permisos existentes.
