# Plan de Refactorización: PHP Monolito → Arquitectura Moderna para AWS

## Contexto

El proyecto es un sistema de inventario de activos fijos (ARPESOD ASOCIADOS SAS, Colombia) construido como un monolito PHP plano con 39+ archivos que mezclan lógica de negocio, consultas SQL, HTML y CSS en un solo archivo. Para migrar a AWS, el arquitecto solicita refactorizar primero. Este plan aborda la refactorización completa para transformarlo en una aplicación moderna, segura y desplegable en AWS.

---

## Stack Tecnológico Recomendado

| Capa | Tecnología | Razón |
|------|-----------|-------|
| **Framework** | Laravel 11.x (PHP 8.3) | ORM, migraciones, Blade, auth, colas, CSRF, ecosistema AWS |
| **Auth** | Laravel Breeze (Blade) + Spatie Permission | Reemplaza auth_check.php + RBAC manual |
| **BD** | MySQL 8+ | Compatible con la actual|
| **Templates** | Blade | Layouts maestros, secciones, push de assets |
| **Frontend** | Bootstrap 5.3 + Alpine.js (liviano) | Ya usan Bootstrap, Alpine para interactividad |
| **PDF** | barryvdh/laravel-dompdf | Reemplaza FPDF (que ya es dependencia vía mpdf) |
| **Excel** | maatwebsite/laravel-excel | Wrapper sobre phpoffice/phpspreadsheet (ya instalado) |
| **Auditoría** | spatie/laravel-activitylog | Reemplaza historial_helper.php |
| **Colas** | Laravel Queue + SQS/Redis | PDFs y Excel pesados en background |
| **Sesiones/Cache** | Redis vía ElastiCache | Sesiones compartidas para auto-escalado |
| **Assets** | Vite + CloudFront | Bundling y CDN |
| **Infra AWS** | Elastic Beanstalk o ECS + RDS + S3 | Mínima fricción de migración |

---

## Mejoras a la Base de Datos

### Migraciones (Laravel Migrations, 1 por tabla)
- 15 tablas existentes se migran 1:1 con mejoras:
  - `activos_tecnologicos`: agregar `deleted_at` (soft deletes), renombrar `Codigo_Inv` → `codigo_inv`
  - `usuarios`: renombrar `usuario` → `cedula`, cambiar `rol` (VARCHAR) a FK a `roles.id_rol`
  - Agregar índices compuestos en `historial_activos(id_activo, fecha_evento)` y `auditoria_detalles(id_auditoria, id_activo)`
  - Tablas Spatie: `permissions`, `roles` (con `guard_name`), `model_has_roles`, `model_has_permissions`, `role_has_permissions`

### Seeders
- RoleSeeder + PermissionSeeder (17 permisos existentes)
- RegionalSeeder (11 sedes), CentroCostoSeeder (~95 centros), CargoSeeder (54 cargos)
- **No** seedear passwords de producción; solo un usuario admin bootstrap

### Limpieza
- Archivos de prueba/desarrollo a eliminar: `prueba_final.php`, `test_datos.php`, `generar_bash.php`, `corregir_regional.php`, `reparar_permisos.php`

---

## Estructura del Nuevo Proyecto

