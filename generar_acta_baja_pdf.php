<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/backend/auth_check.php'; 
require_once __DIR__ . '/backend/db.php'; 
require_once __DIR__ . '/lib/fpdf/fpdf.php';

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error crítico de conexión a la base de datos."); }
$conexion->set_charset("utf8mb4");

if (!isset($_GET['id_historial']) || empty($_GET['id_historial'])) {
    die("Error: No se proporcionó el ID del historial.");
}
$id_historial = (int)$_GET['id_historial'];

$sql = "SELECT 
            h.fecha_evento, h.descripcion_evento, h.usuario_responsable as auditor,
            a.Codigo_Inv, a.serie, a.marca, a.estado as estado_fisico, a.detalles,
            ta.nombre_tipo_activo,
            u.nombre_completo as responsable_nombre, u.usuario as responsable_cedula, 
            u.regional, u.empresa,
            c.nombre_cargo
        FROM historial_activos h
        INNER JOIN activos_tecnologicos a ON h.id_activo = a.id
        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
        LEFT JOIN usuarios u ON a.id_usuario_responsable = u.id
        LEFT JOIN cargos c ON u.id_cargo = c.id_cargo
        WHERE h.id_historial = ?";

$stmt = $conexion->prepare($sql);
if(!$stmt) die("Error al preparar la consulta: ".$conexion->error);
$stmt->bind_param("i", $id_historial);
$stmt->execute();
$result = $stmt->get_result();
$hist = $result->fetch_assoc();
$stmt->close();

if (!$hist) {
    die("No se encontró el registro de baja para el ID proporcionado.");
}

class PDF_Acta_Baja extends FPDF {
    function to_iso($string) { return mb_convert_encoding($string, 'ISO-8859-1', 'UTF-8'); }
    
    function Header() {}
    function Footer() {}
}

$pdf = new PDF_Acta_Baja('P', 'mm', 'Letter');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 10);
$pdf->AddPage();

$w_total = 195; // Ancho total útil de la página

// ==========================================
// BLOQUE 1: CABECERA (ENMARCADA)
// ==========================================
$y_start = $pdf->GetY();
$x_start = $pdf->GetX();

// Dibujar marco exterior grueso
$pdf->SetLineWidth(0.5);
$pdf->Rect($x_start, $y_start, $w_total, 30);
$pdf->SetLineWidth(0.2); // Volver al grosor normal

// Logo
$pdf->Image('imagenes/logo.png', $x_start + 2, $y_start + 4, 38);

// División vertical logo
$pdf->Line($x_start + 42, $y_start, $x_start + 42, $y_start + 30);
// División vertical vacía derecha
$pdf->Line($x_start + 160, $y_start, $x_start + 160, $y_start + 30);

// Textos centrales (Ajustados para que no se desborden)
$pdf->SetFont('Arial', 'B', 8); // Letra ligeramente más pequeña para que encaje
$pdf->SetXY($x_start + 42, $y_start + 2);
$pdf->Cell(118, 4, $pdf->to_iso('PROCESO EVALUACIÓN Y CONTROL'), 0, 1, 'C');
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 4, $pdf->to_iso('PROCEDIMIENTO DE AUDITORIA INTERNA'), 0, 1, 'C');
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 4, $pdf->to_iso('ARPESOD ASOCIADOS SAS'), 0, 1, 'C');
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 4, 'NIT. 900.333.755-6', 0, 1, 'C');

$pdf->Ln(1); // Pequeño espacio separador

$pdf->SetFont('Arial', 'B', 9); // Letra un poco más grande para el título principal
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 5, $pdf->to_iso('SOLICITUD DE INGRESO, TRASLADO Y/O'), 0, 1, 'C');
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 5, $pdf->to_iso('DAR DE BAJA ACTIVOS FIJOS'), 0, 1, 'C');

$pdf->SetY($y_start + 30);

// ==========================================
// BLOQUE 2: DATOS DEL DOCUMENTO
// ==========================================
$pdf->SetFont('Arial', 'B', 8);

// Fila 1: Fecha / Regional
$y_current = $pdf->GetY();
$pdf->Cell($w_total, 7, '', 1, 0); // Contenedor
$pdf->SetXY($x_start, $y_current);
$pdf->Cell(85, 7, 'Fecha: ' . date('d/m/Y', strtotime($hist['fecha_evento'])), 0, 0, 'L');
$pdf->Line($x_start + 85, $y_current, $x_start + 85, $y_current + 7); // División vertical
$pdf->SetX($x_start + 86);
$pdf->Cell(109, 7, 'Regional: ' . $pdf->to_iso($hist['regional'] ?? 'N/A'), 0, 1, 'L');

