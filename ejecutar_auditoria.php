<?php
// =================================================================================
// ARCHIVO: ejecutar_auditoria.php
// ESTADO: FINAL (Buscador arreglado + Campo de Novedades/Observaciones)
// =================================================================================

session_start();
require_once 'backend/auth_check.php';
require_once 'backend/db.php';

// --- AJAX ---
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // ACCIÓN 1: Actualizar Estado (Botones de colores)
    if ($_POST['ajax_action'] === 'actualizar_estado') {
        $id_detalle = (int)$_POST['id_detalle'];
        $nuevo_estado = $_POST['estado']; 
        $sql = "UPDATE auditoria_detalles SET estado_auditoria = ?, fecha_verificacion = NOW() WHERE id_detalle = ?";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param("si", $nuevo_estado, $id_detalle);
        $stmt->execute();
        
        // Recalcular progreso
        $id_auditoria = (int)$_POST['id_auditoria'];
        $total = $conexion->query("SELECT COUNT(*) as c FROM auditoria_detalles WHERE id_auditoria = $id_auditoria")->fetch_assoc()['c'];
        $proc = $conexion->query("SELECT COUNT(*) as c FROM auditoria_detalles WHERE id_auditoria = $id_auditoria AND estado_auditoria != 'Pendiente'")->fetch_assoc()['c'];
        
        echo json_encode(['status' => 'ok', 'progreso' => ($total>0?round(($proc/$total)*100):0), 'procesados' => $proc]);
        exit;
    }

    // ACCIÓN 2: Guardar Observación/Novedad (Texto)
    if ($_POST['ajax_action'] === 'guardar_observacion') {
        $id_detalle = (int)$_POST['id_detalle'];
        $texto = trim($_POST['observacion']);
        
        $sql = "UPDATE auditoria_detalles SET observacion_auditor = ? WHERE id_detalle = ?";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param("si", $texto, $id_detalle);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }
}

// --- FINALIZAR ---
if (isset($_POST['finalizar_auditoria'])) {
    $id_aud = (int)$_POST['id_auditoria'];
    $conexion->query("UPDATE auditorias SET estado = 'Finalizada', fecha_cierre = NOW() WHERE id_auditoria = $id_aud");
    header("Location: auditorias.php");
    exit;
}

// --- CARGA ---
$id_auditoria = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$auditoria = $conexion->query("SELECT * FROM auditorias WHERE id_auditoria = $id_auditoria")->fetch_assoc();

if (!$auditoria) die("Auditoría no encontrada.");
if ($auditoria['estado'] === 'Finalizada') die("Esta auditoría ya fue finalizada.");

// CONSULTA SQL
$sql_items = "SELECT d.id_detalle, d.estado_auditoria, d.observacion_auditor,
                     a.id as id_activo, a.serie, a.Codigo_Inv, a.marca, a.detalles, 
                     ta.nombre_tipo_activo, u.nombre_completo as responsable,
                     cc.nombre_centro_costo as ubicacion_sistema
              FROM auditoria_detalles d
              JOIN activos_tecnologicos a ON d.id_activo = a.id
              LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
              LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
              LEFT JOIN centros_costo cc ON a.id_centro_costo = cc.id_centro_costo
              WHERE d.id_auditoria = $id_auditoria
              ORDER BY 
                CASE WHEN u.nombre_completo IS NULL THEN 1 ELSE 0 END,
                u.nombre_completo ASC, 
                ta.nombre_tipo_activo ASC";

$items = $conexion->query($sql_items);

