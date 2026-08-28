<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'backend/auth_check.php';
require_once 'backend/db.php';
require_once 'lib/fpdf/fpdf.php';

if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion) { die("Error crítico de conexión a la base de datos."); }
$conexion->set_charset("utf8mb4");

if (!isset($_GET['id_historial']) || !filter_var($_GET['id_historial'], FILTER_VALIDATE_INT)) { die("Error: ID de historial no válido."); }
$id_historial = (int)$_GET['id_historial'];

// 1. Obtener el evento de historial para saber IDs de activo y cédulas
$sql_evento = "SELECT id_activo, fecha_evento, datos_nuevos, datos_anteriores, descripcion_evento FROM historial_activos WHERE id_historial = ? AND tipo_evento = 'TRASLADO'";
$stmt_evento = $conexion->prepare($sql_evento);
if(!$stmt_evento) die("Error al preparar la consulta del evento: ".$conexion->error);
$stmt_evento->bind_param("i", $id_historial);
$stmt_evento->execute();
$evento_data = $stmt_evento->get_result()->fetch_assoc();
$stmt_evento->close();

if (!$evento_data) { die("No se encontraron datos para el evento de traslado con ID: " . htmlspecialchars($id_historial)); }

$id_activo = $evento_data['id_activo'];
$datos_nuevos = !empty($evento_data['datos_nuevos']) ? json_decode($evento_data['datos_nuevos'], true) : [];
$datos_anteriores = !empty($evento_data['datos_anteriores']) ? json_decode($evento_data['datos_anteriores'], true) : [];

$cedula_entrega = $datos_anteriores['cedula_responsable_anterior'] ?? null;
$cedula_recibe = $datos_nuevos['destino_cedula'] ?? null;

// 2. Obtener la información completa del activo
$sql_activo = "SELECT a.*, ta.nombre_tipo_activo FROM activos_tecnologicos a LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo WHERE a.id = ?";
$stmt_activo = $conexion->prepare($sql_activo);
$stmt_activo->bind_param("i", $id_activo);
$stmt_activo->execute();
$activo_data = $stmt_activo->get_result()->fetch_assoc();
$stmt_activo->close();

// 3. Obtener los datos completos del usuario que ENTREGA (origen)
$usuario_entrega = null;
if ($cedula_entrega) {
    $stmt_entrega = $conexion->prepare("SELECT u.*, c.nombre_cargo FROM usuarios u LEFT JOIN cargos c ON u.id_cargo = c.id_cargo WHERE u.usuario = ?");
    $stmt_entrega->bind_param("s", $cedula_entrega);
    $stmt_entrega->execute();
    $usuario_entrega = $stmt_entrega->get_result()->fetch_assoc();
    $stmt_entrega->close();
}

// 4. Obtener los datos completos del usuario que RECIBE (destino)
$usuario_recibe = null;
if ($cedula_recibe) {
    $stmt_recibe = $conexion->prepare("SELECT u.*, c.nombre_cargo FROM usuarios u LEFT JOIN cargos c ON u.id_cargo = c.id_cargo WHERE u.usuario = ?");
    $stmt_recibe->bind_param("s", $cedula_recibe);
    $stmt_recibe->execute();
    $usuario_recibe = $stmt_recibe->get_result()->fetch_assoc();
    $stmt_recibe->close();
}

// Asignar variables para el PDF
$nombre_recibe = $usuario_recibe['nombre_completo'] ?? 'N/A';
$cc_recibe = $usuario_recibe['usuario'] ?? 'N/A';
$cargo_recibe = $usuario_recibe['nombre_cargo'] ?? 'N/A';
$empresa = $usuario_recibe['empresa'] ?? 'N/A';
$regional = $usuario_recibe['regional'] ?? 'N/A';

$cc_entrega = $usuario_entrega['usuario'] ?? 'N/A';

// Variables en blanco para firma de autorización
$autorizado_por = "";
$autorizado_cc = "";


