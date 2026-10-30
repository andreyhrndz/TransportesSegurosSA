## 1. Integración

- [x] 1.1 Confirmar contratos de lectura/cuenta y permisos desplegados (lectura desplegada; cuenta pendiente).
- [ ] 1.2 Crear guards por capacidad y endpoints HTMX con CSRF.
- [x] 1.3 Implementar directorio, detalle y matriz individual sin hashes.

## 2. Operaciones

- [ ] 2.1 Implementar alta, edición, estado y restablecimiento mediante SP aprobados.
- [ ] 2.2 Implementar concesión/revocación individual mediante los SP existentes.
- [ ] 2.3 Implementar confirmaciones y mensajes de conflicto/autoprotección.

## 3. Verificación

- [ ] 3.1 Probar permisos, CSRF, duplicados, cuenta propia y última cuenta gestora.
- [ ] 3.2 Comprobar que ningún hash/contraseña llega a HTML, logs o respuestas.
- [ ] 3.3 Probar accesibilidad/responsive y validar estrictamente.

## Evidencia y límites

- `public/usuarios.php` usa `sp_listar_usuarios`, `sp_obtener_usuario` y `sp_listar_permisos`
  con `USUARIOS_VER`; sus resultados no incluyen `password_hash` y la UI no tiene entradas de
  contraseña.
- `sp_conceder_permiso` y `sp_revocar_permiso` están desplegados, pero no protegen por sí mismos
  una auto-revocación crítica ni la última cuenta gestora. No se habilitaron sus mutaciones desde
  PHP hasta aprobar y desplegar el contrato de cuentas y esa regla de autoprotección.
