<?php
// =================================================================================
// ARCHIVO: editar_rol.php
// DESCRIPCIÓN: Interfaz gráfica para marcar/desmarcar permisos de un rol
// ESTADO: CORREGIDO (Usa id_rol en lugar de id)
// =================================================================================

session_start();
require_once 'backend/auth_check.php';
require_once 'backend/db.php'; 

// 1. SEGURIDAD: Solo usuarios con permiso 'ver_usuarios' pueden entrar aquí
// Si tu admin aún no tiene permisos en BD, comenta esta línea temporalmente para entrar
verificar_permiso_o_morir('ver_usuarios');

$id_rol = $_GET['id'] ?? null;
$mensaje = "";

// Validar que venga un ID. Si no viene, devolvemos al usuario.
if (!$id_rol) {
    header("Location: gestionar_roles.php"); 
    exit;
}

// 2. PROCESAR EL GUARDADO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_permisos'])) {
    $id_rol_post = $_POST['id_rol'];
    $permisos_seleccionados = $_POST['permisos'] ?? []; 

    $conexion->begin_transaction();
    try {
        // A. Borrar permisos viejos (Usamos id_rol)
        $stmt = $conexion->prepare("DELETE FROM rol_permisos WHERE id_rol = ?");
        $stmt->bind_param("i", $id_rol_post);
        $stmt->execute();

        // B. Insertar nuevos permisos
        if (!empty($permisos_seleccionados)) {
            $sql_insert = "INSERT INTO rol_permisos (id_rol, id_permiso) VALUES (?, ?)";
            $stmt_ins = $conexion->prepare($sql_insert);
            foreach ($permisos_seleccionados as $id_permiso) {
                $stmt_ins->bind_param("ii", $id_rol_post, $id_permiso);
                $stmt_ins->execute();
            }
        }
        $conexion->commit();
        $mensaje = "<div class='alert alert-success shadow-sm mb-4'>Permisos actualizados correctamente.</div>";
    } catch (Exception $e) {
        $conexion->rollback();
        $mensaje = "<div class='alert alert-danger shadow-sm mb-4'>Error: " . $e->getMessage() . "</div>";
    }
}

// 3. OBTENER DATOS

// Info del Rol (CORRECCIÓN AQUÍ: id_rol en lugar de id)
$stmt = $conexion->prepare("SELECT * FROM roles WHERE id_rol = ?");
$stmt->bind_param("i", $id_rol);
$stmt->execute();
$rol_info = $stmt->get_result()->fetch_assoc();

if (!$rol_info) { 
    die("<div class='container mt-5 alert alert-danger'>Error: El rol solicitado (ID: $id_rol) no existe en la base de datos.</div>"); 
}

// Todos los permisos (Catálogo)
// Asumimos que la tabla 'permisos' sí usa 'id' si la creaste con mi script anterior.
// Si tu tabla permisos usa 'id_permiso', cambia 'p.id' por 'p.id_permiso' abajo.
$res_permisos = $conexion->query("SELECT * FROM permisos ORDER BY categoria DESC, nombre_permiso ASC");
$todos_permisos = [];
while($row = $res_permisos->fetch_assoc()) {
    $todos_permisos[$row['categoria']][] = $row;
}

// Permisos actuales del rol (para marcar los checks)
// Usamos id_rol
$res_actuales = $conexion->query("SELECT id_permiso FROM rol_permisos WHERE id_rol = $id_rol");
$permisos_activos = [];
while($row = $res_actuales->fetch_assoc()) {
    $permisos_activos[] = $row['id_permiso'];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Permisos: <?= htmlspecialchars($rol_info['nombre_rol']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f6f9; padding-top: 40px; padding-bottom: 80px; }
        .card-category { border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; border-radius: 12px; transition: transform 0.2s; }
        .card-category:hover { transform: translateY(-2px); }
        .category-header { background: #fff; padding: 15px 20px; border-bottom: 2px solid #f0f0f0; border-radius: 12px 12px 0 0; display: flex; align-items: center; }
        .category-title { font-weight: 700; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 1px; color: #555; margin: 0; }
        .category-icon { margin-right: 10px; font-size: 1.1rem; color: #0d6efd; }
        .permiso-row { padding: 12px 20px; border-bottom: 1px solid #f8f9fa; display: flex; align-items: center; justify-content: space-between; transition: background 0.2s; }
        .permiso-row:hover { background-color: #fcfcfc; }
        .permiso-row:last-child { border-bottom: none; }
        .form-check-input { cursor: pointer; width: 3em; height: 1.5em; }
        .permiso-label { cursor: pointer; flex-grow: 1; margin-left: 15px; }
        .permiso-name { font-weight: 600; color: #333; display: block; }
        .permiso-key { font-size: 0.75rem; color: #999; font-family: monospace; }
        .floating-save { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; padding: 15px; box-shadow: 0 -5px 20px rgba(0,0,0,0.1); z-index: 1000; text-align: center; border-top: 1px solid #e0e0e0; }
    </style>
</head>
<body>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-0">Gestionar Permisos</h2>
            <p class="text-muted mb-0">Rol: <span class="badge bg-primary fs-6"><?= htmlspecialchars($rol_info['nombre_rol']) ?></span></p>
        </div>
        <a href="gestionar_roles.php" class="btn btn-outline-secondary rounded-pill px-4">
            <i class="bi bi-arrow-left"></i> Volver
        </a>
    </div>

    <?= $mensaje ?>

    <form method="POST" action="">
        <input type="hidden" name="id_rol" value="<?= $id_rol ?>">
        <input type="hidden" name="guardar_permisos" value="1">

        <div class="row g-4">
            <?php foreach ($todos_permisos as $categoria => $lista_permisos): ?>
                <div class="col-lg-6">
                    <div class="card card-category h-100">
                        <div class="category-header">
                            <i class="bi bi-folder2-open category-icon"></i>
                            <h5 class="category-title"><?= htmlspecialchars($categoria) ?></h5>
                        </div>
                        <div class="card-body p-0">
                            <?php foreach ($lista_permisos as $p): 
                                $checked = in_array($p['id'], $permisos_activos) ? 'checked' : '';
                                $id_input = "chk_" . $p['id'];
                            ?>
                                <div class="permiso-row">
                                    <div class="permiso-label" onclick="document.getElementById('<?= $id_input ?>').click()">
                                        <span class="permiso-name"><?= htmlspecialchars($p['nombre_permiso']) ?></span>
                                        <span class="permiso-key"><?= htmlspecialchars($p['clave_permiso']) ?></span>
                                    </div>
                                    <div class="form-check form-switch m-0">
                                        <input class="form-check-input" type="checkbox" name="permisos[]" value="<?= $p['id'] ?>" id="<?= $id_input ?>" <?= $checked ?>>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="height: 100px;"></div>

        <div class="floating-save">
            <div class="container">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted d-none d-md-block">Recuerda guardar los cambios antes de salir.</span>
                    <button type="submit" class="btn btn-success btn-lg px-5 rounded-pill shadow">
                        <i class="bi bi-save2"></i> Guardar Configuración
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

</body>
</html>