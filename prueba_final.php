<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ARCHIVO DE DIAGNÓSTICO DEFINITIVO
header('Content-Type: text/plain; charset=utf-8');

echo "--- INICIO DE LA PRUEBA DE DIAGNÓSTICO ---\n\n";

// --- 1. CONFIGURACIÓN DE LA BASE DE DATOS ---
// ¡¡¡IMPORTANTE: RELLENA ESTOS DATOS CON LOS DE TU SERVIDOR!!!
$servidor_db = "localhost"; // <-- ¡ESTE ES EL CAMBIO!
$usuario_db  = "electroc_webmast";
$clave_db    = "4Rxf1vNLYW_w";
$nombre_db   = "electroc_inventario";

// --- 2. VERIFICAR PARÁMETRO DE ENTRADA ---
if (!isset($_GET['cedula']) || empty($_GET['cedula'])) {
    die("ERROR: No se proporcionó una cédula en la URL. Añade ?cedula=NUMERO al final de la URL.");
}
$cedula_buscada = $_GET['cedula'];
echo "Paso 1: Buscando la cédula: " . htmlspecialchars($cedula_buscada) . "\n";

// --- 3. INTENTO DE CONEXIÓN ---
echo "Paso 2: Intentando conectar a la base de datos...\n";
$conexion_test = new mysqli($servidor_db, $usuario_db, $clave_db, $nombre_db);

if ($conexion_test->connect_error) {
    die("FALLO LA CONEXIÓN: " . $conexion_test->connect_error . "\n");
}
echo "Paso 3: Conexión al servidor exitosa.\n";
echo "Paso 4: Base de datos '" . htmlspecialchars($nombre_db) . "' seleccionada.\n";
$conexion_test->set_charset("utf8mb4");

// --- 4. PREPARAR Y EJECUTAR LA CONSULTA ---
echo "Paso 5: Preparando la consulta SQL...\n";
$sql = "SELECT u.nombre_completo, c.nombre_cargo, u.empresa, u.regional 
        FROM usuarios u
        LEFT JOIN cargos c ON u.id_cargo = c.id_cargo 
        WHERE u.usuario = ?";
$stmt = $conexion_test->prepare($sql);

if ($stmt === false) {
    die("FALLO LA PREPARACIÓN DE LA CONSULTA: " . $conexion_test->error . "\n");
}
echo "Paso 6: Preparación de consulta exitosa.\n";

$stmt->bind_param("s", $cedula_buscada);
$stmt->execute();
$resultado = $stmt->get_result();

echo "Paso 7: Consulta ejecutada. Número de filas encontradas: " . $resultado->num_rows . "\n";

// --- 5. MOSTRAR RESULTADOS ---
if ($resultado->num_rows > 0) {
    $fila = $resultado->fetch_assoc();
    echo "\n--- ¡USUARIO ENCONTRADO! ---\n";
    print_r($fila);
} else {
    echo "\n--- RESULTADO: No se encontró ningún usuario con esa cédula en la base de datos. ---\n";
}

$stmt->close();
$conexion_test->close();

echo "\n--- FIN DE LA PRUEBA ---";
?>