// Función auxiliar para dibujar los bloques de firma verticales
function renderFirmaVertical($pdf, $titulo, $nombre, $cc) {
    $pdf->Ln(4);
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(170, 6, $pdf->to_iso($titulo), 0, 1, 'L');

    $pdf->Ln(10); 

    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(15, 5, 'Firma:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(85, 5, '________________________________________________', 0, 0, 'L');

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(15, 5, 'Fecha:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(55, 5, '_________________________', 0, 1, 'L');

    $pdf->Ln(2);

    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(15, 5, 'Nombre:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(85, 5, $pdf->to_iso($nombre), 0, 0, 'L');

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(15, 5, 'CC:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(55, 5, $pdf->to_iso($cc), 0, 1, 'L');

    $pdf->Ln(4);
}

class PDF_Acta_Traslado extends FPDF {
    function to_iso($string) { return mb_convert_encoding($string, 'ISO-8859-1', 'UTF-8'); }
    function Header() {}
    function Footer() {}
}

$pdf = new PDF_Acta_Traslado('P', 'mm', 'Letter');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 10);
$pdf->AddPage();

$w_total = 195; 
$x_start = 10;
$y_start = 10;

// ==========================================
// BLOQUE 1: CABECERA (ENMARCADA)
// ==========================================
$pdf->SetLineWidth(0.5);
$pdf->Rect($x_start, $y_start, $w_total, 30);
$pdf->SetLineWidth(0.2); 

$pdf->Image('imagenes/logo.png', $x_start + 2, $y_start + 4, 38);
$pdf->Line($x_start + 42, $y_start, $x_start + 42, $y_start + 30); 
$pdf->Line($x_start + 160, $y_start, $x_start + 160, $y_start + 30); 

$pdf->SetFont('Arial', 'B', 8); 
$pdf->SetXY($x_start + 42, $y_start + 2);
$pdf->Cell(118, 4, $pdf->to_iso('PROCESO EVALUACIÓN Y CONTROL'), 0, 1, 'C');
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 4, $pdf->to_iso('PROCEDIMIENTO DE AUDITORIA INTERNA'), 0, 1, 'C');
$pdf->SetX($x_start + 42);
// Dinámico: En traslado el original usaba Finansueños, lo atamos a la empresa destino
if(strtoupper($empresa) === 'FINANSUEÑOS') {
    $pdf->Cell(118, 4, $pdf->to_iso('FINANSUEÑOS SAS'), 0, 1, 'C');
    $pdf->SetX($x_start + 42);
    $pdf->Cell(118, 4, 'NIT. 901.723.445', 0, 1, 'C');
} else {
    $pdf->Cell(118, 4, $pdf->to_iso('ARPESOD ASOCIADOS SAS'), 0, 1, 'C');
    $pdf->SetX($x_start + 42);
    $pdf->Cell(118, 4, 'NIT. 900.333.755-6', 0, 1, 'C');
}

$pdf->Ln(1); 
$pdf->SetFont('Arial', 'B', 9); 
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 5, $pdf->to_iso('SOLICITUD DE INGRESO, TRASLADO Y/O'), 0, 1, 'C');
$pdf->SetX($x_start + 42);
$pdf->Cell(118, 5, $pdf->to_iso('DAR DE BAJA ACTIVOS FIJOS'), 0, 1, 'C');

$pdf->SetY($y_start + 30);

// ==========================================
// BLOQUE 2: DATOS DEL DOCUMENTO
// ==========================================
$pdf->SetFont('Arial', 'B', 8);

$y_current = $pdf->GetY();
$pdf->Cell($w_total, 7, '', 1, 0); 
$pdf->SetXY($x_start, $y_current);
$pdf->Cell(85, 7, 'Fecha: ' . date('d/m/Y', strtotime($evento_data['fecha_evento'])), 0, 0, 'L');
$pdf->Line($x_start + 85, $y_current, $x_start + 85, $y_current + 7); 
$pdf->SetX($x_start + 86);
$pdf->Cell(109, 7, 'Regional: ' . $pdf->to_iso($regional), 0, 1, 'L');

$y_current = $pdf->GetY();
$pdf->Cell($w_total, 7, '', 1, 0); 
$pdf->SetXY($x_start, $y_current);
$pdf->Cell(85, 7, $pdf->to_iso('Área: ' . ($cargo_recibe)), 0, 0, 'L');
$pdf->Line($x_start + 85, $y_current, $x_start + 85, $y_current + 7); 
$pdf->SetX($x_start + 86);
$pdf->Cell(109, 7, 'Punto de Venta: ' . $pdf->to_iso($empresa), 0, 1, 'L');

// ==========================================
// BLOQUE 3: TEXTO LEGAL
// ==========================================
$texto_legal = "Para formalizar la solicitud, en la presente acta quedaran consignados los equipos y muebles que están bajo su responsabilidad, buen uso y cuidado. Los daños que se generen le serán descontados automáticamente.\n\nCuando haya terminación del contrato laboral o retiro voluntario, usted debe hacer entrega de los activos fijos aquí estipulados al líder de zona o en su defecto al nuevo encargado del puesto, ya que este será un requisito indispensable para la firma de paz y salvo por parte de la empresa.";

$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell($w_total, 5, $pdf->to_iso($texto_legal), 1, 'J');

// ==========================================
// BLOQUE 4: TABLA DEL ACTIVO
// ==========================================
$pdf->SetFont('Arial','B',7);
$y_table = $pdf->GetY();

$w_cod = 25;
$w_ser = 35;
$w_mar = 30;
$w_des = 50;
$w_est_title = 15;
$w_est_col = 5;
$w_obs = $w_total - ($w_cod + $w_ser + $w_mar + $w_des + $w_est_title); 

$pdf->Cell($w_cod, 10, $pdf->to_iso('Código'), 1, 0, 'C');
$pdf->Cell($w_ser, 10, 'Serie', 1, 0, 'C');
$pdf->Cell($w_mar, 10, 'Marca', 1, 0, 'C');
$pdf->Cell($w_des, 10, $pdf->to_iso('Descripción del Activo'), 1, 0, 'C');

$x_estado = $pdf->GetX();
$pdf->Cell($w_est_title, 5, 'Estado', 1, 2, 'C');
$pdf->SetFont('Arial','B',6);
$pdf->Cell($w_est_col, 5, 'B', 1, 0, 'C');
$pdf->Cell($w_est_col, 5, 'R', 1, 0, 'C');
$pdf->Cell($w_est_col, 5, 'M', 1, 0, 'C');

$pdf->SetXY($x_estado + $w_est_title, $y_table);
$pdf->SetFont('Arial','B',7);
$pdf->Cell($w_obs, 10, 'Observaciones', 1, 1, 'C');

$pdf->SetFont('Arial','',7);
$estado = isset($activo_data['estado']) ? strtoupper($activo_data['estado']) : '';
$b = ($estado == 'BUENO') ? 'X' : '';
$r = ($estado == 'REGULAR') ? 'X' : '';
$m = ($estado == 'MALO') ? 'X' : '';

$pdf->Cell($w_cod, 8, $pdf->to_iso($activo_data['Codigo_Inv'] ?? ''), 1, 0, 'C');
$pdf->Cell($w_ser, 8, $pdf->to_iso($activo_data['serie'] ?? ''), 1, 0, 'C');
$pdf->Cell($w_mar, 8, $pdf->to_iso($activo_data['marca'] ?? ''), 1, 0, 'C');
$pdf->Cell($w_des, 8, $pdf->to_iso(substr($activo_data['nombre_tipo_activo'] ?? '', 0, 35)), 1, 0, 'C');
$pdf->SetFont('Arial','B',7);
$pdf->Cell($w_est_col, 8, $b, 1, 0, 'C');
$pdf->Cell($w_est_col, 8, $r, 1, 0, 'C');
$pdf->Cell($w_est_col, 8, $m, 1, 0, 'C');
$pdf->SetFont('Arial','',7);
$pdf->Cell($w_obs, 8, $pdf->to_iso(substr($activo_data['detalles'] ?? '', 0, 30)), 1, 1, 'C');


// ==========================================
// BLOQUE 5: OBSERVACIONES GENERALES
// ==========================================
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell($w_total, 6, 'OBSERVACIONES GENERALES:', 1, 1, 'L', true);

$y_obs_text = $pdf->GetY();
$pdf->Rect($x_start, $y_obs_text, $w_total, 20); 
$pdf->SetFont('Arial', '', 9);
$pdf->SetXY($x_start + 2, $y_obs_text + 2);
// Mostramos el detalle del evento de traslado 
$pdf->MultiCell($w_total - 4, 5, $pdf->to_iso($evento_data['descripcion_evento'] ?? ''), 0, 'L');
$pdf->SetY($y_obs_text + 20); 

// =========================================================
// INICIO DEL MARCO INFERIOR CONTINUO (CHECKBOX + FIRMAS)
// =========================================================
$y_start_footer = $pdf->GetY(); 

$pdf->Ln(8);
$pdf->SetX(15);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(70, 6, 'Certifico que el equipo detallado fue por:', 0, 0, 'L');

$pdf->SetFont('Arial', '', 9);

// Ingreso 
$pdf->Rect($pdf->GetX() + 10, $pdf->GetY() + 1, 4, 4);
$pdf->Cell(30, 6, 'Ingreso', 0, 0, 'R');

// Traslado (Marcada porque es acta de traslado)
$pdf->SetFont('Arial', 'B', 10);
$pdf->Rect($pdf->GetX() + 10, $pdf->GetY() + 1, 4, 4);
$pdf->Text($pdf->GetX() + 10.8, $pdf->GetY() + 4.5, 'X'); 
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(30, 6, 'Traslado', 0, 0, 'R');

// Baja
$pdf->Rect($pdf->GetX() + 10, $pdf->GetY() + 1, 4, 4);
$pdf->Cell(25, 6, 'Baja', 0, 1, 'R');

$pdf->Ln(8);

// ==========================================
// FIRMAS VERTICALES ESTILIZADAS
// ==========================================

$pdf->SetDrawColor(200, 200, 200);
$pdf->Line($x_start, $pdf->GetY(), $x_start + $w_total, $pdf->GetY());
$pdf->SetDrawColor(0, 0, 0);

renderFirmaVertical($pdf, 'Autorizado por:', $autorizado_por, $autorizado_cc);

$pdf->SetDrawColor(200, 200, 200);
$pdf->Line(15, $pdf->GetY(), 200, $pdf->GetY());
$pdf->SetDrawColor(0, 0, 0);

// Dejamos el nombre vacío pero ponemos la cédula original para que quien entrega firme
renderFirmaVertical($pdf, 'Nombre de quien entrega:', '', $cc_entrega); 

$pdf->SetDrawColor(200, 200, 200);
$pdf->Line(15, $pdf->GetY(), 200, $pdf->GetY());
$pdf->SetDrawColor(0, 0, 0);

renderFirmaVertical($pdf, 'Nombre de quien recibe:', $nombre_recibe, $cc_recibe);

$pdf->Ln(2);


$y_end_footer = $pdf->GetY();
$pdf->Rect($x_start, $y_start_footer, $w_total, $y_end_footer - $y_start_footer);

$pdf->Output('I', 'Acta_Traslado_S' . ($activo_data['serie'] ?? $id_historial) . '.pdf');
exit;
?>