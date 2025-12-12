<?php
session_start();
require_once __DIR__ . '/backend/auth_check.php';
restringir_acceso_pagina(['admin']);

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';

// Mensajes de resultado de la importación
$mensaje_exito = $_SESSION['import_success_message'] ?? null;
$mensaje_error = $_SESSION['import_error_message'] ?? null;
$errores_detalle = $_SESSION['import_errors'] ?? [];

unset($_SESSION['import_success_message'], $_SESSION['import_error_message'], $_SESSION['import_errors']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar Datos desde Excel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        html { height: 100%; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-top: 80px; background-color: #f8f9fa; display: flex; flex-direction: column; min-height: 100vh; }
        .main-container { flex-grow: 1; }
        .top-bar-custom { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1.5rem; background-color: #ffffff; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .page-title { color: #191970; font-weight: 600; }
        .footer-custom { font-size: 0.9rem; background-color: #f8f9fa; border-top: 1px solid #dee2e6; padding: 1rem 0; margin-top: auto;}
        .footer-custom a i { color: #6c757d; transition: color 0.2s; }
        .footer-custom a i:hover { color: #0d6efd !important; }
    </style>
</head>
<body>
    <div class="top-bar-custom">
        <div class="logo-container-top">
            <a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo"></a>
        </div>
        <div class="d-flex align-items-center">
            <span class="text-dark me-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
            <form action="logout.php" method="post" class="d-flex"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button></form>
        </div>
    </div>

    <div class="container main-container mt-4">
        <h3 class="page-title text-center mb-4"><i class="bi bi-file-earmark-arrow-up-fill"></i> Importador Masivo de Datos</h3>
        
        <?php if ($mensaje_exito): ?>
            <div class="alert alert-success"><?= $mensaje_exito ?></div>
        <?php endif; ?>
        <?php if ($mensaje_error): ?>
            <div class="alert alert-danger"><?= $mensaje_error ?></div>
        <?php endif; ?>

        <?php if (!empty($errores_detalle)): ?>
            <div class="alert alert-warning">
                <h5 class="alert-heading">Detalles de Filas Omitidas:</h5>
                <ul class="mb-0">
                    <?php foreach ($errores_detalle as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="importTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="import-activos-tab" data-bs-toggle="tab" data-bs-target="#import-activos" type="button" role="tab">Importar Activos</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="import-tipos-tab" data-bs-toggle="tab" data-bs-target="#import-tipos" type="button" role="tab">Importar Tipos de Activo</button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content" id="importTabContent">
                    <div class="tab-pane fade show active" id="import-activos" role="tabpanel">
                        <form action="procesar_importacion.php" method="post" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="archivo_excel_activos" class="form-label">Seleccione el archivo de **Activos** para importar:</label>
                                <input class="form-control" type="file" id="archivo_excel_activos" name="archivo_excel" accept=".xlsx" required>
                            </div>
                            <div class="alert alert-primary">
                                <strong>Instrucciones:</strong>
                                <div class="my-3">
                                    <a href="plantillas/plantilla_importacion.xlsx" class="btn btn-info btn-sm" download><i class="bi bi-download"></i> Descargar Plantilla de Activos</a>
                                </div>
                                <ol>
                                    <li>Use la plantilla proporcionada. El archivo debe ser **.xlsx**.</li>
                                    <li>Los campos **serie**, **tipo_activo** y **cedula_responsable** son obligatorios.</li>
                                    <li>El sistema omitirá filas con series que ya existan.</li>
                                </ol>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Procesar Archivo de Activos</button>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="import-tipos" role="tabpanel">
                        <form action="procesar_importacion_tipos.php" method="post" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="archivo_excel_tipos" class="form-label">Seleccione el archivo de **Tipos de Activo** para importar:</label>
                                <input class="form-control" type="file" id="archivo_excel_tipos" name="archivo_excel" accept=".xlsx" required>
                            </div>
                            <div class="alert alert-primary">
                                <strong>Instrucciones:</strong>
                                <div class="my-3">
                                    <a href="plantillas/plantilla_tipos_activo.xlsx" class="btn btn-info btn-sm" download><i class="bi bi-download"></i> Descargar Plantilla de Tipos de Activo</a>
                                </div>
                                <ol>
                                    <li>Use la plantilla proporcionada. El archivo debe ser **.xlsx**.</li>
                                    <li>Las columnas `nombre_tipo_activo` y `vida_util_sugerida` son obligatorias.</li>
                                    <li>El sistema omitirá tipos de activo cuyo nombre ya exista.</li>
                                </ol>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Procesar Archivo de Tipos</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer-custom mt-auto py-3 bg-light border-top shadow-sm">
        <div class="container text-center">
            </div>
    </footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>