<?php
// =================================================================================
// ARCHIVO: dashboard.php
// ESTADO: CORREGIDO (Filtros Cédula y Centro Costo funcionales)
// =================================================================================

// 1. CONFIGURACIÓN
ini_set('display_errors', 0); 
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: login.php"); exit; }

require_once 'backend/auth_check.php'; 
verificar_permiso_o_morir('ver_dashboard');

require_once 'backend/db.php';

if (!isset($conexion) || $conexion->connect_error) { die("Error conexión BD"); }
$conexion->set_charset("utf8mb4");

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';

// 2. HELPER CONSULTAS
function consulta($con, $sql, $params = []) {
    $stmt = $con->prepare($sql);
    if(!$stmt) return false;
    if(!empty($params)) {
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function limpiar($s) { return $s ? mb_convert_encoding($s, 'UTF-8', 'UTF-8') : 'Sin Asignar'; }

// 3. FILTROS
$where = ["a.estado != 'Dado de Baja'"];
$params = [];
$ajax = isset($_GET['ajax']);

// Filtros de Gráficos (Clic)
if ($v = $_GET['filtro_regional'] ?? null) { $where[] = "u.regional = ?"; $params[] = $v; }
if ($v = $_GET['filtro_empresa'] ?? null) { $where[] = "u.empresa = ?"; $params[] = $v; }
if ($v = $_GET['filtro_tipo_activo'] ?? null) { $where[] = "ta.nombre_tipo_activo = ?"; $params[] = $v; }
if ($v = $_GET['filtro_categoria'] ?? null) { $where[] = "cat.nombre_categoria = ?"; $params[] = $v; }

// CORRECCIÓN 1: Agregado filtro de Centro de Costo (Usamos LIKE para mayor flexibilidad con nombres cortos)
if ($v = $_GET['filtro_centro_costo'] ?? null) { 
    $where[] = "cc.nombre_centro_costo LIKE ?"; 
    $params[] = "%" . $v . "%"; 
}

// FILTRO INTELIGENTE (Buscador Principal - Cédula)
if ($v = $_GET['filtro_cedula'] ?? null) { 
    // Busca coincidencia en Cédula, Código Regional (101) o Código Centro Costo (10101)
    $where[] = "(u.usuario = ? OR r.cod_regional = ? OR cc.cod_centro_costo = ?)";
    $params[] = $v;
    $params[] = $v;
    $params[] = $v;
}

$sql_where = " WHERE " . implode(" AND ", $where);

// JOINS MAESTROS
$joins = " FROM activos_tecnologicos a 
            LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id 
            LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
            LEFT JOIN categorias_activo cat ON ta.id_categoria = cat.id_categoria
            LEFT JOIN centros_costo cc ON a.id_centro_costo = cc.id_centro_costo
            LEFT JOIN regionales r ON cc.id_regional = r.id_regional ";

// 4. DATA FETCHING

// A. KPIs
$r = consulta($conexion, "SELECT COUNT(a.id) as T, SUM(a.valor_aproximado) as V $joins $sql_where", $params)->fetch_assoc();
$kpi_total = $r['T'] ?? 0;
$kpi_valor = $r['V'] ?? 0;

$r = consulta($conexion, "SELECT COUNT(DISTINCT a.id_usuario_responsable) as U $joins $sql_where", $params)->fetch_assoc();
$kpi_users = $r['U'] ?? 0;

// B. Estados
$est_data = [];
$res = consulta($conexion, "SELECT COALESCE(NULLIF(a.estado, ''), 'Sin Estado') as estado, COUNT(a.id) as cant $joins $sql_where GROUP BY estado ORDER BY cant DESC", $params);
while($row = $res->fetch_assoc()) $est_data[] = $row;

// C. GRÁFICOS GENERALES
$d_tipo = []; $l_tipo = [];
$res = consulta($conexion, "SELECT ta.nombre_tipo_activo as N, COUNT(a.id) as C $joins $sql_where GROUP BY ta.nombre_tipo_activo ORDER BY C DESC LIMIT 10", $params);
while($row = $res->fetch_assoc()) { $l_tipo[] = limpiar($row['N']); $d_tipo[] = $row['C']; }

$d_reg = []; $l_reg = [];
$res = consulta($conexion, "SELECT u.regional as N, COUNT(a.id) as C $joins $sql_where AND u.regional != '' GROUP BY u.regional ORDER BY C DESC", $params);
while($row = $res->fetch_assoc()) { $l_reg[] = limpiar($row['N']); $d_reg[] = $row['C']; }

$d_emp = []; $l_emp = [];
$res = consulta($conexion, "SELECT u.empresa as N, COUNT(a.id) as C $joins $sql_where AND u.empresa != '' GROUP BY u.empresa ORDER BY C DESC", $params);
while($row = $res->fetch_assoc()) { $l_emp[] = limpiar($row['N']); $d_emp[] = $row['C']; }

$d_cat = []; $l_cat = [];
$res = consulta($conexion, "SELECT cat.nombre_categoria as N, COUNT(a.id) as C $joins $sql_where GROUP BY cat.id_categoria ORDER BY C DESC", $params);
while($row = $res->fetch_assoc()) { $l_cat[] = limpiar($row['N']); $d_cat[] = $row['C']; }

// D. CENTROS DE COSTO (Top 15)
$cc_lbl = []; $cc_cant = [];
$res = consulta($conexion, "SELECT cc.nombre_centro_costo as N, COUNT(a.id) as C $joins $sql_where GROUP BY cc.id_centro_costo ORDER BY C DESC LIMIT 15", $params);
while($row = $res->fetch_assoc()) {
    $n = limpiar($row['N']);
    // Nota: Si abrevias aquí, asegúrate que el LIKE en el filtro PHP pueda encontrarlo
    $n = str_ireplace(['PRINCIPAL','BODEGA','CORRERIAS','CORRERIA'], ['Ppal','Bod','Corr','Corr'], $n);
    $cc_lbl[] = $n;
    $cc_cant[] = $row['C'];
}

// E. DETALLES ESPECÍFICOS
$comp_lbl = []; $comp_dat = [];
$res = consulta($conexion, "SELECT a.tipo_equipo as N, COUNT(a.id) as C $joins $sql_where AND ta.nombre_tipo_activo LIKE '%Computador%' GROUP BY a.tipo_equipo", $params);
while($row = $res->fetch_assoc()) { $comp_lbl[] = limpiar($row['N'] ?: 'No especificado'); $comp_dat[] = $row['C']; }

$imp_lbl = []; $imp_dat = [];
$res = consulta($conexion, "SELECT a.tipo_equipo as N, COUNT(a.id) as C $joins $sql_where AND ta.nombre_tipo_activo LIKE '%Impresora%' GROUP BY a.tipo_equipo", $params);
while($row = $res->fetch_assoc()) { $imp_lbl[] = limpiar($row['N'] ?: 'No especificado'); $imp_dat[] = $row['C']; }


// RETORNO AJAX
if ($ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'kpi' => ['total' => number_format($kpi_total), 'valor' => '$'.number_format($kpi_valor,0,',','.'), 'users' => number_format($kpi_users)],
        'estados' => $est_data,
        'charts' => [
            'tipo' => ['l' => $l_tipo, 'd' => $d_tipo],
            'reg' => ['l' => $l_reg, 'd' => $d_reg],
            'emp' => ['l' => $l_emp, 'd' => $d_emp],
            'cat' => ['l' => $l_cat, 'd' => $d_cat],
            'cc'  => ['l' => $cc_lbl, 'd' => $cc_cant],
            'comp'=> ['l' => $comp_lbl, 'd' => $comp_dat],
            'imp' => ['l' => $imp_lbl, 'd' => $imp_dat]
        ]
    ]);
    exit;
}

