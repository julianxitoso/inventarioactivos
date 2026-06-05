<?php
// =================================================================================
// ARCHIVO: menu.php
// DESCRIPCIÓN: Menú principal con filtrado de tarjetas por permisos de base de datos
// =================================================================================


require_once 'backend/auth_check.php';


// Verificamos que al menos tenga permiso de ver el menú (login básico)
verificar_permiso_o_morir('ver_menu');

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Usuario';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'Desconocido';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1"> 
    <title>Menú Principal - Inventario TI</title>
    <link rel="icon" type="image/x-icon" sizes="96x96" href="imagenes/icono.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="page-menu">
    <div class="top-bar-custom">
        <div class="logo-container-top">
            <a href="menu.php" title="Ir a Inicio">
                <img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS">
            </a>
        </div>
        <div class="d-flex align-items-center">
            <span class="text-dark me-3 user-info-top">
                <i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> 
                (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)
            </span>
            <a href="cambiar_clave.php" class="btn-change-password" title="Cambia tu Contraseña">
                <i class="bi bi-key-fill"></i>
            </a>
            <form action="logout.php" method="post" class="d-flex">
                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
            </form>
        </div>
    </div>

    <main class="container-main container mt-4">
        
        <?php if(isset($_SESSION['error_acceso_pagina'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= $_SESSION['error_acceso_pagina']; // Permitimos HTML básico ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_acceso_pagina']); ?>
        <?php endif; ?>

        <div class="px-3 py-3 pt-md-4 pb-md-3 mx-auto text-center">
            <h1 class="display-6 fw-normal page-header-title">Bienvenido al Sistema de Inventario</h1>
            <p class="fs-5 text-muted">Seleccione una opción para comenzar.</p>
        </div>
        
        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-3 g-4 justify-content-center">
            
            <?php if (tiene_permiso('crear_activo')): ?>
            <div class="col">
                <a href="index.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-plus-square-fill text-success"></i>
                        <h5 class="card-title">Registrar Activo</h5>
                        <p class="card-text">Añadir nuevos activos al inventario.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (tiene_permiso('buscar_activo')): ?>
            <div class="col">
                <a href="buscar.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-search text-info"></i>
                        <h5 class="card-title">Buscar Activos</h5>
                        <p class="card-text">Consultar y ver detalles de los activos.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (tiene_permiso('gestionar_prestamos')): ?>
            <div class="col">
                <a href="gestion_prestamos.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-arrow-left-right text-secondary"></i>
                        <h5 class="card-title">Gestionar Préstamos</h5>
                        <p class="card-text">Registrar y seguir préstamos de activos.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (tiene_permiso('editar_activo') || tiene_permiso('trasladar_activo')): ?>
            <div class="col">
                <a href="editar.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-pencil-square text-primary"></i>
                        <h5 class="card-title">Administrar Activos</h5>
                        <p class="card-text">Editar, trasladar o dar de baja activos.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (tiene_permiso('registrar_mantenimiento')): ?>
            <div class="col">
                <a href="mantenimiento.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-tools text-info"></i>
                        <h5 class="card-title">Mantenimiento Activos</h5>
                        <p class="card-text">Registrar reparaciones y mantenimientos.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (tiene_permiso('ver_dashboard')): ?>
            <div class="col">
                <a href="dashboard.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-bar-chart-line-fill text-secondary"></i>
                        <h5 class="card-title">Dashboard</h5>
                        <p class="card-text">Visualizar estadísticas y KPIs.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (tiene_permiso('generar_informes')): ?>
            <div class="col">
                <a href="informes.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-file-earmark-text-fill text-warning"></i>
                        <h5 class="card-title">Informes</h5>
                        <p class="card-text">Generar reportes detallados.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (tiene_permiso('ver_depreciacion')): ?>
            <div class="col">
                <a href="depreciacion_activos.php" class="card-link"> 
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-graph-down text-dark"></i>
                        <h5 class="card-title">Depreciación de Activos</h5>
                        <p class="card-text">Calcular y consultar la depreciación.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (tiene_permiso('ver_usuarios')): ?>
            <div class="col">
                <a href="centro_gestion.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-gear-wide-connected text-primary"></i>
                        <h5 class="card-title">Centro de Gestión</h5>
                        <p class="card-text">Usuarios, Roles, Cargos, Tipos, Proveedores.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (tiene_permiso('ver_auditorias')): ?>
            <div class="col">
                <a href="auditorias.php" class="card-link">
                <div class="card menu-card h-100">
                    <div class="card-body">
                        <i class="bi bi-clipboard-check text-primary"></i>
                        <h5 class="card-title">Auditorías Físicas</h5>
                        <p class="card-text">Realizar toma física de inventario, validar existencias y generar reportes.</p>
                    </div>
                </div>
                </a>
            </div>
            <?php endif; ?>
            
        </div>
    </main>

    <footer class="footer-custom mt-auto py-3 bg-light border-top shadow-sm">
        <div class="container text-center">
            <div class="row align-items-center">
                <div class="col-md-6 text-md-start mb-2 mb-md-0">
                    <small class="text-muted">Sitio web desarrollado por <a href="https://www.julianxitoso.com" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-primary">@julianxitoso.com</a></small>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="https://facebook.com/tu_pagina" target="_blank" class="text-muted me-3" title="Facebook">
                        <i class="bi bi-facebook" style="font-size: 1.5rem;"></i>
                    </a>
                    <a href="https://instagram.com/tu_usuario" target="_blank" class="text-muted me-3" title="Instagram">
                        <i class="bi bi-instagram" style="font-size: 1.5rem;"></i>
                    </a>
                    <a href="https://tiktok.com/@tu_usuario" target="_blank" class="text-muted" title="TikTok">
                        <i class="bi bi-tiktok" style="font-size: 1.5rem;"></i>
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="chatbot-loader.js"></script>
</body>
</html>