// Fila 2: Area / Punto Venta
$y_current = $pdf->GetY();
$pdf->Cell($w_total, 7, '', 1, 0); // Contenedor
$pdf->SetXY($x_start, $y_current);
$pdf->Cell(85, 7, $pdf->to_iso('Área: ' . ($hist['nombre_cargo'] ?? 'N/A')), 0, 0, 'L');
$pdf->Line($x_start + 85, $y_current, $x_start + 85, $y_current + 7); // División vertical
$pdf->SetX($x_start + 86);
$pdf->Cell(109, 7, 'Punto de Venta: ' . $pdf->to_iso($hist['empresa'] ?? 'N/A'), 0, 1, 'L');

// ==========================================
// BLOQUE 3: TEXTO LEGAL (ENMARCADO)
// ==========================================
$texto_legal = "Para formalizar la solicitud, en la presente acta quedaran consignados los equipos y muebles que están bajo su responsabilidad, buen uso y cuidado. Los daños que se generen le serán descontados automáticamente.\n\nCuando haya terminación del contrato laboral o retiro voluntario, usted debe hacer entrega de los activos fijos aquí estipulados al líder de zona o en su defecto al nuevo encargado del puesto, ya que este será un requisito indispensable para la firma de paz y salvo por parte de la empresa.";

$y_text = $pdf->GetY();
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell($w_total, 5, $pdf->to_iso($texto_legal), 1, 'J');

// ==========================================
// BLOQUE 4: TABLA DEL ACTIVO
// ==========================================
$pdf->SetFont('Arial','B',7);
$y_table = $pdf->GetY();

// Anchos de columnas
$w_cod = 25;
$w_ser = 35;
$w_mar = 30;
$w_des = 50;
$w_est_title = 15;
$w_est_col = 5;
$w_obs = $w_total - ($w_cod + $w_ser + $w_mar + $w_des + $w_est_title); // Resto del espacio (40)

// Cabecera Principal
$pdf->Cell($w_cod, 10, $pdf->to_iso('Código'), 1, 0, 'C');
$pdf->Cell($w_ser, 10, 'Serie', 1, 0, 'C');
$pdf->Cell($w_mar, 10, 'Marca', 1, 0, 'C');
$pdf->Cell($w_des, 10, $pdf->to_iso('Descripción del Activo'), 1, 0, 'C');

// Sub-tabla de Estado
$x_estado = $pdf->GetX();
$pdf->Cell($w_est_title, 5, 'Estado', 1, 2, 'C');
$pdf->SetFont('Arial','B',6);
$pdf->Cell($w_est_col, 5, 'B', 1, 0, 'C');
$pdf->Cell($w_est_col, 5, 'R', 1, 0, 'C');
$pdf->Cell($w_est_col, 5, 'M', 1, 0, 'C');

// Cabecera Observaciones
$pdf->SetXY($x_estado + $w_est_title, $y_table);
$pdf->SetFont('Arial','B',7);
$pdf->Cell($w_obs, 10, 'Observaciones', 1, 1, 'C');

// --- DATOS DEL ACTIVO ---
$pdf->SetFont('Arial','',7);
$estado = strtoupper($hist['estado_fisico']);
$b = ($estado == 'BUENO') ? 'X' : '';
$r = ($estado == 'REGULAR') ? 'X' : '';
$m = ($estado == 'MALO') ? 'X' : '';

$pdf->Cell($w_cod, 8, $pdf->to_iso($hist['Codigo_Inv'] ?? ''), 1, 0, 'C');
$pdf->Cell($w_ser, 8, $pdf->to_iso($hist['serie'] ?? ''), 1, 0, 'C');
$pdf->Cell($w_mar, 8, $pdf->to_iso($hist['marca'] ?? ''), 1, 0, 'C');
$pdf->Cell($w_des, 8, $pdf->to_iso(substr($hist['nombre_tipo_activo'] ?? '', 0, 35)), 1, 0, 'C');
$pdf->SetFont('Arial','B',7);
$pdf->Cell($w_est_col, 8, $b, 1, 0, 'C');
$pdf->Cell($w_est_col, 8, $r, 1, 0, 'C');
$pdf->Cell($w_est_col, 8, $m, 1, 0, 'C');
$pdf->SetFont('Arial','',7);
$pdf->Cell($w_obs, 8, $pdf->to_iso(substr($hist['detalles'] ?? '', 0, 30)), 1, 1, 'C');

