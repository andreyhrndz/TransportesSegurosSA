/*
  Prueba preparada para ejecutar únicamente después de aprobar y desplegar
  06_delivery_recipient_detail_contract.sql en TransportesSegurosSA_Test.
  No crea, altera ni elimina datos.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50961, 'Esta prueba solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

DECLARE @entrega_id INT = (SELECT TOP (1) entrega_id FROM dbo.entregas ORDER BY entrega_id);
IF @entrega_id IS NULL
    THROW 50962, 'Se requiere una entrega existente para probar el detalle.', 1;

EXEC sys.sp_set_session_context @key = N'usuario_id', @value = 1;
EXEC dbo.sp_obtener_entrega @entrega_id = @entrega_id;
GO
