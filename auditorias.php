<?php
// =================================================================================
// ARCHIVO: auditorias.php
// ESTADO: CORREGIDO (Eliminado filtro de columna inexistente 'u.estado')
// =================================================================================
session_start();
require_once 'backend/auth_check.php';
restringir_acceso_pagina(['admin', 'auditor', 'tecnico']); 
require_once 'backend/db.php';

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Auditor';

// --- PROCESAR CREACIÓN DE AUDITORÍA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_auditoria'])) {
    $nombre = trim($_POST['nombre']);
    $reg_id = !empty($_POST['filtro_regional']) ? (int)$_POST['filtro_regional'] : 0;
    $cc_id = !empty($_POST['filtro_cc']) ? (int)$_POST['filtro_cc'] : 0;
    $auditor_id = $_SESSION['usuario_id'];

    if ($nombre && ($reg_id > 0 || $cc_id > 0)) {
        
        $filtros_txt = "Regional: " . ($reg_id ?: 'Todas');
        if($cc_id > 0) $filtros_txt .= " | Centro Costo ID: " . $cc_id;

        $stmt = $conexion->prepare("INSERT INTO auditorias (nombre_auditoria, id_usuario_auditor, filtros_aplicados) VALUES (?, ?, ?)");
        $stmt->bind_param("sis", $nombre, $auditor_id, $filtros_txt);
        
        if ($stmt->execute()) {
            $id_auditoria = $stmt->insert_id;

            // --- LÓGICA DE FILTRADO (POR USUARIO) ---
            $sql_assets = "INSERT INTO auditoria_detalles (id_auditoria, id_activo)
                           SELECT ?, at.id 
                           FROM activos_tecnologicos at
                           INNER JOIN usuarios u ON at.id_usuario_responsable = u.id
                           INNER JOIN centros_costo cc ON u.id_centro_costo = cc.id_centro_costo
                           WHERE at.estado != 'Dado de Baja' ";
            
            $params = [$id_auditoria];
            $types = "i";

            // PRIORIDAD: Si hay Centro de Costo del USUARIO
            if ($cc_id > 0) {
                $sql_assets .= " AND u.id_centro_costo = ?";
                $params[] = $cc_id;
                $types .= "i";
            } 
            // Si NO hay CC, pero SÍ Regional, filtramos por la Regional del USUARIO
            elseif ($reg_id > 0) {
                $sql_assets .= " AND cc.id_regional = ?";
                $params[] = $reg_id;
                $types .= "i";
            }

            $stmt_ins = $conexion->prepare($sql_assets);
            $stmt_ins->bind_param($types, ...$params);
            
            if (!$stmt_ins->execute()) {
                die("Error al generar lista: " . $stmt_ins->error . " <br>Verifica que la tabla usuarios tenga la columna id_centro_costo.");
            }

            header("Location: ejecutar_auditoria.php?id=" . $id_auditoria);
            exit;
        }
    }
}

// --- CONSULTAS PARA LISTAS DESPLEGABLES ---
// Corregido: Se eliminó "WHERE u.estado = 'Activo'" ya que la columna no existe

// 1. Regionales con Usuarios
$sql_regs = "SELECT DISTINCT r.id_regional, r.nombre_regional 
             FROM regionales r 
             JOIN centros_costo cc ON r.id_regional = cc.id_regional 
             JOIN usuarios u ON cc.id_centro_costo = u.id_centro_costo
             ORDER BY r.nombre_regional";
$regionales = $conexion->query($sql_regs)->fetch_all(MYSQLI_ASSOC);

// 2. Centros de Costo con Usuarios
$sql_ccs = "SELECT DISTINCT cc.id_centro_costo, cc.nombre_centro_costo, cc.id_regional 
            FROM centros_costo cc 
            JOIN usuarios u ON cc.id_centro_costo = u.id_centro_costo
            ORDER BY cc.nombre_centro_costo";
$centros_costo = $conexion->query($sql_ccs)->fetch_all(MYSQLI_ASSOC);

// Listado de Auditorías
$sql_list = "SELECT a.*, u.nombre_completo, 
            (SELECT COUNT(*) FROM auditoria_detalles WHERE id_auditoria = a.id_auditoria) as total_items,
            (SELECT COUNT(*) FROM auditoria_detalles WHERE id_auditoria = a.id_auditoria AND estado_auditoria != 'Pendiente') as procesados
            FROM auditorias a 
            JOIN usuarios u ON a.id_usuario_auditor = u.id 
            ORDER BY a.fecha_creacion DESC";
