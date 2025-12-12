<?php
session_start();
require_once 'backend/auth_check.php';
// Restringimos acceso a admins y registradores
restringir_acceso_pagina(['admin', 'registrador']); 

$nombre_usuario_actual_sesion = $_SESSION['nombre_usuario_completo'] ?? 'Administrador';
$rol_usuario_actual_sesion = $_SESSION['rol_usuario'] ?? 'admin';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Centro de Gestión - Inventario TI</title>
    <link rel="icon" type="image/x-icon" href="imagenes/icono.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
    html {
        height: 100%; 
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        padding-top: 100px;
        background-color: #f8f9fa;
        display: flex;
        flex-direction: column;
        min-height: 100vh; 
        margin: 0; 
    }

    .container-main {
        flex-grow: 1; 
        margin-top: 20px;
        margin-bottom: 20px; 
    }

    .top-bar-custom {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1030;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 1.5rem;
        background-color: #ffffff;
        border-bottom: 1px solid #dee2e6;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .logo-container-top img { width: auto; height: 75px; object-fit: contain; margin-right: 15px; }
    .user-info-top { font-size: 0.9rem; }
    .page-title { color: #191970; font-weight: 600; }
    .card { border: 1px solid #e0e0e0; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075); background-color: #ffffff; }
    .management-hub-cards .card { cursor: pointer; transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; }
    .management-hub-cards .card:hover { transform: translateY(-5px); box-shadow: 0 0.5rem 1rem rgba(0,0,0,.15); }
    .management-hub-cards .card-body { text-align: center; }
    .management-hub-cards .card i { font-size: 2.5rem; margin-bottom: 0.5rem; }
    .management-hub-cards .card-title { font-size: 1.1rem; font-weight: 500; }
    .management-hub-cards .card-text { font-size: 0.85rem; }

    /* Estilos para el footer */
    .footer-custom {
        background-color: #343a40;
        color: #ffffff;
        text-align: center;
        padding: 1rem 0;
        font-size: 0.8rem;
        margin-top: auto; 
    }

    .footer-custom a {
        color: #ffffff;
        text-decoration: none;
    }

    .footer-custom a:hover {
        text-decoration: underline;
    }

    .footer-social-icons a {
        color: #ffffff;
        font-size: 1.2rem;
        margin: 0 0.5rem;
    }
</style>
</head>
<body>
<div class="top-bar-custom">
    <div class="logo-container-top">
        <a href="menu.php" title="Ir a Inicio"><img src="imagenes/logo.png" alt="Logo ARPESOD ASOCIADOS SAS"></a>
    </div>
    <div class="d-flex align-items-center">
        <span class="text-dark me-3 user-info-top"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombre_usuario_actual_sesion) ?> (<?= htmlspecialchars(ucfirst($rol_usuario_actual_sesion)) ?>)</span>
        <form action="logout.php" method="post" class="d-flex"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button></form>
    </div>
</div>

<div class="container-main container mt-4">
    <h3 class="page-title text-center mb-5"> 
        <i class="bi bi-gear-wide-connected"></i> Centro de Gestión
    </h3>

    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 mb-5 management-hub-cards justify-content-center">
        
        <?php 
        // BLOQUE DE ADMINISTRACIÓN (Solo Admin)
        if ($_SESSION['rol_usuario'] == 'admin'): 
        ?>
        
        <div class="col">
            <a href="gestionar_usuarios.php" class="text-decoration-none text-dark">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column justify-content-center align-items-center p-4"> 
                        <i class="bi bi-people-fill text-primary"></i>
                        <h5 class="card-title mt-2">Gestionar Usuarios</h5>
                        <p class="card-text small text-muted">Crear, editar y administrar accesos de usuarios.</p>
                    </div>
                </div>
            </a>
        </div>

        <div class="col">
            <a href="gestionar_activos.php" class="text-decoration-none text-dark"> 
                <div class="card h-100">
                     <div class="card-body d-flex flex-column justify-content-center align-items-center p-4">
                        <i class="bi bi-tags-fill text-warning"></i>
                        <h5 class="card-title mt-2">Gestionar Tipos de Activo</h5>
                        <p class="card-text small text-muted">Añadir o eliminar categorías de activos.</p>
                    </div>
                </div>
            </a>
        </div>

        <div class="col">
            <a href="gestionar_roles.php" class="text-decoration-none text-dark">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column justify-content-center align-items-center p-4">
                        <i class="bi bi-shield-lock-fill text-info"></i>
                        <h5 class="card-title mt-2">Gestionar Roles</h5>
                        <p class="card-text small text-muted">Definir los permisos para cada rol del sistema.</p>
                    </div>
                </div>
            </a>
        </div>

        <div class="col">
            <a href="gestionar_cargos.php" class="text-decoration-none text-dark">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column justify-content-center align-items-center p-4">
                        <i class="bi bi-person-badge-fill text-success"></i>
                        <h5 class="card-title mt-2">Gestionar Cargos</h5>
                        <p class="card-text small text-muted">Administrar los cargos de los empleados.</p>
                    </div>
                </div>
            </a>
        </div>

        <div class="col">
            <a href="gestionar_proveedores.php" class="text-decoration-none text-dark">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column justify-content-center align-items-center p-4">
                        <i class="bi bi-truck text-danger"></i> 
                        <h5 class="card-title mt-2">Gestionar Proveedores</h5>
                        <p class="card-text small text-muted">Administrar información de proveedores.</p>
                    </div>
                </div>
            </a>
        </div>

        <div class="col">
            <a href="gestionar_regionales.php" class="text-decoration-none text-dark">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column justify-content-center align-items-center p-4">
                        <i class="bi bi-geo-alt-fill text-danger"></i> 
                        <h5 class="card-title mt-2">Gestionar Regionales</h5>
                        <p class="card-text small text-muted">Administrar sedes y centros de costo.</p>
                    </div>
                </div>
            </a>
        </div>

        <div class="col">
            <a href="importar.php" class="text-decoration-none text-dark">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column justify-content-center align-items-center p-4">
                        <i class="bi bi-file-earmark-arrow-up-fill text-success"></i>
                        <h5 class="card-title mt-2">Importar Categorías y Activos</h5>
                        <p class="card-text small text-muted">Carga masiva desde archivos Excel/CSV.</p>
                    </div>
                </div>
            </a>
        </div>

        <?php endif; // Fin bloque Admin ?>

    </div>
</div>

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
</body>
</html>