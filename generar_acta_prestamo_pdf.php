<?php
// --- INICIO: HABILITAR VISUALIZACIÓN DE ERRORES PARA DEPURACIÓN ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// --- FIN: HABILITAR VISUALIZACIÓN DE ERRORES ---

session_start();
require_once 'backend/auth_check.php'; 
restringir_acceso_pagina(['admin', 'tecnico', 'registrador', 'auditor']); 

require_once 'backend/db.php'; 
require_once 'lib/fpdf/fpdf.php'; 

// Función para convertir a ISO-8859-1 si es necesario para FPDF con fuentes estándar
function to_iso($string) {
    // Asume que la entrada es UTF-8
    return mb_convert_encoding($string, 'ISO-8859-1', 'UTF-8');
}

$conexion_error_msg = null;
if (isset($conn) && !isset($conexion)) { $conexion = $conn; }
if (!isset($conexion) || !$conexion || (method_exists($conexion, 'connect_error') && $conexion->connect_error)) {
    $db_conn_error_detail = method_exists($conexion, 'connect_error') ? $conexion->connect_error : 'Desconocido';
    error_log("Error de conexión BD en generar_acta_prestamo_pdf.php: " . $db_conn_error_detail);
    die("Error crítico de conexión a la base de datos. No se puede generar el acta. Detalle: " . htmlspecialchars($db_conn_error_detail));
}
$conexion->set_charset("utf8mb4");

if (!isset($_GET['id_prestamo']) || !filter_var($_GET['id_prestamo'], FILTER_VALIDATE_INT) || $_GET['id_prestamo'] <= 0) {
    die("ID de préstamo no válido o no proporcionado.");
}
$id_prestamo = (int)$_GET['id_prestamo'];

$sql_acta = "SELECT 
            p.id_prestamo, p.fecha_prestamo, p.fecha_devolucion_esperada, p.estado_activo_prestamo, p.observaciones_prestamo,
            a.id AS activo_id, a.serie AS activo_serie, a.marca AS activo_marca, a.Codigo_Inv AS activo_codigo_inv,
            a.detalles AS activo_detalles_generales, ta.nombre_tipo_activo,
            up.nombre_completo AS nombre_usuario_presta, up.usuario AS cedula_usuario_presta,
            cargos_presta.nombre_cargo AS cargo_usuario_presta, up.empresa AS empresa_usuario_presta, up.regional AS regional_usuario_presta,
            ur.nombre_completo AS nombre_usuario_recibe, ur.usuario AS cedula_usuario_recibe,
            cargos_recibe.nombre_cargo AS cargo_usuario_recibe, ur.empresa AS empresa_usuario_recibe, ur.regional AS regional_usuario_recibe
        FROM prestamos_activos p
        JOIN activos_tecnologicos a ON p.id_activo = a.id
        JOIN usuarios up ON p.id_usuario_presta = up.id
        JOIN usuarios ur ON p.id_usuario_recibe = ur.id
        LEFT JOIN tipos_activo ta ON a.id_tipo_activo = ta.id_tipo_activo
        LEFT JOIN cargos cargos_presta ON up.id_cargo = cargos_presta.id_cargo 
        LEFT JOIN cargos cargos_recibe ON ur.id_cargo = cargos_recibe.id_cargo 
        WHERE p.id_prestamo = ?";

$stmt = $conexion->prepare($sql_acta);
if (!$stmt) { 
    error_log("Error al preparar consulta para acta de préstamo: " . $conexion->error);
    die("Error al preparar datos para el acta (SQL). Revise los logs del servidor.");
 }
$stmt->bind_param("i", $id_prestamo);
if(!$stmt->execute()){ 
    error_log("Error al ejecutar consulta para acta de préstamo: " . $stmt->error);
    die("Error al ejecutar la obtención de datos para el acta (SQL). Revise los logs del servidor.");
 }
$result = $stmt->get_result();
$datos_prestamo = $result->fetch_assoc();
$stmt->close();

if (!$datos_prestamo) { 
    if (isset($conexion) && $conexion && !$conexion_error_msg) { $conexion->close(); }
    die("No se encontraron datos para el préstamo ID: " . htmlspecialchars($id_prestamo) . ". Verifique que el préstamo exista y tenga todos los datos relacionados correctos.");
}

$meses_espanol = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

