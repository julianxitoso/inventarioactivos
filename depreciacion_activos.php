<?php
// =================================================================================
// ARCHIVO: depreciacion.php
// ESTADO: FÓRMULA FINANCIERA EXACTA (Sin topes mínimos, Días/360, Filtro Año 0)
// =================================================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/backend/auth_check.php';
restringir_acceso_pagina(['admin', 'auditor', 'registrador', 'tecnico']);
require_once __DIR__ . '/backend/db.php';

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }

$error_conexion_db = null;
$opciones_tipos = [];
$regionales = []; 
$empresas_disponibles = ['Arpesod', 'Finansueños'];

if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || !$conexion) {
    $error_conexion_db = "Error crítico de conexión a la base de datos.";
} else {
    $conexion->set_charset("utf8mb4");
    $result_tipos = $conexion->query("SELECT id_tipo_activo, nombre_tipo_activo FROM tipos_activo ORDER BY nombre_tipo_activo");
    if ($result_tipos) { $opciones_tipos = $result_tipos->fetch_all(MYSQLI_ASSOC); }

    $result_reg = $conexion->query("SELECT nombre_regional FROM regionales ORDER BY nombre_regional");
    if ($result_reg) { while($row = $result_reg->fetch_assoc()) { $regionales[] = $row['nombre_regional']; } }
}

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

$fecha_corte_contable = '2025-12-31';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Análisis de Depreciación de Activos</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="page-depreciacion">
<div class="top-bar-custom">
    <div class="logo-container-top">
        <a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo"></a>
    </div>
    <div class="d-flex align-items-center">
        <span class="text-dark me-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <form action="logout.php" method="post" class="d-flex">
            <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
        </form>
    </div>
</div>

