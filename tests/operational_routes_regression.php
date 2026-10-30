<?php
declare(strict_types=1);

/** Ejercita rutas protegidas contra Test con una sesión temporal, sin credenciales ni escrituras SQL. */
function routes_fail(string $message): never { fwrite(STDERR, "FALLO: {$message}\n"); exit(1); }
function routes_assert(bool $condition, string $message): void { if (!$condition) { routes_fail($message); } }
function routes_port(): int { $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); if($socket===false){routes_fail('No se pudo reservar puerto.');}$name=stream_socket_get_name($socket,false);fclose($socket);return (int)substr((string)$name,strrpos((string)$name,':')+1); }
/** @return array{0:int,1:string,2:array<int,string>} */
function routes_request(string $url, string $cookie, bool $htmx = false): array { $headers="Cookie: {$cookie}\r\nAccept: text/html\r\n".($htmx?"HX-Request: true\r\n":''); $context=stream_context_create(['http'=>['header'=>$headers,'ignore_errors'=>true,'timeout'=>8,'follow_location'=>0]]);$body=file_get_contents($url,false,$context);$response=$http_response_header??[];$status=(int)(preg_match('/\s(\d{3})\s/',$response[0]??'',$match)?$match[1]:0);return [$status,$body===false?'':$body,$response]; }

putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
$sessionName = 'TSSA_TRANSPORTES_SESSION';
$sessionId = bin2hex(random_bytes(24));
session_name($sessionName); session_id($sessionId); session_start();
$_SESSION = ['usuario_id'=>1,'authenticated_at'=>time(),'last_activity_at'=>time(),'csrf_token'=>bin2hex(random_bytes(32))];
session_write_close();
$port=routes_port();$root=dirname(__DIR__);$log=tempnam(sys_get_temp_dir(),'tssa-operational-routes-');
$process=proc_open([PHP_BINARY,'-S',"127.0.0.1:{$port}",'-t',$root.DIRECTORY_SEPARATOR.'public'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root);
if(!is_resource($process)){@unlink($log);routes_fail('No se pudo iniciar servidor PHP.');}
try { for($i=0;$i<25;$i++){ $socket=@fsockopen('127.0.0.1',$port); if($socket!==false){fclose($socket);break;}usleep(100000);} $base="http://127.0.0.1:{$port}";$cookie="{$sessionName}={$sessionId}";
    foreach(['/solicitudes.php'=>'Solicitudes','/viajes.php'=>'Viajes','/entregas.php'=>'Entregas','/flota.php'=>'Flota y maestros'] as $path=>$title){[$status,$body]=routes_request($base.$path,$cookie);routes_assert($status===200,"{$path} debe responder 200 con sesión autorizada.");routes_assert(str_contains($body,$title),"{$path} debe renderizar su contenido real.");}
    foreach(['/solicitudes/lista.php'=>'table','/viajes/lista.php'=>'table','/entregas/lista.php?seccion=entregas'=>'table','/entregas/lista.php?seccion=incidencias'=>'table','/entregas/lista.php?seccion=notificaciones'=>'table','/flota/lista.php?seccion=vehiculos'=>'table','/flota/lista.php?seccion=conductores'=>'table','/flota/lista.php?seccion=rutas'=>'table','/flota/lista.php?seccion=ubicaciones'=>'table','/flota/lista.php?seccion=catalogos'=>'detail-grid'] as $path=>$expectedMarkup){[$status,$body]=routes_request($base.$path,$cookie,true);routes_assert($status===200,"Fragmento {$path} debe responder 200.");routes_assert(str_contains($body,$expectedMarkup),"Fragmento {$path} debe devolver el componente esperado.");}
    [$status,, $headers]=routes_request($base.'/solicitudes/lista.php','',true);routes_assert($status===401,'HTMX sin sesión debe recibir 401.');routes_assert((bool)array_filter($headers,static fn(string $header):bool=>str_starts_with(strtolower($header),'hx-redirect:')),'HTMX sin sesión debe redirigir al login.');
    fwrite(STDOUT,"OK: rutas operativas consultadas con sesión temporal contra TransportesSegurosSA_Test.\n");
} finally { foreach($pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}}proc_terminate($process);proc_close($process);@unlink($log);session_id($sessionId);session_start();$_SESSION=[];session_destroy(); }