class PDF_Acta_Prestamo extends FPDF {
    private $empresaNombre = 'ARPESOD ASOCIADOS SAS'; 
    // private $empresaNit = 'NIT: XXX.XXX.XXX-X'; // NIT eliminado
    private $logoPath = 'imagenes/logo.png'; 

    function Header() {
        // Logo
        if (file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 20, 15, 40); 
        } else {
            error_log("FPDF Header: No se encontró el logo en la ruta: " . $this->logoPath . " (Ruta absoluta intentada: " . realpath($this->logoPath) . ")");
        }
        // Mover el título más abajo
        $this->Ln(15); // Salto de línea mayor para bajar el título respecto al logo

        $this->SetFont('Arial', 'B', 14); 
        $this->Cell(0, 10, to_iso('ACTA DE PRÉSTAMO DE ELEMENTO TECNOLÓGICO'), 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 6, to_iso($this->empresaNombre), 0, 1, 'C'); // Reducido alto de celda
        // NIT eliminado
        $this->Ln(6); // Salto de línea ajustado
    }

    function Footer() {
        $this->SetY(-15); // Posición a 1.5 cm del final
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 5, to_iso('Generado por Sistema de Inventario TI - ARPESOD ASOCIADOS SAS'), 0, 1, 'C'); // Reducido alto
        $this->Cell(0, 5, to_iso('Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'C'); // Reducido alto
    }

    function InfoCell($label, $value, $labelWidth = 50, $valueWidth = 0, $lineHeight = 5, $border = 0) { // Reducido lineHeight
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($labelWidth, $lineHeight, to_iso($label . ":"), $border, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $current_x = $this->GetX();
        $current_y = $this->GetY();
        if ($valueWidth == 0) { 
            $this->MultiCell(0, $lineHeight, to_iso(trim($value ?: 'N/A')), $border, 'L');
        } else {
            $this->MultiCell($valueWidth, $lineHeight, to_iso(trim($value ?: 'N/A')), $border, 'L');
        }
        // Ajuste para asegurar que no haya doble salto si MultiCell ya lo hizo
        if ($this->GetY() <= $current_y + $lineHeight) {
             $this->Ln($lineHeight - ($this->GetY() - $current_y) + 0.5); // Espacio restante para completar la línea + pequeño extra
        } else {
            $this->Ln(0.5); // Pequeño espacio si MultiCell ya saltó mucho
        }
    }

    function SectionTitle($title, $lineHeight = 6) { // Reducido lineHeight
        $this->Ln(3); 
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor(220, 220, 220); 
        $this->SetTextColor(0,0,0);
        $this->Cell(0, $lineHeight, to_iso($title), 1, 1, 'L', true); 
        $this->Ln(1.5); // Reducido Ln
        $this->SetTextColor(0,0,0); 
    }

    function Paragraph($text, $lineHeight = 4.5) { // Reducido lineHeight
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(0, $lineHeight, to_iso($text), 0, 'J'); 
        $this->Ln(1.5); // Reducido Ln
    }
}

// Creación del objeto PDF
$pdf = new PDF_Acta_Prestamo('P', 'mm', 'Letter');
$pdf->AliasNbPages(); 
$pdf->SetMargins(20, 15, 20); 
$pdf->SetAutoPageBreak(true, 20); // Ajustar margen inferior para el pie de página
$pdf->AddPage();

// Formatear fecha de préstamo
$fecha_prestamo_obj = strtotime($datos_prestamo['fecha_prestamo']);
$dia_prestamo = date("d", $fecha_prestamo_obj);
$mes_prestamo_num = (int)date("m", $fecha_prestamo_obj);
$mes_prestamo_texto = $meses_espanol[$mes_prestamo_num] ?? date("F", $fecha_prestamo_obj);
$anio_prestamo = date("Y", $fecha_prestamo_obj);
$fecha_prestamo_formateada = "Popayán, " . $dia_prestamo . " de " . $mes_prestamo_texto . " de " . $anio_prestamo;

// --- CONTENIDO DEL ACTA ---
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, to_iso($fecha_prestamo_formateada), 0, 1, 'R'); // Reducido alto
$pdf->Cell(0, 6, to_iso("Acta de Préstamo No: PRE-" . str_pad($datos_prestamo['id_prestamo'], 5, "0", STR_PAD_LEFT)), 0, 1, 'R'); // Reducido alto
$pdf->Ln(3); // Reducido Ln

