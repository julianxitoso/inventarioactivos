<?php
// =================================================================================
// ARCHIVO: dashboard.php
// ESTADO: FÓRMULA FINANCIERA EXACTA (Sin topes mínimos, Días/360, Filtro Año 0)
// =================================================================================

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

$listas = [];
if (!isset($_GET['ajax'])) {
    $r = $conexion->query("SELECT DISTINCT empresa FROM usuarios WHERE empresa != '' ORDER BY empresa");
    $listas['empresas'] = $r->fetch_all(MYSQLI_ASSOC);
    
    $r = $conexion->query("SELECT nombre_regional FROM regionales ORDER BY nombre_regional");
    $listas['regionales'] = $r->fetch_all(MYSQLI_ASSOC);
    
    $r = $conexion->query("SELECT nombre_categoria FROM categorias_activo ORDER BY nombre_categoria");
    $listas['categorias'] = $r->fetch_all(MYSQLI_ASSOC);

    $sql_cc = "SELECT DISTINCT cc.nombre_centro_costo, cc.cod_centro_costo 
               FROM centros_costo cc
               INNER JOIN activos_tecnologicos a ON cc.id_centro_costo = a.id_centro_costo
               WHERE a.estado != 'Dado de Baja' ORDER BY cc.nombre_centro_costo";
    $r = $conexion->query($sql_cc);
    $listas['centros'] = $r->fetch_all(MYSQLI_ASSOC);
}

$where = ["a.estado != 'Dado de Baja'"];
$params = [];
$ajax = isset($_GET['ajax']);

if ($v = $_GET['filtro_empresa'] ?? null) { $where[] = "u.empresa = ?"; $params[] = $v; }
if ($v = $_GET['filtro_regional'] ?? null) { $where[] = "r.nombre_regional = ?"; $params[] = $v; }
if ($v = $_GET['filtro_categoria'] ?? null) { $where[] = "cat.nombre_categoria = ?"; $params[] = $v; }
if ($v = $_GET['filtro_centro_costo'] ?? null) { $where[] = "cc.nombre_centro_costo = ?"; $params[] = $v; } 
if ($v = $_GET['filtro_tipo_activo'] ?? null) { $where[] = "ta.nombre_tipo_activo = ?"; $params[] = $v; }

if ($v = $_GET['filtro_cedula'] ?? null) { 
    $where[] = "(u.usuario = ? OR r.cod_regional = ? OR cc.cod_centro_costo = ?)";
    $params[] = $v; $params[] = $v; $params[] = $v;
}

$sql_where = " WHERE " . implode(" AND ", $where);

$joins = " FROM activos_tecnologicos a 
            LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id 
            LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
            LEFT JOIN categorias_activo cat ON ta.id_categoria = cat.id_categoria
            LEFT JOIN centros_costo cc ON a.id_centro_costo = cc.id_centro_costo
            LEFT JOIN regionales r ON cc.id_regional = r.id_regional ";

// DATOS BÁSICOS
$r = consulta($conexion, "SELECT COUNT(a.id) as T, SUM(a.valor_aproximado) as V $joins $sql_where", $params)->fetch_assoc();
$kpi_total = $r['T'] ?? 0;
$kpi_valor = $r['V'] ?? 0;

$r = consulta($conexion, "SELECT COUNT(DISTINCT a.id_usuario_responsable) as U $joins $sql_where", $params)->fetch_assoc();
$kpi_users = $r['U'] ?? 0;

// =========================================================================
// KPI FINANCIEROS (REPLICANDO EXACTAMENTE LA FÓRMULA FINANCIERA)
// =========================================================================
$fecha_corte_contable = '2025-12-31';
$params_dep = $params; 

