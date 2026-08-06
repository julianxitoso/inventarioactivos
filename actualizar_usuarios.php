<?php
// =================================================================================
// ARCHIVO: actualizar_usuarios.php
// DESCRIPCIÓN: Script para sincronizar la tabla `usuarios` con un archivo maestro Excel.
// ACCIONES: Crea, actualiza e inactiva usuarios.
// =================================================================================

// Las dependencias como PhpSpreadsheet ya están cargadas a través de auth_check -> db -> vendor/autoload
use PhpOffice\PhpSpreadsheet\IOFactory;

session_start();

// Cargar el sistema de autenticación. Este se encargará de cargar la BD y otras dependencias.
require_once __DIR__ . '/backend/auth_check.php';
verificar_permiso_o_morir('ver_usuarios');

// Habilitar errores y configuraciones de PHP DESPUÉS de la seguridad.
ini_set('display_errors', 1); // Solo para depuración
error_reporting(E_ALL); // Solo para depuración
set_time_limit(300); // Aumentar el tiempo de ejecución a 5 minutos si es necesario

if (!isset($conexion) || !$conexion || (is_object($conexion) && property_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    die("Error crítico de conexión a la base de datos.");
}
$conexion->set_charset("utf8mb4");

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$mensaje = "";
$reporte = [
    'leidos_excel' => 0,
    'creados' => 0,
    'actualizados' => 0,
    'inactivados' => 0,
    'sin_cambios' => 0,
    'errores' => []
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file_users'])) {
    $file = $_FILES['excel_file_users'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $mensaje = "<div class='alert alert-danger'>Error al subir el archivo: " . $file['error'] . "</div>";
    } else {
        $filePath = $uploadDir . 'sync_users_' . basename($file['name']);
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            try {
                // --- PASO 1: Cargar datos de referencia (roles, cargos, etc.) en memoria ---
                $roles = array_column($conexion->query("SELECT id_rol, nombre_rol FROM roles")->fetch_all(MYSQLI_ASSOC), 'id_rol', 'nombre_rol');
                $cargos = array_column($conexion->query("SELECT id_cargo, nombre_cargo FROM cargos")->fetch_all(MYSQLI_ASSOC), 'id_cargo', 'nombre_cargo');
                $regionales = array_column($conexion->query("SELECT id_regional, nombre_regional FROM regionales")->fetch_all(MYSQLI_ASSOC), 'id_regional', 'nombre_regional');
                $centros_costo = array_column($conexion->query("SELECT id_centro_costo, nombre_centro_costo FROM centros_costo")->fetch_all(MYSQLI_ASSOC), 'id_centro_costo', 'nombre_centro_costo');

                // --- PASO 2: Cargar usuarios del Excel en un array ---
                $spreadsheet = IOFactory::load($filePath);
                $sheet = $spreadsheet->getActiveSheet();
                $highestRow = $sheet->getHighestRow();
                $usuarios_excel = [];
                for ($row = 2; $row <= $highestRow; $row++) {
                    $cedula = trim($sheet->getCell('A' . $row)->getValue() ?? '');
                    if (empty($cedula)) continue;
                    $usuarios_excel[$cedula] = [
                        'nombre_completo' => trim($sheet->getCell('B' . $row)->getValue() ?? ''),
                        'email' => trim($sheet->getCell('C' . $row)->getValue() ?? ''),
                        'nombre_rol' => trim($sheet->getCell('D' . $row)->getValue() ?? ''),
                        'nombre_cargo' => trim($sheet->getCell('E' . $row)->getValue() ?? ''),
                        'nombre_regional' => trim($sheet->getCell('F' . $row)->getValue() ?? ''),
                        'nombre_empresa' => trim($sheet->getCell('G' . $row)->getValue() ?? ''),
                        'nombre_centro_costo' => trim($sheet->getCell('H' . $row)->getValue() ?? ''),
                        'clave_temporal' => trim($sheet->getCell('I' . $row)->getValue() ?? ''),
                        'fila' => $row
                    ];
                }
                $reporte['leidos_excel'] = count($usuarios_excel);

                // --- PASO 3: Cargar usuarios de la BD en un array ---
                $usuarios_db_raw = $conexion->query("SELECT id, usuario, nombre_completo, email, rol, id_cargo, id_centro_costo, regional, empresa, activo FROM usuarios")->fetch_all(MYSQLI_ASSOC);
                $usuarios_db = [];
                foreach ($usuarios_db_raw as $user) {
                    $usuarios_db[$user['usuario']] = $user;
                }

                $conexion->begin_transaction();

                // --- PASO 4: Iterar sobre usuarios del Excel para CREAR o ACTUALIZAR ---
                $stmt_insert = $conexion->prepare("INSERT INTO usuarios (usuario, nombre_completo, email, clave, rol, id_cargo, id_centro_costo, regional, empresa, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt_update = $conexion->prepare("UPDATE usuarios SET nombre_completo=?, email=?, rol=?, id_cargo=?, id_centro_costo=?, regional=?, empresa=?, activo=1 WHERE id=?");

                foreach ($usuarios_excel as $cedula => $data_excel) {
                    // Validar y obtener IDs de las tablas de referencia
                    $id_rol = $roles[$data_excel['nombre_rol']] ?? null;
                    $id_cargo = $cargos[$data_excel['nombre_cargo']] ?? null;
                    $id_regional_nombre = $data_excel['nombre_regional']; // El nombre se guarda directo
                    $id_centro_costo = $centros_costo[$data_excel['nombre_centro_costo']] ?? null;

                    if (!$id_rol) { $reporte['errores'][] = "Fila {$data_excel['fila']} (C.C: $cedula): Rol '{$data_excel['nombre_rol']}' no existe."; continue; }
                    if (!$id_cargo) { $reporte['errores'][] = "Fila {$data_excel['fila']} (C.C: $cedula): Cargo '{$data_excel['nombre_cargo']}' no existe."; continue; }
                    if (!$id_centro_costo) { $reporte['errores'][] = "Fila {$data_excel['fila']} (C.C: $cedula): Centro de Costo '{$data_excel['nombre_centro_costo']}' no existe."; continue; }

                    if (!isset($usuarios_db[$cedula])) {
                        // CREAR USUARIO
                        $clave = !empty($data_excel['clave_temporal']) ? $data_excel['clave_temporal'] : $cedula;
                        $hash_clave = password_hash($clave, PASSWORD_DEFAULT);
                        $stmt_insert->bind_param("ssssiiiss", $cedula, $data_excel['nombre_completo'], $data_excel['email'], $hash_clave, $data_excel['nombre_rol'], $id_cargo, $id_centro_costo, $id_regional_nombre, $data_excel['nombre_empresa']);
                        if ($stmt_insert->execute()) {
                            $reporte['creados']++;
                        } else {
                            $reporte['errores'][] = "Fila {$data_excel['fila']} (C.C: $cedula): Error al crear - " . $stmt_insert->error;
                        }
                    } else {
                        // ACTUALIZAR USUARIO
                        $user_db = $usuarios_db[$cedula];
                        // Comprobar si hay cambios
                        if ($user_db['nombre_completo'] != $data_excel['nombre_completo'] || $user_db['email'] != $data_excel['email'] || $user_db['rol'] != $data_excel['nombre_rol'] || $user_db['id_cargo'] != $id_cargo || $user_db['id_centro_costo'] != $id_centro_costo || $user_db['regional'] != $id_regional_nombre || $user_db['empresa'] != $data_excel['nombre_empresa'] || $user_db['activo'] != 1) {
                            $stmt_update->bind_param("sssiissi", $data_excel['nombre_completo'], $data_excel['email'], $data_excel['nombre_rol'], $id_cargo, $id_centro_costo, $id_regional_nombre, $data_excel['nombre_empresa'], $user_db['id']);
                            if ($stmt_update->execute()) {
                                $reporte['actualizados']++;
                            } else {
                                $reporte['errores'][] = "Fila {$data_excel['fila']} (C.C: $cedula): Error al actualizar - " . $stmt_update->error;
                            }
                        } else {
                            $reporte['sin_cambios']++;
                        }
                    }
                }
                $stmt_insert->close();
                $stmt_update->close();

                // --- PASO 5: Iterar sobre usuarios de la BD para INACTIVAR ---
                $stmt_inactivate = $conexion->prepare("UPDATE usuarios SET activo = 0 WHERE id = ?");
                foreach ($usuarios_db as $cedula => $user_db) {
                    if (!isset($usuarios_excel[$cedula]) && $user_db['activo'] == 1) {
                        // INACTIVAR USUARIO
                        $stmt_inactivate->bind_param("i", $user_db['id']);
                        if ($stmt_inactivate->execute()) {
                            $reporte['inactivados']++;
                        } else {
                            $reporte['errores'][] = "C.C: $cedula: Error al inactivar - " . $stmt_inactivate->error;
                        }
                    }
                }
                $stmt_inactivate->close();

                if (empty($reporte['errores'])) {
                    $conexion->commit();
                    $mensaje = "<div class='alert alert-success'>Sincronización completada con éxito.</div>";
                } else {
                    $conexion->rollback();
                    $mensaje = "<div class='alert alert-danger'>La sincronización falló. Se revirtieron todos los cambios.</div>";
                }

            } catch (Exception $e) {
                if ($conexion->in_transaction) $conexion->rollback();
                $mensaje = "<div class='alert alert-danger'>Error crítico durante el proceso: " . htmlspecialchars($e->getMessage()) . "</div>";
            } finally {
                if (file_exists($filePath)) unlink($filePath);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Actualizar Usuarios Masivamente</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .container { max-width: 900px; margin-top: 50px; }
        .card { border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .card-header { background-color: #0d6efd; color: white; }
        .btn-primary { background-color: #0d6efd; border-color: #0d6efd; }
        .list-group-item span { font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header text-center">
                <h4 class="mb-0"><i class="bi bi-people-fill me-2"></i> Sincronización Maestra de Usuarios</h4>
            </div>
            <div class="card-body p-4">
                <div class="alert alert-warning">
                    <h5 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> ¡Atención!</h5>
                    <p>Este proceso sincronizará la base de datos de usuarios con el archivo Excel que suba.</p>
                    <ul>
                        <li>Los usuarios en el archivo que no existan en el sistema, <strong>serán creados</strong>.</li>
                        <li>Los usuarios que existan en ambos, <strong>serán actualizados</strong>.</li>
                        <li>Los usuarios que existan en el sistema pero no en el archivo, <strong>serán inactivados</strong>.</li>
                    </ul>
                    <p class="mb-0">Asegúrese de que su archivo Excel sea la lista maestra y definitiva de empleados activos.</p>
                </div>

                <?php if ($mensaje) echo $mensaje; ?>

                <?php if (!empty($reporte) && ($reporte['creados'] > 0 || $reporte['actualizados'] > 0 || $reporte['inactivados'] > 0 || !empty($reporte['errores']))): ?>
                <div class="card mt-4">
                    <div class="card-header">Reporte de Sincronización</div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between align-items-center">Usuarios leídos del archivo Excel: <span><?= $reporte['leidos_excel'] ?></span></li>
                            <li class="list-group-item d-flex justify-content-between align-items-center text-success">Usuarios nuevos creados: <span><?= $reporte['creados'] ?></span></li>
                            <li class="list-group-item d-flex justify-content-between align-items-center text-primary">Usuarios existentes actualizados: <span><?= $reporte['actualizados'] ?></span></li>
                            <li class="list-group-item d-flex justify-content-between align-items-center text-secondary">Usuarios sin cambios: <span><?= $reporte['sin_cambios'] ?></span></li>
                            <li class="list-group-item d-flex justify-content-between align-items-center text-danger">Usuarios inactivados (no estaban en el Excel): <span><?= $reporte['inactivados'] ?></span></li>
                        </ul>
                        <?php if (!empty($reporte['errores'])): ?>
                            <h6 class="mt-3 text-danger">Errores encontrados:</h6>
                            <ul class="list-group">
                                <?php foreach ($reporte['errores'] as $error): ?>
                                    <li class="list-group-item list-group-item-danger small"><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <h5 class="mt-4">Subir Archivo Maestro de Usuarios</h5>
                <form action="actualizar_usuarios.php" method="post" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="excel_file_users" class="form-label">Seleccione el archivo .xlsx:</label>
                        <input class="form-control" type="file" id="excel_file_users" name="excel_file_users" accept=".xlsx" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-arrow-repeat me-2"></i> Iniciar Sincronización</button>
                </form>

                <div class="mt-4 pt-3 border-top">
                    <h5 class="mb-3">Descargar Plantilla Maestra</h5>
                    <p class="text-muted small">Descargue la plantilla para asegurarse de que su archivo Excel tenga el formato correcto.</p>
                    <a href="plantillas/plantilla_maestra_usuarios.xlsx" class="btn btn-info" download>
                        <i class="bi bi-download me-2"></i> Descargar Plantilla de Usuarios
                    </a>
                </div>
            </div>
        </div>
        <div class="text-center mt-3">
            <a href="menu.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle"></i> Volver al Menú</a>
        </div>
    </div>
</body>
</html>
