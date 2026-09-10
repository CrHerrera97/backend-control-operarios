<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new InvalidArgumentException('El login solo admite solicitudes POST.');
    }

    $datos = $_POST;
    if ($datos === []) {
        $cuerpo = json_decode(file_get_contents('php://input'), true);
        $datos = is_array($cuerpo) ? $cuerpo : [];
    }

    $autenticacion = new Autenticacion($pdo);
    $usuario = $autenticacion->iniciarSesion(
        trim((string) ($datos['usuario'] ?? '')),
        (string) ($datos['contrasena'] ?? '')
    );

    echo json_encode([
        'exito' => true,
        'mensaje' => 'Inicio de sesión correcto.',
        'usuario' => $usuario
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    if (http_response_code() < 400) {
        http_response_code(422);
    }
    echo json_encode([
        'exito' => false,
        'mensaje' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'exito' => false,
        'mensaje' => 'No se pudo iniciar sesión.'
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    if (http_response_code() < 400) {
        http_response_code(401);
    }
    echo json_encode([
        'exito' => false,
        'mensaje' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
