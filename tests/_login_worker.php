<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
// Proceso de la prueba de concurrencia del login: php tests/_login_worker.php <correo> <clave> <cookie> <ip> <hora de salida>
// Imprime T (el intento contó como dispositivo de confianza) o U (no), y el resultado.

[, $email, $pw, $cookie, $ip, $at] = $argv;
define('UC_TESTING', true);
require dirname(__DIR__) . '/app/bootstrap.php';
$_SERVER['REMOTE_ADDR'] = $ip;
$_COOKIE['uc_trust'] = $cookie;
while (microtime(true) < (float) $at) usleep(500);
try { login_attempt($email, $pw); $r = 'ok'; } catch (UcError $e) { $r = (string) $e->status; } catch (Throwable $e) { $r = '500 ' . $e->getMessage(); }
echo (!empty($GLOBALS['uc_login_trusted']) ? 'T' : 'U'), ' ', $r, "\n";
