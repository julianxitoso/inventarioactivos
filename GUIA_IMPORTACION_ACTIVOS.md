# Guía de Importación de Activos Desde Sistema Externo

## Formato del Archivo Excel (Plantilla Maestra)

El archivo debe ser **.xlsx** con **14 columnas (A a N)**. La primera fila debe ser el encabezado y los datos empiezan en la fila 2.

| Columna | Campo | Obligatorio | Descripción | Ejemplo |
|---------|-------|-------------|-------------|---------|
| **A** | Nombre Categoría | ✅ Sí | Si no existe, se crea automáticamente | `Muebles y Enseres` |
| **B** | Cuenta Contable | ❌ No | Cuenta contable de la categoría | `152405` |
| **C** | Nombre Cuenta | ❌ No | Nombre de la cuenta contable | `Muebles y Enseres` |
| **D** | Nombre Tipo Activo | ✅ Sí | Si no existe, se crea vinculado a su categoría | `Silla Ergonómica` |
| **E** | Vida Útil (años) | ❌ No | Vida útil sugerida para el tipo de activo | `5` |
| **F** | Código Inventario | ❌ No* | Código interno de inventario | `SILLA-001` |
| **G** | Serie / Serial | ✅ Sí* | **Debe ser único en todo el sistema** | `SN-001` |
| **H** | Marca | ❌ No | Marca del activo | `Herman Miller` |
| **I** | Modelo | ❌ No | Modelo (se guarda en "Detalles") | `Aeron` |
| **J** | Cédula del Responsable | ✅ Sí | **Debe existir en la tabla `usuarios`** | `98655270` |
| **K** | Estado | ❌ No | Por defecto: `Bueno` | `Bueno` |
| **L** | Valor / Costo | ❌ No | Valor de compra del activo | `2500000` |
| **M** | Fecha de Compra | ❌ No | Por defecto: fecha de hoy. Acepta formato Excel nativo o texto `YYYY-MM-DD` | `2024-01-15` |
| **N** | Detalles | ❌ No | Observaciones adicionales | `Importado desde sistema legacy` |

> **\*** Si no hay Serie (G) ni Código de Inventario (F), la fila se omite.
> Si falta la Serie (G) pero hay Código de Inventario (F), la serie se genera automáticamente como `SN-[codigo_inventario]`.

---

## Reglas del Importador

### Categorías y Tipos
- Si la **Categoría** (col A) no existe en la tabla `categorias_activo`, se crea automáticamente
- Si el **Tipo de Activo** (col D) no existe en la tabla `tipos_activo`, se crea vinculado a la categoría
- La vida útil del tipo (col E) solo se usa al crear el tipo nuevo; si el tipo ya existe, se ignora

### Validación de duplicados
- La **Serie** (col G) debe ser única en toda la tabla `activos_tecnologicos`
- El **Código de Inventario** (col F) también debe ser único
- Si hay duplicados dentro del mismo archivo o contra la base de datos, la fila se omite

### Responsable (Cédula)
- La cédula (col J) **DEBE existir** en la tabla `usuarios`
- Si no existe, la fila se omite con error
- El **centro de costo** del activo se asigna automáticamente desde el perfil del usuario

### Fechas
- Si la fecha (col M) viene en formato numérico de Excel (serial date), se convierte automáticamente
- Si viene como texto, se espera formato `YYYY-MM-DD`
- Si viene vacía, se usa la fecha actual

---

## Lo Que Necesitas Extraer de Tu Otro Sistema

| Qué necesitas obtener | Columna Excel | Notas importantes |
|----------------------|---------------|-------------------|
| **Categoría del activo** | A | Mapea las categorías de tu otro sistema. Si son diferentes, el importador las creará automáticamente |
| **Tipo de activo** | D | Mapeo 1:1 con el tipo de activo que uses actualmente |
| **Serie / Serial** | G | **Campo más crítico**. Si tu sistema no maneja seriales, puedes concatenar: `TIPO-CODIGO` (ej: `SILLA-001`) |
| **Marca** | H | Migración directa |
| **Modelo** | I | Se guardará en el campo "Detalles" del activo |
| **Cédula del responsable** | J | **Debe existir en `usuarios`**. Si hay responsables nuevos, créalos primero |
| **Estado del activo** | K | Valores esperados: `Bueno`, `Regular`, `Malo` |
| **Valor de compra** | L | Número, sin formato moneda (ej: `2500000` no `$2.500.000`) |
| **Fecha de compra** | M | En lo posible, formato `YYYY-MM-DD` |
| **Código de inventario** | F | Si tu sistema ya tiene códigos de inventario, úsalos aquí |

---

### ⚠️ Punto Crítico: Los Responsables

Antes de importar, verifica que **todas las cédulas** de tu otro sistema existan en la tabla `usuarios` de esta plataforma.

Consulta SQL para verificar:
```sql
SELECT u.usuario, u.nombre_completo
FROM usuarios u
WHERE u.usuario IN ('cedula1', 'cedula2', 'cedula3', ...);
```

Si faltan responsables, tienes dos opciones:

**Opción A** — Crearlos manualmente desde el menú:
1. Ir a `Centro de Gestión` → `Usuarios`
2. Registrar cada responsable con su cédula como usuario y clave

**Opción B** — Crear un importador de usuarios:
Si tu otro sistema puede exportar también los datos de los responsables (cédula, nombre, cargo, empresa), puedo ayudarte a hacer un precargado masivo antes de importar los activos.

---

## Relaciones Entre Tablas

```
Excel (col I) ───> usuarios.usuario (debe existir)
Excel (col A) ───> categorias_activo.nombre_categoria (se crea si no existe)
Excel (col C) ───> tipos_activo.nombre_tipo_activo (se crea si no existe)
                       └──> vinculado a categorias_activo.id_categoria
Activo creado:
  activos_tecnologicos.id_usuario_responsable ───> usuarios.id
  activos_tecnologicos.id_tipo_activo ───────────> tipos_activo.id_tipo_activo
  activos_tecnologicos.id_centro_costo ──────────> usuarios.id_centro_costo (se copia del responsable)
```

---

## Flujo de Preparación Recomendado

1. Exporta los datos de tu otro sistema a un CSV/Excel
2. Agrega las columnas A-M según el mapeo de arriba
3. Verifica que todas las cédulas existan en `usuarios` (SQL de verificación arriba)
4. Descarga la plantilla desde `importar.php` → "Descargar Plantilla Maestra"
5. Ajusta tu archivo al mismo formato de la plantilla
6. Sube el archivo desde `importar.php` → "Procesar Archivo Completo"
7. Revisa el reporte: cuántos activos se importaron y cuántos se omitieron

Si tu otro sistema puede exportar un archivo ya en este formato, el proceso es directo y no requiere programación adicional. Si el formato es muy distinto, dime cómo se ve la exportación y te ayudo a mapearlo.
