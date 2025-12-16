<?php
// =================================================================================
// ARCHIVO: sso_login.php
// DESCRIPCIÓN: Punto de entrada para Single Sign-On (SSO) vía JWT
// =================================================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/backend/db.php');
require_once(__DIR__ . '/vendor/autoload.php');

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

// Cargar variables de entorno
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Obtener clave secreta del .env
$jwt_secret_key = $_ENV['JWT_SECRET_KEY'] ?? null;

if (!$jwt_secret_key) {
    die("Error de configuración: Clave JWT no encontrada.");
}

if (!isset($_GET['token']) || empty($_GET['token'])) {
    die("Error: No se proporcionó un token.");
}

$token = $_GET['token'];

// Margen de tiempo para validación (leeway)
JWT::$leeway = 10;

try {
    // Decodificar el token
    $decoded = JWT::decode($token, new Key($jwt_secret_key, 'HS256'));

    // Asumimos que la cédula viene en el campo 'cedula' del payload, o dentro de 'data'
    // Ajustar según la estructura real del JWT que envíe el proveedor
    $cedula_usuario = $decoded->data->cedula ?? null;

    if (!$cedula_usuario) {
        die("Error: El token no contiene una cédula válida.");
    }

    if (!isset($conexion) || !$conexion) {
        die("Error crítico: No hay conexión a la base de datos.");
    }

    // Buscar usuario por Cédula (campo 'usuario' en la tabla 'usuarios')
    // Reutilizamos la lógica de login.php para obtener todos los datos necesarios para la sesión
    $sql = "SELECT u.id, u.usuario, u.clave, u.nombre_completo, u.rol, u.activo, 
                   c.nombre_cargo, u.empresa, u.regional 
            FROM usuarios u
            LEFT JOIN cargos c ON u.id_cargo = c.id_cargo 
            WHERE u.usuario = ?";

    if ($stmt = $conexion->prepare($sql)) {
        $stmt->bind_param("s", $cedula_usuario);

        if ($stmt->execute()) {
            $stmt->store_result();

            if ($stmt->num_rows == 1) {
                $stmt->bind_result($id_db, $usuario_db_col, $clave_hash_db_col, $nombre_completo_db, $rol_db, $activo_db, $nombre_cargo_db, $empresa_db, $regional_db);

                if ($stmt->fetch()) {
                    if ($activo_db == 1 || $activo_db === TRUE) {
                        // --- INICIO DE SESIÓN EXITOSO ---
                        session_start();
                        session_regenerate_id(true);

                        $_SESSION["loggedin"] = true;
                        $_SESSION["usuario_id"] = $id_db;
                        $_SESSION["usuario_login"] = $usuario_db_col; // Cédula
                        $_SESSION["nombre_usuario_completo"] = $nombre_completo_db;
                        $_SESSION["rol_usuario"] = $rol_db;
                        $_SESSION["cargo_usuario"] = $nombre_cargo_db;
                        $_SESSION["empresa_usuario"] = $empresa_db;
                        $_SESSION["regional_usuario"] = $regional_db;

                        // Redirigir al menú principal
                        header("Location: menu.php");
                        exit();
                    } else {
                        die("Error: Esta cuenta de usuario ha sido desactivada.");
                    }
                } else {
                    die("Error: No se pudieron obtener los datos del usuario.");
                }
            } else {
                die("Error: No se encontró un usuario registrado con la cédula $cedula_usuario.");
            }
        } else {
            die("Error: Fallo en la consulta a la base de datos.");
        }
        $stmt->close();
    } else {
        die("Error: Fallo al preparar la consulta.");
    }
} catch (ExpiredException $e) {
    die("Error: El token ha expirado.");
} catch (SignatureInvalidException $e) {
    die("Error: La firma del token es inválida.");
} catch (BeforeValidException $e) {
    die("Error: El token aún no es válido.");
} catch (Exception $e) {
    die("Error procesando el token: " . $e->getMessage());
}
