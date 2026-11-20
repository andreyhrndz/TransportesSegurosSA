# Guía de Datos Ideales de Prueba y Exposición — Transportes Seguros S.A.

Este documento describe el **escenario ideal de datos** (estilo paquetería y mensajería nacional en Costa Rica, similar a Correos de Costa Rica) para que **todas las funcionalidades del sistema se luzcan en vivo** durante pruebas y exposiciones sin tropiezos ni datos vacíos.

> [!CAUTION]
> **Regla obligatoria:** Toda carga de semillas, resets y demostraciones con escrituras se ejecutan **exclusivamente en `TransportesSegurosSA_Test`**. Nunca ejecutes scripts de semilla o limpieza sobre `TransportesSegurosSA` (base original).

---

## 1. Preparación rápida en 1 minuto (PowerShell)

Desde la raíz del repositorio `TransportesSegurosSA`:

```powershell
# 1. Asegurar esquema y migraciones 01..14 en TransportesSegurosSA_Test
.\tools\scripts\init-test-db.ps1 -Force

# 2. Activar modelo FULL (necesario para respaldos Transaction Log .trn con RPO <= 15 min)
sqlcmd -S localhost -d master -E -I -b -Q "ALTER DATABASE [TransportesSegurosSA_Test] SET RECOVERY FULL;"

# 3. Cargar escenario nacional limpio de Costa Rica (sucursales, rutas, clientes, flota, pedidos y viajes base)
sqlcmd -S localhost -d TransportesSegurosSA_Test -E -I -b -f 65001 -i database\seed\05_demo_exposicion_cr.sql

# 4. Abrir los 5 viajes troncales PLANIFICADO cubriendo todo Costa Rica (+ reserva B1/B2 libre)
sqlcmd -S localhost -d TransportesSegurosSA_Test -E -I -b -f 65001 -i database\seed\06_viajes_cobertura_nacional_cr.sql

# 5. (Opcional) Sincronizar el Data Warehouse para Reporte 3 (Análisis histórico DW)
sqlcmd -S localhost -d TransportesSegurosSA_DW -E -I -b -Q "EXEC dbo.sp_cargar_datawarehouse;"

# 6. Levantar el servidor local
.\tools\scripts\start-dev.ps1
```

---

## 2. Por qué este set de datos es ideal para la presentación

### 2.1 Cobertura completa de Costa Rica (5 viajes `PLANIFICADO` abiertos)
En lugar de tener que crear un viaje desde cero cada vez que llega una solicitud, el sistema arranca con **5 viajes troncales abiertos en estado `PLANIFICADO`** que cubren las 5 grandes regiones del país, tal como opera una red nacional de correos y encomiendas:

| Región / Corredor | Ruta base | Unidad asignada | Conductor |
|---|---|---|---|
| **GAM** *(San José, Cartago, Heredia, Alajuela)* | `Distribución GAM Este (Zapote → Cartago)` | `CL-45210` Isuzu NPR (`4.200 kg`, B2) | Andrés Felipe Rojas (`B2`) |
| **Chorotega / Guanacaste** *(Liberia, Santa Cruz)* | `Troncal Chorotega (Alajuela → Liberia)` | `CL-58930` Hino 300 (`4.500 kg`, B2) | Luis Fernando Solano (`B2`) |
| **Pacífico Central** *(Puntarenas, Caldera)* | `Troncal Pacífico Central (Alajuela → Puntarenas)` | `CL-20815` Hyundai H100 (`1.400 kg`, B1) | Pamela Lucía Chacón (`E2`) |
| **Caribe / Ruta 32** *(Limón)* | `Troncal Caribe Ruta 32 (Zapote → Limón)` | `CL-79104` Hino 500 (`9.500 kg`, B3) | Esteban Mauricio Monge (`B3`) |
| **Zona Sur / Brunca** *(Pérez Zeledón)* | `Troncal Zona Sur (Cartago → Pérez Zeledón)` | `C-164820` Freightliner (`21.000 kg`, B4) | Randall Gerardo Quesada (`B4`) |

**Recursos de reserva siempre `DISPONIBLE` (para demos en vivo):**
- **Vehículos libres:** `CL-10428` Toyota Hiace (`LIVIANO`, PMA `3.200 kg`, B1) y `CL-63410` Mitsubishi Fuso Canter (`MEDIANO_RIGIDO`, PMA `7.500 kg`, B2).
- **Conductores libres:** `Carlos Alberto Mora Vega` (`B1`) y `Diego Vega Araya` (`B2`).
- *Para demostrar la Ley 9078 (Error `50320`):* en **Viajes → Nuevo viaje**, selecciona **`CL-63410` (7.500 kg)** + **`Carlos Alberto Mora` (`B1`)** → bloqueo legal inmediato; cambia a **`Diego Vega` (`B2`)** → habilitado en verde.

---

