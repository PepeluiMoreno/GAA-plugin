<?php
class GAA_Ingreso {
    
    public static function init() {
        // Shortcodes
        add_shortcode('gaa_formulario_ingreso', array(__CLASS__, 'formulario_ingreso'));
        add_shortcode('gaa_validar_email', array(__CLASS__, 'validar_email'));
        
        // Procesar formularios
        add_action('template_redirect', array(__CLASS__, 'procesar_solicitud'));
        add_action('template_redirect', array(__CLASS__, 'procesar_validacion'));
        
        // CPT Socio
        add_action('init', array(__CLASS__, 'registrar_cpt_socio'));
        
        // Meta boxes en admin
        add_action('add_meta_boxes', array(__CLASS__, 'meta_boxes'));
        add_action('save_post', array(__CLASS__, 'guardar_meta'));
    }
    
    // REGISTRAR CPT SOCIO
    public static function registrar_cpt_socio() {
        register_post_type('socio', array(
            'labels' => array(
                'name' => 'Socios',
                'singular_name' => 'Socio',
                'add_new' => 'Añadir Nuevo',
                'edit_item' => 'Revisar Solicitud'
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // Oculto, solo accesible desde admisión
            'capability_type' => 'post',
            'supports' => array('title'),
            'menu_icon' => 'dashicons-groups'
        ));
    }
    
    // FORMULARIO DE INGRESO (paso 1)
    public static function formulario_ingreso() {
        if (isset($_GET['enviado']) && $_GET['enviado'] == 'ok') {
            return '<div style="background:#d4edda; color:#155724; padding:20px; border-radius:4px;">
                <h3>¡Solicitud recibida!</h3>
                <p>Revisa tu email para validar tu dirección y continuar el proceso.</p>
            </div>';
        }
        
        ob_start();
        ?>
        <div style="max-width:600px; margin:0 auto; padding:20px;">
            <h2>Solicitud de Ingreso</h2>
            <form method="post">
                <?php wp_nonce_field('gaa_solicitud_ingreso', 'gaa_nonce'); ?>
                
                <p>
                    <label>Nombre:</label>
                    <input type="text" name="nombre" required style="width:100%; padding:8px;">
                </p>
                
                <p>
                    <label>Apellidos:</label>
                    <input type="text" name="apellidos" required style="width:100%; padding:8px;">
                </p>
                
                <p>
                    <label>Email:</label>
                    <input type="email" name="email" required style="width:100%; padding:8px;">
                </p>
                
                <p>
                    <label>Teléfono:</label>
                    <input type="tel" name="telefono" style="width:100%; padding:8px;">
                </p>
                
                <p>
                    <label>Dirección:</label>
                    <textarea name="direccion" rows="3" style="width:100%; padding:8px;"></textarea>
                </p>
                
                <p>
                    <label>Profesión:</label>
                    <input type="text" name="profesion" style="width:100%; padding:8px;">
                </p>
                
                <p>
                    <label>Nivel de estudios:</label>
                    <select name="nivel_estudios" style="width:100%; padding:8px;">
                        <option value="">Seleccione...</option>
                        <option value="primaria">Primaria</option>
                        <option value="secundaria">Secundaria</option>
                        <option value="universitaria">Universitaria</option>
                        <option value="otros">Otros</option>
                    </select>
                </p>
                
                <p>
                    <label>Año de nacimiento:</label>
                    <input type="number" name="ano_nacimiento" min="1900" max="<?php echo date('Y'); ?>" style="width:100%; padding:8px;">
                </p>
                
                <p>
                    <label>
                        <input type="checkbox" name="acepto" required>
                        Acepto los estatutos de la asociación
                    </label>
                </p>
                
                <p>
                    <button type="submit" name="enviar_solicitud" style="background:#0073aa; color:#fff; padding:12px 24px; border:none; border-radius:4px; cursor:pointer;">
                        Enviar solicitud
                    </button>
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // PROCESAR SOLICITUD (paso 1)
    public static function procesar_solicitud() {
        if (!isset($_POST['enviar_solicitud'])) return;
        if (!wp_verify_nonce($_POST['gaa_nonce'], 'gaa_solicitud_ingreso')) return;
        
        $email = sanitize_email($_POST['email']);
        $nombre = sanitize_text_field($_POST['nombre']);
        $apellidos = sanitize_text_field($_POST['apellidos']);
        
        // Crear token único
        $token = wp_generate_password(32, false);
        
        // Crear socio en estado "solicitante"
        $socio_id = wp_insert_post(array(
            'post_title' => $nombre . ' ' . $apellidos,
            'post_type' => 'socio',
            'post_status' => 'private', // No publico
            'meta_input' => array(
                '_gaa_nombre' => $nombre,
                '_gaa_apellidos' => $apellidos,
                '_gaa_email' => $email,
                '_gaa_telefono' => sanitize_text_field($_POST['telefono']),
                '_gaa_direccion' => sanitize_textarea_field($_POST['direccion']),
                '_gaa_profesion' => isset($_POST['profesion']) ? sanitize_text_field($_POST['profesion']) : '',
                '_gaa_nivel_estudios' => isset($_POST['nivel_estudios']) ? sanitize_text_field($_POST['nivel_estudios']) : '',
                '_gaa_ano_nacimiento' => isset($_POST['ano_nacimiento']) ? intval($_POST['ano_nacimiento']) : 0,
                '_gaa_acepto' => isset($_POST['acepto']) ? 1 : 0,
                '_gaa_token' => $token,
            ),
        ));

        // Redirigir al formulario con indicador de envío
        if ($socio_id && !is_wp_error($socio_id)) {
            $current = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : home_url('/');
            wp_safe_redirect( add_query_arg('enviado', 'ok', $current) );
            exit;
        }
    }

    // Procesar validación (placeholder)
    public static function procesar_validacion() {
        return;
    }

    // Shortcode para validar email (placeholder)
    public static function validar_email() {
        return '';
    }

    // Meta boxes (placeholder)
    public static function meta_boxes() {
    }

    // Guardar metadatos al guardar el post
    public static function guardar_meta($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (get_post_type($post_id) !== 'socio') return;
    }

}
