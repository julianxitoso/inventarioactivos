<?php
// =================================================================================
// ARCHIVO: exportar_excel.php
// ESTADO: FINAL BLINDADO (Con Diccionario de Respaldo para asegurar columnas)
// =================================================================================

// 1. LIMPIEZA DE BÚFER
if (ob_get_level()) ob_end_clean();
ob_start();

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

try {
    session_start();
    
    // Validaciones
    if (!isset($_SESSION['usuario_id'])) throw new Exception("Sesión expirada.");
    if (!file_exists(__DIR__ . '/backend/db.php')) throw new Exception("Falta backend/db.php");
    
    require_once __DIR__ . '/backend/db.php';
    require_once __DIR__ . '/backend/auth_check.php';
    restringir_acceso_pagina(['admin', 'tecnico', 'auditor']);

    if (!isset($conexion) || $conexion->connect_error) throw new Exception("Error conexión BD");
    $conexion->set_charset("utf8mb4");

    // --- DICCIONARIO MAESTRO DE RESPALDO ---
    // Si el formulario no envía el código SQL, usamos esto para traducir el nombre
    $diccionario_maestro = [
        // Datos Básicos
        'ID Activo' => 'at.id',
        'Tipo de Activo' => 'ta.nombre_tipo_activo',
        'Marca' => 'at.marca',
        'Serie' => 'at.serie',
        'Cód. Inventario' => 'at.Codigo_Inv',
        'Estado Actual' => 'at.estado',
        'Valor Compra' => 'at.valor_aproximado',
        'Valor Compra (Costo Histórico)' => 'at.valor_aproximado',
        'Fecha Compra' => 'at.fecha_compra',
        'Detalles' => 'at.detalles',
        'Detalles / Observaciones' => 'at.detalles',

        // Ubicación
        'Centro de Costo' => 'cc.nombre_centro_costo',
        'Centro de Costo (Ubicación)' => 'cc.nombre_centro_costo',
        'Centro de Costo (Ubicación Real)' => 'cc.nombre_centro_costo',
        'Regional' => 'r.nombre_regional',
        'Regional (Ubicación)' => 'r.nombre_regional',
        'Regional (Ubicación Real)' => 'r.nombre_regional',

        // Responsable
        'Nombre Responsable' => 'u.nombre_completo',
        'Responsable' => 'u.nombre_completo',
        'Cédula Responsable' => 'u.usuario',
        'Cargo Responsable' => 'c.nombre_cargo',
        'Empresa Responsable' => 'u.empresa',
        'Empresa' => 'u.empresa',
        'Aplicaciones Usadas' => 'u.aplicaciones_usadas',

        // Técnico
        'Procesador' => 'at.procesador',
        'Memoria RAM' => 'at.ram',
        'Disco Duro' => 'at.disco_duro',
        'Sistema Operativo' => 'at.sistema_operativo',
        'Offimática' => 'at.offimatica',
        'Antivirus' => 'at.antivirus',
        'Tipo Específico' => 'at.tipo_equipo',

        // Depreciación (Campos base y calculados)
        'Vida Útil (Años)' => 'at.vida_util',
        'Vida Útil Base (Años)' => 'at.vida_util',
        'Inicio Depreciación' => 'at.fecha_inicio_depreciacion',
        'Valor Residual' => 'at.valor_residual',
        'Vida Útil Fiscal Aplicada (Años)' => 'CALCULADO_VIDA_UTIL_FISCAL',
        'SMMLV Año Compra' => 'CALCULADO_SMMLV',
        'Tiempo Transcurrido (Meses)' => 'CALCULADO_MESES_USO',
        'Vida Útil Restante (Meses)' => 'CALCULADO_MESES_RESTANTES',
        'Depreciación Mensual' => 'CALCULADO_DEP_MENSUAL',
        'Depreciación Acumulada' => 'CALCULADO_DEP_ACUMULADA',
        'Valor Neto en Libros' => 'CALCULADO_VALOR_LIBROS',
        'Valor en Libros (Calculado)' => 'CALCULADO_VALOR_LIBROS',

        // Préstamos
        'ID Préstamo' => 'p.id_prestamo',
        'Prestado Por' => '(SELECT nombre_completo FROM usuarios up WHERE up.id = p.id_usuario_presta)',
        'Fecha Préstamo' => 'p.fecha_prestamo',
        'Fecha Dev. Esperada' => 'p.fecha_devolucion_esperada',
        'Estado del Préstamo' => 'p.estado_prestamo'
    ];

    // --- RECEPCIÓN DE DATOS ---
    $tipo_informe = $_POST['tipo_informe'] ?? 'general';
    $fecha_desde = $_POST['fecha_desde'] ?? '';
    $fecha_hasta = $_POST['fecha_hasta'] ?? '';
    $campos_raw = $_POST['campos_seleccionados'] ?? [];

    // HISTÓRICO SMMLV
    $historico_smmlv = [
        2010 => 515000, 2011 => 535600, 2012 => 566700, 2013 => 589500, 2014 => 616000,
        2015 => 644350, 2016 => 689455, 2017 => 737717, 2018 => 781242, 2019 => 828116,
        2020 => 877803, 2021 => 908526, 2022 => 1000000, 2023 => 1160000, 2024 => 1300000,
        2025 => 1423500, 'default' => 1423500
    ];
    $UMBRAL_DEPRECIACION = 1;

    // --- CONSTRUCCIÓN INTELIGENTE DE CONSULTA ---
    $select_parts = [];
    $mapa_col_alias = [];
    $contador = 0;
    $necesita_calculo = false;

    // Si llega vacío o no es array, intentamos recuperar del diccionario por defecto
    if (empty($campos_raw) || !is_array($campos_raw)) {
        // Fallback: Si no llega nada, forzar ID y Nombre
        $campos_raw = ['at.id|||ID Activo', 'ta.nombre_tipo_activo|||Tipo'];
    }

    foreach ($campos_raw as $val) {
        $col_db = '';
        $header_name = '';

        // CASO A: Viene con separador (Formato Nuevo)
        if (strpos($val, '|||') !== false) {
            $parts = explode('|||', $val);
            $col_db = $parts[0];
            $header_name = $parts[1];
        } 
        // CASO B: Viene solo el nombre (Formato Viejo/Caché) -> USAR DICCIONARIO
        else {
            $header_name = $val;
            // Buscamos en el diccionario maestro
            if (isset($diccionario_maestro[$val])) {
                $col_db = $diccionario_maestro[$val];
            } else {
                // Si no encontramos mapeo, ignoramos para no romper SQL
                continue; 
            }
        }

        // Ya tenemos columna y nombre, procedemos
        $alias_seguro = "col_" . $contador++;
        
        if (strpos($col_db, 'CALCULADO_') !== false || strpos($header_name, 'Valor en Libros') !== false) {
            $necesita_calculo = true;
            // Guardamos el código del cálculo como clave en el mapa
            $clave_calculo = (strpos($col_db, 'CALCULADO_') !== false) ? $col_db : 'CALCULADO_VALOR_LIBROS';
            $mapa_col_alias[$clave_calculo] = $header_name;
        } else {
            $select_parts[] = "$col_db AS $alias_seguro";
            $mapa_col_alias[$alias_seguro] = $header_name;
        }
    }

    // --- PROTECCIÓN FINAL ---
    // Si después de todo el proceso $select_parts está vacío, agregamos ID por defecto
    if (empty($select_parts)) {
        $select_parts[] = "at.id AS col_default";
        $mapa_col_alias['col_default'] = "ID Activo";
    }

    // Campos ocultos para cálculo
    if ($necesita_calculo) {
        $select_parts[] = "at.valor_aproximado AS _calc_valor";
        $select_parts[] = "at.valor_residual AS _calc_residual";
        $select_parts[] = "at.fecha_compra AS _calc_fecha";
        $select_parts[] = "at.vida_util AS _calc_vida";
        $select_parts[] = "ta.vida_util_sugerida AS _calc_sugerida";
        $select_parts[] = "ta.nombre_tipo_activo AS _calc_tipo";
    }

    $sql_select = implode(", ", $select_parts);
    
    // JOINS
    $joins = " FROM activos_tecnologicos at
               LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
               LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id
               LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
               LEFT JOIN centros_costo cc ON at.id_centro_costo = cc.id_centro_costo
               LEFT JOIN regionales r ON cc.id_regional = r.id_regional
               LEFT JOIN prestamos_activos p ON (at.id = p.id_activo AND p.fecha_devolucion_real IS NULL) ";

    // WHERE Y ORDER
    $where_conds = ["at.estado != 'Dado de Baja'"];
    $params = [];
    $types = "";

    if (!empty($fecha_desde) && !empty($fecha_hasta)) {
        $fh = $fecha_hasta . ' 23:59:59';
        $where_conds[] = "at.fecha_compra BETWEEN ? AND ?";
        $params = [$fecha_desde, $fh]; $types = "ss";
    } elseif (!empty($fecha_desde)) {
        $where_conds[] = "at.fecha_compra >= ?";
        $params = [$fecha_desde]; $types = "s";
    }

    $order_by = " ORDER BY at.id ASC";
    if ($tipo_informe == 'activos_en_prestamo') {
        $where_conds = ["p.id_prestamo IS NOT NULL AND p.fecha_devolucion_real IS NULL"];
        if(!empty($fecha_desde)) { 
            $where_conds[] = "p.fecha_prestamo >= ?"; $params = [$fecha_desde]; $types = "s"; 
        }
    } elseif ($tipo_informe == 'dados_baja') {
        $where_conds = ["at.estado = 'Dado de Baja'"];
    } elseif ($tipo_informe == 'general') {
        $order_by = " ORDER BY u.empresa, u.nombre_completo";
    }

    $sql_where = " WHERE " . implode(" AND ", $where_conds);
    $sql_final = "SELECT $sql_select $joins $sql_where $order_by";

    // EJECUTAR
    $stmt = $conexion->prepare($sql_final);
    if (!$stmt) throw new Exception("Error SQL: " . $conexion->error);
    
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) throw new Exception("Error Ejecución: " . $stmt->error);
    $result = $stmt->get_result();

    // --- EXCEL ---
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Reporte');

    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF191970']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ];

    // Encabezados
    $col = 'A';
    foreach ($mapa_col_alias as $key => $headerName) {
        $sheet->setCellValue($col . '1', $headerName);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }
    $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray($headerStyle);

    // Filas
    $rowNum = 2;
    while ($row = $result->fetch_assoc()) {
        $col = 'A';
        
        // CÁLCULOS FINANCIEROS PREVIOS
        $res_calc = [];
        if ($necesita_calculo) {
            $v_compra = floatval($row['_calc_valor'] ?? 0);
            $v_residual = floatval($row['_calc_residual'] ?? 0);
            $f_compra = $row['_calc_fecha'] ?? null;
            $vida_base = intval($row['_calc_vida'] ?? $row['_calc_sugerida'] ?? 0);
            $tipo = strtolower($row['_calc_tipo'] ?? '');

            if (strpos($tipo, 'computador') !== false || strpos($tipo, 'portatil') !== false) $vida_base = 5;
            elseif (strpos($tipo, 'celular') !== false) $vida_base = 5;
            elseif (strpos($tipo, 'vehiculo') !== false) $vida_base = 10;

            $anio = $f_compra ? date('Y', strtotime($f_compra)) : 'default';
            $smmlv = $historico_smmlv[$anio] ?? $historico_smmlv['default'];
            $val_salarios = ($smmlv > 0) ? ($v_compra / $smmlv) : 0;
            $es_gasto = ($val_salarios < $UMBRAL_DEPRECIACION);

            // Tiempos
            $f_inicio = ($f_compra) ? new DateTime($f_compra) : null;
            $f_actual = new DateTime();
            $meses_uso = 0;
            if ($f_inicio && $f_actual > $f_inicio) {
                $meses_uso = (($f_actual->format('Y') - $f_inicio->format('Y')) * 12) + ($f_actual->format('m') - $f_inicio->format('m'));
            }
            $vida_meses = $vida_base * 12;
            $meses_rest = max(0, $vida_meses - $meses_uso);
            $valor_base = max(0, $v_compra - $v_residual);

            $res_calc['CALCULADO_SMMLV'] = $smmlv;
            $res_calc['CALCULADO_VIDA_UTIL_FISCAL'] = $vida_base;
            $res_calc['CALCULADO_MESES_USO'] = $meses_uso;
            $res_calc['CALCULADO_MESES_RESTANTES'] = $meses_rest;

            if ($es_gasto) {
                $res_calc['CALCULADO_DEP_MENSUAL'] = 0;
                $res_calc['CALCULADO_DEP_ACUMULADA'] = $v_compra;
                $res_calc['CALCULADO_VALOR_LIBROS'] = 0;
            } elseif ($f_inicio && $vida_base > 0) {
                $dep_men = $valor_base / $vida_meses;
                $dep_acum = $dep_men * min($meses_uso, $vida_meses);
                
                $res_calc['CALCULADO_DEP_MENSUAL'] = $dep_men;
                $res_calc['CALCULADO_DEP_ACUMULADA'] = $dep_acum;
                $res_calc['CALCULADO_VALOR_LIBROS'] = max($v_residual, $v_compra - $dep_acum);
            } else {
                $res_calc['CALCULADO_DEP_MENSUAL'] = 0;
                $res_calc['CALCULADO_DEP_ACUMULADA'] = 0;
                $res_calc['CALCULADO_VALOR_LIBROS'] = $v_compra;
            }
        }

        foreach ($mapa_col_alias as $key => $headerName) {
            $valor_final = '';

            if (strpos($key, 'CALCULADO_') === 0) {
                $valor_final = $res_calc[$key] ?? 0;
            } else {
                $valor_final = $row[$key] ?? '';
            }

            // Moneda
            $campos_moneda = ['Valor Compra', 'Valor Compra (Costo Histórico)', 'Valor Residual', 'Valor Neto en Libros', 'Depreciación Mensual', 'Depreciación Acumulada', 'SMMLV Año Compra', 'Valor', 'Valor Aproximado'];
            
            if (in_array($headerName, $campos_moneda) && is_numeric($valor_final)) {
                $sheet->setCellValue($col . $rowNum, (float)$valor_final);
                $sheet->getStyle($col . $rowNum)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            } else {
                $sheet->setCellValue($col . $rowNum, $valor_final);
            }
            $col++;
        }
        $rowNum++;
    }

    ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Reporte_' . $tipo_informe . '_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    ob_end_clean();
    echo "<div style='padding:20px; border:1px solid red; background:#ffe6e6;'><h3>Error:</h3>" . $e->getMessage() . "</div>";
}
?>