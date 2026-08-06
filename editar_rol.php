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
$stmt_actuales = $conexion->prepare("SELECT id_permiso FROM rol_permisos WHERE id_rol = ?");
$stmt_actuales->bind_param("i", $id_rol);
$stmt_actuales->execute();
$res_actuales = $stmt_actuales->get_result();
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
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="page-editar-rol">

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