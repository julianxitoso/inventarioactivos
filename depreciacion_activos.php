<?php
// =================================================================================
// ARCHIVO: depreciacion.php
// DESCRIPCIÓN: Análisis Contable con Histórico SMMLV (2010-2026)
// ESTADO: FINAL (Lógica Histórica Retroactiva)
// =================================================================================

// 1. CONFIGURACIÓN Y SEGURIDAD
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

// Validar conexión
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }

$error_conexion_db = null;
$opciones_tipos = [];
$regionales = []; 
$empresas_disponibles = ['Arpesod', 'Finansueños'];

if (!isset($conexion) || (method_exists($conexion, 'connect_error') && $conexion->connect_error) || !$conexion) {
    $error_conexion_db = "Error crítico de conexión a la base de datos. Funcionalidad limitada.";
    error_log("Fallo CRÍTICO de conexión a BD (depreciacion.php): " . ($conexion->connect_error ?? 'Desconocido'));
} else {
    $conexion->set_charset("utf8mb4");
    
    // Cargar Tipos de Activo
    $result_tipos = $conexion->query("SELECT id_tipo_activo, nombre_tipo_activo FROM tipos_activo ORDER BY nombre_tipo_activo");
    if ($result_tipos) {
        $opciones_tipos = $result_tipos->fetch_all(MYSQLI_ASSOC);
    }

    // Cargar Regionales desde BD
    $result_reg = $conexion->query("SELECT nombre_regional FROM regionales ORDER BY nombre_regional");
    if ($result_reg) {
        while($row = $result_reg->fetch_assoc()) {
            $regionales[] = $row['nombre_regional'];
        }
    }
}

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

// --- HISTÓRICO DE SALARIOS MÍNIMOS (COLOMBIA 2010-2026) ---
// Fuente: Decretos Gobierno Nacional
$historico_smmlv = [
    2010 => 515000,
    2011 => 535600,
    2012 => 566700,
    2013 => 589500,
    2014 => 616000,
    2015 => 644350,
    2016 => 689455,
    2017 => 737717,
    2018 => 781242,
    2019 => 828116,
    2020 => 877803,
    2021 => 908526,
    2022 => 1000000,
    2023 => 1160000,
    2024 => 1300000,
    2025 => 1423500, // Valor Oficial 2025
    2026 => 1550000, // Proyección
    'default' => 1423500 // Valor por defecto si no encuentra año
];

