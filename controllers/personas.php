<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

class PersonasController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function listarPersonas(): array
    {
        $consulta = $this->pdo->prepare(
            'SELECT p.id, p.usuario_id, p.nombre_completo,
                    u.usuario, u.rol
             FROM personas p
             INNER JOIN usuarios u ON u.id = p.usuario_id'
        );
        $consulta->execute();

        return $consulta->fetchAll();
    }

    public function crearPersona(int $usuarioId, string $nombreCompleto): int
    {
        $this->validarDatos($usuarioId, $nombreCompleto);
        $this->validarUsuarioSinPersona($usuarioId);

        $consulta = $this->pdo->prepare(
            'INSERT INTO personas (usuario_id, nombre_completo)
             VALUES (:usuario_id, :nombre_completo)'
        );
        $consulta->execute([
            ':usuario_id' => $usuarioId,
            ':nombre_completo' => $nombreCompleto
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function buscarPorId(int $personaId): ?array
    {
        $this->validarId($personaId);

        $consulta = $this->pdo->prepare(
            'SELECT p.id, p.usuario_id, p.nombre_completo,
                    u.usuario, u.rol
             FROM personas p
             INNER JOIN usuarios u ON u.id = p.usuario_id
             WHERE p.id = :id'
        );
        $consulta->execute([':id' => $personaId]);

        $resultado = $consulta->fetch();
        return $resultado === false ? null : $resultado;
    }

    public function editarPersona(int $personaId, array $datos): bool
    {
        $this->validarId($personaId);
        $campos = [];
        $valores = [':id' => $personaId];

        if (array_key_exists('usuario_id', $datos)) {
            $usuarioId = (int) $datos['usuario_id'];
            if ($usuarioId <= 0) {
                throw new InvalidArgumentException('El ID de usuario no es válido.');
            }
            $this->validarUsuarioSinPersona($usuarioId, $personaId);
            $campos[] = 'usuario_id = :usuario_id';
            $valores[':usuario_id'] = $usuarioId;
        }

        if (array_key_exists('nombre_completo', $datos)) {
            $nombreCompleto = trim((string) $datos['nombre_completo']);
            if ($nombreCompleto === '' || strlen($nombreCompleto) > 150) {
                throw new InvalidArgumentException('El nombre completo debe tener entre 1 y 150 caracteres.');
            }
            $campos[] = 'nombre_completo = :nombre_completo';
            $valores[':nombre_completo'] = $nombreCompleto;
        }

        if ($campos === []) {
            throw new InvalidArgumentException('Debes enviar al menos un campo para actualizar.');
        }

        $consulta = $this->pdo->prepare(
            'UPDATE personas SET ' . implode(', ', $campos) . ' WHERE id = :id'
        );
        $consulta->execute($valores);

        return $consulta->rowCount() > 0;
    }

    public function eliminarPersona(int $personaId): bool
    {
        $this->validarId($personaId);

        $consulta = $this->pdo->prepare('DELETE FROM personas WHERE id = :id');
        $consulta->execute([':id' => $personaId]);

        return $consulta->rowCount() > 0;
    }

    private function validarId(int $personaId): void
    {
        if ($personaId <= 0) {
            throw new InvalidArgumentException('El ID de la persona no es válido.');
        }
    }

    private function validarDatos(int $usuarioId, string $nombreCompleto): void
    {
        if ($usuarioId <= 0) {
            throw new InvalidArgumentException('El ID de usuario no es válido.');
        }

        if ($nombreCompleto === '' || strlen($nombreCompleto) > 150) {
            throw new InvalidArgumentException('El nombre completo debe tener entre 1 y 150 caracteres.');
        }
    }

    private function validarUsuarioSinPersona(int $usuarioId, ?int $personaId = null): void
    {
        $consulta = 'SELECT 1 FROM personas WHERE usuario_id = :usuario_id';
        $valores = [':usuario_id' => $usuarioId];

        if ($personaId !== null) {
            $consulta .= ' AND id <> :persona_id';
            $valores[':persona_id'] = $personaId;
        }

        $consulta = $this->pdo->prepare($consulta . ' LIMIT 1');
        $consulta->execute($valores);

        if ($consulta->fetchColumn() !== false) {
            throw new InvalidArgumentException('El usuario ya está relacionado con una persona.');
        }
    }
}

if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET', 'PUT', 'DELETE'], true)) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $autenticacion = new Autenticacion($pdo);
        $autenticacion->exigirAdministrador();
        $controlador = new PersonasController($pdo);
        $metodo = $_SERVER['REQUEST_METHOD'];
        $personaId = (int) ($_GET['id'] ?? 0);

        if ($metodo === 'POST') {
            $personaId = $controlador->crearPersona(
                (int) ($_POST['usuario_id'] ?? 0),
                trim((string) ($_POST['nombre_completo'] ?? ''))
            );
            http_response_code(201);
            $respuesta = [
                'exito' => true,
                'mensaje' => 'Persona creada correctamente.',
                'persona_id' => $personaId
            ];
        } elseif ($metodo === 'GET') {
            if ($personaId <= 0) {
                $respuesta = [
                    'exito' => true,
                    'personas' => $controlador->listarPersonas()
                ];
                echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
                exit;
            }

            $resultado = $controlador->buscarPorId($personaId);
            if ($resultado === null) {
                http_response_code(404);
                throw new InvalidArgumentException('Persona no encontrada.');
            }
            $respuesta = ['exito' => true, 'persona' => $resultado];
        } elseif ($metodo === 'PUT') {
            $datos = json_decode(file_get_contents('php://input'), true);
            if (!is_array($datos)) {
                throw new InvalidArgumentException('El cuerpo debe ser un JSON válido.');
            }
            if (!$controlador->editarPersona($personaId, $datos)) {
                http_response_code(404);
                throw new InvalidArgumentException('Persona no encontrada o sin cambios.');
            }
            $respuesta = ['exito' => true, 'mensaje' => 'Persona actualizada correctamente.'];
        } else {
            if (!$controlador->eliminarPersona($personaId)) {
                http_response_code(404);
                throw new InvalidArgumentException('Persona no encontrada.');
            }
            $respuesta = ['exito' => true, 'mensaje' => 'Persona eliminada correctamente.'];
        }

        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
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
            'mensaje' => 'No se pudo procesar la persona. Verifica que el usuario exista y que no tenga movimientos relacionados.'
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
}