// TOTAL DEPRECIADO: (Valor de compra / vida util años) * Años depreciados
// AÑOS DEPRECIADOS: (Fecha corte - Fecha compra) / 360
// *Nota: Se ignoran las fechas nulas o '0000-00-00' para que no den 2000 años de depreciación.
$sql_dep_acumulada = "
SELECT SUM(
    CASE 
        WHEN a.vida_util IS NULL OR a.vida_util <= 0 THEN 0
        WHEN a.fecha_compra IS NULL OR a.fecha_compra <= '1990-01-01' THEN 0
        ELSE LEAST(
            a.valor_aproximado, 
            (a.valor_aproximado / a.vida_util) * (GREATEST(0, DATEDIFF('$fecha_corte_contable', a.fecha_compra)) / 360)
        )
    END
) as TotalDepreciado
$joins 
$sql_where 
";
$r_dep_acum = consulta($conexion, $sql_dep_acumulada, $params_dep)->fetch_assoc();
$kpi_total_depreciado = $r_dep_acum['TotalDepreciado'] ?? 0;

// VALOR NETO EN LIBROS: Lo que costaron menos lo que se depreció
$kpi_depreciables = $kpi_valor - $kpi_total_depreciado;

$est_lbl = []; $est_dat = [];
$res = consulta($conexion, "SELECT COALESCE(NULLIF(a.estado, ''), 'Sin Estado') as N, COUNT(a.id) as C $joins $sql_where GROUP BY estado ORDER BY C DESC", $params);
while($row = $res->fetch_assoc()) { $est_lbl[] = limpiar($row['N']); $est_dat[] = $row['C']; }

$d_cat_cant = []; $l_cat_cant = [];
$res = consulta($conexion, "SELECT cat.nombre_categoria as N, COUNT(a.id) as C $joins $sql_where GROUP BY cat.id_categoria ORDER BY C DESC LIMIT 8", $params);
while($row = $res->fetch_assoc()) { $l_cat_cant[] = limpiar($row['N']); $d_cat_cant[] = $row['C']; }

$d_cat_val = []; $l_cat_val = [];
$res = consulta($conexion, "SELECT cat.nombre_categoria as N, SUM(a.valor_aproximado) as C $joins $sql_where GROUP BY cat.id_categoria ORDER BY C DESC LIMIT 8", $params);
while($row = $res->fetch_assoc()) { $l_cat_val[] = limpiar($row['N']); $d_cat_val[] = $row['C']; }

$d_trend = []; $l_trend = [];
$sql_trend = "SELECT YEAR(a.fecha_compra) as anio, COUNT(a.id) as C $joins $sql_where AND a.fecha_compra IS NOT NULL AND a.fecha_compra > '1990-01-01' GROUP BY anio HAVING anio > 2015 ORDER BY anio ASC";
$res = consulta($conexion, $sql_trend, $params);
while($row = $res->fetch_assoc()) { $l_trend[] = $row['anio']; $d_trend[] = $row['C']; }

$cc_lbl = []; $cc_cant = [];
$res = consulta($conexion, "SELECT cc.nombre_centro_costo as N, COUNT(a.id) as C $joins $sql_where GROUP BY cc.id_centro_costo ORDER BY C DESC LIMIT 10", $params);
while($row = $res->fetch_assoc()) {
    $n = limpiar($row['N']);
    $n = str_ireplace(['PRINCIPAL','BODEGA','CORRERIAS','CORRERIA'], ['Ppal','Bod','Corr','Corr'], $n);
    $cc_lbl[] = $n; $cc_cant[] = $row['C'];
}

$d_reg = []; $l_reg = [];
$res = consulta($conexion, "SELECT r.nombre_regional as N, COUNT(a.id) as C $joins $sql_where AND r.nombre_regional IS NOT NULL GROUP BY r.nombre_regional ORDER BY C DESC", $params);
while($row = $res->fetch_assoc()) { $l_reg[] = limpiar($row['N']); $d_reg[] = $row['C']; }

$d_emp = []; $l_emp = [];
$res = consulta($conexion, "SELECT u.empresa as N, COUNT(a.id) as C $joins $sql_where AND u.empresa != '' GROUP BY u.empresa ORDER BY C DESC", $params);
while($row = $res->fetch_assoc()) { $l_emp[] = limpiar($row['N']); $d_emp[] = $row['C']; }

