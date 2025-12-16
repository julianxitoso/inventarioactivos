<?php
// =================================================================================
// ARCHIVO: procesar_importacion_completo.php
// DESCRIPCIÓN: Importador Maestro (Corrige error de Series duplicadas 'S/N')
// =================================================================================

session_start();
require_once __DIR__ . '/backend/auth_check.php';
restringir_acceso_pagina(['admin']);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/backend/db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

set_time_limit(300); 
ini_set('memory_limit', '256M');

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion)) { die("Error DB"); }
$conexion->set_charset("utf8mb4");

if (isset($_FILES['archivo_excel']) && $_FILES['archivo_excel']['error'] == UPLOAD_ERR_OK) {
    $nombreArchivo = $_FILES['archivo_excel']['tmp_name'];
    
    try {
        $spreadsheet = IOFactory::load($nombreArchivo);
        $worksheet = $spreadsheet->getActiveSheet();
        $filas = $worksheet->getRowIterator(2);

        $stats = ['cat_new'=>0, 'tipo_new'=>0, 'activos'=>0, 'omitidos'=>0];
        $errores = [];

        // --- CACHÉS ---
        $cache_cat = [];
        $res = $conexion->query("SELECT id_categoria, LOWER(nombre_categoria) as n FROM categorias_activo");
        while($r = $res->fetch_assoc()) $cache_cat[$r['n']] = $r['id_categoria'];

        $cache_tipo = [];
        $res = $conexion->query("SELECT id_tipo_activo, LOWER(nombre_tipo_activo) as n FROM tipos_activo");
        while($r = $res->fetch_assoc()) $cache_tipo[$r['n']] = $r['id_tipo_activo'];

        $cache_user = [];
        $res = $conexion->query("SELECT id, usuario FROM usuarios");
        while($r = $res->fetch_assoc()) $cache_user[$r['usuario']] = $r['id'];

        $identificadores_procesados = [];

        foreach ($filas as $fila) {
            $cellIterator = $fila->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $d = [];
            foreach ($cellIterator as $celda) $d[] = trim($celda->getValue() ?? '');

            // MAPEO (13 Columnas)
            $cat_nombre = $d[0];
            $cat_codigo = (int)($d[1] ?? 0);
            
            $tipo_nombre = $d[2];
            $vida_util_tipo = (int)($d[3] ?? 0);
            
            $cod_inv_excel = strtoupper($d[4]);
            $serie = strtoupper($d[5]);
            
            $marca = $d[6];
            $modelo = $d[7];
            $cedula = $d[8];
            $estado = $d[9] ?: 'Bueno';
            $costo = (float)($d[10] ?? 0);
            $fecha_raw = $d[11];
            $detalles_extra = $d[12] ?? ''; 

            // 1. VALIDACIÓN DE IDENTIDAD
            if (empty($serie) && empty($cod_inv_excel)) {
                if(empty(implode('', $d))) continue; 
                $errores[] = "Fila {$fila->getRowIndex()}: Falta Serie o Código Inventario.";
                $stats['omitidos']++; continue;
            }

            // --- CORRECCIÓN DUPLICADOS S/N ---
            // Si no tiene serie, usamos el Código de Inventario como base para crear una serie única virtual
            if (empty($serie)) {
                // Ejemplo: Si el código es 'SILLA-01', la serie será 'SN-SILLA-01'
                $serie = "SN-" . $cod_inv_excel;
            }

            // 2. VALIDAR DUPLICADOS (DB)
            $condiciones_dup = [];
            if (!empty($cod_inv_excel)) $condiciones_dup[] = "Codigo_Inv = '$cod_inv_excel'";
            
            // Solo validamos serie si no es una generada automáticamente (aunque igual debe ser única)
            $condiciones_dup[] = "serie = '$serie'";
            
            if (!empty($condiciones_dup)) {
                $sql_check = "SELECT id FROM activos_tecnologicos WHERE " . implode(' OR ', $condiciones_dup);
                $check = $conexion->query($sql_check);
                if ($check->num_rows > 0) {
                    $errores[] = "Fila {$fila->getRowIndex()}: Duplicado en BD (Serie '$serie' o Código '$cod_inv_excel' ya existen).";
                    $stats['omitidos']++; continue;
                }
            }

            // 3. VALIDAR DUPLICADOS (Archivo)
            $clave_unica = $serie . '_' . $cod_inv_excel;
            if (in_array($clave_unica, $identificadores_procesados)) {
                $errores[] = "Fila {$fila->getRowIndex()}: Duplicado en archivo.";
                $stats['omitidos']++; continue;
            }

            // 4. PROCESAR CATEGORÍA
            if (empty($cat_nombre)) { $errores[] = "Fila {$fila->getRowIndex()}: Falta Categoría."; $stats['omitidos']++; continue; }
            $cat_key = strtolower($cat_nombre);
            
            if (!isset($cache_cat[$cat_key])) {
                $stmt = $conexion->prepare("INSERT INTO categorias_activo (nombre_categoria, cod_contable, descripcion_categoria) VALUES (?, ?, 'Imp. Masiva')");
                $stmt->bind_param("si", $cat_nombre, $cat_codigo);
                
                if($stmt->execute()) {
                    $cache_cat[$cat_key] = $stmt->insert_id;
                    $stats['cat_new']++;
                } else {
                    $errores[] = "Fila {$fila->getRowIndex()}: Error creando categoría.";
                    $stats['omitidos']++; continue;
                }
            }
            $id_cat = $cache_cat[$cat_key];

            // 5. PROCESAR TIPO
            if (empty($tipo_nombre)) { $errores[] = "Fila {$fila->getRowIndex()}: Falta Tipo."; $stats['omitidos']++; continue; }
            $tipo_key = strtolower($tipo_nombre);
            if (!isset($cache_tipo[$tipo_key])) {
                $stmt = $conexion->prepare("INSERT INTO tipos_activo (nombre_tipo_activo, id_categoria, vida_util_sugerida) VALUES (?, ?, ?)");
                $stmt->bind_param("sii", $tipo_nombre, $id_cat, $vida_util_tipo);
                if($stmt->execute()) {
                    $cache_tipo[$tipo_key] = $stmt->insert_id;
                    $stats['tipo_new']++;
                } else {
                    $errores[] = "Fila {$fila->getRowIndex()}: Error creando tipo.";
                    $stats['omitidos']++; continue;
                }
            }
            $id_tipo = $cache_tipo[$tipo_key];

            // 6. VALIDAR USUARIO
            if (!isset($cache_user[$cedula])) {
                $errores[] = "Fila {$fila->getRowIndex()}: Cédula '$cedula' no encontrada.";
                $stats['omitidos']++; continue;
            }
            $id_user = $cache_user[$cedula];

            // 7. FECHA
            if (is_numeric($fecha_raw)) {
                $fecha_compra = Date::excelToDateTimeObject($fecha_raw)->format('Y-m-d');
            } else {
                $fecha_compra = !empty($fecha_raw) ? date('Y-m-d', strtotime($fecha_raw)) : date('Y-m-d');
            }

            // 8. INSERTAR ACTIVO
            $detalles_final = "Modelo: $modelo";
            if (!empty($detalles_extra)) {
                $detalles_final .= ". " . $detalles_extra;
            } else {
                $detalles_final .= ". Imp. Masiva";
            }
            
            // Si no trajo código de inventario, generamos uno temporal
            $codigo_final = !empty($cod_inv_excel) ? $cod_inv_excel : 'IMP-' . strtoupper(substr(md5($serie . time()), 0, 6));

            $sql = "INSERT INTO activos_tecnologicos 
                    (serie, marca, id_tipo_activo, id_usuario_responsable, estado, valor_aproximado, fecha_compra, detalles, Codigo_Inv, vida_util, id_centro_costo) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, (SELECT id_centro_costo FROM usuarios WHERE id = ? LIMIT 1))";
            
            $stmt = $conexion->prepare($sql);
            $vida_real = ($vida_util_tipo > 0) ? $vida_util_tipo : 0; 
            
            $stmt->bind_param("ssiisssssii", $serie, $marca, $id_tipo, $id_user, $estado, $costo, $fecha_compra, $detalles_final, $codigo_final, $vida_real, $id_user);

            if ($stmt->execute()) {
                $stats['activos']++;
                $identificadores_procesados[] = $clave_unica;
            } else {
                $errores[] = "Fila {$fila->getRowIndex()}: Error SQL - " . $stmt->error;
                $stats['omitidos']++;
            }
        }

        $_SESSION['import_success_message'] = "Proceso Completo.<br>Activos: <b>{$stats['activos']}</b> | Categorías: <b>{$stats['cat_new']}</b> | Tipos: <b>{$stats['tipo_new']}</b>";
        if ($stats['omitidos'] > 0) {
            $_SESSION['import_error_message'] = "Se omitieron <b>{$stats['omitidos']}</b> filas.";
            $_SESSION['import_errors'] = $errores;
        }

    } catch (Exception $e) {
        $_SESSION['import_error_message'] = "Error: " . $e->getMessage();
    }
} else {
    $_SESSION['import_error_message'] = "No se subió archivo.";
}

header("Location: importar.php");
exit;
?>