// ==========================================
// BLOQUE 5: OBSERVACIONES GENERALES
// ==========================================
$y_obs = $pdf->GetY();
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell($w_total, 6, 'OBSERVACIONES GENERALES:', 1, 1, 'L', true);

// Espacio vacío para escribir observaciones (aprox 20mm) o el motivo de la baja
$y_obs_text = $pdf->GetY();
$pdf->Rect($x_start, $y_obs_text, $w_total, 20); // Marco de observaciones
$pdf->SetFont('Arial', '', 9);
$pdf->SetXY($x_start + 2, $y_obs_text + 2);
$pdf->MultiCell($w_total - 4, 5, $pdf->to_iso($hist['descripcion_evento']), 0, 'L');
$pdf->SetY($y_obs_text + 20); // Avanzar el cursor debajo del marco

// ==========================================
// BLOQUE 6: CHECKBOX (INGRESO / TRASLADO / BAJA)
// ==========================================
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(80, 6, 'Certifico que el equipo detallado fue por:', 0, 0, 'L');

$pdf->SetFont('Arial', '', 9);
// Ingreso
$pdf->Rect($pdf->GetX() + 5, $pdf->GetY() + 1, 4, 4);
$pdf->Cell(25, 6, 'Ingreso', 0, 0, 'R');

// Traslado
$pdf->Rect($pdf->GetX() + 10, $pdf->GetY() + 1, 4, 4);
$pdf->Cell(30, 6, 'Traslado', 0, 0, 'R');

// Baja (Marcada)
$pdf->SetFont('Arial', 'B', 10);
$pdf->Rect($pdf->GetX() + 10, $pdf->GetY() + 1, 4, 4);
$pdf->Text($pdf->GetX() + 10.8, $pdf->GetY() + 4.5, 'X'); // Escribir la X dentro
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(25, 6, 'Baja', 0, 1, 'R');

// ==========================================
// BLOQUE 7: FIRMAS HORIZONTALES
// ==========================================
$pdf->Ln(15); // Espacio prudente antes de las firmas
$col_width = $w_total / 3;

// --- Fila 1: Títulos de Firmas ---
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell($col_width, 5, 'Autorizado por', 0, 0, 'L');
$pdf->Cell($col_width, 5, 'Nombre de quien entrega', 0, 0, 'L');
$pdf->Cell($col_width, 5, 'Nombre de quien recibe', 0, 1, 'L');

// --- Fila 2: Cédulas ---
$pdf->SetFont('Arial', '', 8);
$pdf->Cell($col_width, 5, 'CC:', 0, 0, 'L');
$pdf->Cell($col_width, 5, 'CC: ' . $pdf->to_iso($hist['responsable_cedula'] ?? ''), 0, 0, 'L');
$pdf->Cell($col_width, 5, 'CC:', 0, 1, 'L');

$pdf->Ln(14); // Espacio en blanco amplio para que la persona firme a mano

// --- Fila 3: Línea de Firma ---
$pdf->SetFont('Arial', 'B', 9);
$linea_firma = '____________________________________';
$pdf->Cell($col_width, 5, $linea_firma, 0, 0, 'L');
$pdf->Cell($col_width, 5, $linea_firma, 0, 0, 'L');
$pdf->Cell($col_width, 5, $linea_firma, 0, 1, 'L');

// --- Fila 4: Etiqueta "Firma" debajo de la línea ---
$pdf->SetFont('Arial', '', 8);
$pdf->Cell($col_width, 4, 'Firma', 0, 0, 'L');
$pdf->Cell($col_width, 4, 'Firma', 0, 0, 'L');
$pdf->Cell($col_width, 4, 'Firma', 0, 1, 'L');

$pdf->Ln(4);

// --- Fila 5: Línea de Fecha ---
$pdf->SetFont('Arial', 'B', 9);
$linea_fecha = 'Fecha ___________________________';
$pdf->Cell($col_width, 5, $linea_fecha, 0, 0, 'L');
$pdf->Cell($col_width, 5, $linea_fecha, 0, 0, 'L');
$pdf->Cell($col_width, 5, $linea_fecha, 0, 1, 'L');

$pdf->Output('I', 'Acta_Baja_S' . ($hist['serie'] ?? 'N-A') . '.pdf');
exit;
?>