$auditorias = $conexion->query($sql_list);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Auditorías</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>body{padding-top:80px;background:#f0f2f5;font-family:'Segoe UI',sans-serif;}.top-bar-custom{position:fixed;top:0;left:0;right:0;z-index:1030;background:#fff;border-bottom:1px solid #dee2e6;padding:10px 15px;box-shadow:0 2px 4px rgba(0,0,0,0.05);}.logo-container-top img{height:60px;object-fit:contain;margin-right:15px;}</style>
</head>
<body>
    
    <div class="top-bar-custom">
        <div class="d-flex justify-content-between align-items-center">
            <div class="logo-container-top">
                <a href="menu.php"><img src="imagenes/logo.png" alt="Logo"></a>
            </div>
            <div class="d-flex align-items-center">
                <span class="me-3 fw-bold text-secondary d-none d-md-block"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?></span>
                <form action="logout.php" method="post"><button class="btn btn-outline-danger btn-sm rounded-pill" type="submit">Salir</button></form>
            </div>
        </div>
    </div>

    <div class="container mt-4 mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="text-primary"><i class="bi bi-clipboard-check"></i> Auditorías Físicas</h3>
            <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNueva">
                <i class="bi bi-plus-lg"></i> Nueva Auditoría
            </button>
        </div>

        <div class="row g-3">
            <?php if ($auditorias && $auditorias->num_rows > 0): ?>
                <?php while($row = $auditorias->fetch_assoc()): 
                    $progreso = $row['total_items'] > 0 ? round(($row['procesados'] / $row['total_items']) * 100) : 0;
                    $color_estado = $row['estado'] == 'En Proceso' ? 'primary' : 'success';
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="card-title fw-bold text-dark m-0"><?= htmlspecialchars($row['nombre_auditoria']) ?></h6>
                                <span class="badge bg-<?= $color_estado ?>"><?= $row['estado'] ?></span>
                            </div>
                            <p class="text-muted small mb-3">
                                <i class="bi bi-calendar"></i> <?= date('d/m/Y H:i', strtotime($row['fecha_creacion'])) ?><br>
                                <i class="bi bi-filter"></i> <span class="fst-italic" style="font-size:0.85em"><?= htmlspecialchars($row['filtros_aplicados']) ?></span>
                            </p>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between small fw-bold text-secondary">
                                    <span><?= $row['procesados'] ?> / <?= $row['total_items'] ?></span>
                                    <span><?= $progreso ?>%</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-<?= $color_estado ?>" role="progressbar" style="width: <?= $progreso ?>%"></div>
                                </div>
                            </div>

                            <div class="d-grid">
                                <?php if($row['estado'] == 'En Proceso'): ?>
                                    <a href="ejecutar_auditoria.php?id=<?= $row['id_auditoria'] ?>" class="btn btn-sm btn-outline-primary fw-bold">
                                        <i class="bi bi-play-fill"></i> Continuar
                                    </a>
                                <?php else: ?>
                                    <a href="resultado_auditoria.php?id=<?= $row['id_auditoria'] ?>" class="btn btn-sm btn-outline-success fw-bold">
                                        <i class="bi bi-file-text"></i> Resultados
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5">
                    <h5 class="text-muted">No hay auditorías registradas</h5>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="modalNueva" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Iniciar Auditoría (Basada en Usuarios)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre descriptivo *</label>
                        <input type="text" name="nombre" class="form-control" placeholder="Ej: Auditoría Sede Norte" required>
                    </div>

                    <div class="alert alert-info border small">
                        <i class="bi bi-people-fill"></i> <strong>Nota:</strong> Se cargarán los activos asignados a los <strong>USUARIOS</strong> que pertenezcan a la ubicación seleccionada.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">1. Regional del Usuario</label>
                        <select name="filtro_regional" id="selRegional" class="form-select" required onchange="filtrarCC()">
                            <option value="">Seleccione Regional...</option>
                            <?php foreach($regionales as $reg) echo "<option value='{$reg['id_regional']}'>{$reg['nombre_regional']}</option>"; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">2. Centro de Costo del Usuario</label>
                        <select name="filtro_cc" id="selCC" class="form-select">
                            <option value="">(Opcional) Todos los usuarios de la Regional</option>
                            </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="crear_auditoria" class="btn btn-primary fw-bold px-4">Comenzar</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const centrosCosto = <?= json_encode($centros_costo) ?>;

        function filtrarCC() {
            const regId = document.getElementById('selRegional').value;
            const selCC = document.getElementById('selCC');
            
            selCC.innerHTML = '<option value="">(Opcional) Todos los usuarios de la Regional</option>';

            if (regId !== "") {
                const filtrados = centrosCosto.filter(cc => cc.id_regional == regId);
                
                if (filtrados.length > 0) {
                    filtrados.forEach(cc => {
                        const opt = document.createElement('option');
                        opt.value = cc.id_centro_costo;
                        opt.innerText = cc.nombre_centro_costo;
                        selCC.appendChild(opt);
                    });
                } else {
                     const opt = document.createElement('option');
                     opt.innerText = "-- Sin usuarios en esta zona --";
                     selCC.appendChild(opt);
                }
            }
        }
    </script>
</body>
</html>