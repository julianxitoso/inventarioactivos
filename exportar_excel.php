<?php
// =================================================================================
// ARCHIVO: exportar_excel.php
// ESTADO: FINAL (Soporta ta.vida_util_sugerida + Fallback Seguro)
// =================================================================================

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
    
    if (!isset($_SESSION['usuario_id'])) throw new Exception("Sesión expirada.");
    if (!file_exists(__DIR__ . '/backend/db.php')) throw new Exception("Falta backend/db.php");
    
    require_once __DIR__ . '/backend/db.php';
    require_once __DIR__ . '/backend/auth_check.php';
    restringir_acceso_pagina(['admin', 'tecnico', 'auditor']);

    if (!isset($conexion) || $conexion->connect_error) throw new Exception("Error conexión BD");
    $conexion->set_charset("utf8mb4");

    // --- DICCIONARIO MAESTRO (Respaldo) ---
    $diccionario_maestro = [
        'ID Activo' => 'at.id',
        'Tipo de Activo' => 'ta.nombre_tipo_activo',
        'Categoría' => 'cat.nombre_categoria',
        'Marca' => 'at.marca',
        'Serie' => 'at.serie',
        'Cód. Inventario' => 'at.Codigo_Inv',
        'Estado Actual' => 'at.estado',
        'Valor Compra' => 'at.valor_aproximado',
        'Fecha Compra' => 'at.fecha_compra',
        'Detalles' => 'at.detalles',
        'Centro de Costo' => 'cc.nombre_centro_costo',
        'Regional' => 'r.nombre_regional',
        'Nombre Responsable' => 'u.nombre_completo',
        'Cédula Responsable' => 'u.usuario',
        'Empresa Responsable' => 'u.empresa',
        'Vida Útil (Años)' => 'at.vida_util',
        'Vida Útil Estándar (Catálogo)' => 'ta.vida_util_sugerida', // NUEVO
        'Valor Residual' => 'at.valor_residual',
        'Valor Neto en Libros' => 'CALCULADO_VALOR_LIBROS',
        'Depreciación Acumulada' => 'CALCULADO_DEP_ACUMULADA',
        'Gasto Depreciación Mensual' => 'CALCULADO_DEP_MENSUAL'
    ];

    $tipo_informe = $_POST['tipo_informe'] ?? 'general';
    $fecha_desde = $_POST['fecha_desde'] ?? '';
    $fecha_hasta = $_POST['fecha_hasta'] ?? '';
    $campos_raw = $_POST['campos_seleccionados'] ?? [];
    $q_busqueda = $_POST['q_busqueda'] ?? '';
    
    $SMMLV_2025 = 1423500;
    $UMBRAL_DEPRECIACION = 1;

    $select_parts = [];
    $mapa_col_alias = [];
    $contador = 0;
    $necesita_calculo = false;

    if (empty($campos_raw) || !is_array($campos_raw)) {
        $campos_raw = ['at.id|||ID Activo', 'ta.nombre_tipo_activo|||Tipo'];
    }

    foreach ($campos_raw as $val) {
        $col_db = '';
        $header_name = '';

        if (strpos($val, '|||') !== false) {
            $parts = explode('|||', $val);
            $col_db = $parts[0];
            $header_name = $parts[1];
        } else {
            // Soporte para formato antiguo
            $header_name = $val;
            $col_db = $diccionario_maestro[$val] ?? '';
            if(empty($col_db)) continue;
        }

        // CAMPOS FANTASMA (Para evitar errores si alguien los fuerza)
        $campos_fantasma = [
            'at.modelo', 'at.proveedor', 'at.numero_factura', 
            'at.mac_lan', 'at.mac_wifi', 'at.ip_asignada', 'at.licencia_so',
            'at.procesador', 'at.ram', 'at.disco_duro', 'at.sistema_operativo',
            'at.offimatica', 'at.antivirus', 'at.tipo_equipo'
        ];

        if (in_array($col_db, $campos_fantasma)) {
            $col_db = "''";
        }

        $alias_seguro = "col_" . $contador++;
        
        if (strpos($col_db, 'CALCULADO_') !== false) {
            $necesita_calculo = true;
            $mapa_col_alias[$col_db] = $header_name;
        } else {
            $select_parts[] = "$col_db AS $alias_seguro";
            $mapa_col_alias[$alias_seguro] = $header_name;
        }
    }

    if (empty($select_parts)) {
        $select_parts[] = "at.id AS col_default";
        $mapa_col_alias['col_default'] = "ID Activo";
    }

    if ($necesita_calculo) {
        $select_parts[] = "at.valor_aproximado AS _calc_valor";
        $select_parts[] = "at.valor_residual AS _calc_residual";
        $select_parts[] = "at.fecha_compra AS _calc_fecha";
        $select_parts[] = "at.vida_util AS _calc_vida";
        $select_parts[] = "ta.vida_util_sugerida AS _calc_sugerida";
    }

    $sql_select = implode(", ", $select_parts);
    
    $joins = " FROM activos_tecnologicos at
               LEFT JOIN tipos_activo ta ON at.id_tipo_activo = ta.id_tipo_activo
               LEFT JOIN categorias_activo cat ON ta.id_categoria = cat.id_categoria
               LEFT JOIN usuarios u ON at.id_usuario_responsable = u.id
               LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
               LEFT JOIN centros_costo cc ON at.id_centro_costo = cc.id_centro_costo
               LEFT JOIN regionales r ON cc.id_regional = r.id_regional
               LEFT JOIN prestamos_activos p ON (at.id = p.id_activo AND p.fecha_devolucion_real IS NULL) ";

    $where_conds = ["at.estado != 'Dado de Baja'"];
    $params = [];
    $types = "";

    if (!empty($fecha_desde) && !empty($fecha_hasta)) {
        $fh = $fecha_hasta . ' 23:59:59';
        $where_conds[] = "at.fecha_compra BETWEEN ? AND ?";
        $params[] = $fecha_desde; $params[] = $fh; $types .= "ss";
    } elseif (!empty($fecha_desde)) {
        $where_conds[] = "at.fecha_compra >= ?";
        $params[] = $fecha_desde; $types .= "s";
    }

    if (!empty($q_busqueda)) {
        $term = "%{$q_busqueda}%";
        $where_conds[] = "(u.usuario LIKE ? OR u.nombre_completo LIKE ? OR at.serie LIKE ? OR at.Codigo_Inv LIKE ?)";
        $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term; $types .= "ssss";
    }

    $order_by = " ORDER BY at.id ASC";
    if ($tipo_informe == 'activos_en_prestamo') {
        $where_conds = ["p.id_prestamo IS NOT NULL AND p.fecha_devolucion_real IS NULL"];
    } elseif ($tipo_informe == 'dados_baja') {
        $where_conds = ["at.estado = 'Dado de Baja'"];
    } elseif ($tipo_informe == 'general') {
        $order_by = " ORDER BY u.empresa, u.nombre_completo";
    }

    $sql_where = " WHERE " . implode(" AND ", $where_conds);
    $sql_final = "SELECT $sql_select $joins $sql_where $order_by";

    $stmt = $conexion->prepare($sql_final);
    if (!$stmt) throw new Exception("Error SQL: " . $conexion->error);
    
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) throw new Exception("Error Ejecución: " . $stmt->error);
    $result = $stmt->get_result();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Reporte');

    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF191970']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ];

    $col = 'A';
    foreach ($mapa_col_alias as $key => $headerName) {
        $sheet->setCellValue($col . '1', $headerName);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }
    $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray($headerStyle);

    $rowNum = 2;
    while ($row = $result->fetch_assoc()) {
        $col = 'A';
        
        $res_calc = [];
        if ($necesita_calculo) {
            $v_compra = floatval($row['_calc_valor'] ?? 0);
            $v_residual = floatval($row['_calc_residual'] ?? 0);
            $f_compra = $row['_calc_fecha'] ?? null;
            $vida_base = intval($row['_calc_vida'] > 0 ? $row['_calc_vida'] : ($row['_calc_sugerida'] ?? 0));
            
            $salarios = ($SMMLV_2025 > 0) ? ($v_compra / $SMMLV_2025) : 0;
            $es_gasto = ($salarios < $UMBRAL_DEPRECIACION);

            if ($es_gasto) {
                $res_calc['CALCULADO_DEP_MENSUAL'] = 0;
                $res_calc['CALCULADO_DEP_ACUMULADA'] = $v_compra;
                $res_calc['CALCULADO_VALOR_LIBROS'] = 0;
            } elseif ($f_compra && $vida_base > 0) {
                $f_inicio = new DateTime($f_compra);
                $f_actual = new DateTime();
                $meses_uso = 0;
                if ($f_actual > $f_inicio) {
                    $meses_uso = (($f_actual->format('Y') - $f_inicio->format('Y')) * 12) + ($f_actual->format('m') - $f_inicio->format('m'));
                }
                $vida_meses = $vida_base * 12;
                $valor_base_dep = max(0, $v_compra - $v_residual);
                $dep_men = $valor_base_dep / $vida_meses;
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

            $es_moneda = stripos($headerName, 'Valor') !== false || stripos($headerName, 'Costo') !== false || stripos($headerName, 'Depreciación') !== false;
            
            if ($es_moneda && is_numeric($valor_final)) {
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
    echo "<div style='padding:20px; border:1px solid red; background:#ffe6e6;'><h3>Error en Exportación:</h3>" . $e->getMessage() . "</div>";
}
?>