$payload = [
    'kpi' => [
        'total' => number_format($kpi_total), 
        'valor' => '$'.number_format($kpi_valor,0,',','.'), 
        'users' => number_format($kpi_users),
        'depreciables' => '$'.number_format($kpi_depreciables,0,',','.'), 
        'depreciado' => '$'.number_format($kpi_total_depreciado,0,',','.') 
    ],
    'charts' => [
        'estado' => ['l' => $est_lbl, 'd' => $est_dat],
        'cat_cant' => ['l' => $l_cat_cant, 'd' => $d_cat_cant],
        'cat_val' => ['l' => $l_cat_val, 'd' => $d_cat_val],
        'trend' => ['l' => $l_trend, 'd' => $d_trend],
        'cc'  => ['l' => $cc_lbl, 'd' => $cc_cant],
        'reg' => ['l' => $l_reg, 'd' => $d_reg],
        'emp' => ['l' => $l_emp, 'd' => $d_emp]
    ]
];

if ($ajax) {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

$init_data = json_encode($payload, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Corporativo</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
</head>
<body class="page-dashboard">

<div class="top-bar-custom">
    <div class="d-flex align-items-center">
        <a href="menu.php"><img src="imagenes/logo.png" height="75" alt="Logo"></a>
        <h4 class="ms-3 mb-0 text-secondary d-none d-md-block" style="font-weight: 600;">Control de Activos</h4>
    </div>
    <div>
        <span class="me-3 fw-bold text-secondary"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?></span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-4">Salir</a>
    </div>
</div>

<div class="container-fluid px-4 mb-5">
    
    <div class="filter-bar">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="small fw-bold text-muted mb-1">Empresa</label>
                <select id="selEmpresa" class="form-select filter-select">
                    <option value="">Todas</option>
                    <?php foreach($listas['empresas'] as $i) echo "<option value='{$i['empresa']}'>{$i['empresa']}</option>"; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="small fw-bold text-muted mb-1">Regional</label>
                <select id="selRegional" class="form-select filter-select">
                    <option value="">Todas</option>
                    <?php foreach($listas['regionales'] as $i) echo "<option value='{$i['nombre_regional']}'>{$i['nombre_regional']}</option>"; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="small fw-bold text-muted mb-1">Categoría</label>
                <select id="selCategoria" class="form-select filter-select">
                    <option value="">Todas</option>
                    <?php foreach($listas['categorias'] as $i) echo "<option value='{$i['nombre_categoria']}'>{$i['nombre_categoria']}</option>"; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold text-muted mb-1">Centro de Costo</label>
                <select id="selCentro" class="form-select filter-select">
                    <option value="">Todos</option>
                    <?php 
                    foreach($listas['centros'] as $i) {
                        $label = $i['nombre_centro_costo'] . ' (' . $i['cod_centro_costo'] . ')';
                        echo "<option value='{$i['nombre_centro_costo']}'>{$label}</option>"; 
                    }
                    ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <div style="flex-grow:1;">
                    <label class="small fw-bold text-muted mb-1">Buscador</label>
                    <input type="text" id="filtroCedula" class="form-control" placeholder="Cédula / Código...">
                </div>
                <div>
                    <label class="small fw-bold text-muted mb-1 text-white">.</label>
                    <button class="btn btn-outline-secondary w-100" onclick="resetFiltros()" title="Borrar Filtros"><i class="bi bi-eraser"></i></button>
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-5 g-3 mb-4">
        <div class="col">
            <div class="kpi-card border-start border-4 border-primary ps-3">
                <div class="d-flex align-items-center">
                    <div class="fs-1 me-3 text-primary bg-primary bg-opacity-10 p-2 rounded"><i class="bi bi-box-seam"></i></div>
                    <div><div class="kpi-value" id="kpiTotal">--</div><div class="kpi-label">Total Items</div></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card border-start border-4 border-success ps-3">
                <div class="d-flex align-items-center">
                    <div class="fs-1 me-3 text-success bg-success bg-opacity-10 p-2 rounded"><i class="bi bi-cash-stack"></i></div>
                    <div><div class="kpi-value" id="kpiValor">--</div><div class="kpi-label">Inversión Total</div></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card border-start border-4 border-warning ps-3" style="border-color: #6f42c1 !important;">
                <div class="d-flex align-items-center">
                    <div class="fs-1 me-3 text-purple bg-info bg-opacity-10 p-2 rounded" style="color: #6f42c1 !important;"><i class="bi bi-graph-down-arrow"></i></div>
                    <div><div class="kpi-value" id="kpiDepreciables">--</div><div class="kpi-label">Valor Neto Actual</div></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card border-start border-4 border-danger ps-3">
                <div class="d-flex align-items-center">
                    <div class="fs-1 me-3 text-danger bg-danger bg-opacity-10 p-2 rounded"><i class="bi bi-graph-down"></i></div>
                    <div><div class="kpi-value" id="kpiDepreciado">--</div><div class="kpi-label">Total Depreciado</div></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card border-start border-4 border-info ps-3">
                <div class="d-flex align-items-center">
                    <div class="fs-1 me-3 text-info bg-info bg-opacity-10 p-2 rounded"><i class="bi bi-people"></i></div>
                    <div><div class="kpi-value" id="kpiUsers">--</div><div class="kpi-label">Usuarios</div></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-3">
            <div class="chart-box h-100">
                <h5 class="chart-title"><i class="bi bi-activity"></i> Estado Físico</h5>
                <div class="chart-wrapper" style="height:250px"><canvas id="chartEstado"></canvas></div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="chart-box h-100">
                <h5 class="chart-title"><i class="bi bi-grid-3x3"></i> Cantidad por Categoría</h5>
                <div class="chart-wrapper" style="height:250px"><canvas id="chartCatCant"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-box h-100">
                <h5 class="chart-title"><i class="bi bi-pie-chart"></i> Inversión ($) por Categoría</h5>
                <div class="chart-wrapper" style="height:250px"><canvas id="chartCatVal"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="chart-box h-100">
                <h5 class="chart-title"><i class="bi bi-geo-alt"></i> Distribución por Regional</h5>
                <div class="chart-wrapper"><canvas id="chartReg"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-box h-100">
                <h5 class="chart-title"><i class="bi bi-building"></i> Distribución por Empresa</h5>
                <div class="chart-wrapper"><canvas id="chartEmp"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="chart-box">
                <h5 class="chart-title"><i class="bi bi-calendar-event"></i> Adquisiciones por Año</h5>
                <div class="chart-wrapper"><canvas id="chartTrend"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-box">
                <h5 class="chart-title"><i class="bi bi-geo-alt"></i> Top 10 Centros de Costo</h5>
                <div class="chart-wrapper"><canvas id="chartCC"></canvas></div>
            </div>
        </div>
    </div>

</div>

<footer class="footer-custom"><small>Sistema de Gestión v3.0 &copy; <?= date('Y') ?></small></footer>

<script>
const db = <?= $init_data ?>;
const palette = ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796', '#5a5c69'];
let charts = {};

$(document).ready(function() {
    $('.filter-select').select2({ width: '100%' });
    $('.filter-select').on('change', filtrarDatos);
    $('#filtroCedula').on('keypress', function (e) { if (e.key === 'Enter') filtrarDatos(); });
    initCharts();
});

function initCharts() {
    updateKPIs(db.kpi);
    
    charts.est = newChart('chartEstado', 'doughnut', db.charts.estado.l, db.charts.estado.d, {cutout: '65%', customColors: ['#1cc88a', '#f6c23e', '#e74a3b']});
    charts.catc = newChart('chartCatCant', 'bar', db.charts.cat_cant.l, db.charts.cat_cant.d, {indexAxis: 'y', targetFilter:'#selCategoria', color: '#4e73df'});
    charts.catv = newChart('chartCatVal', 'pie', db.charts.cat_val.l, db.charts.cat_val.d, {targetFilter:'#selCategoria'});
    
    charts.reg = newChart('chartReg', 'bar', db.charts.reg.l, db.charts.reg.d, {indexAxis: 'y', targetFilter:'#selRegional', color: '#1cc88a'});
    charts.emp = newChart('chartEmp', 'doughnut', db.charts.emp.l, db.charts.emp.d, {targetFilter:'#selEmpresa', cutout: '60%', customColors: true});
    charts.trend = newChart('chartTrend', 'line', db.charts.trend.l, db.charts.trend.d, {fill: true, color: 'rgba(78, 115, 223, 0.1)', borderColor: '#4e73df'});
    charts.cc = newChart('chartCC', 'bar', db.charts.cc.l, db.charts.cc.d, {targetFilter:'#selCentro', color: '#36b9cc'});
}

function filtrarDatos() {
    const params = new URLSearchParams();
    if($('#selEmpresa').val()) params.append('filtro_empresa', $('#selEmpresa').val());
    if($('#selRegional').val()) params.append('filtro_regional', $('#selRegional').val());
    if($('#selCategoria').val()) params.append('filtro_categoria', $('#selCategoria').val());
    if($('#selCentro').val()) params.append('filtro_centro_costo', $('#selCentro').val());
    if($('#filtroCedula').val()) params.append('filtro_cedula', $('#filtroCedula').val());

    fetch(`dashboard.php?ajax=1&${params.toString()}`)
    .then(r => r.json())
    .then(d => {
        updateKPIs(d.kpi);
        upd(charts.est, d.charts.estado);
        upd(charts.catc, d.charts.cat_cant);
        upd(charts.catv, d.charts.cat_val);
        upd(charts.reg, d.charts.reg);
        upd(charts.emp, d.charts.emp);
        upd(charts.trend, d.charts.trend);
        upd(charts.cc, d.charts.cc);
    });
}

function newChart(id, type, labels, data, opts={}) {
    const ctx = document.getElementById(id);
    if(!ctx) return null;

    let dataset = { label: 'Datos', data: data, borderWidth: 1, borderRadius: 4 };

    if (opts.customColors === true) {
        dataset.backgroundColor = labels.map(l => {
            const t = l.toLowerCase();
            if(t.includes('arpesod')) return '#D52B1E'; 
            if(t.includes('finansueños')) return '#191970'; 
            return '#ccc';
        });
    } else if (Array.isArray(opts.customColors)) {
        dataset.backgroundColor = opts.customColors;
    } else if(type === 'line') {
        dataset.backgroundColor = opts.color || 'rgba(78, 115, 223, 0.1)';
        dataset.borderColor = opts.borderColor || '#4e73df';
        dataset.tension = 0.3;
        dataset.fill = true;
    } else {
        dataset.backgroundColor = opts.color ? opts.color : palette;
    }

    const cfg = {
        type: type,
        data: { labels: labels, datasets: [dataset] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: opts.indexAxis || 'x',
            plugins: {
                legend: { display: (type === 'doughnut' || type === 'pie'), position: 'bottom', labels:{boxWidth:10, font:{size:10}} }
            },
            scales: (type === 'bar' || type === 'line') ? {
                x: { grid: {display: false} },
                y: { grid: {color: '#f1f5f9'}, beginAtZero: true }
            } : {},
            onClick: (e, el) => {
                if(!el.length || !opts.targetFilter) return;
                const idx = el[0].index;
                const val = labels[idx];
                $(opts.targetFilter).val(val).trigger('change');
            }
        }
    };
    if(opts.cutout) cfg.options.cutout = opts.cutout;
    if(opts.targetFilter) ctx.style.cursor = 'pointer';

    return new Chart(ctx, cfg);
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

function updateKPIs(kpi) {
    $('#kpiTotal').text(kpi.total);
    $('#kpiValor').text(kpi.valor);
    $('#kpiUsers').text(kpi.users);
    $('#kpiDepreciables').text(kpi.depreciables);
    $('#kpiDepreciado').text(kpi.depreciado);
}

function resetFiltros() {
    $('.filter-select').val('').trigger('change.select2');
    $('#filtroCedula').val('');
    filtrarDatos();
}
</script>
</body>
</html>