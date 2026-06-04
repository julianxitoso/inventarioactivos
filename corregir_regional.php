<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'backend/db.php'; 

$mensaje = "";



if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $cedula = $conexion->real_escape_string(trim($_POST['cedula']));
    $codigo_cc = $conexion->real_escape_string(trim($_POST['codigo_cc'])); // Ej: 10401

    // 1. Buscar el ID interno del Centro de Costo y el nombre de su Regional
    $sql_cc = "SELECT c.id_centro_costo, c.nombre_centro_costo, r.nombre_regional 
               FROM centros_costo c 
               JOIN regionales r ON c.id_regional = r.id_regional 
               WHERE c.cod_centro_costo = '$codigo_cc' LIMIT 1";
    $res_cc = $conexion->query($sql_cc);

    if ($res_cc && $res_cc->num_rows > 0) {
        $row_cc = $res_cc->fetch_assoc();
        $id_cc_interno = $row_cc['id_centro_costo']; // Ej: 30
        $nombre_regional = $row_cc['nombre_regional']; // Ej: 'Ambienta'

        // 2. Buscar el usuario
        $sql_user = "SELECT id, nombre_completo FROM usuarios WHERE usuario = '$cedula'";
        $res_user = $conexion->query($sql_user);

        if ($res_user && $res_user->num_rows > 0) {
            $row_user = $res_user->fetch_assoc();
            $id_usuario = $row_user['id'];
            $nombre_usuario = $row_user['nombre_completo'];

            // === INICIAMOS ACTUALIZACIÓN ===
            $conexion->begin_transaction();

            try {
                // A. Actualizar USUARIO (ID numérico y Texto del Dashboard)
                $sql_upd_user = "UPDATE usuarios 
                                 SET id_centro_costo = $id_cc_interno, 
                                     regional = '$nombre_regional' 
                                 WHERE id = $id_usuario";
                $conexion->query($sql_upd_user);

                // B. Actualizar TODOS los activos de este usuario a su nueva ubicación
                $sql_upd_activos = "UPDATE activos_tecnologicos 
                                    SET id_centro_costo = $id_cc_interno 
                                    WHERE id_usuario_responsable = $id_usuario";
                $conexion->query($sql_upd_activos);
                $filas_activos = $conexion->affected_rows;

                $conexion->commit();

                $mensaje = "<div class='alert alert-success'>
                                <h4><i class='bi bi-check-circle-fill'></i> ¡Sincronización Exitosa!</h4>
                                <p class='mb-1'>Usuario: <b>$nombre_usuario</b></p>
                                <p class='mb-1'>Nueva Ubicación: <b>$nombre_regional ($codigo_cc)</b></p>
                                <p class='mb-0'>Se movieron <b>$filas_activos activos</b> a esta nueva sede. El Dashboard ya debe reflejar los cambios.</p>
                            </div>";

            } catch (mysqli_sql_exception $e) {
                $conexion->rollback();
                $mensaje = "<div class='alert alert-danger'>Error al actualizar: " . $e->getMessage() . "</div>";
            }
        } else {
            $mensaje = "<div class='alert alert-warning'>No se encontró la cédula <b>$cedula</b>.</div>";
        }
    } else {
        $mensaje = "<div class='alert alert-danger'>El código de centro de costo <b>$codigo_cc</b> no existe (Ej de válidos: 10401, 10101).</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Sincronizar Regionales</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f6f9; }
        .container-custom { max-width: 500px; margin-top: 50px; }
    </style>
</head>
<body>
    <div class="container container-custom">
        <div class="card shadow border-0">
            <div class="card-header bg-primary text-white text-center">
                <h4 class="mb-0 py-2">Reparar Ubicación y Dashboard</h4>
            </div>
            <div class="card-body p-4">
                <?= $mensaje ?>
                
                <p class="text-muted small mb-4">
                    Ingresa la cédula del empleado y el código de la nueva sede. El sistema actualizará su perfil y arrastrará todos sus activos a la nueva ubicación automáticamente.
                </p>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Cédula del Responsable:</label>
                        <input type="text" name="cedula" class="form-control form-control-lg" required placeholder="Ej: 98655270">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold">Código del Centro de Costo:</label>
                        <input type="text" name="codigo_cc" class="form-control form-control-lg" required placeholder="Ej: 10401">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold"><i class="bi bi-arrow-repeat"></i> Sincronizar Todo</button>
                </form>
                
                <div class="text-center mt-3">
                    <a href="index.php" class="text-decoration-none">Volver al Inicio</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>