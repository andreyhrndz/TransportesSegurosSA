## 1. Contratos SQL

- [x] 1.1 Confirmar catálogo, objetos y política de hash realmente desplegados (catálogo y columna confirmados; política de hash pendiente de aprobación).
- [ ] 1.2 Diseñar permisos `USUARIOS_VER` y `USUARIOS_GESTIONAR_CUENTAS` idempotentes.
- [ ] 1.3 Implementar las cuatro firmas propuestas con entradas, salida y códigos 50412–50420 documentados.

## 2. Seguridad

- [ ] 2.1 Verificar actor, unicidad, hash y exclusión de hash en resultados.
- [ ] 2.2 Proteger auto-desactivación y la última cuenta administradora activa bajo transacción.
- [ ] 2.3 Probar concesión/revocación individual mediante los SP existentes.

## 3. Verificación

- [ ] 3.1 Probar autorizado, denegado, duplicado, hash inválido y usuario inexistente.
- [ ] 3.2 Probar cuenta propia y última cuenta administradora.
- [ ] 3.3 Validar el change y registrar evidencia SQL.

## Evidencia y bloqueo de aprobación

- Test tiene `USUARIOS_VER` y `USUARIOS_GESTIONAR_PERMISOS`, los contratos de lectura y los SP
  `sp_conceder_permiso(@usuario_destino,@codigo)` / `sp_revocar_permiso(@usuario_destino,@codigo)`.
  No tiene `USUARIOS_GESTIONAR_CUENTAS` ni SP de alta, edición, estado o restablecimiento.
- `usuarios` conserva unicidad de nombre/correo y `password_hash NVARCHAR(255)`, pero el esquema
  no define una política de algoritmo/versionado. Producto y DBA deben aprobar bcrypt/Argon2id,
  la regla de última cuenta gestora y si la auto-revocación de permisos críticos se prohíbe.
- No se ejecutó DDL ni DML de cuentas; por ello 1.2–3.3 siguen pendientes salvo la constatación
  de objetos existente de 1.1.
