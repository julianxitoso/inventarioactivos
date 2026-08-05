<?php
// =================================================================================
// ARCHIVO: procesar_importacion_activos.php
// DESCRIPCIÓN: Script para importar activos desde un archivo Excel a la tabla activos_tecnologicos.
// =================================================================================

session_start();
require_once __DIR__ . '/backend/auth_check.php';
verificar_permiso_o_morir('crear_activo'); // Solo usuarios con permiso de crear pueden importar

// Habilitar visualización de errores para depuración (SOLO DESPUÉS de auth_check)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/backend/db.php'; // Asegúrate de que esta ruta sea correcta
require_once __DIR__ . '/vendor/autoload.php'; // Carga de PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// --- VERIFICACIÓN DE CONEXIÓN A BD ---
if (!isset($conexion) || !$conexion || (is_object($conexion) && property_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    die("Error crítico de conexión a la base de datos. No se puede continuar.");
}
$conexion->set_charset("utf8mb4");

$uploadDir = __DIR__ . '/uploads/'; // Directorio donde se guardará el archivo Excel
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$mensaje = "";
$errores_importacion = [];
$activos_importados_count = 0;
$filas_procesadas = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];

    // Validar subida de archivo
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $mensaje = "<div class='alert alert-danger'>Error al subir el archivo: " . $file['error'] . "</div>";
    } elseif ($file['type'] !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
        $mensaje = "<div class='alert alert-danger'>Error: El archivo debe ser de tipo .xlsx</div>";
    } else {
        $filePath = $uploadDir . basename($file['name']);
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            $mensaje = "<div class='alert alert-danger'>Error al mover el archivo subido.</div>";
        } else {
            try {
                $spreadsheet = IOFactory::load($filePath);
                $sheet = $spreadsheet->getActiveSheet();
                $highestRow = $sheet->getHighestRow();

                // Preparar statements para inserciones/búsquedas
                $stmt_get_categoria_id = $conexion->prepare("SELECT id_categoria FROM categorias_activo WHERE nombre_categoria = ?");
                $stmt_insert_categoria = $conexion->prepare("INSERT INTO categorias_activo (nombre_categoria, cod_contable) VALUES (?, ?)");
                
                $stmt_get_tipo_id = $conexion->prepare("SELECT id_tipo_activo, vida_util_sugerida FROM tipos_activo WHERE nombre_tipo_activo = ? AND id_categoria = ?");
                $stmt_insert_tipo = $conexion->prepare("INSERT INTO tipos_activo (nombre_tipo_activo, vida_util_sugerida, id_categoria) VALUES (?, ?, ?)");
                
                $stmt_get_user_info = $conexion->prepare("SELECT id, id_centro_costo FROM usuarios WHERE usuario = ? AND activo = 1");
                
                $stmt_check_serie_exist = $conexion->prepare("SELECT id FROM activos_tecnologicos WHERE serie = ?");
                $stmt_check_codigo_exist = $conexion->prepare("SELECT id FROM activos_tecnologicos WHERE Codigo_Inv = ?");

                $stmt_insert_activo = $conexion->prepare("INSERT INTO activos_tecnologicos (
                    id_usuario_responsable, id_tipo_activo, marca, serie, estado, valor_aproximado, 
                    fecha_compra, Codigo_Inv, vida_util, detalles, id_centro_costo, fecha_registro
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

                // Iniciar transacción
                $conexion->begin_transaction();

                for ($row = 2; $row <= $highestRow; $row++) { // Empezar desde la fila 2 (después de los encabezados)
                    $filas_procesadas++;
                    $nombre_categoria = trim($sheet->getCell('A' . $row)->getValue() ?? '');
                    $codigo_contable_categoria = trim($sheet->getCell('B' . $row)->getValue() ?? '0');
                    $nombre_tipo_activo = trim($sheet->getCell('C' . $row)->getValue() ?? '');
                    $vida_util_sugerida = (int)($sheet->getCell('D' . $row)->getValue() ?? 0);
                    $codigo_inventario = trim($sheet->getCell('E' . $row)->getValue() ?? '');
                    $serie = trim($sheet->getCell('F' . $row)->getValue() ?? '');
                    $marca = trim($sheet->getCell('G' . $row)->getValue() ?? '');
                    $modelo = trim($sheet->getCell('H' . $row)->getValue() ?? '');
                    $cedula_responsable = trim($sheet->getCell('I' . $row)->getValue() ?? '');
                    $estado = trim($sheet->getCell('J' . $row)->getValue() ?? 'Bueno');
                    $valor_compra = (float)($sheet->getCell('K' . $row)->getValue() ?? 0.00);
                    $fecha_compra_excel = $sheet->getCell('L' . $row)->getValue();
                    $detalles = trim($sheet->getCell('M' . $row)->getValue() ?? '');

                    // --- Validaciones iniciales ---
                    if (empty($nombre_categoria) || empty($nombre_tipo_activo) || empty($cedula_responsable)) {
                        $errores_importacion[] = "Fila {$row}: Campos obligatorios (Categoría, Tipo, Cédula Responsable) vacíos. Fila omitida.";
                        continue;
                    }
                    if (empty($serie) && empty($codigo_inventario)) {
                        $errores_importacion[] = "Fila {$row}: No se proporcionó Serie ni Código de Inventario. Fila omitida.";
                        continue;
                    }
                    if (empty($serie) && !empty($codigo_inventario)) {
                        $serie = "SN-" . $codigo_inventario; // Generar serie si solo hay código de inventario
                    }

                    // --- Procesar Fecha de Compra ---
                    $fecha_compra = date('Y-m-d'); // Por defecto, hoy
                    if (!empty($fecha_compra_excel)) {
                        if (is_numeric($fecha_compra_excel) && Date::excelToDateTimeObject($fecha_compra_excel)) {
                            $fecha_compra = Date::excelToDateTimeObject($fecha_compra_excel)->format('Y-m-d');
                        } else {
                            try {
                                $dateObj = new DateTime($fecha_compra_excel);
                                $fecha_compra = $dateObj->format('Y-m-d');
                            } catch (Exception $e) {
                                $errores_importacion[] = "Fila {$row}: Formato de fecha de compra inválido ('" . htmlspecialchars($fecha_compra_excel) . "'). Usando fecha actual.";
                            }
                        }
                    }

                    // --- Buscar/Crear Categoría ---
                    $id_categoria = null;
                    $stmt_get_categoria_id->bind_param("s", $nombre_categoria);
                    $stmt_get_categoria_id->execute();
                    $res_cat = $stmt_get_categoria_id->get_result();
                    if ($row_cat = $res_cat->fetch_assoc()) {
                        $id_categoria = $row_cat['id_categoria'];
                    } else {
                        $stmt_insert_categoria->bind_param("si", $nombre_categoria, $codigo_contable_categoria);
                        if ($stmt_insert_categoria->execute()) {
                            $id_categoria = $stmt_insert_categoria->insert_id;
                        } else {
                            $errores_importacion[] = "Fila {$row}: Error al crear categoría '" . htmlspecialchars($nombre_categoria) . "': " . $stmt_insert_categoria->error;
                            continue;
                        }
                    }

                    // --- Buscar/Crear Tipo de Activo ---
                    $id_tipo_activo = null;
                    $vida_util_final = $vida_util_sugerida; // Usar la sugerida si es nuevo tipo
                    $stmt_get_tipo_id->bind_param("si", $nombre_tipo_activo, $id_categoria);
                    $stmt_get_tipo_id->execute();
                    $res_tipo = $stmt_get_tipo_id->get_result();
                    if ($row_tipo = $res_tipo->fetch_assoc()) {
                        $id_tipo_activo = $row_tipo['id_tipo_activo'];
                        $vida_util_final = $row_tipo['vida_util_sugerida']; // Si ya existe, usar su vida útil
                    } else {
                        $stmt_insert_tipo->bind_param("sii", $nombre_tipo_activo, $vida_util_sugerida, $id_categoria);
                        if ($stmt_insert_tipo->execute()) {
                            $id_tipo_activo = $stmt_insert_tipo->insert_id;
                        } else {
                            $errores_importacion[] = "Fila {$row}: Error al crear tipo de activo '" . htmlspecialchars($nombre_tipo_activo) . "': " . $stmt_insert_tipo->error;
                            continue;
                        }
                    }

                    // --- Buscar Información del Responsable ---
                    $id_usuario_responsable = null;
                    $id_centro_costo = null;
                    $stmt_get_user_info->bind_param("s", $cedula_responsable);
                    $stmt_get_user_info->execute();
                    $res_user_info = $stmt_get_user_info->get_result();
                    if ($user_data = $res_user_info->fetch_assoc()) {
                        $id_usuario_responsable = $user_data['id'];
                        $id_centro_costo = $user_data['id_centro_costo'];
                    } else {
                        $errores_importacion[] = "Fila {$row}: Cédula de responsable '" . htmlspecialchars($cedula_responsable) . "' no encontrada o usuario inactivo. Fila omitida.";
                        continue;
                    }

                    // --- Validar Duplicados ---
                    $is_duplicate = false;
                    $stmt_check_serie_exist->bind_param("s", $serie);
                    $stmt_check_serie_exist->execute();
                    if ($stmt_check_serie_exist->get_result()->num_rows > 0) {
                        $errores_importacion[] = "Fila {$row}: Serie '" . htmlspecialchars($serie) . "' ya existe en la base de datos. Fila omitida.";
                        $is_duplicate = true;
                    }
                    if (!$is_duplicate && !empty($codigo_inventario)) {
                        $stmt_check_codigo_exist->bind_param("s", $codigo_inventario);
                        $stmt_check_codigo_exist->execute();
                        if ($stmt_check_codigo_exist->get_result()->num_rows > 0) {
                            $errores_importacion[] = "Fila {$row}: Código de Inventario '" . htmlspecialchars($codigo_inventario) . "' ya existe en la base de datos. Fila omitida.";
                            $is_duplicate = true;
                        }
                    }
                    if ($is_duplicate) {
                        continue;
                    }

                    // --- Combinar Modelo en Detalles ---
                    if (!empty($modelo)) {
                        $detalles = (empty($detalles) ? "" : $detalles . ". ") . "Modelo: " . $modelo;
                    }
                    
                    // --- Insertar Activo ---
                    $stmt_insert_activo->bind_param("iisdssisisi",
                        $id_usuario_responsable,
                        $id_tipo_activo,
                        $marca,
                        $serie,
                        $estado,
                        $valor_compra,
                        $fecha_compra,
                        $codigo_inventario,
                        $vida_util_final,
                        $detalles,
                        $id_centro_costo
                    );

                    if ($stmt_insert_activo->execute()) {
                        $activos_importados_count++;
                    } else {
                        $errores_importacion[] = "Fila {$row}: Error al insertar activo (Serie: " . htmlspecialchars($serie) . "): " . $stmt_insert_activo->error;
                    }
                }

                $stmt_get_categoria_id->close();
                $stmt_insert_categoria->close();
                $stmt_get_tipo_id->close();
                $stmt_insert_tipo->close();
                $stmt_get_user_info->close();
                $stmt_check_serie_exist->close();
                $stmt_check_codigo_exist->close();
                $stmt_insert_activo->close();

                $conexion->commit();
                $mensaje = "<div class='alert alert-success'>Importación finalizada. Se procesaron {$filas_procesadas} filas. Se importaron {$activos_importados_count} activos.</div>";
                if (!empty($errores_importacion)) {
                    $mensaje .= "<div class='alert alert-warning'>Con errores:<ul>";
                    foreach ($errores_importacion as $error) {
                        $mensaje .= "<li>" . htmlspecialchars($error) . "</li>";
                    }
                    $mensaje .= "</ul></div>";
                }

            } catch (Exception $e) {
                $conexion->rollback();
                $mensaje = "<div class='alert alert-danger'>Error crítico durante la importación: " . htmlspecialchars($e->getMessage()) . "</div>";
            } finally {
                unlink($filePath); // Eliminar el archivo temporal
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Activos desde Excel</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .container { max-width: 800px; margin-top: 50px; }
        .card { border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .card-header { background-color: #191970; color: white; border-radius: 10px 10px 0 0; }
        .btn-primary { background-color: #191970; border-color: #191970; }
        .btn-primary:hover { background-color: #14145a; border-color: #14145a; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header text-center">
                <h4 class="mb-0"><i class="bi bi-file-earmark-spreadsheet-fill me-2"></i> Importar Activos desde Excel</h4>
            </div>
            <div class="card-body">
                <?php if ($mensaje) echo $mensaje; ?>

                <p class="text-muted">Utilice esta herramienta para cargar masivamente activos desde un archivo Excel. Asegúrese de que su archivo cumpla con el formato de la plantilla maestra.</p>
                
                <h5 class="mt-4 mb-3">Subir Archivo Excel</h5>
                <form action="procesar_importacion_activos.php" method="post" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="excel_file" class="form-label">Seleccione el archivo .xlsx:</label>
                        <input class="form-control" type="file" id="excel_file" name="excel_file" accept=".xlsx" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-2"></i> Cargar y Procesar</button>
                </form>

                <h5 class="mt-5 mb-3">Descargar Plantilla Maestra</h5>
                <p>Descargue la plantilla para asegurarse de que su archivo Excel tenga el formato correcto.</p>
                <a href="GUIA_IMPORTACION_ACTIVOS.md" class="btn btn-info" download="plantilla_importacion_activos.xlsx">
                    <i class="bi bi-download me-2"></i> Descargar Plantilla (Formato Markdown)
                </a>
                <p class="mt-2 text-muted small">
                    <i class="bi bi-info-circle-fill"></i> La plantilla descargada es un archivo Markdown. Copie su contenido en un editor de texto y guárdelo como `.xlsx` o copie la tabla directamente en Excel.
                </p>
            </div>
        </div>
        <div class="text-center mt-3">
            <a href="menu.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle"></i> Volver al Menú Principal</a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>