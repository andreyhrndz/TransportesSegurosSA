# OpenSpec inicial — Transportes Seguros S.A.

## Alcance del paquete

Incluye **únicamente documentación, instrucciones de agente y especificaciones**; no modifica código PHP, base SQL Server, usuarios, Jobs ni archivos de respaldo.

Se elaboró a partir de la versión inicial del frontend y sus archivos PHP/CSS compartidos. El agente debe contrastarlo con el repositorio local real antes de aplicar tareas.

## Instalación recomendada (Windows)

1. Abre PowerShell y verifica que el proyecto que ya ejecuta PHP en `http://127.0.0.1:8000` esté ubicado en `C:\AdmBD2026\ProyectoTransportesSegurosSA`. Si todavía reside en `C:\Proyectos\TransportesSegurosSA`, mueve/copia **el proyecto completo, conservando su contenido**, y evita tener dos copias que se modifiquen por separado. No muevas SQL Server, los MDF/LDF ni la instalación de PHP.
2. Abre PowerShell en la raíz del proyecto:

   ```powershell
   cd C:\AdmBD2026\ProyectoTransportesSegurosSA
   Test-Path .\app\bootstrap.php
   Test-Path .\public\index.php
   openspec --version
   ```

   Los dos `Test-Path` deben dar `True`. Si OpenSpec no está instalado, sigue https://openspec.dev/docs/installation (la edición standalone para Windows no exige Node.js; también existe npm con Node compatible).

3. Inicializa OpenSpec en el **repositorio del frontend**, no en `C:\AdmBD2026`:

   ```powershell
   openspec init --tools codex,antigravity
   ```

   Reinicia el editor/terminal de los agentes si no aparecen las instrucciones nuevas.

4. Copia desde este paquete **`AGENTS.md`, `docs/` y `openspec/`** a la raíz del proyecto, fusionando las carpetas. Al copiar, reemplaza `openspec/config.yaml` por el de este paquete solo después de revisar que no tienes configuración previa que quieras conservar. **No reemplaces tus carpetas `app/` ni `public/`.**
5. Valida el primer change:

   ```powershell
   openspec status --change frontend-foundation-design-system
   openspec validate frontend-foundation-design-system --strict
   ```

6. Da a Codex el texto de `docs/PRIMER-PROMPT-CODEX.md`. Primero debe revisar y corregir las especificaciones a partir del código real. Luego ejecutará la implementación del change con `$openspec-apply-change` (sintaxis del skill según la versión instalada).

## Directorios

- `openspec/config.yaml`: contexto estable y reglas SDD.
- `openspec/changes/frontend-foundation-design-system/`: **primer change**, propuesta, delta spec, diseño y tareas. No está implementado.
- `docs/frontend-roadmap.md`: secuencia sugerida de cambios futuros. **No son funcionalidades terminadas ni specs canónicas.**
- `docs/guia-visual.md`: tokens de color y convenciones UI.
- `docs/PRIMER-PROMPT-CODEX.md`: instrucciones para auditar y preparar el primer change.
- `AGENTS.md`: convenciones comunes para Codex y Antigravity.

## Seguridad

El frontend inicial funciona en local con autenticación integrada de Windows y `TrustServerCertificate=yes`. No lo publiques en Internet ni asumas que esa configuración es adecuada para producción. No subas contraseñas, hashes reales ni archivos de conexión secretos a Git.

El archivo SQL principal y el DW están separados del frontend. Toda modificación de esquema, permisos, procedimientos o datos debe convertirse en un change específico, reversible y aprobado previamente.