define('UMBRAL_DEPRECIACION', 1); // Activos < 1 SMMLV del año de compra son Gasto
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Análisis de Depreciación de Activos</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        html { height: 100%; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            padding-top: 80px; 
            background-color: #f4f6f9; 
            display: flex; 
            flex-direction: column; 
            min-height: 100vh; 
        }
        .main-container { flex-grow: 1; }
        .top-bar-custom { 
            position: fixed; top: 0; left: 0; right: 0; z-index: 1030; 
            display: flex; justify-content: space-between; align-items: center; 
            padding: 0.5rem 1.5rem; background-color: #ffffff; 
            border-bottom: 1px solid #dee2e6; box-shadow: 0 1px 3px rgba(0,0,0,0.05); 
        }
        .logo-container-top img { height: 75px; }
        .page-header-title { color: #0d6efd; font-weight: 600; }
        .accordion-button:not(.collapsed) { color: #ffffff; background-color: #0d6efd; }
        .accordion-button:focus { box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25); }
        .loader { border: 5px solid #f3f3f3; border-radius: 50%; border-top: 5px solid #0d6efd; width: 40px; height: 40px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        #columna-resultados .list-group-item { cursor: pointer; border-radius: .5rem; margin-bottom: 5px; border: 1px solid #ddd;}
        #columna-resultados .list-group-item:hover { background-color: #e9ecef; }
        #columna-resultados .list-group-item.active { background-color: #0d6efd; border-color: #0d6efd; color: white; }
        #columna-detalles { background-color: #ffffff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 5px rgba(0,0,0,0.08); min-height: 500px; }
        .card-depreciacion { border-left: 4px solid #0d6efd; }
        .footer-custom {
            font-size: 0.9rem; background-color: #f8f9fa; 
            border-top: 1px solid #dee2e6; padding: 1rem 0; margin-top: auto;
        }
        .footer-custom a i { color: #6c757d; transition: color 0.2s; }
        .footer-custom a i:hover { color: #0d6efd !important; }
    </style>
</head>
<body>
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
                                        <option value="proximo">Próximo a Vencer (6m)</option>
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

<footer class="footer-custom mt-auto py-3 bg-light border-top shadow-sm">
    <div class="container text-center">
        <div class="row align-items-center">
            <div class="col-md-6 text-md-start mb-2 mb-md-0">
                <small class="text-muted">Sitio web desarrollado por <a href="https://www.julianxitoso.com" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-primary">@julianxitoso.com</a></small>
            </div>
            <div class="col-md-6 text-md-end">
                <a href="#" target="_blank" class="text-muted me-3"><i class="bi bi-facebook" style="font-size: 1.5rem;"></i></a>
                <a href="#" target="_blank" class="text-muted me-3"><i class="bi bi-instagram" style="font-size: 1.5rem;"></i></a>
                <a href="#" target="_blank" class="text-muted"><i class="bi bi-tiktok" style="font-size: 1.5rem;"></i></a>
            </div>
        </div>
    </div>
</footer>

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

    // --- DATOS HISTÓRICOS INYECTADOS DESDE PHP ---
    const historicoSMMLV = <?= json_encode($historico_smmlv) ?>;
    const UMBRAL_SMMLV = <?= UMBRAL_DEPRECIACION ?>;

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
                <small class="text-muted text-truncate d-block">Resp: ${activo.nombre_responsable || 'Sin asignar'}</small>
            `;
            resultadosContainer.appendChild(item);
        });
    }

    // === LÓGICA FINANCIERA HISTÓRICA (SMMLV DEL AÑO DE COMPRA) ===
    function mostrarDetalles(activo) {
        const valorCompra = parseFloat(activo.valor_aproximado || 0);
        const valorResidual = parseFloat(activo.valor_residual || 0);
        const fechaCompra = activo.fecha_compra;
        const tipoActivo = (activo.nombre_tipo_activo || '').toLowerCase();

        // 1. Determinar Salario Mínimo del Año de Compra
        let anioCompra = 2025; // Default
        let salarioAplicable = historicoSMMLV['default'];
        
        if (fechaCompra) {
            anioCompra = new Date(fechaCompra).getFullYear();
            // Si el año es muy viejo (antes de 2010), usamos 2010 como base
            if (anioCompra < 2010) salarioAplicable = historicoSMMLV[2010];
            else salarioAplicable = historicoSMMLV[anioCompra] || historicoSMMLV['default'];
        }

        // 2. Determinar Vida Útil Fiscal (Reglas Negocio)
        let vidaUtilAnios = parseInt(activo.vida_util_sugerida || 0, 10);
        let mensajeFiscal = '';

        if (tipoActivo.includes('computador') || tipoActivo.includes('portatil') || tipoActivo.includes('todo en 1')) {
            vidaUtilAnios = 5; 
            mensajeFiscal = '<p class="small text-info mb-0"><i class="bi bi-info-circle"></i> Vida útil fiscal: 5 años (Tecnología).</p>';
        } else if (tipoActivo.includes('celular') || tipoActivo.includes('telefono')) {
            vidaUtilAnios = 5; 
            mensajeFiscal = '<p class="small text-info mb-0"><i class="bi bi-info-circle"></i> Vida útil fiscal: 5 años (Comunicaciones).</p>';
        } else if (tipoActivo.includes('vehiculo') || tipoActivo.includes('carro') || tipoActivo.includes('moto')) {
            vidaUtilAnios = 10; 
            mensajeFiscal = '<p class="small text-info mb-0"><i class="bi bi-info-circle"></i> Vida útil fiscal: 10 años (Flota).</p>';
        } else if (tipoActivo.includes('mueble') || tipoActivo.includes('silla') || tipoActivo.includes('escritorio')) {
            vidaUtilAnios = 10; 
            mensajeFiscal = '<p class="small text-info mb-0"><i class="bi bi-info-circle"></i> Vida útil fiscal: 10 años (Muebles).</p>';
        }

        let depreciacion = {};
        let mesesTranscurridos = 0;
        
        // Calcular valor en Salarios Mínimos (Usando el del año de compra)
        const valorEnSalarios = salarioAplicable > 0 ? (valorCompra / salarioAplicable) : 0;
        
        // --- CASO 1: MENOR CUANTÍA (< 1 SMMLV DEL AÑO COMPRA) ---
        if (valorEnSalarios < UMBRAL_SMMLV) {
            depreciacion.esDeduccionDirecta = true;
            depreciacion.mensaje_especial = `Activo de Menor Cuantía (< 1 SMMLV de ${anioCompra}). Se deprecia al 100% en el año de compra.`;
            depreciacion.valorADeducir = valorCompra;
            depreciacion.valorEnLibros = 0;
            depreciacion.depAcumulada = valorCompra;
            depreciacion.depMensual = 0;
            depreciacion.estado = 'Gasto Directo (Depreciado)';
        } 
        // --- CASO 2: ACTIVO FIJO DEPRECIABLE ---
        else if (fechaCompra && vidaUtilAnios > 0) {
            depreciacion.aplica = true;
            depreciacion.esDeduccionDirecta = false;
            
            const fechaInicio = new Date(fechaCompra + 'T00:00:00');
            const fechaActual = new Date();
            
            // Calcular meses de uso
            if (fechaActual >= fechaInicio) {
                mesesTranscurridos = (fechaActual.getFullYear() - fechaInicio.getFullYear()) * 12 + (fechaActual.getMonth() - fechaInicio.getMonth());
                if (fechaActual.getDate() < fechaInicio.getDate()) {
                   mesesTranscurridos--; 
                }
                if (mesesTranscurridos < 0) mesesTranscurridos = 0;
            }

            const vidaUtilMeses = vidaUtilAnios * 12;
            const valorBase = Math.max(0, valorCompra - valorResidual);
            
            // Línea Recta
            depreciacion.depMensual = valorBase / vidaUtilMeses;
            
            const mesesEfectivos = Math.min(mesesTranscurridos, vidaUtilMeses);
            depreciacion.depAcumulada = depreciacion.depMensual * mesesEfectivos;
            
            if (depreciacion.depAcumulada > valorBase) depreciacion.depAcumulada = valorBase;

            depreciacion.valorEnLibros = valorCompra - depreciacion.depAcumulada;
            if (depreciacion.valorEnLibros < valorResidual) depreciacion.valorEnLibros = valorResidual;

            depreciacion.mesesRestantes = Math.max(0, vidaUtilMeses - mesesTranscurridos);
            
            if (fechaActual < fechaInicio) depreciacion.estado = 'No iniciada (Fecha futura)';
            else if (depreciacion.valorEnLibros <= valorResidual) depreciacion.estado = 'Totalmente Depreciado';
            else depreciacion.estado = 'En Curso';
            
        } else {
            // CASO 3: DATOS FALTANTES
            depreciacion.aplica = false;
            depreciacion.mensaje_no_aplica = "Faltan datos clave (Fecha Compra o Vida Útil) para calcular.";
            depreciacion.valorEnLibros = valorCompra;
        }

        // RENDER HTML
        const f = (num) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(num || 0);
        const f2 = (num) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(num || 0);
        const escape = (str) => str ? String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;') : '';

        let htmlDetalles = `
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-bold text-primary border-bottom-0"><i class="bi bi-box-seam"></i> Ficha Técnica</div>
                <div class="card-body small">
                    <h5 class="card-title text-dark mb-0">${escape(activo.nombre_tipo_activo)} ${escape(activo.marca)}</h5>
                    <p class="text-muted mb-3">${escape(activo.serie)}</p>
                    
                    <ul class="list-group list-group-flush mb-3">
                        <li class="list-group-item d-flex justify-content-between"><span>Responsable:</span> <span class="text-end text-truncate" style="max-width:180px;">${escape(activo.nombre_responsable)}</span></li>
                        <li class="list-group-item d-flex justify-content-between"><span>Fecha Compra:</span> <span>${fechaCompra || 'N/A'}</span></li>
                        <li class="list-group-item d-flex justify-content-between bg-light"><span>Costo Histórico:</span> <strong>${f(valorCompra)}</strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>SMMLV (${anioCompra}):</span> <span>${f2(salarioAplicable)}</span></li>
                        <li class="list-group-item d-flex justify-content-between"><span>Valor en Salarios:</span> <span>${valorEnSalarios.toFixed(2)} SMMLV</span></li>
                    </ul>
                    ${mensajeFiscal}
                </div>
            </div>`;
        
        let htmlCalculo = `
            <div class="card card-depreciacion h-100 mt-3 shadow-sm border-0">
                <div class="card-header bg-white fw-bold text-success border-bottom-0"><i class="bi bi-calculator"></i> Resultado Contable (${new Date().getFullYear()})</div>
                <div class="card-body">`;
        
        if (depreciacion.esDeduccionDirecta) {
            htmlCalculo += `
                <div class="alert alert-info border-0 shadow-sm">
                    <h6 class="alert-heading mb-1"><i class="bi bi-lightning-fill"></i> Gasto Directo</h6>
                    <small>${depreciacion.mensaje_especial}</small>
                </div>
                <div class="text-center my-4">
                    <h1 class="display-5 fw-bold text-secondary">${f(0)}</h1>
                    <p class="text-muted small">Valor Neto en Libros</p>
                </div>
                <div class="d-grid"><button class="btn btn-light btn-sm disabled">Depreciado 100%</button></div>`;
        } else if(depreciacion.aplica) {
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
                <div class="d-flex justify-content-between small text-muted mb-3">
                    <span>0%</span>
                    <span>Avance Depreciación: ${porcentaje.toFixed(1)}%</span>
                    <span>100%</span>
                </div>
                
                <div class="row text-center mb-3 g-2">
                    <div class="col-6">
                        <div class="p-2 bg-light rounded">
                            <small class="d-block text-muted">Acumulada</small>
                            <span class="fw-bold text-danger">${f(depreciacion.depAcumulada)}</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 bg-light rounded">
                            <small class="d-block text-muted">Mensual</small>
                            <span class="fw-bold text-dark">${f(depreciacion.depMensual)}</span>
                        </div>
                    </div>
                </div>

                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between px-0"><span>Vida Útil:</span> <span>${vidaUtilAnios} años (${vidaUtilAnios*12} m)</span></li>
                    <li class="list-group-item d-flex justify-content-between px-0"><span>Transcurrido:</span> <span>${mesesTranscurridos} meses</span></li>
                </ul>
                
                <div class="mt-2 text-center">
                    <span class="badge bg-light text-dark border">${depreciacion.estado}</span>
                </div>`;
        } else {
            htmlCalculo += `<div class="alert alert-warning small">${depreciacion.mensaje_no_aplica}</div>
            <p class="text-center mt-3">Valor en Libros: <strong>${f(depreciacion.valorEnLibros)}</strong></p>`;
        }
        
        htmlCalculo += `</div></div>`;
        detallesContainer.innerHTML = `<div class="row g-3"><div class="col-lg-5">${htmlDetalles}</div><div class="col-lg-7">${htmlCalculo}</div></div>`;
    }
});
</script>

</body>
</html>