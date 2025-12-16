<?php
session_start();
require_once __DIR__ . '/backend/auth_check.php';
restringir_acceso_pagina(['admin']);

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

// Mensajes
$mensaje_exito = $_SESSION['import_success_message'] ?? null;
$mensaje_error = $_SESSION['import_error_message'] ?? null;
$errores_detalle = $_SESSION['import_errors'] ?? [];
unset($_SESSION['import_success_message'], $_SESSION['import_error_message'], $_SESSION['import_errors']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importador Maestro - Sistema de Activos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; padding-top: 80px; background-color: #f8f9fa; min-height: 100vh; display: flex; flex-direction: column; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; background-color: #fff; border-bottom: 1px solid #dee2e6; padding: 0.5rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        .logo-container-top img { height: 60px; object-fit: contain; }
    </style>
</head>
<body>
    <div class="top-bar-custom">
        <div class="logo-container-top"><a href="menu.php"><img src="imagenes/logo.png" alt="Logo"></a></div>
        <div class="d-flex align-items-center">
            <span class="text-dark me-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?></span>
            <form action="logout.php" method="post"><button class="btn btn-outline-danger btn-sm" type="submit">Salir</button></form>
        </div>
    </div>

    <div class="container mt-5 flex-grow-1" style="max-width: 800px;">
        <div class="card shadow-lg border-0">
            <div class="card-body p-5">
                <h2 class="text-center text-primary mb-4"><i class="bi bi-cloud-upload-fill"></i> Importador Maestro</h2>
                <p class="text-center text-muted mb-4">Cargue activos, categorías y tipos en un solo paso.</p>

                <?php if ($mensaje_exito): ?><div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= $mensaje_exito ?></div><?php endif; ?>
                <?php if ($mensaje_error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= $mensaje_error ?></div><?php endif; ?>
                <?php if (!empty($errores_detalle)): ?>
                    <div class="alert alert-warning">
                        <strong>Filas omitidas:</strong>
                        <ul class="mb-0 small mt-2" style="max-height: 150px; overflow-y: auto;">
                            <?php foreach ($errores_detalle as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form action="procesar_importacion_completo.php" method="post" enctype="multipart/form-data" class="mt-4">
                    <div class="mb-4">
                        <label class="form-label fw-bold">Archivo Maestro (.xlsx)</label>
                        <input class="form-control form-control-lg" type="file" name="archivo_excel" accept=".xlsx" required>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">Procesar Archivo Completo</button>
                        <a href="plantillas/plantilla_maestra.xlsx" class="btn btn-outline-secondary" download>
                            <i class="bi bi-download"></i> Descargar Plantilla Maestra
                        </a>
                    </div>
                </form>

                <div class="mt-4 p-3 bg-light rounded small">
                    <h6 class="fw-bold"><i class="bi bi-info-circle"></i> Reglas de Importación:</h6>
                    <ul class="mb-0 ps-3">
                        <li>Si la <strong>Categoría</strong> no existe, se creará automáticamente.</li>
                        <li>Si el <strong>Tipo de Activo</strong> no existe, se creará vinculado a la categoría.</li>
                        <li>La <strong>Cédula del Responsable</strong> DEBE existir en el sistema previamente.</li>
                        <li>La <strong>Serie</strong> debe ser única.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>