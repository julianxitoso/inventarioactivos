<?php
// =================================================================================
// ARCHIVO: menu.php
// DESCRIPCIÓN: Menú principal con filtrado de tarjetas por permisos
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        html, body {
            height: 100%; 
        }
        body { 
            background-color: #ffffff !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 95px; 
            display: flex; 
            flex-direction: column; 
            min-height: 100vh; 
        }
        .top-bar-custom {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1030;
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.5rem 1.5rem; background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
        .user-info-top { font-size: 0.9rem; }
        main.container-main { margin-top: 20px; margin-bottom: 40px; flex-grow: 1; }
        .page-header-title { color: #191970; }
        .card-link { text-decoration: none; }
        .menu-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            border: 1px solid #e0e0e0; border-radius: 0.5rem; display: flex; 
            flex-direction: column; background-color: #ffffff; 
        }
        .menu-card:hover { transform: translateY(-5px); box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.1); }
        .menu-card .card-body { 
            text-align: center; padding: 1.0rem; flex-grow: 1; 
            display: flex; flex-direction: column;
            justify-content: center; align-items: center;
        }
        .menu-card i { font-size: 2.0rem; margin-bottom: 0.75rem; display: block; }
        .menu-card .card-title {
            font-weight: 500; color: #333; margin-bottom: 0.5rem; font-size: 1rem; 
        }
        .menu-card .card-text { font-size: 0.8rem; color: #555; min-height: 35px; }
        .btn-change-password { color: #6c757d; text-decoration: none; font-size: 1.2rem; margin-right: 0.75rem; }
        .btn-change-password:hover { color: #0d6efd; }

        .footer-custom {
            font-size: 0.9rem; background-color: #f8f9fa; 
            border-top: 1px solid #dee2e6; 
        }
        .footer-custom a i { color: #6c757d; transition: color 0.2s ease-in-out; }
        .footer-custom a i:hover { color: #0d6efd !important; }

        /* ESTILOS PARA EL CHATBOT */
        #chatbot-button {
            position: fixed; bottom: 25px; right: 25px; width: 60px; height: 60px;
            background-color: #007bff; color: white; border-radius: 50%;
            display: flex; justify-content: center; align-items: center;
            cursor: pointer; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            z-index: 999; transition: transform 0.2s ease-in-out;
        }
        #chatbot-button:hover { transform: scale(1.1); }
        #chatbot-container {
            position: fixed; bottom: 95px; right: 25px; width: 370px; 
            height: 70vh; max-height: 500px; background-color: white;
            border-radius: 15px; box-shadow: 0 8px 20px rgba(0,0,0,0.15); 
            display: none; flex-direction: column; overflow: hidden; z-index: 1000;
            border: 1px solid #dee2e6; 
        }
        #chatbot-container.visible { display: flex; }
        #chatbot-header {
            background-color: #007bff; color: white; padding: 10px 15px;
            font-weight: bold; display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid #0056b3; 
        }
        #chatbot-header-content { 
            display: flex;
            align-items: center;
        }
        #chatbot-header-content img { 
            width: 52px; 
            height: 52px; 
            border-radius: 50%; 
            margin-right: 10px;
            border: 2px solid #ffffff; 
            object-fit: cover; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.2); 
        }
        #chatbot-header-content span { 
            font-size: 1.1rem;
        }
        #chatbot-close-button {
            cursor: pointer; font-size: 24px; font-weight: bold;
            padding: 0 5px; 
            line-height: 1;
        }
        #chatbot-close-button:hover {
            color: #e9ecef;
        }
        #chatbot-iframe { width: 100%; height: 100%; border: none; }
    </style>
</head>
<body>
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