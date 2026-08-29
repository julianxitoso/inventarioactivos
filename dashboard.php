<?php
// =================================================================================
// ARCHIVO: dashboard.php
// ESTADO: REDISEÑO PROFESIONAL v4.1
// - Fórmula financiera exacta (fecha de corte fija + año comercial 360 días)
// - Filtros: Rango de fecha, Tenencia, Cuenta Contable, Cuenta de Depreciación
// - Gráficos: Antigüedad del Parque, Top Marcas, Inversión por Categoría (barras)
// - Informe: Depreciación Acumulada por Cuenta Contable (tabla estilo Financiera)
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

// 3. CARGAR LISTAS PARA FILTROS
$listas = [];
$hay_cuentas_limpias = true;
$hay_cuentas_dep_limpias = true;

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

    // Tenencia (se descartan filas con fórmulas de Excel sin calcular)
    $r = $conexion->query("SELECT DISTINCT tenencia FROM activos_tecnologicos 
                            WHERE tenencia IS NOT NULL AND tenencia != '' AND tenencia NOT LIKE '=%' 
                            ORDER BY tenencia");
    $listas['tenencias'] = $r->fetch_all(MYSQLI_ASSOC);

    // Cuenta Contable (se descartan fórmulas de Excel sin calcular - ver aviso en el HTML)
    $r = $conexion->query("SELECT DISTINCT cuenta_contable, nombre_cuenta FROM categorias_activo 
                            WHERE cuenta_contable IS NOT NULL AND cuenta_contable != '' AND cuenta_contable NOT LIKE '=%' 
                            ORDER BY cuenta_contable");
    $listas['cuentas'] = $r->fetch_all(MYSQLI_ASSOC);
    $hay_cuentas_limpias = count($listas['cuentas']) > 0;

    // Cuenta de Depreciación (mismo problema de datos sucios que Cuenta Contable)
    $r = $conexion->query("SELECT DISTINCT cuenta_depreciacion, nombre_cuenta_depreciacion FROM categorias_activo 
                            WHERE cuenta_depreciacion IS NOT NULL AND cuenta_depreciacion != '' AND cuenta_depreciacion NOT LIKE '=%' 
                            ORDER BY cuenta_depreciacion");
    $listas['cuentas_dep'] = $r->fetch_all(MYSQLI_ASSOC);
    $hay_cuentas_dep_limpias = count($listas['cuentas_dep']) > 0;
}

// 4. FILTROS
$where = ["a.estado != 'Dado de Baja'"];
$params = [];
$ajax = isset($_GET['ajax']);

if ($v = $_GET['filtro_empresa'] ?? null) { $where[] = "u.empresa = ?"; $params[] = $v; }
if ($v = $_GET['filtro_regional'] ?? null) { $where[] = "r.nombre_regional = ?"; $params[] = $v; }
if ($v = $_GET['filtro_categoria'] ?? null) { $where[] = "cat.nombre_categoria = ?"; $params[] = $v; }
if ($v = $_GET['filtro_centro_costo'] ?? null) { $where[] = "cc.nombre_centro_costo = ?"; $params[] = $v; } 
if ($v = $_GET['filtro_tipo_activo'] ?? null) { $where[] = "ta.nombre_tipo_activo = ?"; $params[] = $v; }

// Filtros nuevos
if ($v = $_GET['filtro_tenencia'] ?? null) { $where[] = "a.tenencia = ?"; $params[] = $v; }
if ($v = $_GET['filtro_cuenta_contable'] ?? null) { $where[] = "cat.cuenta_contable = ?"; $params[] = $v; }
if ($v = $_GET['filtro_cuenta_depreciacion'] ?? null) { $where[] = "cat.cuenta_depreciacion = ?"; $params[] = $v; }
if ($v = $_GET['filtro_fecha_desde'] ?? null) { $where[] = "a.fecha_compra >= ?"; $params[] = $v; }
if ($v = $_GET['filtro_fecha_hasta'] ?? null) { $where[] = "a.fecha_compra <= ?"; $params[] = $v; }

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

// 5. DATOS

// A. KPIs Básicos
$r = consulta($conexion, "SELECT COUNT(a.id) as T, SUM(a.valor_aproximado) as V $joins $sql_where", $params)->fetch_assoc();
$kpi_total = $r['T'] ?? 0;
$kpi_valor = $r['V'] ?? 0;

$r = consulta($conexion, "SELECT COUNT(DISTINCT a.id_usuario_responsable) as U $joins $sql_where", $params)->fetch_assoc();
$kpi_users = $r['U'] ?? 0;

// B. KPI FINANCIEROS (fórmula financiera exacta: fecha de corte fija + año comercial 360 días)
$fecha_corte_contable = '2025-12-31';
$params_dep = $params; 

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

// Informe de Depreciación por Cuenta (igual a la tabla dinámica de Financiera)
// Defensivo: si el código/nombre de cuenta trae fórmula de Excel sin calcular, se agrupa como 'NA'
$sql_dep_por_cuenta = "
SELECT 
    COALESCE(NULLIF(CASE WHEN cat.cuenta_depreciacion LIKE '=%' THEN NULL ELSE cat.cuenta_depreciacion END, ''), 'NA') as cuenta,
    COALESCE(NULLIF(CASE WHEN cat.nombre_cuenta_depreciacion LIKE '=%' THEN NULL ELSE cat.nombre_cuenta_depreciacion END, ''), 'NA') as nombre_cuenta,
    SUM(
        CASE 
            WHEN a.vida_util IS NULL OR a.vida_util <= 0 THEN 0
            WHEN a.fecha_compra IS NULL OR a.fecha_compra <= '1990-01-01' THEN 0
            ELSE LEAST(
                a.valor_aproximado, 
                (a.valor_aproximado / a.vida_util) * (GREATEST(0, DATEDIFF('$fecha_corte_contable', a.fecha_compra)) / 360)
            )
        END
    ) as suma_depreciado
$joins 
$sql_where 
GROUP BY cuenta, nombre_cuenta
ORDER BY suma_depreciado DESC
";
$res_cuenta = consulta($conexion, $sql_dep_por_cuenta, $params_dep);
$reporte_cuentas = [];
while($row = $res_cuenta->fetch_assoc()) { $reporte_cuentas[] = $row; }

// VALOR NETO EN LIBROS: Lo que costaron menos lo que se depreció
$kpi_depreciables = $kpi_valor - $kpi_total_depreciado;

// C. Estados
$est_lbl = []; $est_dat = [];
$res = consulta($conexion, "SELECT COALESCE(NULLIF(a.estado, ''), 'Sin Estado') as N, COUNT(a.id) as C $joins $sql_where GROUP BY estado ORDER BY C DESC", $params);
while($row = $res->fetch_assoc()) { $est_lbl[] = limpiar($row['N']); $est_dat[] = $row['C']; }

// D. Gráficos existentes
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

// E. Antigüedad del Parque (rangos de años de uso, sobre fecha de HOY, no la de corte contable)
$d_edad = []; $l_edad = [];
$sql_edad = "
SELECT 
    CASE 
        WHEN a.fecha_compra IS NULL OR a.fecha_compra <= '1990-01-01' THEN 'Sin Fecha'
        WHEN TIMESTAMPDIFF(YEAR, a.fecha_compra, CURDATE()) < 1 THEN '0-1 años'
        WHEN TIMESTAMPDIFF(YEAR, a.fecha_compra, CURDATE()) < 3 THEN '1-3 años'
        WHEN TIMESTAMPDIFF(YEAR, a.fecha_compra, CURDATE()) < 5 THEN '3-5 años'
        WHEN TIMESTAMPDIFF(YEAR, a.fecha_compra, CURDATE()) < 8 THEN '5-8 años'
        WHEN TIMESTAMPDIFF(YEAR, a.fecha_compra, CURDATE()) < 10 THEN '8-10 años'
        ELSE '+10 años'
    END as rango,
    COUNT(a.id) as C
$joins 
$sql_where 
GROUP BY rango
ORDER BY FIELD(rango, '0-1 años','1-3 años','3-5 años','5-8 años','8-10 años','+10 años','Sin Fecha')
";
$res = consulta($conexion, $sql_edad, $params);
while($row = $res->fetch_assoc()) { $l_edad[] = $row['rango']; $d_edad[] = $row['C']; }

// F. Top 10 Marcas (excluyendo "SIN MARCA" para que el gráfico sea útil)
$d_marcas = []; $l_marcas = [];
$sql_marcas = "
SELECT a.marca as N, COUNT(a.id) as C 
$joins 
$sql_where 
AND a.marca IS NOT NULL AND a.marca != '' AND a.marca != 'SIN MARCA'
GROUP BY a.marca ORDER BY C DESC LIMIT 10
";
$res = consulta($conexion, $sql_marcas, $params);
while($row = $res->fetch_assoc()) { $l_marcas[] = limpiar($row['N']); $d_marcas[] = $row['C']; }


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
        'emp' => ['l' => $l_emp, 'd' => $d_emp],
        'edad' => ['l' => $l_edad, 'd' => $d_edad],
        'marcas' => ['l' => $l_marcas, 'd' => $d_marcas]
    ],
    'reporte_cuentas' => $reporte_cuentas
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <style>
        :root {
            --c-primary:  #4361ee;
            --c-success:  #1cc88a;
            --c-purple:   #6f42c1;
            --c-danger:   #e74a3b;
            --c-info:     #36b9cc;
            --c-warning:  #f6c23e;
            --c-orange:   #fd7e14;
            --c-arpesod:  #D52B1E;
            --c-finan:    #191970;
            --bg-page:    #f3f5fa;
            --card-radius: 16px;
            --shadow-soft: 0 4px 18px rgba(30, 41, 59, 0.06);
            --shadow-hover: 0 10px 28px rgba(30, 41, 59, 0.12);
        }

        body.page-dashboard {
            background: var(--bg-page);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        /* ---------- TOP BAR ---------- */
        .top-bar-custom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 32px;
            background: #ffffff;
            box-shadow: var(--shadow-soft);
            position: relative;
            z-index: 5;
        }
        .top-bar-accent {
            height: 4px;
            width: 100%;
            background: linear-gradient(90deg, var(--c-arpesod) 0%, var(--c-arpesod) 48%, var(--c-finan) 52%, var(--c-finan) 100%);
        }

        /* ---------- FILTER BAR ---------- */
        .filter-bar {
            background: #ffffff;
            border-radius: var(--card-radius);
            box-shadow: var(--shadow-soft);
            padding: 22px 24px 18px 24px;
            margin-bottom: 22px;
            border: 1px solid rgba(0,0,0,0.03);
        }
        .filter-bar label {
            text-transform: uppercase;
            letter-spacing: .04em;
            font-size: .70rem !important;
            color: #8a93a3 !important;
        }
        .filter-bar .form-select, .filter-bar .form-control {
            border-radius: 10px;
            border: 1px solid #e4e8ef;
        }
        .filter-bar .select2-container .select2-selection--single {
            border-radius: 10px !important;
            border: 1px solid #e4e8ef !important;
            height: 38px !important;
        }
        .filter-divider {
            border-top: 1px dashed #e4e8ef;
            margin: 14px 0;
        }

        /* ---------- KPI CARDS ---------- */
        .kpi-card {
            background: #ffffff;
            border-radius: var(--card-radius);
            box-shadow: var(--shadow-soft);
            padding: 18px 20px;
            border: 1px solid rgba(0,0,0,0.03);
            transition: transform .15s ease, box-shadow .15s ease;
            height: 100%;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }
        .kpi-icon {
            width: 52px; height: 52px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        .kpi-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e2939;
            line-height: 1.15;
        }
        .kpi-label {
            font-size: .74rem;
            font-weight: 600;
            color: #8a93a3;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        /* ---------- CHART BOXES ---------- */
        .chart-box {
            background: #ffffff;
            border-radius: var(--card-radius);
            box-shadow: var(--shadow-soft);
            padding: 20px 22px;
            border: 1px solid rgba(0,0,0,0.03);
            transition: box-shadow .15s ease;
        }
        .chart-box:hover { box-shadow: var(--shadow-hover); }
        .chart-title {
            font-size: .92rem;
            font-weight: 700;
            color: #37415a;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chart-title i { color: var(--c-primary); }

        .alert-data-quality {
            border-radius: 12px;
            border: none;
            background: #fff6e6;
            color: #8a6416;
            font-size: .85rem;
            padding: 12px 16px;
        }

        .footer-custom {
            background: transparent !important;
            color: #a0a8b8;
        }

        /* ---------- TABLA INFORME ---------- */
        #tablaDepCuenta thead th {
            font-size: .72rem;
            letter-spacing: .04em;
            border-bottom: 2px solid #e4e8ef;
        }
        #tablaDepCuenta tbody td {
            font-size: .88rem;
            border-color: #f1f3f7;
        }
        #tablaDepCuenta tfoot td {
            font-size: .92rem;
            background: #f8f9fc;
        }
    </style>