```
activosfijos/
├── app/
│   ├── Enums/                     # AssetStatus, DepreciationMethod, LoanStatus
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/              # Login, Register, SSO, ChangePassword
│   │   │   ├── Assets/            # Asset, AssetSearch, AssetEdit, Depreciation
│   │   │   ├── Management/        # User, Role, Cargo, Regional, Category, TipoActivo, CentroCosto, Proveedor
│   │   │   ├── Operations/        # Loan, Maintenance, Audit
│   │   │   ├── Reports/           # Dashboard, Report, History, Export
│   │   │   ├── Pdf/               # ActaEntrega, ActaDevolucion, ActaPrestamo, ActaTraslado, ActaPorCedula
│   │   │   ├── Import/            # ImportController
│   │   │   └── Api/               # AssetSearchApi, DepreciationApi
│   │   ├── Middleware/            # CheckPermission (reemplaza tiene_permiso)
│   │   └── Requests/             # StoreAssetRequest, UpdateAssetRequest, etc.
│   ├── Models/                   # User, Asset, AssetType, Category, Regional, CentroCosto, etc.
│   └── Services/                 # DepreciationService, AuditService, LoanService, PdfGenerationService, etc.
├── database/
│   ├── migrations/               # 15+ migraciones
│   └── seeders/                  # RoleSeeder, PermissionSeeder, RegionalSeeder, etc.
├── resources/
│   ├── views/
│   │   ├── layouts/app.blade.php  # Layout maestro (topbar, footer, chatbot, alerts)
│   │   ├── assets/               # create, search, edit, depreciation
│   │   ├── management/           # users, roles, cargos, categories, asset-types, regionals, etc.
│   │   ├── operations/           # loans, maintenance, audits
│   │   └── reports/              # dashboard, reports, history
│   └── js/                      # asset-form.js, asset-search.js, etc.
├── routes/
│   ├── web.php                   # Rutas web
│   └── api.php                   # Rutas REST API
├── tests/
│   ├── Feature/                  # Auth, AssetManagement, Depreciation, Loan, Audit
│   └── Unit/                     # Services/DepreciationServiceTest, etc.
├── config/                       # database, permission, activitylog, dompdf, excel, filesystems, session, queue
├── docker-compose.yml            # Dev local: PHP 8.3 + MariaDB + Redis
└── .env.example
```

---

## Mapeo Archivos Antiguos → Nuevos (Agrupado por Sprint)

### Sprint 1: Fundación
| Antiguo | Nuevo |
|---------|-------|
| `backend/db.php` | Eliminado (Laravel gestiona BD vía `.env` + `config/database.php`) |
| `backend/auth_check.php` | Eliminado → `app/Http/Middleware/CheckPermission.php` + Spatie |
| `backend/historial_helper.php` | `app/Services/AssetHistoryService.php` (o Spatie Activitylog) |
| `backend/obtener_datos_dinamicos.php` | Rutas API: `GET /api/centros-por-regional/{id}`, `GET /api/tipos-por-categoria/{id}` |

### Sprint 2: Auth + Menú
| Antiguo | Nuevo |
|---------|-------|
| `login.php` | Breeze scaffolding → `Auth/LoginController` |
| `logout.php` | Breeze `AuthenticatedSessionController@destroy` |
| `registro.php` | `Auth/RegisterController` |
| `sso_login.php` | `Auth/SsoController` (firebase/php-jwt ya instalado) |
| `cambiar_clave.php` | `Auth/ChangePasswordController` |
| `menu.php` | `MenuController` + `menu.blade.php` con `@can` |

### Sprint 3: Core Activos
| Antiguo | Nuevo |
|---------|-------|
| `index.php` (809 líneas) | `AssetController` + `assets/create.blade.php` + `asset-form.js` |
| `guardar_activo.php` | `AssetController@store` |
| `buscar.php` + `api_buscar.php` | `AssetSearchController` + `assets/search.blade.php` |
| `editar.php` (1022 líneas, 7 acciones) | `AssetEditController` (7 métodos: update, transfer, baja, delete, restore, history, details) |
| `historial.php` | `HistoryController` |

### Sprint 4: Módulos de Gestión
| Antiguo | Nuevo |
|---------|-------|
| `centro_gestion.php` | Vista hub de gestión |
| `gestionar_usuarios.php` + `guardar_usuario.php` | `UserController` |
| `gestionar_roles.php` + `editar_rol.php` | `RoleController` (Spatie UI) |
| `gestionar_cargos.php` | `CargoController` |
| `gestionar_categorias.php` | `CategoryController` |
| `gestionar_activos.php` (tipos) | `TipoActivoController` |
| `gestionar_regionales.php` | `RegionalController` |
| `gestionar_proveedores.php` | `ProveedorController` |
| `buscar_datos_usuario.php` | `UserController@search` (AJAX) |