### 2.2 Red de 10 Centros / Sucursales con coordenadas GPS reales
Todas las sedes tienen `latitud` y `longitud` exactas en Costa Rica para que funcionen **Despacho inteligente (Haversine)**, **Ruta inteligente**, **Mapa Leaflet** y **Ruta completa en Google Maps**:
1. Centro de Clasificación Zapote (HUB GAM)
2. Sucursal San José Centro
3. Centro Logístico Alajuela (El Coyol)
4. Sucursal Heredia Centro
5. Sucursal Cartago Centro
6. Sucursal Escazú - Santa Ana
7. Sucursal Regional Liberia (Guanacaste)
8. Sucursal Regional Puntarenas
9. Sucursal Regional Limón
10. Sucursal Regional Pérez Zeledón

---

### 2.3 Alertas y estados variados listos en el Panel General (`/`)
Para que el **Centro de atención operativa** en el inicio muestre alertas reales (y cada botón filtre exactamente lo que anuncia):
- **1 solicitud atrasada + urgente (`SOL-00008`, Zapote → Cartago):** demuestra la alerta roja y el emparejamiento **`Óptimo`** en el viaje del GAM.
- **Solicitudes de otras regiones (`SOL-00009` Alajuela → Liberia, `SOL-00010` Zapote → Puntarenas):** demuestran cómo el Despacho Inteligente sugiere el viaje correcto de su región y marca **`Desvío alto (~147 km)`** en los viajes de otras costas.
- **1 viaje `EN_CURSO` (`VIA-00003`, Ruta 32) con 2 paquetes `EN_TRANSITO`:** activa el badge **`En tránsito (2)`** en `/entregas.php` y permite demostrar **Registrar entrega** con receptor prellenado y subida segura de foto/PDF sincronizada a **Documentos**.
- **1 entrega fallida (`SOL-00003`):** permite demostrar el botón **«Reprogramar en nueva solicitud»** (precarga cliente, trayecto, destinatario y mercancías sin romper `UNIQUE(solicitud_id)`).
- **1 incidencia abierta (neblina en el Zurquí, Ruta 32):** visible en el Dashboard y lista para resolver en **Entregas → Incidencias**.
- **1 conductor con licencia próxima a vencer en 14 días (`Esteban Mauricio Monge`, `B3`):** activa la alerta amarilla `≤ 30 días` tanto en el Dashboard como en **Flota → Conductores**.
- **2 entregas exitosas previas (`VIA-00001`):** alimentan tanto los reportes operacionales como el **Data Warehouse (`TransportesSegurosSA_DW`)** para mostrar el **Reporte 3 — Análisis histórico (DW)**.

---

## 3. Secuencia recomendada para la exposición (5 minutos)

1. **Panel general (`/`):** Mostrar el **Centro de atención operativa** y hacer clic en uno de los botones de alerta (ej. *por asignar a viaje* o *licencia por vencer*) mostrando el filtrado exacto.
2. **Solicitudes + Cliente rápido + Mapa:** Abrir **Nueva solicitud**, mostrar el botón **«+ Nuevo cliente»** (con **Consultar** a Hacienda) y el **buscador de direcciones en el mapa** (Nominatim CR).
3. **Despacho inteligente + Ruta inteligente:**
   - Abrir **`SOL-00009`** (*El Coyol → Liberia*) y mostrar cómo recomienda el viaje de **Chorotega** como `Óptimo` y advierte **`Desvío alto`** en los viajes del GAM/Caribe.
   - Entrar a un viaje `PLANIFICADO` → **«Asignar varias»** → **«Seleccionar compatibles»** (con medidor de capacidad libre y **Aplicar Ruta inteligente automáticamente**) → **«Asignar seleccionadas»**.
   - Mostrar **«Ruta completa en Google Maps»** y **«Hoja de ruta (PDF)»**.
4. **Ley 9078 (Bloqueo transaccional SQL):** En **Nuevo viaje**, mostrar el rechazo `50320` de `Carlos Mora (B1)` con `CL-63410 (7.500 kg)`.
5. **Entregas y Documentos:** En **Entregas → En tránsito**, registrar una entrega con receptor prellenado y archivo adjunto → abrir la solicitud (vista 360°) comprobando que quedó archivado automáticamente en **Documentos**.
6. **Cierre DBA (`/auditoria.php`, `/reportes.php` y `/respaldos.php`):**
   - Mostrar la bitácora en lenguaje humano (`/auditoria.php`).
   - Mostrar **Reportes → Análisis histórico (DW)** (`ApplicationIntent=ReadOnly`).
   - Abrir **Administración → Respaldos y recuperación** (`/respaldos.php`) mostrando los 4 jobs (`FULL`, `DIFF`, **`LOG cada 15 min → RPO ≤ 15 min`** y `ETL`), las rutas en disco y generar un respaldo `LOG (.trn)` verificado con `CHECKSUM + RESTORE VERIFYONLY` en vivo.