</head>
<body class="page-dashboard">

<div class="top-bar-custom">
    <div class="d-flex align-items-center">
        <a href="menu.php"><img src="imagenes/logo.png" height="75" alt="Logo"></a>
        <h4 class="ms-3 mb-0 text-secondary d-none d-md-block" style="font-weight: 700;">Control de Activos</h4>
    </div>
    <div>
        <span class="me-3 fw-bold text-secondary"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?></span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-4">Salir</a>
    </div>
</div>
<div class="top-bar-accent"></div>

<div class="container-fluid px-4 mt-4 mb-5">

    <?php if (!$hay_cuentas_limpias || !$hay_cuentas_dep_limpias): ?>
    <div class="alert-data-quality mb-3">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong>Filtros de Cuenta Contable / Cuenta de Depreciación deshabilitados temporalmente:</strong>
        la tabla <code>categorias_activo</code> tiene fórmulas de Excel sin calcular en esos campos.
        Corrige esos datos y los filtros se activarán automáticamente, sin tocar código.
    </div>
    <?php endif; ?>

    <div class="filter-bar">
        <div class="row g-2 align-items-end">
            <div class="col-lg-2 col-md-4">
                <label class="small fw-bold mb-1">Empresa</label>
                <select id="selEmpresa" class="form-select filter-select">
                    <option value="">Todas</option>
                    <?php foreach($listas['empresas'] as $i) echo "<option value='{$i['empresa']}'>{$i['empresa']}</option>"; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="small fw-bold mb-1">Regional</label>
                <select id="selRegional" class="form-select filter-select">
                    <option value="">Todas</option>
                    <?php foreach($listas['regionales'] as $i) echo "<option value='{$i['nombre_regional']}'>{$i['nombre_regional']}</option>"; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="small fw-bold mb-1">Categoría</label>
                <select id="selCategoria" class="form-select filter-select">
                    <option value="">Todas</option>
                    <?php foreach($listas['categorias'] as $i) echo "<option value='{$i['nombre_categoria']}'>{$i['nombre_categoria']}</option>"; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-6">
                <label class="small fw-bold mb-1">Centro de Costo</label>
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
            <div class="col-lg-2 col-md-3">
                <label class="small fw-bold mb-1">Tenencia</label>
                <select id="selTenencia" class="form-select filter-select">
                    <option value="">Todas</option>
                    <?php foreach($listas['tenencias'] as $i) echo "<option value='{$i['tenencia']}'>{$i['tenencia']}</option>"; ?>
                </select>
            </div>
            <div class="col-lg-1 col-md-3">
                <label class="small fw-bold mb-1 text-white d-none d-lg-block">.</label>
                <button class="btn btn-outline-secondary w-100" onclick="resetFiltros()" title="Borrar Filtros"><i class="bi bi-eraser"></i></button>
            </div>
        </div>

        <div class="filter-divider"></div>

        <div class="row g-2 align-items-end">
            <div class="col-lg-3 col-md-4">
                <label class="small fw-bold mb-1">Cuenta Contable <?= !$hay_cuentas_limpias ? '(sin datos limpios)' : '' ?></label>
                <select id="selCuenta" class="form-select filter-select" <?= !$hay_cuentas_limpias ? 'disabled' : '' ?>>
                    <option value="">Todas</option>
                    <?php foreach($listas['cuentas'] as $i) {
                        $label = $i['cuenta_contable'] . ' - ' . $i['nombre_cuenta'];
                        echo "<option value='{$i['cuenta_contable']}'>{$label}</option>"; 
                    } ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-4">
                <label class="small fw-bold mb-1">Cuenta Depreciación <?= !$hay_cuentas_dep_limpias ? '(sin datos limpios)' : '' ?></label>
                <select id="selCuentaDep" class="form-select filter-select" <?= !$hay_cuentas_dep_limpias ? 'disabled' : '' ?>>
                    <option value="">Todas</option>
                    <?php foreach($listas['cuentas_dep'] as $i) {
                        $label = $i['cuenta_depreciacion'] . ' - ' . $i['nombre_cuenta_depreciacion'];
                        echo "<option value='{$i['cuenta_depreciacion']}'>{$label}</option>"; 
                    } ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="small fw-bold mb-1">Compra Desde</label>
                <input type="date" id="fechaDesde" class="form-control filter-input">
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="small fw-bold mb-1">Compra Hasta</label>
                <input type="date" id="fechaHasta" class="form-control filter-input">
            </div>
            <div class="col-lg-2 col-md-8">
                <label class="small fw-bold mb-1">Buscador</label>
                <input type="text" id="filtroCedula" class="form-control" placeholder="Cédula / Código...">
            </div>
        </div>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-5 g-3 mb-4">
        <div class="col">
            <div class="kpi-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="kpi-icon" style="background:rgba(67,97,238,.1); color:var(--c-primary);"><i class="bi bi-box-seam"></i></div>
                    <div><div class="kpi-value" id="kpiTotal">--</div><div class="kpi-label">Total Items</div></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="kpi-icon" style="background:rgba(28,200,138,.1); color:var(--c-success);"><i class="bi bi-cash-stack"></i></div>
                    <div><div class="kpi-value" id="kpiValor">--</div><div class="kpi-label">Inversión Total</div></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="kpi-icon" style="background:rgba(111,66,193,.1); color:var(--c-purple);"><i class="bi bi-graph-down-arrow"></i></div>
                    <div><div class="kpi-value" id="kpiDepreciables">--</div><div class="kpi-label">Valor Neto Actual</div></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="kpi-icon" style="background:rgba(231,74,59,.1); color:var(--c-danger);"><i class="bi bi-graph-down"></i></div>
                    <div><div class="kpi-value" id="kpiDepreciado">--</div><div class="kpi-label">Total Depreciado</div></div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="kpi-icon" style="background:rgba(54,185,204,.1); color:var(--c-info);"><i class="bi bi-people"></i></div>
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
                <h5 class="chart-title"><i class="bi bi-bar-chart"></i> Inversión ($) por Categoría</h5>
                <div class="chart-wrapper" style="height:280px"><canvas id="chartCatVal"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="chart-box h-100">
                <h5 class="chart-title"><i class="bi bi-hourglass-split"></i> Antigüedad del Parque</h5>
                <div class="chart-wrapper" style="height:260px"><canvas id="chartEdad"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-box h-100">
                <h5 class="chart-title"><i class="bi bi-tags"></i> Top 10 Marcas <small class="text-muted fw-normal ms-1">(excluye "Sin Marca")</small></h5>
                <div class="chart-wrapper" style="height:260px"><canvas id="chartMarcas"></canvas></div>
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

    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="chart-box">
                <h5 class="chart-title"><i class="bi bi-table"></i> Depreciación Acumulada por Cuenta Contable</h5>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="tablaDepCuenta">
                        <thead>
                            <tr class="text-muted small text-uppercase">
                                <th>Cuenta Depreciación</th>
                                <th>Nombre Cuenta Depreciación</th>
                                <th class="text-end">Suma Valor Depreciado</th>
                            </tr>
                        </thead>
                        <tbody id="tablaDepCuentaBody"></tbody>
                        <tfoot>
                            <tr class="fw-bold border-top">
                                <td colspan="2">Total General</td>
                                <td class="text-end" id="tablaDepCuentaTotal">--</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<footer class="footer-custom text-center py-3"><small>Sistema de Gestión v4.1 &copy; <?= date('Y') ?></small></footer>