### Sprint 5: Operaciones + Reportes
| Antiguo | Nuevo |
|---------|-------|
| `gestion_prestamos.php` (998 líneas) | `LoanController` + `LoanService` |
| `mantenimiento.php` | `MaintenanceController` |
| `auditorias.php` + `ejecutar_auditoria.php` + `resultado_auditoria.php` | `AuditController` + `AuditService` |
| `dashboard.php` (499 líneas) | `DashboardController` |
| `informes.php` | `ReportController` |

### Sprint 6: PDF/Excel/Import
| Antiguo | Nuevo |
|---------|-------|
| 6 archivos `generar_acta_*.php` | `PdfGenerationService` + controladores por tipo de acta |
| `exportar_excel.php` + `exportar_auditoria.php` | `ExportController` (maatwebsite/laravel-excel) |
| `importar.php` + 3 `procesar_importacion_*.php` | `ImportController` + `ImportService` |

---

## Checklist de Seguridad

| Problema | Solución |
|----------|----------|
| Sin CSRF | Laravel `@csrf` + `VerifyCsrfToken` middleware |
| SQL Injection parcial | 100% Eloquent ORM o queries parametrizadas |
| Error disclosure (`display_errors=1` en 10+ archivos) | `APP_DEBUG=false` en prod, todo a logs |
| `.env` en git | Ya en `.gitignore`; Secrets Manager en AWS |
| Sin rate limiting | `RateLimiter` en login y API |
| XSS parcial (algunos `echo $var` sin escapar) | Blade `{{ }}` auto-escapa siempre |
| Sin validación de uploads | Validar MIME + tamaño, S3 con URLs firmadas |
| Headers HTTP faltantes | Middleware HSTS, X-Frame-Options, X-Content-Type-Options |

---

## AWS Readiness

| Requisito | Implementación |
|-----------|---------------|
| Stateless | Sesiones en ElastiCache Redis |
| Config vía entorno | `.env` + Secrets Manager (sin valores hardcodeados) |
| Logging | Laravel logs + CloudWatch Agent |
| File Storage | S3 (RUTs, PDFs, exports) |
| Colas | SQS para PDF/Excel pesados |
| Auto-escalado | Elastic Beanstalk con CPU/memory triggers |
| CDN | CloudFront para assets estáticos |
| Health check | `GET /health` → DB + cache |
| CI/CD | GitHub Actions → EB / ECS |
| Database | RDS MariaDB 10.6 (compatible 1:1) |

---

## Fases de Implementación (8 semanas)

| Fase | Semanas | Entregable |
|------|---------|------------|
| **1. Fundación** | 1-2 | Laravel instalado, migraciones, modelos, layout master, Docker local |
| **2. Auth + Menú** | 2-3 | Login funcional, permisos Spatie, menú con @can |
| **3. Core Activos** | 3-4 | CRUD completo de activos con AJAX, búsqueda, edición |
| **4. Gestión** | 4-5 | Todos los CRUDs administrativos (usuarios, roles, cargos, regionales, etc.) |
| **5. Operaciones** | 5-6 | Préstamos, mantenimiento, auditorías, dashboard, reportes |
| **6. PDF/Excel/Import** | 6-7 | Exportaciones, importaciones, generación de actas |
| **7. Testing + Seguridad** | 7-8 | Tests feature/unit, auditoría de seguridad |
| **8. Migración AWS** | 8 |  S3, EC2 |

---

## Verificación

1. **Local**: `docker-compose up` → app funcionando en `localhost:8080`
2. **Datos**: migraciones + seeders contra copia de BD producción → mismos datos
3. **Auth**: login con usuarios existentes (misma tabla `usuarios` con bcrypt)
4. **Funcional**: cada ruta mapeada produce el mismo resultado que el archivo PHP original
5. **Tests**: `php artisan test` → verde (mínimo: auth, permisos, depreciation, asset CRUD)
6. **Seguridad**: OWASP ZAP scan → 0 críticos
7. **AWS**: Pendiente