$data_js = [];
$procesados_ini = 0;
while($row = $items->fetch_assoc()) {
    if($row['estado_auditoria'] != 'Pendiente') $procesados_ini++;
    $data_js[] = $row;
}
$total_items = count($data_js);
$progreso_ini = $total_items > 0 ? round(($procesados_ini / $total_items) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ejecutando Auditoría</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { padding-top: 70px; background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; padding-bottom: 80px; }
        .top-bar-fixed { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; background: #fff; border-bottom: 1px solid #dee2e6; padding: 10px 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .item-card { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 10px; transition: all 0.2s; border-left: 5px solid #ccc; }
        .item-card.status-Pendiente { border-left-color: #6c757d; }
        .item-card.status-Encontrado { border-left-color: #198754; background-color: #f8fff9; }
        .item-card.status-No_Encontrado { border-left-color: #dc3545; opacity: 0.8; }
        .item-card.status-Malo { border-left-color: #ffc107; }
        .btn-action { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-left: 5px; }
        .bottom-bar { position: fixed; bottom: 0; left: 0; right: 0; background: white; padding: 10px; border-top: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; z-index: 1030; }
        .info-label { font-weight: bold; color: #555; font-size: 0.9em; }
        
        .group-header { 
            background-color: #e9ecef; 
            padding: 8px 15px; 
            border-radius: 5px; 
            margin-top: 20px; 
            margin-bottom: 10px; 
            font-weight: bold; 
            color: #495057; 
            border-left: 4px solid #0d6efd;
            display: flex;
            align-items: center;
        }
        
        /* Estilo para el input de novedad */
        .input-novedad {
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.85rem;
            background-color: #fcfcfc;
            transition: border-color 0.3s;
        }
        .input-novedad:focus {
            background-color: #fff;
            box-shadow: none;
            border-color: #86b7fe;
        }
        .guardado-ok {
            border-color: #198754 !important;
            background-color: #f0fff4 !important;
        }
    </style>
</head>
<body>

    <div class="top-bar-fixed">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="m-0 text-truncate" style="max-width: 70%;"><?= htmlspecialchars($auditoria['nombre_auditoria']) ?></h6>
            <a href="auditorias.php" class="btn btn-sm btn-outline-secondary">Salir</a>
        </div>
        <div class="progress" style="height: 10px;">
            <div id="progressBar" class="progress-bar bg-success" style="width: <?= $progreso_ini ?>%"></div>
        </div>
        <div class="d-flex justify-content-between small text-muted mt-1">
            <span id="txtProgreso"><?= $procesados_ini ?> / <?= $total_items ?></span>
            <span id="lblPorcentaje"><?= $progreso_ini ?>%</span>
        </div>
        <div class="mt-2">
            <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" id="buscador" class="form-control" placeholder="Buscar serie, placa, responsable..." autocomplete="off">
            </div>
        </div>
    </div>

    <div class="container mt-5 pt-5" id="listaItems"></div>

    <div class="bottom-bar">
        <small class="text-muted">¿Listo?</small>
        <form method="POST" onsubmit="return confirm('¿Finalizar auditoría?');">
            <input type="hidden" name="id_auditoria" value="<?= $id_auditoria ?>">
            <button type="submit" name="finalizar_auditoria" class="btn btn-primary fw-bold px-4"><i class="bi bi-check2-circle"></i> Finalizar</button>
        </form>
    </div>

    <script>
        const items = <?= json_encode($data_js) ?>;
        const idAuditoria = <?= $id_auditoria ?>;
        const contenedor = document.getElementById('listaItems');
        const inputBusqueda = document.getElementById('buscador');
        
        function renderizar(lista) {
            contenedor.innerHTML = '';
            if(lista.length === 0) {
                contenedor.innerHTML = '<div class="text-center text-muted mt-5"><i class="bi bi-inbox fs-1"></i><p>Sin resultados.</p></div>';
                return;
            }

            let ultimoResponsable = "---INIT---"; 

            lista.forEach(item => {
                let respActual = item.responsable ? item.responsable : "Sin Asignar (Bodega/Stock)";

                if (respActual !== ultimoResponsable) {
                    let htmlHeader = `
                        <div class="group-header shadow-sm">
                            <i class="bi bi-person-circle me-2 fs-5"></i> ${respActual}
                        </div>`;
                    contenedor.insertAdjacentHTML('beforeend', htmlHeader);
                    ultimoResponsable = respActual;
                }

                let colorClass = 'status-' + item.estado_auditoria.replace(' ', '_').replace(/[\(\)]/g, ''); 
                if(item.estado_auditoria.includes('Malo')) colorClass = 'status-Malo';
                if(item.estado_auditoria === 'No Encontrado') colorClass = 'status-No_Encontrado';

                // CORRECCIÓN: Renderizado con Campo de Novedades
                let html = `
                <div class="item-card p-3 ${colorClass}" id="card-${item.id_detalle}">
                    <div class="d-flex justify-content-between mb-2">
                        <div style="flex: 1;">
                            <h6 class="fw-bold mb-1 text-primary">${item.nombre_tipo_activo}</h6>
                            <div style="font-size: 0.9em;">
                                <div><span class="info-label">Serie:</span> ${item.serie}</div>
                                <div><span class="info-label">Placa:</span> ${item.Codigo_Inv}</div>
                                <div class="text-danger mt-1"><span class="info-label">Ubicación Sistema:</span> ${item.ubicacion_sistema}</div>
                                <div class="text-muted fst-italic mt-1 small">${item.marca} ${item.detalles || ''}</div>
                            </div>
                        </div>
                        <div class="d-flex flex-column justify-content-start ms-2">
                            <button onclick="cambiarEstado(${item.id_detalle}, 'Encontrado')" class="btn btn-outline-success btn-action mb-2 ${item.estado_auditoria === 'Encontrado' ? 'active bg-success text-white' : ''}"><i class="bi bi-check-lg"></i></button>
                            <button onclick="cambiarEstado(${item.id_detalle}, 'No Encontrado')" class="btn btn-outline-danger btn-action mb-2 ${item.estado_auditoria === 'No Encontrado' ? 'active bg-danger text-white' : ''}"><i class="bi bi-x-lg"></i></button>
                            <button onclick="cambiarEstado(${item.id_detalle}, 'Encontrado (Malo)')" class="btn btn-outline-warning btn-action ${item.estado_auditoria === 'Encontrado (Malo)' ? 'active bg-warning text-dark' : ''}"><i class="bi bi-exclamation-triangle"></i></button>
                        </div>
                    </div>
                    
                    <div class="mt-2">
                        <textarea 
                            class="form-control input-novedad" 
                            id="obs-${item.id_detalle}" 
                            rows="2" 
                            placeholder="Escribir novedad (Daño, no existe, etc)..."
                            onblur="guardarObservacion(${item.id_detalle}, this)"
                        >${item.observacion_auditor || ''}</textarea>
                    </div>
                </div>`;
                contenedor.insertAdjacentHTML('beforeend', html);
            });
        }

        // CORRECCIÓN: Buscador con protección contra Nulos (|| '')
        inputBusqueda.addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase();
            const filtrados = items.filter(i => 
                (i.serie || '').toLowerCase().includes(term) || 
                (i.Codigo_Inv || '').toLowerCase().includes(term) || 
                (i.responsable || '').toLowerCase().includes(term) ||
                (i.nombre_tipo_activo || '').toLowerCase().includes(term)
            );
            renderizar(filtrados);
        });

        function cambiarEstado(idDetalle, nuevoEstado) {
            const formData = new FormData();
            formData.append('ajax_action', 'actualizar_estado');
            formData.append('id_detalle', idDetalle);
            formData.append('estado', nuevoEstado);
            formData.append('id_auditoria', idAuditoria);
            fetch('ejecutar_auditoria.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => { if(data.status === 'ok') location.reload(); });
        }

        // NUEVA FUNCIÓN: Guardar observación al salir del campo (onblur)
        function guardarObservacion(idDetalle, inputElement) {
            const texto = inputElement.value;
            // Solo guardar si hubo cambios (o mejora visual)
            
            const formData = new FormData();
            formData.append('ajax_action', 'guardar_observacion');
            formData.append('id_detalle', idDetalle);
            formData.append('observacion', texto);

            fetch('ejecutar_auditoria.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => { 
                if(data.status === 'ok') {
                    // Feedback visual: Verde por 1 segundo
                    inputElement.classList.add('guardado-ok');
                    setTimeout(() => inputElement.classList.remove('guardado-ok'), 1000);
                }
            });
        }

        renderizar(items);
    </script>
</body>
</html>