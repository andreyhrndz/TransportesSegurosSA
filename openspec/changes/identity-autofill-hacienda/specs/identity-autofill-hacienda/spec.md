# Delta: consulta a Hacienda y destinatario condicional en solicitudes

## Purpose

Definir los requisitos observables para la consulta oficial de identificaciones en el Ministerio de Hacienda y la gestión condicional del destinatario esperado en solicitudes de transporte.

## ADDED Requirements

### Requirement: Consulta segura de identificación mediante API de Hacienda
El sistema SHALL permitir consultar identificaciones costarricenses desde el servidor PHP hacia el servicio de Hacienda (`api.hacienda.go.cr`), autocompletando únicamente el nombre registrado y tipo de identificación sin comprometer la seguridad ni inventar datos adicionales.

#### Scenario: Consulta exitosa de cédula física o jurídica
- **GIVEN** un operador en el formulario de clientes o destinatario que digita una cédula válida registrada
- **WHEN** presiona el botón «Consultar Hacienda»
- **THEN** el sistema consulta la API oficial desde PHP
- **AND** rellena automáticamente el campo de nombre o razón social correspondiente
- **AND** mantiene los campos editables para permitir ajustes por parte del operador.

#### Scenario: Identificación no registrada o falla de red
- **GIVEN** una identificación inexistente o un fallo temporal de conexión con Hacienda
- **WHEN** se solicita la consulta
- **THEN** el sistema muestra un mensaje informativo claro («No se encontró en Hacienda» o «Servicio no disponible»)
- **AND** los campos de texto quedan habilitados para digitación manual sin interrumpir el flujo.

### Requirement: Destinatario esperado condicional según tipo de cliente
En el registro de solicitudes, el sistema SHALL preguntar quién recibirá la mercancía y adaptar la captura de datos según la naturaleza física o jurídica del cliente seleccionado.

#### Scenario: Cliente físico que recibe personalmente
- **GIVEN** un cliente físico seleccionado en el formulario de solicitud
- **WHEN** se marca la opción «El mismo cliente»
- **THEN** los datos del cliente (nombre, apellidos, identificación y teléfono) se copian a los campos de destinatario esperado sin exigir reescritura manual.

#### Scenario: Cliente jurídico o tercero autorizado
- **GIVEN** un cliente de tipo empresa o la elección de «Otra persona autorizada»
- **WHEN** se visualiza la sección de destinatario
- **THEN** el primer campo solicitado es la identificación con el botón «Consultar Hacienda»
- **AND** se exige registrar una persona física con nombre y apellidos válidos.
