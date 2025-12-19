<?php
// =================================================================================
// ARCHIVO: exportar_auditoria.php
// ESTADO: CORREGIDO (Eliminado error 'a.modelo', agregado 'a.detalles')
// =================================================================================

require 'vendor/autoload.php'; 
require_once 'backend/auth_check.php';
require_once 'backend/db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// 1. LIMPIAR BÚFER (Evita errores de archivo corrupto)
if (ob_get_level()) ob_end_clean();
ob_start();

$id_auditoria = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 2. OBTENER DATOS DE LA AUDITORÍA
$stmt = $conexion->prepare("SELECT a.*, u.nombre_completo as auditor FROM auditorias a JOIN usuarios u ON a.id_usuario_auditor = u.id WHERE a.id_auditoria = ?");
$stmt->bind_param("i", $id_auditoria);
$stmt->execute();
$info = $stmt->get_result()->fetch_assoc();

if (!$info) die("Auditoría no encontrada.");

// 3. OBTENER DETALLES (Consulta Corregida)
$sql = "SELECT d.estado_auditoria, d.observacion_auditor, 
               a.serie, a.Codigo_Inv, a.marca, a.detalles, 
               ta.nombre_tipo_activo, u.nombre_completo as responsable
        FROM auditoria_detalles d
        JOIN activos_tecnologicos a ON d.id_activo = a.id
        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
        LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
        WHERE d.id_auditoria = $id_auditoria
        ORDER BY d.estado_auditoria ASC";

$result = $conexion->query($sql);

if (!$result) {
    die("Error en SQL: " . $conexion->error);
}

$items = $result->fetch_all(MYSQLI_ASSOC);

// Clasificar items
$faltantes = array_filter($items, fn($i) => $i['estado_auditoria'] === 'No Encontrado');
$novedades = array_filter($items, fn($i) => strpos($i['estado_auditoria'], 'Malo') !== false || !empty($i['observacion_auditor']));

// 4. CREAR EXCEL
$spreadsheet = new Spreadsheet();

// --- ESTILOS COMUNES ---
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF191970']], // Azul oscuro
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
];
$redStyle = [
    'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDC3545']], // Rojo
];

// --- HOJA 1: RESUMEN Y FALTANTES ---
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Faltantes y Resumen');

// Encabezado del Reporte
$sheet->setCellValue('A1', 'AUDITORÍA: ' . $info['nombre_auditoria']);
$sheet->setCellValue('A2', 'Auditor: ' . $info['auditor']);
$sheet->setCellValue('A3', 'Fecha Cierre: ' . $info['fecha_cierre']);
$sheet->mergeCells('A1:F1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

// Tabla de Faltantes
$sheet->setCellValue('A5', 'LISTADO DE ACTIVOS NO ENCONTRADOS (PÉRDIDAS)');
$sheet->mergeCells('A5:F5');
$sheet->getStyle('A5')->applyFromArray($redStyle);

$headers = ['Serie', 'Placa Inventario', 'Tipo Activo', 'Marca', 'Responsable Sistema', 'Observaciones Auditor'];
$col = 'A';
foreach($headers as $h) { $sheet->setCellValue($col++ . '6', $h); }
$sheet->getStyle('A6:F6')->applyFromArray($headerStyle);

$rowNum = 7;
foreach ($faltantes as $row) {
    $sheet->setCellValue('A' . $rowNum, $row['serie']);
    $sheet->setCellValue('B' . $rowNum, $row['Codigo_Inv']);
    $sheet->setCellValue('C' . $rowNum, $row['nombre_tipo_activo']);
    $sheet->setCellValue('D' . $rowNum, $row['marca']);
    $sheet->setCellValue('E' . $rowNum, $row['responsable']);
    $sheet->setCellValue('F' . $rowNum, $row['observacion_auditor']);
    $rowNum++;
}
foreach(range('A','F') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);


// --- HOJA 2: NOVEDADES ---
$spreadsheet->createSheet();
$sheet = $spreadsheet->setActiveSheetIndex(1);
$sheet->setTitle('Novedades y Daños');

$headers = ['Serie', 'Tipo', 'Estado Auditoría', 'Responsable', 'Detalle Novedad'];
$col = 'A';
foreach($headers as $h) { $sheet->setCellValue($col++ . '1', $h); }
$sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

$rowNum = 2;
foreach ($novedades as $row) {
    $sheet->setCellValue('A' . $rowNum, $row['serie']);
    $sheet->setCellValue('B' . $rowNum, $row['nombre_tipo_activo']);
    $sheet->setCellValue('C' . $rowNum, $row['estado_auditoria']);
    $sheet->setCellValue('D' . $rowNum, $row['responsable']);
    $sheet->setCellValue('E' . $rowNum, $row['observacion_auditor']);
    $rowNum++;
}
foreach(range('A','E') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);


// --- HOJA 3: LISTADO COMPLETO ---
$spreadsheet->createSheet();
$sheet = $spreadsheet->setActiveSheetIndex(2);
$sheet->setTitle('Listado Completo');

$headers = ['Estado', 'Serie', 'Placa', 'Tipo', 'Marca', 'Detalles', 'Responsable', 'Observaciones'];
$col = 'A';
foreach($headers as $h) { $sheet->setCellValue($col++ . '1', $h); }
$sheet->getStyle('A1:H1')->applyFromArray($headerStyle);

$rowNum = 2;
foreach ($items as $row) {
    $sheet->setCellValue('A' . $rowNum, $row['estado_auditoria']);
    $sheet->setCellValue('B' . $rowNum, $row['serie']);
    $sheet->setCellValue('C' . $rowNum, $row['Codigo_Inv']);
    $sheet->setCellValue('D' . $rowNum, $row['nombre_tipo_activo']);
    $sheet->setCellValue('E' . $rowNum, $row['marca']);
    $sheet->setCellValue('F' . $rowNum, $row['detalles']); // Dato agregado
    $sheet->setCellValue('G' . $rowNum, $row['responsable']);
    $sheet->setCellValue('H' . $rowNum, $row['observacion_auditor']);
    $rowNum++;
}
foreach(range('A','H') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

// Volver a la primera hoja
$spreadsheet->setActiveSheetIndex(0);

// Descargar
ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Resultados_Auditoria_'.$id_auditoria.'.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>