<script>
const db = <?= $init_data ?>;
const palette = ['#4361ee', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796', '#6f42c1', '#fd7e14'];
let charts = {};

$(document).ready(function() {
    $('.filter-select').select2({ width: '100%' });
    $('.filter-select').on('change', filtrarDatos);
    $('.filter-input').on('change', filtrarDatos);
    $('#filtroCedula').on('keypress', function (e) { if (e.key === 'Enter') filtrarDatos(); });
    initCharts();
});

function initCharts() {
    updateKPIs(db.kpi);
    renderTablaDepCuenta(db.reporte_cuentas);
    
    charts.est = newChart('chartEstado', 'doughnut', db.charts.estado.l, db.charts.estado.d, {cutout: '65%', customColors: ['#1cc88a', '#f6c23e', '#e74a3b']});
    charts.catc = newChart('chartCatCant', 'bar', db.charts.cat_cant.l, db.charts.cat_cant.d, {indexAxis: 'y', targetFilter:'#selCategoria', color: '#4361ee'});
    charts.catv = newChart('chartCatVal', 'bar', db.charts.cat_val.l, db.charts.cat_val.d, {indexAxis: 'y', targetFilter:'#selCategoria', color: '#1cc88a', format: 'currency'});
    
    charts.edad = newChart('chartEdad', 'bar', db.charts.edad.l, db.charts.edad.d, {color: '#f6c23e'});
    charts.marcas = newChart('chartMarcas', 'bar', db.charts.marcas.l, db.charts.marcas.d, {indexAxis: 'y', color: '#fd7e14'});

    charts.reg = newChart('chartReg', 'bar', db.charts.reg.l, db.charts.reg.d, {indexAxis: 'y', targetFilter:'#selRegional', color: '#1cc88a'});
    charts.emp = newChart('chartEmp', 'doughnut', db.charts.emp.l, db.charts.emp.d, {targetFilter:'#selEmpresa', cutout: '60%', customColors: true});
    charts.trend = newChart('chartTrend', 'line', db.charts.trend.l, db.charts.trend.d, {fill: true, color: 'rgba(67, 97, 238, 0.1)', borderColor: '#4361ee'});
    charts.cc = newChart('chartCC', 'bar', db.charts.cc.l, db.charts.cc.d, {targetFilter:'#selCentro', color: '#36b9cc'});
}

function filtrarDatos() {
    const params = new URLSearchParams();
    if($('#selEmpresa').val()) params.append('filtro_empresa', $('#selEmpresa').val());
    if($('#selRegional').val()) params.append('filtro_regional', $('#selRegional').val());
    if($('#selCategoria').val()) params.append('filtro_categoria', $('#selCategoria').val());
    if($('#selCentro').val()) params.append('filtro_centro_costo', $('#selCentro').val());
    if($('#selTenencia').val()) params.append('filtro_tenencia', $('#selTenencia').val());
    if($('#selCuenta').val()) params.append('filtro_cuenta_contable', $('#selCuenta').val());
    if($('#selCuentaDep').val()) params.append('filtro_cuenta_depreciacion', $('#selCuentaDep').val());
    if($('#fechaDesde').val()) params.append('filtro_fecha_desde', $('#fechaDesde').val());
    if($('#fechaHasta').val()) params.append('filtro_fecha_hasta', $('#fechaHasta').val());
    if($('#filtroCedula').val()) params.append('filtro_cedula', $('#filtroCedula').val());

    fetch(`dashboard.php?ajax=1&${params.toString()}`)
    .then(r => r.json())
    .then(d => {
        updateKPIs(d.kpi);
        renderTablaDepCuenta(d.reporte_cuentas);
        upd(charts.est, d.charts.estado);
        upd(charts.catc, d.charts.cat_cant);
        upd(charts.catv, d.charts.cat_val);
        upd(charts.edad, d.charts.edad);
        upd(charts.marcas, d.charts.marcas);
        upd(charts.reg, d.charts.reg);
        upd(charts.emp, d.charts.emp);
        upd(charts.trend, d.charts.trend);
        upd(charts.cc, d.charts.cc);
    });
}

function newChart(id, type, labels, data, opts={}) {
    const ctx = document.getElementById(id);
    if(!ctx) return null;

    let dataset = { label: 'Datos', data: data, borderWidth: 1, borderRadius: 6 };

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
        dataset.backgroundColor = opts.color || 'rgba(67, 97, 238, 0.1)';
        dataset.borderColor = opts.borderColor || '#4361ee';
        dataset.tension = 0.3;
        dataset.fill = true;
        dataset.borderWidth = 2;
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
                x: { 
                    grid: {display: false},
                    ticks: opts.format === 'currency' ? { callback: (val) => '$' + new Intl.NumberFormat('es-CO').format(val) } : {}
                },
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

function renderTablaDepCuenta(rows) {
    const f = (num) => '$' + new Intl.NumberFormat('es-CO').format(Math.round(num));
    let total = 0;
    let html = '';
    rows.forEach(r => {
        total += parseFloat(r.suma_depreciado);
        html += `<tr>
            <td>${r.cuenta}</td>
            <td>${r.nombre_cuenta}</td>
            <td class="text-end">${f(r.suma_depreciado)}</td>
        </tr>`;
    });
    $('#tablaDepCuentaBody').html(html);
    $('#tablaDepCuentaTotal').text(f(total));
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
    $('.filter-input').val('');
    $('#filtroCedula').val('');
    filtrarDatos();
}
</script>
</body>
</html>