<div class="container-fluid mt-4 px-lg-4 main-container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="page-header-title mb-0"><i class="bi bi-calculator-fill"></i> Análisis de Depreciación</h3>
        <a href="menu.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left-circle"></i> Volver al Menú</a>
    </div>

    <?php if ($error_conexion_db): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_conexion_db) ?></div>
    <?php else: ?>
        <div class="accordion mb-4" id="acordeon-filtros">
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFiltros"><i class="bi bi-funnel-fill me-2"></i> Panel de Filtros</button>
                </h2>
                <div id="collapseFiltros" class="accordion-collapse collapse show">
                    <div class="accordion-body bg-light">
                        <form id="form-filtros">
                             <div class="row g-3">
                                <div class="col-lg-12"><input type="text" class="form-control" name="q" placeholder="Buscar por Serie, Cód. Inventario, Cédula o Nombre..."></div>
                                <div class="col-md-3">
                                    <select name="tipo_activo" class="form-select">
                                        <option value="">-- Tipo Activo --</option>
                                        <?php foreach($opciones_tipos as $t) echo "<option value='{$t['id_tipo_activo']}'>".htmlspecialchars($t['nombre_tipo_activo'])."</option>"; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select name="regional" class="form-select">
                                        <option value="">-- Regional --</option>
                                        <?php foreach($regionales as $r) echo "<option value='".htmlspecialchars($r)."'>".htmlspecialchars($r)."</option>"; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select name="empresa" class="form-select">
                                        <option value="">-- Empresa --</option>
                                        <?php foreach($empresas_disponibles as $e) echo "<option value='".htmlspecialchars($e)."'>".htmlspecialchars($e)."</option>"; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select name="estado_depreciacion" class="form-select">
                                        <option value="">-- Estado Depreciación --</option>
                                        <option value="en_curso">En Curso</option>
                                        <option value="depreciado">Totalmente Depreciado</option>
                                        <option value="no_aplica">No Aplica para Depreciar</option>
                                    </select>
                                </div>
                                <div class="col-md-3"><label class="form-label small mb-0">Compra Desde:</label><input type="date" class="form-control form-control-sm" name="fecha_desde"></div>
                                <div class="col-md-3"><label class="form-label small mb-0">Compra Hasta:</label><input type="date" class="form-control form-control-sm" name="fecha_hasta"></div>
                            </div>
                            <hr class="my-3">
                            <div class="d-flex justify-content-end gap-2">
                                 <button type="button" id="btn-limpiar" class="btn btn-secondary"><i class="bi bi-eraser-fill me-1"></i> Limpiar</button>
                                 <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i> Consultar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-5">
                <h5 class="text-muted">Resultados de Búsqueda</h5>
                <div id="columna-resultados" class="list-group" style="max-height: 600px; overflow-y: auto;">
                    <div class="d-flex justify-content-center mt-5 d-none" id="loader"><div class="loader"></div></div>
                    <div class="text-center p-5 text-muted" id="placeholder-resultados">Use los filtros para buscar activos.</div>
                </div>
            </div>
            <div class="col-lg-7">
                <h5 class="text-muted">Detalles del Activo Seleccionado</h5>
                <div id="columna-detalles">
                    <div class="text-center p-5 text-muted">Seleccione un activo de la lista de resultados para ver sus detalles contables.</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const formFiltros = document.getElementById('form-filtros');
    if (!formFiltros) return;

    const btnLimpiar = document.getElementById('btn-limpiar');
    const resultadosContainer = document.getElementById('columna-resultados');
    const detallesContainer = document.getElementById('columna-detalles');
    const loader = document.getElementById('loader');
    const placeholderResultados = document.getElementById('placeholder-resultados');
    
    let activosCache = [];

    const FECHA_CORTE_CONTABLE = '<?= $fecha_corte_contable ?>'; 

    formFiltros.addEventListener('submit', function(e) {
        e.preventDefault();
        realizarBusqueda();
    });

    btnLimpiar.addEventListener('click', function() {
        formFiltros.reset();
        activosCache = [];
        resultadosContainer.innerHTML = '';
        placeholderResultados.classList.remove('d-none');
        resultadosContainer.appendChild(placeholderResultados);
        detallesContainer.innerHTML = '<div class="text-center p-5 text-muted">Seleccione un activo de la lista.</div>';
    });
    
    resultadosContainer.addEventListener('click', function(e) {
        const item = e.target.closest('.list-group-item');
        if (item) {
            e.preventDefault();
            const activeItem = resultadosContainer.querySelector('.list-group-item.active');
            if(activeItem) activeItem.classList.remove('active');
            item.classList.add('active');

            const index = parseInt(item.dataset.index, 10);
            const activoSeleccionado = activosCache[index];
            if (activoSeleccionado) {
                mostrarDetalles(activoSeleccionado);
            }
        }
    });

    async function realizarBusqueda() {
        loader.classList.remove('d-none');
        placeholderResultados.classList.add('d-none');
        resultadosContainer.innerHTML = '';
        resultadosContainer.appendChild(loader);
        detallesContainer.innerHTML = '<div class="text-center p-5 text-muted">Cargando...</div>';
        
        const formData = new FormData(formFiltros);
        const params = new URLSearchParams(formData).toString();

        try {
            const response = await fetch(`api/api_depreciacion.php?${params}`);
            if (!response.ok) throw new Error(`Error del servidor: ${response.statusText}`);
            
            const data = await response.json();
            activosCache = data;
            renderizarLista(data);
            detallesContainer.innerHTML = '<div class="text-center p-5 text-muted">Seleccione un activo para ver el detalle.</div>';
        } catch (error) {
            console.error('Error en la búsqueda AJAX:', error);
            resultadosContainer.innerHTML = `<div class="alert alert-danger">Error al cargar los datos. Verifique la consola.</div>`;
            detallesContainer.innerHTML = '';
        } finally {
            if(loader.parentNode) loader.remove();
        }
    }

    function renderizarLista(activos) {
        resultadosContainer.innerHTML = '';
        if (!activos || activos.length === 0) {
            if (new URLSearchParams(new FormData(formFiltros)).toString().length > 0) {
                 resultadosContainer.innerHTML = '<div class="text-center p-5 text-muted">No se encontraron activos con estos filtros.</div>';
            } else {
                 resultadosContainer.appendChild(placeholderResultados);
                 placeholderResultados.classList.remove('d-none');
            }
            return;
        }

        activos.forEach((activo, index) => {
            const item = document.createElement('a');
            item.href = '#';
            item.className = 'list-group-item list-group-item-action';
            item.dataset.index = index;
            item.innerHTML = `
                <div class="d-flex w-100 justify-content-between">
                    <h6 class="mb-1 text-truncate" style="max-width: 70%;">${activo.nombre_tipo_activo || 'N/A'} - ${activo.marca || ''}</h6>
                    <small>ID: ${activo.id}</small>
                </div>
                <p class="mb-1 small text-truncate">S/N: ${activo.serie || 'N/A'}</p>
                <small class="text-muted text-truncate d-block">Costo: $${new Intl.NumberFormat('es-CO').format(activo.valor_aproximado || 0)}</small>
            `;
            resultadosContainer.appendChild(item);
        });
    }

    // === LÓGICA FINANCIERA EXACTA (FÓRMULA CRUDA) ===
    function mostrarDetalles(activo) {
        const valorCompra = parseFloat(activo.valor_aproximado || 0);
        const fechaCompra = activo.fecha_compra;
        const vidaUtilAnios = parseFloat(activo.vida_util || 0);
        
        let depreciacion = {};
        let diasTranscurridos = 0;
        let aniosDepreciados = 0;
        
        // Aplica si tiene fecha, es mayor a 1990 y tiene vida útil
        if (fechaCompra && fechaCompra > '1990-01-01' && vidaUtilAnios > 0) {
            depreciacion.aplica = true;
            
            const partsInicio = fechaCompra.split('-');
            const fechaInicio = Date.UTC(partsInicio[0], partsInicio[1] - 1, partsInicio[2]);
            
            const partsCorte = FECHA_CORTE_CONTABLE.split('-');
            const fechaCorte = Date.UTC(partsCorte[0], partsCorte[1] - 1, partsCorte[2]);
            
            const MS_POR_DIA = 1000 * 60 * 60 * 24;
            
            if (fechaCorte > fechaInicio) {
                diasTranscurridos = Math.floor((fechaCorte - fechaInicio) / MS_POR_DIA);
            }

            // FÓRMULA DE FINANCIERA EXACTA
            aniosDepreciados = diasTranscurridos / 360;
            const depCalculada = (valorCompra / vidaUtilAnios) * aniosDepreciados;
            
            // TOPES: Nunca depreciar más del valor de compra, ni menos de 0
            depreciacion.depAcumulada = Math.min(valorCompra, Math.max(0, depCalculada));
            depreciacion.valorEnLibros = valorCompra - depreciacion.depAcumulada;
            
            if (fechaCorte <= fechaInicio) depreciacion.estado = 'No iniciada (Compra Posterior al Corte)';
            else if (depreciacion.valorEnLibros <= 0) depreciacion.estado = 'Totalmente Depreciado';
            else depreciacion.estado = 'En Curso';
            
        } else {
            depreciacion.aplica = false;
            depreciacion.mensaje_no_aplica = "El activo carece de Fecha de Compra válida o no tiene Vida Útil asignada en Base de Datos.";
            depreciacion.valorEnLibros = valorCompra;
        }

        const f = (num) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(num || 0);
        const escape = (str) => str ? String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;') : '';

        let htmlDetalles = `
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-bold text-primary border-bottom-0"><i class="bi bi-box-seam"></i> Ficha Técnica</div>
                <div class="card-body small">
                    <h5 class="card-title text-dark mb-0">${escape(activo.nombre_tipo_activo)} ${escape(activo.marca)}</h5>
                    <p class="text-muted mb-3">Serie: ${escape(activo.serie)}</p>
                    
                    <ul class="list-group list-group-flush mb-3">
                        <li class="list-group-item d-flex justify-content-between"><span>Responsable:</span> <span class="text-end text-truncate" style="max-width:180px;">${escape(activo.nombre_responsable)}</span></li>
                        <li class="list-group-item d-flex justify-content-between"><span>Fecha Compra:</span> <span class="fw-bold text-dark">${fechaCompra || 'N/A'}</span></li>
                        <li class="list-group-item d-flex justify-content-between"><span>Fecha de Corte:</span> <span>${FECHA_CORTE_CONTABLE}</span></li>
                        <li class="list-group-item d-flex justify-content-between bg-light"><span>Costo Histórico:</span> <strong class="text-primary">${f(valorCompra)}</strong></li>
                    </ul>
                    <p class="small text-info mb-0"><i class="bi bi-info-circle"></i> Vida útil: <strong>${vidaUtilAnios} años</strong> (Dato exacto de BD).</p>
                </div>
            </div>`;
        
        let htmlCalculo = `
            <div class="card card-depreciacion h-100 mt-3 shadow-sm border-0">
                <div class="card-header bg-white fw-bold text-success border-bottom-0"><i class="bi bi-calculator"></i> Resultado Contable (${new Date(FECHA_CORTE_CONTABLE + 'T00:00:00').getFullYear()})</div>
                <div class="card-body">`;
        
        if(depreciacion.aplica) {
            const porcentaje = ((valorCompra - depreciacion.valorEnLibros) / valorCompra) * 100;
            const colorBarra = porcentaje >= 100 ? 'bg-success' : 'bg-primary';
            
            htmlCalculo += `
                <div class="text-center mb-3">
                    <h2 class="fw-bold text-dark mb-0">${f(depreciacion.valorEnLibros)}</h2>
                    <small class="text-muted text-uppercase" style="font-size:0.7rem">Valor Neto en Libros</small>
                </div>

                <div class="progress mb-3" style="height: 15px;">
                    <div class="progress-bar ${colorBarra}" role="progressbar" style="width: ${porcentaje}%" aria-valuenow="${porcentaje}" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                
                <div class="row text-center mb-3 g-2">
                    <div class="col-12">
                        <div class="p-2 bg-light rounded border border-danger border-opacity-25">
                            <small class="d-block text-muted">Total Depreciado (Valor Consumido)</small>
                            <span class="fw-bold text-danger fs-5">${f(depreciacion.depAcumulada)}</span>
                        </div>
                    </div>
                </div>

                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between px-0"><span>Días Transcurridos:</span> <span>${diasTranscurridos} días</span></li>
                    <li class="list-group-item d-flex justify-content-between px-0"><span>Años Depreciados:</span> <span>${aniosDepreciados.toFixed(2)} años</span></li>
                </ul>
                
                <div class="mt-2 text-center">
                    <span class="badge bg-light text-dark border">${depreciacion.estado}</span>
                </div>`;
        } else {
            htmlCalculo += `<div class="alert alert-warning small">${depreciacion.mensaje_no_aplica}</div>
            <p class="text-center mt-3">Valor Neto Actual: <strong>${f(depreciacion.valorEnLibros)}</strong></p>`;
        }
        
        htmlCalculo += `</div></div>`;
        detallesContainer.innerHTML = `<div class="row g-3"><div class="col-lg-5">${htmlDetalles}</div><div class="col-lg-7">${htmlCalculo}</div></div>`;
    }
});
</script>
</body>
</html>