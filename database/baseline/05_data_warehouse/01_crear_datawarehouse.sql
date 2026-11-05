USE master;
GO

-- Crear una base analitica separada.
IF DB_ID(N'TransportesSegurosSA_DW') IS NOT NULL
    THROW 50701, 'TransportesSegurosSA_DW ya existe.', 1;

EXEC sys.sp_executesql N'
CREATE DATABASE TransportesSegurosSA_DW
ON PRIMARY
(
    NAME = N''TransportesSegurosSA_DW_Data'',
    FILENAME = N''C:\SQL\SQLData\TransportesSegurosSA_DW.mdf'',
    SIZE = 16MB,
    FILEGROWTH = 8MB
)
LOG ON
(
    NAME = N''TransportesSegurosSA_DW_Log'',
    FILENAME = N''C:\SQL\SQLLog\TransportesSegurosSA_DW.ldf'',
    SIZE = 8MB,
    FILEGROWTH = 8MB
);
';
GO

USE TransportesSegurosSA_DW;
GO

CREATE TABLE dbo.dim_fecha
(
    fecha_key INT PRIMARY KEY,
    fecha DATE NOT NULL UNIQUE,
    anio SMALLINT NOT NULL,
    mes TINYINT NOT NULL,
    dia TINYINT NOT NULL
);
GO

CREATE TABLE dbo.dim_cliente
(
    cliente_key INT IDENTITY(1,1) PRIMARY KEY,
    cliente_id_origen INT NOT NULL UNIQUE,
    identificacion NVARCHAR(30) NOT NULL
);
GO

CREATE TABLE dbo.dim_vehiculo
(
    vehiculo_key INT IDENTITY(1,1) PRIMARY KEY,
    vehiculo_id_origen INT NOT NULL UNIQUE,
    placa NVARCHAR(20) NOT NULL
);
GO

CREATE TABLE dbo.dim_conductor
(
    conductor_key INT IDENTITY(1,1) PRIMARY KEY,
    conductor_id_origen INT NOT NULL UNIQUE,
    nombre_completo NVARCHAR(320) NOT NULL
);
GO

CREATE TABLE dbo.dim_ruta
(
    ruta_key INT IDENTITY(1,1) PRIMARY KEY,
    ruta_id_origen INT NOT NULL UNIQUE,
    nombre NVARCHAR(150) NOT NULL
);
GO

CREATE TABLE dbo.fact_entregas
(
    entrega_id_origen INT PRIMARY KEY,
    fecha_key INT NOT NULL REFERENCES dbo.dim_fecha(fecha_key),
    cliente_key INT NOT NULL REFERENCES dbo.dim_cliente(cliente_key),
    vehiculo_key INT NOT NULL REFERENCES dbo.dim_vehiculo(vehiculo_key),
    conductor_key INT NOT NULL REFERENCES dbo.dim_conductor(conductor_key),
    ruta_key INT NOT NULL REFERENCES dbo.dim_ruta(ruta_key),
    viaje_id_origen INT NOT NULL,
    solicitud_id_origen INT NOT NULL,
    resultado VARCHAR(15) NOT NULL,
    peso_kg DECIMAL(18,2) NOT NULL,
    volumen_m3 DECIMAL(18,3) NULL,
    incidencias INT NOT NULL,
    entrega_puntual BIT NOT NULL,
    fecha_carga_utc DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME())
);
GO

SELECT COUNT(*) AS TablasDW FROM sys.tables;
GO
