## 1. Contratos SQL

- [ ] 1.1 Confirmar catálogo, objetos y política de hash realmente desplegados.
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