// DATOS INICIALES
$init_data = json_encode([
    'kpi' => ['total' => number_format($kpi_total), 'valor' => '$'.number_format($kpi_valor,0,',','.'), 'users' => number_format($kpi_users)],
    'estados' => $est_data,
    'charts' => [
        'tipo' => ['l' => $l_tipo, 'd' => $d_tipo],
        'reg' => ['l' => $l_reg, 'd' => $d_reg],
        'emp' => ['l' => $l_emp, 'd' => $d_emp],
        'cat' => ['l' => $l_cat, 'd' => $d_cat],
        'cc'  => ['l' => $cc_lbl, 'd' => $cc_cant],
        'comp'=> ['l' => $comp_lbl, 'd' => $comp_dat],
        'imp' => ['l' => $imp_lbl, 'd' => $imp_dat]
    ]
], JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Gerencial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f4f7fa; font-family: 'Segoe UI', sans-serif; padding-top: 120px; padding-bottom: 60px; }
        
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; background: #fff; border-bottom: 1px solid #e1e4e8; padding: 0.6rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        
        .kpi-card { background: #fff; border-radius: 12px; padding: 1.5rem; position: relative; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f0f0f0; height: 100%; transition: transform 0.2s; }
        .kpi-card:hover { transform: translateY(-3px); }
        .kpi-icon { font-size: 2.2rem; margin-bottom: 0.5rem; color: #191970; opacity: 0.9; }
        .kpi-value { font-size: 1.8rem; font-weight: 800; color: #2c3e50; line-height: 1.2; }
        .kpi-label { color: #6c757d; font-size: 0.9rem; font-weight: 600; text-transform: uppercase; }
        
        .chart-box { background: #fff; border-radius: 12px; padding: 1.5rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f0f0f0; margin-bottom: 1.5rem; }
        .chart-title { font-size: 1.05rem; font-weight: 700; color: #2d3748; margin-bottom: 1rem; text-align: center; }
        .chart-wrapper { position: relative; height: 260px; width: 100%; }
        
        .estado-item { background: #f8f9fa; padding: 8px; border-radius: 8px; text-align: center; border: 1px solid #e9ecef; flex: 1; margin: 0 4px; min-width: 80px; }
        .estado-val { display: block; font-weight: 800; font-size: 1.3rem; }
        
        .text-success { color: #198754 !important; } 
        .text-warning { color: #ffc107 !important; } 
        .text-danger { color: #dc3545 !important; }
        .text-primary { color: #0d6efd !important; }
        
        .footer-custom { background: #fff; border-top: 1px solid #e1e4e8; padding: 1.2rem; position: fixed; bottom: 0; width: 100%; z-index: 1000; text-align: center; color: #a0aec0; }
    </style>
</head>
<body>

<div class="top-bar-custom">
    <div class="d-flex align-items-center">
        <a href="menu.php"><img src="imagenes/logo.png" height="75" alt="Logo"></a>
        
        <div class="ms-4 d-flex">
            <input type="text" id="filtroCedula" class="form-control form-control-sm me-2" placeholder="Buscar: Cédula, 101, 10101..." style="width: 220px;">
            <button class="btn btn-primary btn-sm" onclick="buscarCedula()"><i class="bi bi-search"></i></button>
        </div>
    </div>
    
    <div>
        <span class="me-3 fw-bold text-secondary"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?></span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-4">Salir</a>
    </div>
</div>

<div class="container-fluid px-4 mb-5">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 id="subtitulo" class="text-secondary m-0"></h5>
        
        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" id="btnReset" onclick="resetFiltros()" style="display:none;">
            <i class="bi bi-arrow-counterclockwise"></i> Quitar Filtros
        </button>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="d-flex align-items-center">
                    <div class="fs-1 me-3 text-primary"><i class="bi bi-box-seam"></i></div>
                    <div><div class="kpi-value" id="kpiTotal">--</div><div class="kpi-label">Activos</div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="d-flex align-items-center">
                    <div class="fs-1 me-3 text-success"><i class="bi bi-cash-stack"></i></div>
                    <div><div class="kpi-value" id="kpiValor">--</div><div class="kpi-label">Valor Total</div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="d-flex align-items-center">
                    <div class="fs-1 me-3 text-info"><i class="bi bi-people"></i></div>
                    <div><div class="kpi-value" id="kpiUsers">--</div><div class="kpi-label">Responsables</div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card py-2">
                <div class="kpi-label mb-2">Estado General</div>
                <div class="d-flex" id="kpiEstados"></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="chart-box">
                <h5 class="chart-title"><i class="bi bi-laptop"></i> Computadores</h5>
                <div class="chart-wrapper"><canvas id="chartComp"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-box">
                <h5 class="chart-title"><i class="bi bi-printer"></i> Impresoras</h5>
                <div class="chart-wrapper"><canvas id="chartImp"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="chart-box">
                <h5 class="chart-title">📊 Activos por Centro de Costo (Top 15)</h5>
                <div class="chart-wrapper" style="height:350px;"><canvas id="chartCC"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-box">
                <h5 class="chart-title">📂 Categorías</h5>
                <div class="chart-wrapper" style="height:350px;"><canvas id="chartCat"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="chart-box">
                <h5 class="chart-title">🌍 Regionales</h5>
                <div class="chart-wrapper"><canvas id="chartReg"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-box">
                <h5 class="chart-title">📦 Tipos de Activo</h5>
                <div class="chart-wrapper"><canvas id="chartTipo"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-box">
                <h5 class="chart-title">🏢 Empresas</h5>
                <div class="chart-wrapper"><canvas id="chartEmp"></canvas></div>
            </div>
        </div>
    </div>

</div>

<footer class="footer-custom"><small>Sistema de Gestión v3.0 &copy; <?= date('Y') ?></small></footer>

<script>
const db = <?= $init_data ?>;
const palette = ['#4361ee', '#7209b7', '#4cc9f0', '#2ecc71', '#f39c12', '#e74c3c', '#95a5a6'];
let charts = {};

function init() {
    updateKPIs(db.kpi, db.estados);

    // 1. Computadores & Impresoras
    charts.comp = newChart('chartComp', 'doughnut', db.charts.comp.l, db.charts.comp.d, {cutout:'60%'});
    charts.imp = newChart('chartImp', 'pie', db.charts.imp.l, db.charts.imp.d);

    // 2. Centros de Costo - CORRECCIÓN 3: Agregado clickFilter para que filtre al hacer clic
    charts.cc = newChart('chartCC', 'bar', db.charts.cc.l, db.charts.cc.d, {indexAxis:'y', label:'Cantidad', clickFilter:'centro_costo'});

    // 3. Categorías
    charts.cat = newChart('chartCat', 'doughnut', db.charts.cat.l, db.charts.cat.d, {cutout:'70%', clickFilter:'categoria'});

    // 4. Regionales
    charts.reg = newChart('chartReg', 'bar', db.charts.reg.l, db.charts.reg.d, {indexAxis:'y', clickFilter:'regional'});

    // 5. Tipos
    charts.tipo = newChart('chartTipo', 'bar', db.charts.tipo.l, db.charts.tipo.d, {clickFilter:'tipo_activo'});

    // 6. Empresas
    charts.emp = newChart('chartEmp', 'doughnut', db.charts.emp.l, db.charts.emp.d, {clickFilter:'empresa', customColors: true});
}

function newChart(id, type, labels, data, opts={}) {
    const ctx = document.getElementById(id);
    if(!ctx) return null;

    let bgColors = palette;
    if(opts.customColors) {
        bgColors = labels.map(l => {
            const t = l.toLowerCase();
            if(t.includes('arpesod')) return '#D52B1E'; 
            if(t.includes('finansueños')) return '#191970'; 
            return '#ccc';
        });
    }

    const cfg = {
        type: type,
        data: {
            labels: labels,
            datasets: [{
                label: opts.label || 'Cantidad',
                data: data,
                backgroundColor: bgColors,
                borderRadius: 5,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: opts.indexAxis || 'x',
            plugins: {
                legend: { display: (type !== 'bar'), position: 'bottom', labels:{boxWidth:12} }
            },
            scales: (type === 'bar') ? {
                x: { grid: {display: false} },
                y: { grid: {color: '#f1f5f9'} }
            } : {}
        }
    };

    if(opts.clickFilter) {
        cfg.options.onClick = (e, el, chart) => {
            if(!el.length) return;
            const idx = el[0].index;
            const val = chart.data.labels[idx];
            aplicarFiltro(opts.clickFilter, val);
        };
        ctx.style.cursor = 'pointer';
    }
    if(opts.cutout) cfg.options.cutout = opts.cutout;

    return new Chart(ctx, cfg);
}

function updateKPIs(kpi, estados) {
    document.getElementById('kpiTotal').innerText = kpi.total;
    document.getElementById('kpiValor').innerText = kpi.valor;
    document.getElementById('kpiUsers').innerText = kpi.users;

    const estDiv = document.getElementById('kpiEstados');
    estDiv.innerHTML = '';
    const map = {'Bueno':'bg-success text-white', 'Regular':'bg-warning text-dark', 'Malo':'bg-danger text-white'};
    
    estados.forEach(item => {
        const colorClass = map[item.estado] || 'bg-primary text-white';
        estDiv.innerHTML += `
            <div class="estado-item ${colorClass}">
                <small class="d-block opacity-75">${item.estado}</small>
                <span class="estado-val">${item.cant}</span>
            </div>`;
    });
    if(estados.length === 0) estDiv.innerHTML = '<small class="text-muted w-100 text-center">Sin datos</small>';
}

function buscarCedula() {
    const val = document.getElementById('filtroCedula').value.trim();
    if(val) aplicarFiltro('cedula', val);
}

document.getElementById('filtroCedula').addEventListener('keypress', function (e) {
    if (e.key === 'Enter') buscarCedula();
});

function aplicarFiltro(tipo, valor) {
    // 1. Mostrar botón reset
    const btn = document.getElementById('btnReset');
    if(btn) btn.style.display = 'block';

    // 2. Mostrar subtítulo (Evitamos error si no existe, aunque ya lo agregamos)
    const sub = document.getElementById('subtitulo');
    if(sub) sub.innerHTML = `Filtro activo: <b>${valor}</b>`;
    
    fetch(`dashboard.php?ajax=1&filtro_${tipo}=${encodeURIComponent(valor)}`)
    .then(r => r.json())
    .then(d => {
        updateKPIs(d.kpi, d.estados);
        
        upd(charts.comp, d.charts.comp);
        upd(charts.imp, d.charts.imp);
        upd(charts.cc, d.charts.cc);
        upd(charts.reg, d.charts.reg);
        upd(charts.cat, d.charts.cat);
        upd(charts.tipo, d.charts.tipo);
        upd(charts.emp, d.charts.emp);
    })
    .catch(err => console.error("Error al filtrar:", err));
}

function upd(chart, dataObj) {
    if(!chart) return;
    chart.data.labels = dataObj.l;
    chart.data.datasets[0].data = dataObj.d;
    
    if(chart.canvas.id === 'chartEmp') {
        chart.data.datasets[0].backgroundColor = dataObj.l.map(l => {
            const t = l.toLowerCase();
            if(t.includes('arpesod')) return '#D52B1E';
            if(t.includes('finansueños')) return '#191970';
            return '#ccc';
        });
    }
    chart.update();
}

function resetFiltros() { window.location.href = 'dashboard.php'; }

init();
</script>
</body>
</html>