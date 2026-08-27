// ========================================================
                // 2. GESTIÓN DE CATEGORÍAS (AQUÍ VA LA CONTABILIDAD AHORA)
                // ========================================================
                $id_categoria = null;
                $res_cat = $conexion->query("SELECT id_categoria FROM categorias_activo WHERE nombre_categoria = '" . $conexion->real_escape_string($categoria) . "' LIMIT 1");
                
                if ($res_cat && $res_cat->num_rows > 0) {
                    $id_categoria = $res_cat->fetch_object()->id_categoria;
                    
                    // Si la categoría ya existe, le actualizamos/inyectamos sus cuentas contables
                    $sql_upd_cat = "UPDATE categorias_activo SET cuenta_contable = ?, nombre_cuenta = ?, cuenta_depreciacion = ?, nombre_cuenta_depreciacion = ? WHERE id_categoria = ?";
                    $stmt_upd_cat = $conexion->prepare($sql_upd_cat);
                    $stmt_upd_cat->bind_param("ssssi", $codigo_cuenta, $nombre_cuenta, $codigo_depreciacion, $nombre_depreciacion, $id_categoria);
                    $stmt_upd_cat->execute();
                    $stmt_upd_cat->close();
                } else {
                    // Si no existe, creamos la categoría junto con todo su esquema contable
                    $sql_ins_cat = "INSERT INTO categorias_activo (nombre_categoria, cuenta_contable, nombre_cuenta, cuenta_depreciacion, nombre_cuenta_depreciacion) VALUES (?, ?, ?, ?, ?)";
                    $stmt_ins_cat = $conexion->prepare($sql_ins_cat);
                    $stmt_ins_cat->bind_param("sssss", $categoria, $codigo_cuenta, $nombre_cuenta, $codigo_depreciacion, $nombre_depreciacion);
                    $stmt_ins_cat->execute();
                    $id_categoria = $stmt_ins_cat->insert_id;
                    $stmt_ins_cat->close();
                }

                // ========================================================
                // 3. GESTIÓN DE TIPOS DE ACTIVO (Limpio y simple)
                // ========================================================
                $id_tipo_activo = null;
                $res_tipo = $conexion->query("SELECT id_tipo_activo FROM tipos_activo WHERE nombre_tipo_activo = '" . $conexion->real_escape_string($nombre_tipo) . "' LIMIT 1");
                
                if ($res_tipo && $res_tipo->num_rows > 0) {
                    $id_tipo_activo = $res_tipo->fetch_object()->id_tipo_activo;
                } else {
                    $sql_ins = "INSERT INTO tipos_activo (nombre_tipo_activo, id_categoria) VALUES (?, ?)";
                    $stmt_ins = $conexion->prepare($sql_ins);
                    $stmt_ins->bind_param("si", $nombre_tipo, $id_categoria);
                    $stmt_ins->execute();
                    $id_tipo_activo = $stmt_ins->insert_id;
                    $stmt_ins->close();
                }