$pdf->SectionTitle('1. INFORMACIÓN DEL ELEMENTO TECNOLÓGICO ENTREGADO');
$pdf->InfoCell('Tipo de Activo', $datos_prestamo['nombre_tipo_activo']);
$pdf->InfoCell('Marca', $datos_prestamo['activo_marca']);
$pdf->InfoCell('Serie / Serial', $datos_prestamo['activo_serie']);
$pdf->InfoCell('Cód. Inventario', $datos_prestamo['activo_codigo_inv']);
$pdf->InfoCell('Detalles Generales', $datos_prestamo['activo_detalles_generales']);
$pdf->InfoCell('Estado al Entregar', $datos_prestamo['estado_activo_prestamo']);

$pdf->SectionTitle('2. CONDICIONES DEL PRÉSTAMO');
$pdf->InfoCell('Devolución Esperada', date("d/m/Y", strtotime($datos_prestamo['fecha_devolucion_esperada'])));
$pdf->InfoCell('Observaciones', $datos_prestamo['observaciones_prestamo']);
$pdf->Paragraph(
    "El receptor se compromete a utilizar el elemento tecnológico descrito anteriormente de manera adecuada, siguiendo las políticas de uso de la empresa, y a devolverlo en la fecha estipulada o cuando sea requerido por ARPESOD ASOCIADOS SAS. " .
    "Cualquier daño o pérdida del activo, más allá del desgaste normal por uso, deberá ser reportado inmediatamente al área encargada. " .
    "El receptor es responsable por la custodia, seguridad y buen uso del elemento mientras esté bajo su cargo."
);

$pdf->SectionTitle('3. ENTREGA (Funcionario que Autoriza/Entrega)');
$pdf->InfoCell('Nombre', $datos_prestamo['nombre_usuario_presta']);
$pdf->InfoCell('Cédula', $datos_prestamo['cedula_usuario_presta']);
$pdf->InfoCell('Cargo', $datos_prestamo['cargo_usuario_presta']);
$pdf->InfoCell('Empresa', $datos_prestamo['empresa_usuario_presta']);

$pdf->SectionTitle('4. RECIBE (Funcionario Receptor del Elemento)');
$pdf->InfoCell('Nombre', $datos_prestamo['nombre_usuario_recibe']);
$pdf->InfoCell('Cédula', $datos_prestamo['cedula_usuario_recibe']);
$pdf->InfoCell('Cargo', $datos_prestamo['cargo_usuario_recibe']);
$pdf->InfoCell('Empresa', $datos_prestamo['empresa_usuario_recibe']);

$pdf->Ln(10); // Reducido espacio antes de las firmas

// Espacios para Firmas
$signature_y_start = $pdf->GetY();
if ($signature_y_start > $pdf->GetPageHeight() - 50) { // Ajustado el chequeo de espacio
    $pdf->AddPage();
    $signature_y_start = $pdf->GetY() + 5; 
}

$pdf->SetFont('Arial','',10);
$pdf->Cell(85, 6, to_iso('_________________________'), 0, 0, 'C'); // Reducido alto
$pdf->Cell(10, 6, '', 0, 0, 'C'); 
$pdf->Cell(85, 6, to_iso('_________________________'), 0, 1, 'C');

$pdf->Cell(85, 6, to_iso('Firma Quien Entrega'), 0, 0, 'C');
$pdf->Cell(10, 6, '', 0, 0, 'C');
$pdf->Cell(85, 6, to_iso('Firma Quien Recibe'), 0, 1, 'C');

$pdf->Cell(85, 6, to_iso('C.C: ' . $datos_prestamo['cedula_usuario_presta']), 0, 0, 'C');
$pdf->Cell(10, 6, '', 0, 0, 'C');
$pdf->Cell(85, 6, to_iso('C.C: ' . $datos_prestamo['cedula_usuario_recibe']), 0, 1, 'C');

// Salida del PDF
$nombre_archivo_saneado = preg_replace('/[^A-Za-z0-9\-]/', '_', $datos_prestamo['activo_serie'] ?: ('PrestamoID' . $datos_prestamo['id_prestamo']));
$nombre_pdf = "Acta_Prestamo_" . $nombre_archivo_saneado . ".pdf";

if (ob_get_length()) ob_end_clean(); 

$pdf->Output('I', $nombre_pdf); 
if (isset($conexion) && $conexion && !$conexion_error_msg) { $conexion->close(); } 
exit;
?>
