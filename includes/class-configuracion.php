<?php
class GAA_Configuracion {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu_configuracion'));
        // Añadir columna en la lista de usuarios para mostrar rol de admisiones
        add_filter('manage_users_columns', array(__CLASS__, 'add_gaa_user_column'));
        add_action('manage_users_custom_column', array(__CLASS__, 'render_gaa_user_column'), 10, 3);
        // AJAX para obtener contenido de plantillas MailPoet
        add_action('wp_ajax_gaa_fetch_mailpoet_template', array(__CLASS__, 'ajax_fetch_mailpoet_template'));
    }

    // Obtener una instancia segura de la API de MailPoet (compatible con distintas firmas de MP())
    public static function get_mailpoet_api() {
        if (!class_exists('MailPoet\\API\\API')) return null;
        try {
            $rm = new ReflectionMethod('MailPoet\\API\\API', 'MP');
            $req = $rm->getNumberOfRequiredParameters();
            if ($req === 0) {
                try { return \MailPoet\API\API::MP(); } catch (\Throwable $e) { /* continue */ }
            }
            // Intentar con parámetros comunes
            $candidates = array('v1','v2','v3','default');
            foreach ($candidates as $c) {
                try {
                    $inst = \MailPoet\API\API::MP($c);
                    if (is_object($inst)) return $inst;
                } catch (\Throwable $__e) { continue; }
            }
            // Intentar sin comprobación (último recurso)
            try { return \MailPoet\API\API::MP('v1'); } catch (\Throwable $__e) { return null; }
        } catch (\ReflectionException $e) {
            try { return \MailPoet\API\API::MP('v1'); } catch (\Throwable $e2) { return null; }
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function ajax_fetch_mailpoet_template() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('msg'=>'No autorizado'));
        if (empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gaa_mailpoet_import')) wp_send_json_error(array('msg'=>'Nonce inválido'));
        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
        if (empty($id)) wp_send_json_error(array('msg'=>'ID de plantilla no proporcionada'));

        // Intentar obtener HTML vía MailPoet API (varias interfaces posibles)
        try {
              if (!class_exists('MailPoet\\API\\API')) wp_send_json_error(array('msg'=>'MailPoet no disponible'));
              $mp = self::get_mailpoet_api();
            $tpl = null;
            if (is_object($mp)) {
                if (method_exists($mp,'templates')) {
                    $svc = $mp->templates();
                    if (is_object($svc) && method_exists($svc,'get')) $tpl = $svc->get($id);
                    elseif (is_object($svc) && method_exists($svc,'getById')) $tpl = $svc->getById($id);
                    elseif (is_object($svc) && method_exists($svc,'getTemplate')) $tpl = $svc->getTemplate($id);
                }
                if (!$tpl && method_exists($mp,'getTemplate')) $tpl = $mp->getTemplate($id);
            }
            // Fallback: cargar como post si no se obtuvo via API
            if (!$tpl) {
                $post = get_post($id);
                if ($post) $tpl = $post;
            }

            $html = '';
            if ($tpl) {
                if (is_array($tpl)) {
                    if (!empty($tpl['html'])) $html = $tpl['html'];
                    elseif (!empty($tpl['content'])) $html = $tpl['content'];
                    elseif (!empty($tpl['body'])) $html = $tpl['body'];
                } elseif (is_object($tpl)) {
                    if (isset($tpl->html) && !empty($tpl->html)) $html = $tpl->html;
                    elseif (isset($tpl->content) && !empty($tpl->content)) $html = $tpl->content;
                    elseif (isset($tpl->body) && !empty($tpl->body)) $html = $tpl->body;
                    elseif (isset($tpl->post_content) && !empty($tpl->post_content)) $html = $tpl->post_content;
                }
            }

            if (empty($html)) wp_send_json_error(array('msg'=>'No se pudo extraer HTML de la plantilla seleccionada'));
            wp_send_json_success(array('html' => $html));
        } catch (\Throwable $e) {
            wp_send_json_error(array('msg' => 'Error extrayendo plantilla: ' . $e->getMessage()));
        }
    }

    public static function add_gaa_user_column($columns) {
        $new = array();
        foreach ($columns as $key => $title) {
            $new[$key] = $title;
            if ($key === 'name') {
                $new['gaa_roles'] = 'Roles';
            }
        }
        return $new;
    }

    public static function render_gaa_user_column($value, $column_name, $user_id) {
        if ($column_name !== 'gaa_roles') return $value;
        $user = get_userdata($user_id);
        if (!$user) return '';
        $roles = (array) $user->roles;
        $labels = array();
        foreach ($roles as $r) {
            if ($r === 'gestion_afiliados') {
                $labels[] = 'Responsable de Admisiones';
            } else {
                $role_obj = get_role($r);
                $labels[] = $role_obj ? $role_obj->name : $r;
            }
        }
        return implode(', ', $labels);
    }
    
    public static function menu_configuracion() {
        // Crear menú principal "Asociados" siempre, con submenu "Ajustes".
        $parent_slug = 'gaa-asociados';
        add_menu_page(
            'Asociados',
            'Asociados',
            'manage_options',
            $parent_slug,
            array(__CLASS__, 'pagina_configuracion'),
            'dashicons-groups',
            26
        );
        // Submenu Ajustes (lleva a la misma página de configuración)
        add_submenu_page(
            $parent_slug,
            'Ajustes - Asociados',
            'Ajustes',
            'manage_options',
            'gaa-config',
            array(__CLASS__, 'pagina_configuracion')
        );

        // Submenu Solicitudes: solo si plugin está correctamente configurado
        $usuario_admision = get_option('gaa_usuario_admision');
        $mail_ok = get_option('gaa_mail_test_ok');
        if (!empty($usuario_admision) && !empty($mail_ok)) {
            add_submenu_page(
                $parent_slug,
                'Solicitudes de ingreso',
                'Solicitudes',
                'manage_options',
                'gaa-solicitudes',
                array(__CLASS__, 'pagina_solicitudes')
            );
        }
        // Submenu Asociaciones: lista e importación CSV
        add_submenu_page(
            $parent_slug,
            'Asociaciones',
            'Asociaciones',
            'manage_options',
            'gaa-asociaciones',
            array(__CLASS__, 'pagina_asociaciones')
        );
        // Submenu Cuotas (módulo separado)
        add_submenu_page(
            $parent_slug,
            'Cuotas',
            'Cuotas',
            'manage_options',
            'gaa-cuotas',
            array(__CLASS__, 'pagina_cuotas')
        );
    }
    
    public static function pagina_configuracion() {
        if (!current_user_can('manage_options')) return;
        
        if (isset($_POST['gaa_guardar'])) {
            $new_usuario_admision = intval($_POST['usuario_admision']);
            $prev_usuario_admision = get_option('gaa_usuario_admision');
            update_option('gaa_usuario_admision', $new_usuario_admision);

            // Asegurar que exista el rol 'gestion_afiliados' y asignarlo de forma acumulativa
            $role_slug = 'gestion_afiliados';
            if (!get_role($role_slug)) {
                add_role($role_slug, 'Gestion de Afiliados', array('read' => true));
            }
            // Si cambió el usuario responsable, quitar el rol al anterior (sin tocar otros roles)
            if (!empty($prev_usuario_admision) && $prev_usuario_admision != $new_usuario_admision) {
                $prev_user = get_user_by('id', $prev_usuario_admision);
                if ($prev_user && in_array($role_slug, (array) $prev_user->roles, true)) {
                    $prev_user->remove_role($role_slug);
                }
            }
            // Añadir el rol al usuario seleccionado (no eliminar roles existentes)
            if (!empty($new_usuario_admision)) {
                $new_user = get_user_by('id', $new_usuario_admision);
                if ($new_user && !in_array($role_slug, (array) $new_user->roles, true)) {
                    $new_user->add_role($role_slug);
                }
            }

            // Opciones de correo
            update_option('gaa_mail_driver', sanitize_text_field($_POST['gaa_mail_driver']));
            update_option('gaa_welcome_body', wp_kses_post($_POST['gaa_welcome_body']));
            update_option('gaa_welcome_footer', wp_kses_post($_POST['gaa_welcome_footer']));
            update_option('gaa_smtp_host', sanitize_text_field($_POST['gaa_smtp_host']));
            update_option('gaa_smtp_port', intval($_POST['gaa_smtp_port']));
            update_option('gaa_smtp_user', sanitize_text_field($_POST['gaa_smtp_user']));
            update_option('gaa_smtp_pass', sanitize_text_field($_POST['gaa_smtp_pass']));
            update_option('gaa_smtp_secure', sanitize_text_field($_POST['gaa_smtp_secure']));
            update_option('gaa_privacy_url', esc_url_raw($_POST['gaa_privacy_url']));
            update_option('gaa_estatutos_url', esc_url_raw($_POST['gaa_estatutos_url']));
            // Guardar IDs de adjuntos seleccionados (si vienen desde el media picker)
            $privacy_att_id = isset($_POST['gaa_privacy_attachment_id']) ? intval($_POST['gaa_privacy_attachment_id']) : '';
            $estatutos_att_id = isset($_POST['gaa_estatutos_attachment_id']) ? intval($_POST['gaa_estatutos_attachment_id']) : '';
            update_option('gaa_privacy_attachment_id', $privacy_att_id);
            update_option('gaa_estatutos_attachment_id', $estatutos_att_id);
            // Remitente configurable
            update_option('gaa_from_email', sanitize_email($_POST['gaa_from_email']));
            update_option('gaa_from_name', sanitize_text_field($_POST['gaa_from_name']));
            // Protección de datos - campos legales
            update_option('gaa_dp_controller', sanitize_text_field($_POST['gaa_dp_controller']));
            update_option('gaa_dp_contact_email', sanitize_email($_POST['gaa_dp_contact_email']));
            update_option('gaa_dp_purpose', wp_kses_post($_POST['gaa_dp_purpose']));
            update_option('gaa_dp_rights', wp_kses_post($_POST['gaa_dp_rights']));
            update_option('gaa_dp_retention', sanitize_text_field($_POST['gaa_dp_retention']));
            // Permitir altas de asociaciones (checkbox)
            $allow_assoc = isset($_POST['gaa_allow_associations']) && $_POST['gaa_allow_associations'] == '1' ? 1 : 0;
            update_option('gaa_allow_associations', $allow_assoc);
            // Formularios editor (Inscripción y Alta final)
            if (isset($_POST['gaa_inscripcion_form'])) update_option('gaa_inscripcion_form', wp_kses_post($_POST['gaa_inscripcion_form']));
            if (isset($_POST['gaa_alta_final_form'])) update_option('gaa_alta_final_form', wp_kses_post($_POST['gaa_alta_final_form']));
            // Opciones MailPoet: usar plantilla externa o editor interno
            $use_mp_insc = isset($_POST['gaa_use_mailpoet_inscripcion']) && $_POST['gaa_use_mailpoet_inscripcion'] == '1' ? 1 : 0;
            $use_mp_alta = isset($_POST['gaa_use_mailpoet_alta']) && $_POST['gaa_use_mailpoet_alta'] == '1' ? 1 : 0;
            update_option('gaa_use_mailpoet_inscripcion', $use_mp_insc);
            update_option('gaa_use_mailpoet_alta', $use_mp_alta);
            if (isset($_POST['gaa_mailpoet_inscripcion_id'])) update_option('gaa_mailpoet_inscripcion_id', sanitize_text_field($_POST['gaa_mailpoet_inscripcion_id']));
            if (isset($_POST['gaa_mailpoet_alta_id'])) update_option('gaa_mailpoet_alta_id', sanitize_text_field($_POST['gaa_mailpoet_alta_id']));
            // Al cambiar la configuración, invalidar el test de correo para forzar revalidación
            update_option('gaa_mail_test_ok', 0);
            // Guardar configuración de dominios permitidos
            $enf = isset($_POST['gaa_enforce_from_domain']) && $_POST['gaa_enforce_from_domain']=='1' ? 1 : 0;
            update_option('gaa_enforce_from_domain', $enf);
            $allowed_raw = isset($_POST['gaa_allowed_from_domains']) ? sanitize_textarea_field($_POST['gaa_allowed_from_domains']) : '';
            // Normalizar: una lista de dominios, guardada como líneas
            $allowed_lines = array();
            if (!empty($allowed_raw)) {
                $parts = preg_split('/\r?\n/', $allowed_raw);
                foreach ($parts as $p) { $d = trim(strtolower($p)); if (!empty($d)) $allowed_lines[] = preg_replace('/^www\./','',$d); }
            }
            update_option('gaa_allowed_from_domains', implode("\n", $allowed_lines));
            echo '<div class="notice notice-success"><p>Configuracion guardada.</p></div>';
            // Intentar sincronizar remitente con MailPoet si está instalado
                if (class_exists('MailPoet\\API\\API')) {
                    try {
                        $mp = self::get_mailpoet_api();
                        $synced = false;
                        if ($mp) {
                            $tries = array('setFrom','setSender','setSenderAddress','updateSettings','setSettings','set_from');
                            foreach ($tries as $m) {
                                if (is_object($mp) && method_exists($mp, $m)) {
                                    try {
                                        if ($m === 'updateSettings' || $m === 'setSettings') {
                                            $mp->{$m}(array('from_name' => $from_name, 'from_email' => $from_email));
                                        } else {
                                            $mp->{$m}($from_email, $from_name);
                                        }
                                        $synced = true; break;
                                    } catch (\Throwable $__e) { continue; }
                                }
                            }
                        }
                        if ($synced) echo '<div class="notice notice-success"><p>Remitente sincronizado con MailPoet.</p></div>';
                        else echo '<div class="notice notice-warning"><p>No se ha podido sincronizar automáticamente con MailPoet. Por favor revisa la configuración en MailPoet.</p></div>';
                    } catch (\Throwable $__err) {
                        echo '<div class="notice notice-warning"><p>MailPoet detectado pero no se ha podido sincronizar el remitente: ' . esc_html($__err->getMessage()) . '</p></div>';
                    }
                }
                
        }
        
        // Envío de correo de prueba desde la UI
        $test_result = '';
        if (isset($_POST['gaa_send_test'])) {
            if (!isset($_POST['gaa_test_nonce']) || !wp_verify_nonce($_POST['gaa_test_nonce'], 'gaa_test_email')) {
                $test_result = '<div class="notice notice-error"><p>Solicitud inválida (nonce).</p></div>';
            } else {
                $test_email = isset($_POST['gaa_test_email']) ? sanitize_email($_POST['gaa_test_email']) : '';
                if (!is_email($test_email)) {
                    $test_result = '<div class="notice notice-error"><p>Dirección de prueba no válida.</p></div>';
                } else {
                $mail_driver = get_option('gaa_mail_driver', 'wp_mail');
                $welcome_message = get_option('gaa_welcome_body', '<p>Bienvenido/a, gracias por unirte.</p>');
                $welcome_footer = get_option('gaa_welcome_footer', '<p>Atentamente,<br>' . get_bloginfo('name') . '</p>');
                // Construir mensaje completo (cuerpo + pie + datos protección)
                $dp_controller = get_option('gaa_dp_controller', '');
                $dp_contact = get_option('gaa_dp_contact_email', '');
                $dp_purpose = get_option('gaa_dp_purpose', '');
                $dp_rights = get_option('gaa_dp_rights', '');
                $dp_retention = get_option('gaa_dp_retention', '');
                $dp_html = '';
                if (!empty($dp_controller) || !empty($dp_contact) || !empty($dp_purpose)) {
                    $dp_html .= '<hr><div style="font-size:0.9em; color:#666;">';
                    if (!empty($dp_controller)) $dp_html .= '<p><strong>Responsable:</strong> ' . esc_html($dp_controller) . '</p>';
                    if (!empty($dp_contact)) $dp_html .= '<p><strong>Contacto:</strong> ' . esc_html($dp_contact) . '</p>';
                    if (!empty($dp_purpose)) $dp_html .= '<p><strong>Finalidad:</strong> ' . $dp_purpose . '</p>';
                    if (!empty($dp_rights)) $dp_html .= '<p><strong>Derechos:</strong> ' . $dp_rights . '</p>';
                    if (!empty($dp_retention)) $dp_html .= '<p><strong>Conservación:</strong> ' . esc_html($dp_retention) . '</p>';
                    $dp_html .= '</div>';
                }
                $message = $welcome_message . $welcome_footer . $dp_html;
                $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . $from_name . ' <' . $from_email . '>');

                    if ($mail_driver === 'smtp') {
                        $host = get_option('gaa_smtp_host', '');
                        $port = get_option('gaa_smtp_port', 587);
                        $user = get_option('gaa_smtp_user', '');
                        $pass = get_option('gaa_smtp_pass', '');
                        $secure = get_option('gaa_smtp_secure', 'tls');

                        $hook = function($phpmailer) use ($host, $port, $user, $pass, $secure, $from_email, $from_name) {
                            $phpmailer->isSMTP();
                            $phpmailer->Host = $host;
                            $phpmailer->Port = $port;
                            $phpmailer->SMTPAuth = !empty($user);
                            if (!empty($user)) {
                                $phpmailer->Username = $user;
                                $phpmailer->Password = $pass;
                            }
                            if ($secure === 'ssl') {
                                $phpmailer->SMTPSecure = 'ssl';
                            } elseif ($secure === 'tls') {
                                $phpmailer->SMTPSecure = 'tls';
                            }
                            if (!empty($from_email)) {
                                try { $phpmailer->setFrom($from_email, $from_name); } catch (Exception $e) {}
                                $phpmailer->Sender = $from_email;
                            }
                        };
                        add_action('phpmailer_init', $hook);
                        $sent = wp_mail($test_email, $subject, $message, $headers);
                        remove_action('phpmailer_init', $hook);
                    } else {
                        $sent = wp_mail($test_email, $subject, $message, $headers);
                    }
                }

                if ($sent) {
                    $test_result = '<div class="notice notice-success"><p>Correo de prueba enviado correctamente a ' . esc_html($test_email) . '.</p></div>';
                    update_option('gaa_mail_test_ok', 1);
                } else {
                    $test_result = '<div class="notice notice-error"><p>Error enviando el correo de prueba. Revisa la configuración.</p></div>';
                    update_option('gaa_mail_test_ok', 0);
                }
            }
        }

        $usuario_admision = get_option('gaa_usuario_admision', '');
        // Correo: opciones
        $mail_driver = get_option('gaa_mail_driver', 'wp_mail');
        $welcome_body = get_option('gaa_welcome_body', '<p>Bienvenido/a, gracias por unirte.</p>');
        $welcome_footer = get_option('gaa_welcome_footer', '<p>Atentamente,<br>' . get_bloginfo('name') . '</p>');
        $from_email = get_option('gaa_from_email', get_option('admin_email'));
        $from_name = get_option('gaa_from_name', get_bloginfo('name'));
        $smtp_host = get_option('gaa_smtp_host', '');
        $smtp_port = get_option('gaa_smtp_port', 587);
        $smtp_user = get_option('gaa_smtp_user', '');
        $smtp_pass = get_option('gaa_smtp_pass', '');
        $smtp_secure = get_option('gaa_smtp_secure', 'tls');
        $privacy_url = get_option('gaa_privacy_url', '');
        $estatutos_url = get_option('gaa_estatutos_url', '');
        $privacy_att = get_option('gaa_privacy_attachment_id', '');
        $estatutos_att = get_option('gaa_estatutos_attachment_id', '');
        $allow_assoc = get_option('gaa_allow_associations', 1);

        // Encolar la librería de medios para poder abrir la biblioteca desde la página
        if (function_exists('wp_enqueue_media')) wp_enqueue_media();

        // Mostrar aviso si el From no comparte dominio con el sitio
        $site_host = parse_url(home_url('/'), PHP_URL_HOST);
        if ($site_host) {
            $site_host = preg_replace('/^www\./', '', strtolower($site_host));
            $from_domain = '';
            if (strpos($from_email, '@') !== false) {
                $from_domain = strtolower(substr($from_email, strpos($from_email, '@') + 1));
            }
            if (!empty($from_domain) && $from_domain !== $site_host) {
                $site_host_esc = esc_html($site_host);
                $from_email_esc = esc_html($from_email);
                echo '<div class="notice notice-warning"><p><strong>Atención:</strong> El remitente configurado (' . $from_email_esc . ') no pertenece al dominio del sitio (' . $site_host_esc . '). Esto puede aumentar la probabilidad de que los correos lleguen a SPAM. Se recomienda usar una cuenta en el dominio ' . $site_host_esc . '.</p></div>';
            }
        }

        // Detectar roles aceptables (variantes) y mostrar solo usuarios con dichos roles.
        global $wp_roles;
        $role_key = '';
        $accepted_names = array('Gestion de Asociados', 'Gestión de Asociados', 'Control de Admisiones', 'Control Admisiones', 'Gestión de Admisiones');
        $accepted_slugs = array('gestion_de_asociados', 'gestion_afiliados', 'control_de_admisiones', 'control_admisiones', 'gestion_admisiones');

        if ( isset($wp_roles) && ! empty($wp_roles->roles) ) {
            foreach ($wp_roles->roles as $key => $role) {
                if (isset($role['name']) && in_array($role['name'], $accepted_names, true)) {
                    $role_key = $key;
                    break;
                }
                if (in_array($key, $accepted_slugs, true)) {
                    $role_key = $key;
                    break;
                }
            }
        }

        if ($role_key) {
            $usuarios = get_users(array('role' => $role_key, 'fields' => array('ID', 'display_name', 'user_email')));
        } else {
            // Fallback: filtrar manualmente si no se encuentra la clave del rol (por compatibilidad)
            $all_users = get_users(array('fields' => array('ID', 'display_name', 'user_email', 'roles')));
            $usuarios = array();
            foreach ($all_users as $u) {
                if (empty($u->roles)) continue;
                foreach ($u->roles as $r_key) {
                    $r_name = isset($wp_roles->roles[$r_key]['name']) ? $wp_roles->roles[$r_key]['name'] : $r_key;
                    if (in_array($r_name, $accepted_names, true) || in_array($r_key, $accepted_slugs, true)) {
                        $usuarios[] = $u;
                        break;
                    }
                }
            }
        }
        ?>
        <style>
        .gaa-config-container { max-width:920px; margin:22px auto; padding:12px; }
        .gaa-config-container .form-table { width:100%; }
        .gaa-config-container .form-table th { width:280px; text-align:left; vertical-align:top; padding:12px 18px 12px 0; }
        .gaa-config-container .form-table td { text-align:left; padding:12px 0; }
        .gaa-config-container .wp-editor-wrap { max-width:100%; }
        .gaa-file-preview { margin-top:8px; font-size:13px; color:#333; }
        .gaa-file-preview img { max-width:140px; max-height:80px; display:block; border:1px solid #ddd; padding:4px; background:#fff; }
        @media (max-width:768px) { .gaa-config-container{padding:8px;} .gaa-config-container .form-table th{width:140px;} }
        </style>
        <div class="wrap gaa-config-container">
            <h1>Configuracion - Gestion de Afiliados</h1>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th>Usuario responsable de admisiones</th>
                        <td>
                            <select name="usuario_admision" required>
                                <option value="">Seleccionar usuario WP...</option>
                                <?php foreach ($usuarios as $u): ?>
                                    <option value="<?php echo $u->ID; ?>" <?php selected($usuario_admision, $u->ID); ?>>
                                        <?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Este usuario recibira las notificaciones y podra aprobar nuevos afiliados.</p>
                            <?php if (empty($usuarios)): ?>
                                <div class="notice notice-warning" style="margin-top:10px; padding:8px;">
                                    <p><strong>Advertencia:</strong> No hay ningún usuario con el perfil de responsable de admisiones definido. Por favor, crea o asigna un usuario con el rol correspondiente y selecciónalo aquí. <a href="<?php echo esc_url(admin_url('user-new.php')); ?>">Añadir usuario</a></p>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                        <tr>
                            <th>Driver de correo</th>
                            <td>
                                <?php
                                // Detectar MailPoet
                                $mailpoet_installed = class_exists('MailPoet\API\API');
                                $drivers = array('wp_mail' => 'wp_mail', 'smtp' => 'Servidor SMTP externo');
                                ?>
                                <select name="gaa_mail_driver" id="gaa_mail_driver">
                                    <?php foreach ($drivers as $k => $label): ?>
                                        <option value="<?php echo esc_attr($k); ?>" <?php selected($mail_driver, $k); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Elige cómo se enviarán los correos de bienvenida.</p>
                            </td>
                        </tr>
                        <tr id="gaa_message_row">
                            <th>Mensaje de bienvenida - Cuerpo (HTML)</th>
                            <td>
                                <?php
                                $welcome_body = get_option('gaa_welcome_body', '<p>Bienvenido/a, gracias por unirte.</p>');
                                wp_editor($welcome_body, 'gaa_welcome_body_editor', array(
                                    'textarea_name' => 'gaa_welcome_body',
                                    'textarea_rows' => 10,
                                    'media_buttons' => false,
                                    'teeny' => false,
                                    'quicktags' => false,
                                    'tinymce' => array(
                                        'toolbar1' => 'bold italic underline | bullist numlist | link',
                                        'toolbar2' => '',
                                    ),
                                ));
                                ?>
                                <p class="description">Contenido principal del email de bienvenida.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Mensaje de bienvenida - Pie (HTML)</th>
                            <td>
                                <?php
                                $welcome_footer = get_option('gaa_welcome_footer', '<p>Atentamente,<br>' . get_bloginfo('name') . '</p>');
                                wp_editor($welcome_footer, 'gaa_welcome_footer_editor', array(
                                    'textarea_name' => 'gaa_welcome_footer',
                                    'textarea_rows' => 6,
                                    'media_buttons' => false,
                                    'teeny' => false,
                                    'quicktags' => false,
                                    'tinymce' => array(
                                        'toolbar1' => 'bold italic underline | link',
                                        'toolbar2' => '',
                                    ),
                                ));
                                ?>
                                <p class="description">Pie de página que se añadirá al final del email (datos de contacto, enlaces legales, etc.).</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Remitente (From)</th>
                            <td>
                                <p><label>Nombre: <input type="text" name="gaa_from_name" value="<?php echo esc_attr($from_name); ?>" style="width:40%; padding:6px;"></label></p>
                                <p><label>Email: <input type="email" name="gaa_from_email" value="<?php echo esc_attr($from_email); ?>" style="width:40%; padding:6px;"></label></p>
                                <p class="description">Dirección de correo que se usará en el encabezado From. Usar una cuenta en el mismo dominio para mejorar la entrega (SPF/DKIM).</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Restricción de dominio del remitente</th>
                            <td>
                                <?php $enforce = get_option('gaa_enforce_from_domain', 0); $allowed = get_option('gaa_allowed_from_domains',''); ?>
                                <label><input type="checkbox" name="gaa_enforce_from_domain" value="1" <?php checked($enforce,1); ?>> Restringir remitente a dominios permitidos</label>
                                <p class="description">Si se activa, `From` que no pertenezca a la lista de dominios permitidos será reemplazado por la dirección administrativa del sitio.</p>
                                <p style="margin-top:8px;"><label>Dominios permitidos (uno por línea):<br>
                                <textarea name="gaa_allowed_from_domains" rows="3" style="width:60%; padding:6px;"><?php echo esc_textarea($allowed); ?></textarea></label></p>
                                <p><button type="button" id="gaa_fill_site_domain" class="button">Usar dominio del sitio</button></p>
                            </td>
                        </tr>
                        <?php
                        // Preparar integración con MailPoet (si está disponible)
                        $mailpoet_templates = array();
                                if (class_exists('MailPoet\\API\\API')) {
                                    try {
                                        $mp = self::get_mailpoet_api();
                                        if ($mp) {
                                            $svc = null;
                                            if (is_object($mp) && method_exists($mp, 'templates')) $svc = $mp->templates();
                                            elseif (is_object($mp) && method_exists($mp, 'getTemplates')) $svc = $mp;
                                            $items = array();
                                            if ($svc) {
                                                if (method_exists($svc, 'getAll')) {
                                                    $items = $svc->getAll();
                                                } elseif (method_exists($svc, 'all')) {
                                                    $items = $svc->all();
                                                } elseif (method_exists($svc, 'findAll')) {
                                                    $items = $svc->findAll();
                                                }
                                            } elseif (method_exists($mp, 'getTemplate') || method_exists($mp,'getTemplates')) {
                                                // some versions expose templates differently: attempt to fetch recent posts of type mailpoet_template
                                                $items = array();
                                            }
                                            if (is_object($items) && method_exists($items,'toArray')) $items = $items->toArray();
                                            if (is_array($items)) {
                                                foreach ($items as $it) {
                                                    $id = is_array($it) && isset($it['id']) ? $it['id'] : (is_object($it) && isset($it->id) ? $it->id : '');
                                                    $name = is_array($it) && isset($it['name']) ? $it['name'] : (is_object($it) && isset($it->name) ? $it->name : '');
                                                    if (!empty($id)) $mailpoet_templates[] = array('id' => $id, 'name' => $name);
                                                }
                                            }
                                        }
                                    } catch (\Throwable $__mpterr) {
                                        // ignore listing errors
                                    }
                                }

                        $selected_mp_insc = get_option('gaa_mailpoet_inscripcion_id','');
                        $use_mp_insc = get_option('gaa_use_mailpoet_inscripcion', 0);

                        ?>
                        <tr>
                            <th>Formulario de Inscripción (editor)</th>
                            <td>
                                <?php if (!empty($mailpoet_templates)): ?>
                                    <label><input type="checkbox" name="gaa_use_mailpoet_inscripcion" value="1" <?php checked($use_mp_insc,1); ?>> Usar plantilla MailPoet</label>
                                    <p>
                                        <select name="gaa_mailpoet_inscripcion_id" id="gaa_mailpoet_inscripcion_id">
                                            <option value="">-- Seleccionar plantilla MailPoet --</option>
                                            <?php foreach ($mailpoet_templates as $mt): ?>
                                                <option value="<?php echo esc_attr($mt['id']); ?>" <?php selected($selected_mp_insc, $mt['id']); ?>><?php echo esc_html($mt['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" id="gaa_import_mp_insc" class="button">Importar a editor</button>
                                    </p>
                                <?php elseif (class_exists('MailPoet\\API\\API')): ?>
                                    <p class="description">MailPoet detectado pero no se pudieron listar las plantillas desde la API.</p>
                                <?php endif; ?>
                                <?php
                                $insc = get_option('gaa_inscripcion_form', '');
                                wp_editor($insc, 'gaa_inscripcion_form_editor', array(
                                    'textarea_name' => 'gaa_inscripcion_form',
                                    'textarea_rows' => 8,
                                    'media_buttons' => false,
                                    'teeny' => false,
                                    'quicktags' => false,
                                    'tinymce' => array('toolbar1' => 'bold italic | bullist numlist | link')
                                ));
                                ?>
                                <p class="description">Editor para el formulario que se usará en la fase de inscripción (HTML).</p>
                            </td>
                        </tr>
                        <?php
                        $selected_mp_alta = get_option('gaa_mailpoet_alta_id','');
                        $use_mp_alta = get_option('gaa_use_mailpoet_alta', 0);
                        ?>
                        <tr>
                            <th>Formulario de Alta Final (editor)</th>
                            <td>
                                <?php if (!empty($mailpoet_templates)): ?>
                                    <label><input type="checkbox" name="gaa_use_mailpoet_alta" value="1" <?php checked($use_mp_alta,1); ?>> Usar plantilla MailPoet</label>
                                    <p>
                                        <select name="gaa_mailpoet_alta_id" id="gaa_mailpoet_alta_id">
                                            <option value="">-- Seleccionar plantilla MailPoet --</option>
                                            <?php foreach ($mailpoet_templates as $mt): ?>
                                                <option value="<?php echo esc_attr($mt['id']); ?>" <?php selected($selected_mp_alta, $mt['id']); ?>><?php echo esc_html($mt['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" id="gaa_import_mp_alta" class="button">Importar a editor</button>
                                    </p>
                                <?php endif; ?>
                                <?php
                                $alta = get_option('gaa_alta_final_form', '');
                                wp_editor($alta, 'gaa_alta_final_form_editor', array(
                                    'textarea_name' => 'gaa_alta_final_form',
                                    'textarea_rows' => 8,
                                    'media_buttons' => false,
                                    'teeny' => false,
                                    'quicktags' => false,
                                    'tinymce' => array('toolbar1' => 'bold italic | bullist numlist | link')
                                ));
                                ?>
                                <p class="description">Editor para el formulario definitivo que se enviará al usuario (pedirá datos de cuenta y cálculo de cuota).</p>
                            </td>
                        </tr>
                        <tr id="gaa_smtp_row" style="display:<?php echo ($mail_driver==='smtp') ? 'table-row' : 'none'; ?>;">
                            <th>Servidor SMTP</th>
                            <td>
                                <p><label>Host: <input type="text" name="gaa_smtp_host" value="<?php echo esc_attr($smtp_host); ?>"></label></p>
                                <p><label>Puerto: <input type="number" name="gaa_smtp_port" value="<?php echo esc_attr($smtp_port); ?>"></label></p>
                                <p><label>Usuario: <input type="text" name="gaa_smtp_user" value="<?php echo esc_attr($smtp_user); ?>"></label></p>
                                <p><label>Contraseña: <input type="password" name="gaa_smtp_pass" value="<?php echo esc_attr($smtp_pass); ?>"></label></p>
                                <p><label>Seguridad: <select name="gaa_smtp_secure"><option value="tls" <?php selected($smtp_secure,'tls'); ?>>TLS</option><option value="ssl" <?php selected($smtp_secure,'ssl'); ?>>SSL</option><option value="none" <?php selected($smtp_secure,'none'); ?>>Ninguna</option></select></label></p>
                            </td>
                        </tr>
                        <tr>
                            <th>Documentos legales</th>
                            <td>
                                <p>
                                    <label>Política de privacidad: </label>
                                    <input type="url" id="gaa_privacy_url" name="gaa_privacy_url" value="<?php echo esc_attr($privacy_url); ?>" style="width:50%; padding:6px;"> 
                                    <input type="hidden" id="gaa_privacy_attachment_id" name="gaa_privacy_attachment_id" value="<?php echo esc_attr($privacy_att); ?>">
                                    <button type="button" class="button" id="gaa_pick_privacy">Seleccionar</button>
                                    <div class="gaa-file-preview" id="gaa_privacy_preview"><?php if(!empty($privacy_url)) echo '<a href="'.esc_url($privacy_url).'" target="_blank" rel="noopener">Ver archivo</a>'; ?></div>
                                </p>
                                <p>
                                    <label>Estatutos (PDF): </label>
                                    <input type="url" id="gaa_estatutos_url" name="gaa_estatutos_url" value="<?php echo esc_attr($estatutos_url); ?>" style="width:50%; padding:6px;"> 
                                    <input type="hidden" id="gaa_estatutos_attachment_id" name="gaa_estatutos_attachment_id" value="<?php echo esc_attr($estatutos_att); ?>">
                                    <button type="button" class="button" id="gaa_pick_estatutos">Seleccionar</button>
                                    <div class="gaa-file-preview" id="gaa_estatutos_preview"><?php if(!empty($estatutos_url)) echo '<a href="'.esc_url($estatutos_url).'" target="_blank" rel="noopener">Ver archivo</a>'; ?></div>
                                </p>
                                <p class="description">Selecciona documentos desde la biblioteca de medios o pega la URL manualmente.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Protección de datos</th>
                            <td>
                                <p><label>Responsable del fichero / entidad: <input type="text" name="gaa_dp_controller" value="<?php echo esc_attr(get_option('gaa_dp_controller','')); ?>" style="width:60%; padding:6px;"></label></p>
                                <p><label>Contacto (email): <input type="email" name="gaa_dp_contact_email" value="<?php echo esc_attr(get_option('gaa_dp_contact_email','')); ?>" style="width:40%; padding:6px;"></label></p>
                                <p><label>Finalidad del tratamiento:</label><br>
                                <textarea name="gaa_dp_purpose" rows="3" style="width:80%; padding:6px;"><?php echo esc_textarea(get_option('gaa_dp_purpose','')); ?></textarea></p>
                                <p><label>Derechos y cómo ejercerlos:</label><br>
                                <textarea name="gaa_dp_rights" rows="3" style="width:80%; padding:6px;"><?php echo esc_textarea(get_option('gaa_dp_rights','')); ?></textarea></p>
                                <p><label>Periodo de conservación: <input type="text" name="gaa_dp_retention" value="<?php echo esc_attr(get_option('gaa_dp_retention','')); ?>" style="width:40%; padding:6px;"></label></p>
                                <p class="description">Estos datos se añadirán al pie de los correos y se mostrarán en la política de privacidad si procede.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Permitir altas de asociaciones</th>
                            <td>
                                <label><input type="checkbox" name="gaa_allow_associations" value="1" <?php checked($allow_assoc,1); ?>> Permitir que los usuarios soliciten altas como asociación</label>
                                <p class="description">Si está desactivado, solo se permitirán solicitudes a título individual.</p>
                            </td>
                        </tr>
                </table>
                <?php submit_button('Guardar configuracion', 'primary', 'gaa_guardar'); ?>
                <button id="gaa_help_csv" type="button" class="button" style="margin-left:10px;">Ayuda CSV Asociaciones</button>
            </form>

            <h2>Enviar mensaje de prueba</h2>
            <div id="gaa_csv_help_modal" style="display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:99999;">
                <div style="background:#fff; width:720px; max-width:95%; margin:6% auto; padding:20px; border-radius:6px; position:relative;">
                    <button id="gaa_csv_help_close" style="position:absolute; right:10px; top:10px;">Cerrar</button>
                    <h2>Estructura CSV para importar Asociaciones</h2>
                    <p>El archivo debe ser CSV con cabecera (se aceptan mayúsculas/minúsculas). Campos recomendados:</p>
                    <ul>
                        <li><strong>nombre</strong> (obligatorio o se usará 'Asociación')</li>
                        <li><strong>nif</strong> (NIF/CIF de la asociación; caracteres alfanuméricos, 5-12 caracteres)</li>
                        <li><strong>telefono</strong></li>
                        <li><strong>direccion</strong></li>
                        <li><strong>contacto</strong> o <strong>contacto_email</strong> (si contiene '@' se tratará como email y será validado)</li>
                    </ul>
                    <h3>Reglas de validación</h3>
                    <ul>
                        <li>Si se proporciona un email de contacto, debe ser válido (ej: user@example.com). Filas con email inválido se omiten.</li>
                        <li>Si se proporciona NIF/CIF, debe contener solo letras y números y tener entre 5 y 12 caracteres; si no cumple, la fila se omitirá.</li>
                        <li>Las filas vacías se omiten.</li>
                    </ul>
                    <p class="description">Ejemplo de cabecera: <code>nombre,nif,telefono,direccion,contacto</code></p>
                </div>
            </div>
            <script>
            (function(){
                var btn = document.getElementById('gaa_help_csv');
                var modal = document.getElementById('gaa_csv_help_modal');
                var close = document.getElementById('gaa_csv_help_close');
                if (btn && modal) btn.addEventListener('click', function(){ modal.style.display = 'block'; });
                if (close && modal) close.addEventListener('click', function(){ modal.style.display = 'none'; });
            })();
            </script>
            <script>
            (function(){
                var btn = document.getElementById('gaa_fill_site_domain');
                if (!btn) return;
                btn.addEventListener('click', function(){
                    try {
                        var host = location.hostname.replace(/^www\./, '');
                        var ta = document.getElementsByName('gaa_allowed_from_domains')[0];
                        if (ta) { ta.value = host; alert('Dominio del sitio insertado: ' + host); }
                    } catch(e){ alert('No se pudo obtener el dominio del sitio.'); }
                });
            })();
            </script>
            <form method="post" style="margin-bottom:1em;">
                <?php wp_nonce_field('gaa_test_email', 'gaa_test_nonce'); ?>
                <p>
                    <label>Email de prueba: <input type="email" name="gaa_test_email" required style="width:300px; padding:6px;" value=""></label>
                    <button type="submit" name="gaa_send_test" class="button button-primary">Enviar mensaje de prueba</button>
                </p>
            </form>

            <div id="gaa_test_result">
                <?php if (!empty($test_result)) echo $test_result; ?>
            </div>
        </div>
        <script>
        (function(){
            if (typeof jQuery === 'undefined') {
                // fallback to vanilla if jQuery not available
                var sel = document.getElementById('gaa_mail_driver');
                function updateRowsVanilla() {
                    var v = sel ? sel.value : '';
                    var mailpoetRow = null;
                    var messageRow = document.getElementById('gaa_message_row');
                    var smtpRow = document.getElementById('gaa_smtp_row');
                    if (mailpoetRow) mailpoetRow.style.display = (v === 'mailpoet') ? '' : 'none';
                    if (smtpRow) smtpRow.style.display = (v === 'smtp') ? '' : 'none';
                    if (messageRow) messageRow.style.display = '';
                }
                if (sel) {
                    sel.addEventListener('change', updateRowsVanilla);
                    updateRowsVanilla();
                }
                return;
            }
            jQuery(function($){
                var sel = $('#gaa_mail_driver');
                var mailpoetRow = $('#gaa_mailpoet_row');
                var messageRow = $('#gaa_message_row');
                var smtpRow = $('#gaa_smtp_row');
                function update(){
                    var v = sel.val();
                    if (mailpoetRow.length) {
                        if (v === 'mailpoet') mailpoetRow.show(); else mailpoetRow.hide();
                    }
                    if (smtpRow.length) {
                        if (v === 'smtp') smtpRow.show(); else smtpRow.hide();
                    }
                    if (messageRow.length) messageRow.show();
                }
                sel.on('change', update);
                update();
            });
        })();
            </script>
            <script>
            (function(){
                var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
                var mpNonce = '<?php echo wp_create_nonce('gaa_mailpoet_import'); ?>';

                function setEditorContent(editorId, textareaName, html) {
                    try {
                        if (typeof tinyMCE !== 'undefined' && tinyMCE.get(editorId)) {
                            tinyMCE.get(editorId).setContent(html);
                        } else {
                            var ta = document.getElementsByName(textareaName)[0]; if (ta) ta.value = html;
                        }
                    } catch(e) { console.log(e); }
                }

                function fetchTemplateAndImport(id, editorId, textareaName) {
                    if (!id) { alert('Selecciona una plantilla MailPoet.'); return; }
                    var xhr = new XMLHttpRequest(); xhr.open('POST', ajaxUrl);
                    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
                    xhr.onload = function(){
                        if (xhr.status===200) {
                            try { var res = JSON.parse(xhr.responseText); } catch(e){ res=null; }
                            if (res && (res.success===true || res.ok===true) && res.data && res.data.html) {
                                setEditorContent(editorId, textareaName, res.data.html);
                                alert('Plantilla importada en el editor. Revisa y guarda la configuración.');
                            } else {
                                alert('No se pudo obtener la plantilla: ' + (res && res.data && res.data.msg ? res.data.msg : xhr.responseText));
                            }
                        } else {
                            alert('Error HTTP: ' + xhr.status);
                        }
                    };
                    xhr.send('action=gaa_fetch_mailpoet_template&id='+encodeURIComponent(id)+'&nonce='+encodeURIComponent(mpNonce));
                }

                var btnIns = document.getElementById('gaa_import_mp_insc');
                if (btnIns) btnIns.addEventListener('click', function(){ var sel=document.getElementById('gaa_mailpoet_inscripcion_id'); if(sel) fetchTemplateAndImport(sel.value, 'gaa_inscripcion_form_editor', 'gaa_inscripcion_form'); });
                var btnAlta = document.getElementById('gaa_import_mp_alta');
                if (btnAlta) btnAlta.addEventListener('click', function(){ var sel=document.getElementById('gaa_mailpoet_alta_id'); if(sel) fetchTemplateAndImport(sel.value, 'gaa_alta_final_form_editor', 'gaa_alta_final_form'); });
            })();
            </script>
            <script>
            (function(){
                function openMediaAndPick(callback){
                    if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                        alert('La biblioteca de medios no está disponible. Recarga la página e intenta de nuevo.');
                        return;
                    }
                    var frame = wp.media({
                        title: 'Seleccionar documento',
                        button: { text: 'Seleccionar' },
                        library: { type: '' },
                        multiple: false
                    });
                    frame.on('select', function(){
                        var attachment = frame.state().get('selection').first().toJSON();
                        callback(attachment);
                    });
                    frame.open();
                    return frame;
                }

                var btnP = document.getElementById('gaa_pick_privacy');
                var inpP = document.getElementById('gaa_privacy_url');
                if (btnP && inpP) {
                    btnP.addEventListener('click', function(e){
                        e.preventDefault();
                        openMediaAndPick(function(att){
                            if (att && att.url) {
                                inpP.value = att.url;
                                var hid = document.getElementById('gaa_privacy_attachment_id'); if (hid) hid.value = att.id || '';
                                var prev = document.getElementById('gaa_privacy_preview');
                                if (prev) {
                                    if (att.mime && att.mime.indexOf('image/') === 0) {
                                        prev.innerHTML = '<a href="'+att.url+'" target="_blank" rel="noopener"><img src="'+att.url+'" alt="preview"></a>';
                                    } else {
                                        var name = att.filename || att.url.split('/').pop();
                                        prev.innerHTML = '<a href="'+att.url+'" target="_blank" rel="noopener">'+name+'</a>';
                                    }
                                }
                            }
                        });
                    });
                }

                var btnE = document.getElementById('gaa_pick_estatutos');
                var inpE = document.getElementById('gaa_estatutos_url');
                if (btnE && inpE) {
                    btnE.addEventListener('click', function(e){
                        e.preventDefault();
                        openMediaAndPick(function(att){
                            if (att && att.url) {
                                inpE.value = att.url;
                                var hidE = document.getElementById('gaa_estatutos_attachment_id'); if (hidE) hidE.value = att.id || '';
                                var prev = document.getElementById('gaa_estatutos_preview');
                                if (prev) {
                                    if (att.mime && att.mime.indexOf('image/') === 0) {
                                        prev.innerHTML = '<a href="'+att.url+'" target="_blank" rel="noopener"><img src="'+att.url+'" alt="preview"></a>';
                                    } else {
                                        var name = att.filename || att.url.split('/').pop();
                                        prev.innerHTML = '<a href="'+att.url+'" target="_blank" rel="noopener">'+name+'</a>';
                                    }
                                }
                            }
                        });
                    });
                }
            })();
            </script>
        <?php
    }

    public static function pagina_solicitudes() {
        if (!current_user_can('manage_options')) return;

        $nonce = wp_create_nonce('gaa_admin_nonce');
        $status = isset($_GET['gaa_status']) ? sanitize_text_field($_GET['gaa_status']) : 'all';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;

        $search = isset($_GET['gaa_search']) ? sanitize_text_field($_GET['gaa_search']) : '';
        $date_from = isset($_GET['gaa_date_from']) ? sanitize_text_field($_GET['gaa_date_from']) : '';
        $date_to = isset($_GET['gaa_date_to']) ? sanitize_text_field($_GET['gaa_date_to']) : '';
        $phone = isset($_GET['gaa_phone']) ? sanitize_text_field($_GET['gaa_phone']) : '';
        $profesion = isset($_GET['gaa_profesion']) ? sanitize_text_field($_GET['gaa_profesion']) : '';

        $args = array(
            'post_type' => 'socio',
            'post_status' => 'any',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC'
        );

        // Meta query builder
        $meta_query = array('relation' => 'AND');

        // Filtrar por estado
        if ($status === 'approved') {
            $meta_query[] = array('key'=>'_gaa_aprobado','value'=>'1','compare'=>'=');
        } elseif ($status === 'pending') {
            $meta_query[] = array(
                'relation' => 'OR',
                array('key'=>'_gaa_aprobado','value'=>'1','compare'=>'!='),
                array('key'=>'_gaa_aprobado','compare'=>'NOT EXISTS'),
            );
        }

        // Búsqueda por nombre o email (buscar en post_title, _gaa_nombre y _gaa_email)
        if (!empty($search)) {
            // Añadir búsqueda en meta (OR)
            $meta_query[] = array(
                'relation' => 'OR',
                array('key' => '_gaa_nombre', 'value' => $search, 'compare' => 'LIKE'),
                array('key' => '_gaa_email', 'value' => $search, 'compare' => 'LIKE'),
            );
            // También usar 's' para buscar en post_title
            $args['s'] = $search;
        }

        // Filtrar por teléfono
        if (!empty($phone)) {
            $meta_query[] = array('key' => '_gaa_telefono', 'value' => $phone, 'compare' => 'LIKE');
        }

        // Filtrar por profesión
        if (!empty($profesion)) {
            $meta_query[] = array('key' => '_gaa_profesion', 'value' => $profesion, 'compare' => 'LIKE');
        }

        // Filtrar por rango de fechas (fecha del post)
        $date_query = array();
        if (!empty($date_from)) {
            $date_query['after'] = date('Y-m-d', strtotime($date_from));
            $date_query['inclusive'] = true;
        }
        if (!empty($date_to)) {
            $date_query['before'] = date('Y-m-d', strtotime($date_to));
            $date_query['inclusive'] = true;
        }
        if (!empty($date_query)) {
            $args['date_query'] = array($date_query);
        }

        if (count($meta_query) > 1) {
            $args['meta_query'] = $meta_query;
        }

        $q = new WP_Query($args);
        ?>
        <div class="wrap">
            <h1>Solicitudes de ingreso</h1>
            <form method="get" style="margin-bottom:12px;">
                <input type="hidden" name="page" value="gaa-solicitudes">
                <label style="margin-right:8px;">Buscar: <input type="search" name="gaa_search" value="<?php echo esc_attr($search); ?>" placeholder="Nombre o email" style="padding:6px;"></label>
                <label style="margin-right:8px;">Estado: <select name="gaa_status">
                    <option value="all" <?php selected($status,'all'); ?>>Todos</option>
                    <option value="pending" <?php selected($status,'pending'); ?>>Pendientes</option>
                    <option value="approved" <?php selected($status,'approved'); ?>>Aprobados</option>
                </select></label>
                <label style="margin-right:8px;">Desde: <input type="date" name="gaa_date_from" value="<?php echo esc_attr($date_from); ?>"></label>
                <label style="margin-right:8px;">Hasta: <input type="date" name="gaa_date_to" value="<?php echo esc_attr($date_to); ?>"></label>
                <button class="button">Filtrar</button>
            </form>
            <table class="widefat fixed striped">
                <thead><tr><th>Nombre</th><th>Email</th><th>Tipo</th><th>Teléfono</th><th>Profesión</th><th>Asociación</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                    <?php if ($q->have_posts()): while ($q->have_posts()): $q->the_post();
                        $id = get_the_ID();
                        $nombre = get_post_meta($id, '_gaa_nombre', true);
                        $email = get_post_meta($id, '_gaa_email', true);
                        $telefono = get_post_meta($id, '_gaa_telefono', true);
                        $prof = get_post_meta($id, '_gaa_profesion', true);
                        $is_assoc = get_post_meta($id, '_gaa_is_association', true);
                        $assoc_name = get_post_meta($id, '_gaa_assoc_nombre', true);
                        $assoc_nif = get_post_meta($id, '_gaa_assoc_nif', true);
                        $aprobado = get_post_meta($id, '_gaa_aprobado', true);
                    ?>
                    <tr data-id="<?php echo esc_attr($id); ?>">
                        <td><?php echo esc_html($nombre ? $nombre : get_the_title()); ?></td>
                        <td><?php echo esc_html($email); ?></td>
                        <td><?php echo $is_assoc==='1' ? '<span>Asociación</span>' : '<span>Individual</span>'; ?></td>
                        <td><?php echo esc_html($telefono); ?></td>
                        <td><?php echo esc_html($prof); ?></td>
                        <td><?php echo $is_assoc==='1' ? esc_html($assoc_name) . '<br><small>' . esc_html($assoc_nif) . '</small>' : ''; ?></td>
                        <td><?php echo $aprobado === '1' ? '<span style="color:green;">Aprobado</span>' : '<span style="color:orange;">Pendiente</span>'; ?></td>
                        <td>
                            <button class="button gaa-show" data-id="<?php echo esc_attr($id); ?>">Mostrar</button>
                            <button class="button button-secondary gaa-delete" data-id="<?php echo esc_attr($id); ?>">Eliminar</button>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="4">No hay solicitudes.</td></tr>
                    <?php endif; wp_reset_postdata(); ?>
                </tbody>
            </table>

            <?php
            // Paginación
            $total_pages = max(1, $q->max_num_pages);
            if ($total_pages > 1) {
                $base = add_query_arg('paged','%#%');
                if (!empty($_GET['gaa_status'])) $base = add_query_arg('gaa_status', urlencode($status), $base);
                if (!empty($_GET['gaa_search'])) $base = add_query_arg('gaa_search', urlencode($search), $base);
                if (!empty($_GET['gaa_date_from'])) $base = add_query_arg('gaa_date_from', urlencode($date_from), $base);
                if (!empty($_GET['gaa_date_to'])) $base = add_query_arg('gaa_date_to', urlencode($date_to), $base);
                if (!empty($_GET['gaa_phone'])) $base = add_query_arg('gaa_phone', urlencode($phone), $base);
                if (!empty($_GET['gaa_profesion'])) $base = add_query_arg('gaa_profesion', urlencode($profesion), $base);
                echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(array(
                    'base' => $base,
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $paged
                )) . '</div></div>';
            }
            ?>

            <div id="gaa_modal" style="display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:9999;">
                <div id="gaa_modal_inner" style="background:#fff; width:700px; max-width:95%; margin:4% auto; padding:20px; border-radius:4px; position:relative;">
                    <button id="gaa_modal_close" style="position:absolute; right:10px; top:10px;">Cerrar</button>
                    <div id="gaa_modal_content">Cargando...</div>
                    <div style="margin-top:12px;">
                        <button id="gaa_approve" class="button button-primary">Admitir</button>
                        <button id="gaa_reject" class="button">No admitir</button>
                        <button id="gaa_delete_modal" class="button button-secondary" style="margin-left:8px;">Eliminar</button>
                    </div>
                </div>
            </div>

        </div>

        <script>
        (function(){
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var nonce = '<?php echo $nonce; ?>';

            function by(selector, ctx){ return (ctx||document).querySelector(selector); }
            function onAll(selector, fn){ Array.prototype.forEach.call(document.querySelectorAll(selector), fn); }

            onAll('.gaa-show', function(btn){
                btn.addEventListener('click', function(e){
                    var id = this.getAttribute('data-id');
                    var modal = by('#gaa_modal');
                    var content = by('#gaa_modal_content');
                    content.innerHTML = 'Cargando...';
                    modal.style.display = 'block';

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl);
                    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
                    xhr.onload = function(){
                        if (xhr.status === 200) {
                            var res;
                            try{ res = JSON.parse(xhr.responseText); }catch(e){ res = null; }
                            // soportar formato WP: { success: true, data: {...} } o formato anterior { ok: true, data: {...} }
                            var ok = (res && (res.ok === true || res.success === true));
                            var payload = (res && typeof res.data !== 'undefined') ? res.data : res;
                            // payload puede contener un campo 'data' adicional (wp_send_json_success(array('data'=>$data)))
                            var dataObj = payload && typeof payload.data !== 'undefined' ? payload.data : payload;
                            if (ok && dataObj) {
                                var html = '<p><strong>Nombre:</strong> '+(dataObj.nombre||'')+'</p>';
                                html += '<p><strong>Email:</strong> '+(dataObj.email||'')+'</p>';
                                html += '<p><strong>Teléfono:</strong> '+(dataObj.telefono||'')+'</p>';
                                html += '<p><strong>Dirección:</strong> '+(dataObj.direccion||'')+'</p>';
                                html += '<p><strong>Profesión:</strong> '+(dataObj.profesion||'')+'</p>';
                                html += '<p><strong>Fecha de nacimiento:</strong> '+(dataObj.fecha_nacimiento||'')+'</p>';
                                html += '<p><strong>Disponible para ayudar:</strong> '+(dataObj.disponible_ayuda=='1' ? 'Sí' : 'No')+'</p>';
                                html += '<p><strong>Alcance colaboración:</strong> '+(dataObj.alcance_colaboracion||'')+'</p>';
                                html += '<p><strong>Mensaje:</strong> '+(dataObj.mensaje||'')+'</p>';
                                content.innerHTML = html;
                                by('#gaa_approve').setAttribute('data-id', id);
                                by('#gaa_reject').setAttribute('data-id', id);
                            } else {
                                // Mostrar información de depuración si la respuesta no es JSON o no contiene msg
                                var raw = xhr.responseText || 'Sin respuesta';
                                var errMsg = (res && res.msg) ? res.msg : raw;
                                content.innerHTML = '<div style="color:#a94442; background:#f2dede; padding:10px; border-radius:4px;"><strong>Error:</strong><pre style="white-space:pre-wrap;">'+errMsg+'</pre></div>';
                            }
                        } else {
                            content.innerHTML = '<p>Error cargando.</p>';
                        }
                    };
                    xhr.send('action=gaa_get_solicitud&id='+encodeURIComponent(id)+'&nonce='+encodeURIComponent(nonce));
                });
            });

            by('#gaa_modal_close').addEventListener('click', function(){ by('#gaa_modal').style.display='none'; });

            function sendDecision(id, decision){
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxUrl);
                xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
                xhr.onload = function(){
                    if (xhr.status===200){
                        var res;
                        try{ res = JSON.parse(xhr.responseText); } catch(e) { res = null; }
                        var ok = (res && (res.ok === true || res.success === true));
                        if (ok){ alert('Operación completada'); location.reload(); }
                        else {
                            var raw = xhr.responseText || 'Sin respuesta';
                            var errMsg = (res && res.msg) ? res.msg : raw;
                            alert('Error: ' + errMsg);
                        }
                    } else {
                        alert('Error HTTP: '+xhr.status+'\n'+xhr.statusText);
                    }
                };
                xhr.send('action=gaa_set_aprobacion&id='+encodeURIComponent(id)+'&decision='+encodeURIComponent(decision)+'&nonce='+encodeURIComponent(nonce));
            }

            by('#gaa_approve').addEventListener('click', function(){ var id=this.getAttribute('data-id'); sendDecision(id,'approve'); });
            by('#gaa_reject').addEventListener('click', function(){ var id=this.getAttribute('data-id'); sendDecision(id,'reject'); });

            // Delete handlers (modal)
            if (by('#gaa_delete_modal')) {
                by('#gaa_delete_modal').addEventListener('click', function(){
                    var id=this.getAttribute('data-id'); if (!id) return; if(!confirm('¿Eliminar esta solicitud?')) return;
                    var xhr=new XMLHttpRequest(); xhr.open('POST', ajaxUrl); xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded'); xhr.onload=function(){ if(xhr.status===200){ try{var res=JSON.parse(xhr.responseText);}catch(e){res=null;} var ok=(res && (res.ok===true||res.success===true)); if(ok){ alert('Solicitud eliminada'); location.reload(); } else { alert('Error: '+(res && res.msg? res.msg : xhr.responseText)); } } else { alert('Error HTTP: '+xhr.status); } }; xhr.send('action=gaa_delete_solicitud&id='+encodeURIComponent(id)+'&nonce='+encodeURIComponent(nonce)); });
            }

            // Delete handlers (row buttons)
            onAll('.gaa-delete', function(btn){ btn.addEventListener('click', function(){ var id=this.getAttribute('data-id'); if(!confirm('¿Eliminar esta solicitud?')) return; var xhr=new XMLHttpRequest(); xhr.open('POST', ajaxUrl); xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded'); xhr.onload=function(){ if(xhr.status===200){ try{var res=JSON.parse(xhr.responseText);}catch(e){res=null;} var ok=(res && (res.ok===true||res.success===true)); if(ok){ alert('Solicitud eliminada'); location.reload(); } else { alert('Error: '+(res && res.msg? res.msg : xhr.responseText)); } } else { alert('Error HTTP: '+xhr.status); } }; xhr.send('action=gaa_delete_solicitud&id='+encodeURIComponent(id)+'&nonce='+encodeURIComponent(nonce)); }); });

        })();
        </script>
        <?php
    }

    public static function pagina_asociaciones() {
        if (!current_user_can('manage_options')) return;
        $msg = '';
        if (isset($_POST['gaa_import_asoc'])) {
            if (!isset($_POST['gaa_asoc_nonce']) || !wp_verify_nonce($_POST['gaa_asoc_nonce'], 'gaa_asoc_import')) {
                $msg = '<div class="notice notice-error"><p>Nonce inválido.</p></div>';
            } else {
                if (!empty($_FILES['gaa_asoc_file']['tmp_name'])) {
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                    $uploaded = wp_handle_upload($_FILES['gaa_asoc_file'], array('test_form'=>false));
                    if (!empty($uploaded['file'])) {
                        $contents = file_get_contents($uploaded['file']);
                        $rows = array_map('str_getcsv', explode("\n", $contents));
                        $header = array(); $created=0; $skipped=0; $updated=0;
                        $errors = array();
                        foreach ($rows as $ridx => $row) {
                            if ($ridx===0) { $header = $row; continue; }
                            if (empty(array_filter($row))) continue;
                            $data = array();
                            foreach ($header as $i => $h) { $h = trim(strtolower($h)); $data[$h] = isset($row[$i]) ? trim($row[$i]) : ''; }

                            // Validaciones: NIF (si proporcionado) y email de contacto (si se detecta)
                            $title = !empty($data['nombre']) ? $data['nombre'] : (!empty($data['name']) ? $data['name'] : 'Asociación');

                            // Detectar posible email en campos comunes
                            $contact_candidates = array('contacto_email','contacto','email');
                            $contact_email = '';
                            foreach ($contact_candidates as $c) {
                                if (!empty($data[$c]) && strpos($data[$c], '@') !== false) { $contact_email = $data[$c]; break; }
                            }
                            if (!empty($contact_email) && !is_email($contact_email)) {
                                $errors[] = 'Fila ' . ($ridx+1) . ': email de contacto inválido (' . esc_html($contact_email) . ')';
                                $skipped++;
                                continue;
                            }

                            // Validar NIF si existe (simple check: alfanumérico y 5-12 caracteres)
                            if (!empty($data['nif'])) {
                                $nif = strtoupper(preg_replace('/[^A-Z0-9]/', '', $data['nif']));
                                if (strlen($nif) < 5 || strlen($nif) > 12 || !ctype_alnum($nif)) {
                                    $errors[] = 'Fila ' . ($ridx+1) . ': NIF/CIF inválido (' . esc_html($data['nif']) . ')';
                                    $skipped++;
                                    continue;
                                }
                            }

                            // Buscar por NIF existente
                            $existing_id = 0;
                            if (!empty($data['nif'])) {
                                $found = get_posts(array('post_type'=>'asociacion','meta_key'=>'_gaa_assoc_nif','meta_value'=>$data['nif'],'numberposts'=>1));
                                if (!empty($found)) $existing_id = $found[0]->ID;
                            }
                            // Si no hay NIF o no encontrado, intentar buscar por título
                            if (!$existing_id) {
                                $found_by_title = get_page_by_title($title, OBJECT, 'asociacion');
                                if ($found_by_title) $existing_id = $found_by_title->ID;
                            }

                            $meta_nif = isset($data['nif']) ? sanitize_text_field($data['nif']) : '';
                            $meta_tel = isset($data['telefono']) ? sanitize_text_field($data['telefono']) : '';
                            $meta_dir = isset($data['direccion']) ? sanitize_textarea_field($data['direccion']) : '';
                            $meta_cont = isset($data['contacto']) ? sanitize_text_field($data['contacto']) : '';
                            $meta_cont_email = !empty($contact_email) ? sanitize_email($contact_email) : '';

                            if ($existing_id) {
                                // Actualizar post existente
                                $upd = array('ID' => $existing_id);
                                if (get_the_title($existing_id) !== $title) $upd['post_title'] = $title;
                                wp_update_post($upd);
                                if (!empty($meta_nif)) update_post_meta($existing_id, '_gaa_assoc_nif', $meta_nif);
                                if (!empty($meta_tel)) update_post_meta($existing_id, '_gaa_assoc_telefono', $meta_tel);
                                if (!empty($meta_dir)) update_post_meta($existing_id, '_gaa_assoc_direccion', $meta_dir);
                                if (!empty($meta_cont)) update_post_meta($existing_id, '_gaa_assoc_contacto', $meta_cont);
                                if (!empty($meta_cont_email)) update_post_meta($existing_id, '_gaa_assoc_contact_email', $meta_cont_email);
                                $updated++;
                            } else {
                                $postarr = array('post_title' => $title, 'post_type'=>'asociacion', 'post_status'=>'publish');
                                $pid = wp_insert_post($postarr);
                                if (is_wp_error($pid) || !$pid) { $skipped++; continue; }
                                if (!empty($meta_nif)) update_post_meta($pid, '_gaa_assoc_nif', $meta_nif);
                                if (!empty($meta_tel)) update_post_meta($pid, '_gaa_assoc_telefono', $meta_tel);
                                if (!empty($meta_dir)) update_post_meta($pid, '_gaa_assoc_direccion', $meta_dir);
                                if (!empty($meta_cont)) update_post_meta($pid, '_gaa_assoc_contacto', $meta_cont);
                                if (!empty($meta_cont_email)) update_post_meta($pid, '_gaa_assoc_contact_email', $meta_cont_email);
                                $created++;
                            }
                        }
                        $msg = '<div class="notice notice-success"><p>Creadas: ' . intval($created) . '. Actualizadas: ' . intval($updated) . '. Omitidas: ' . intval($skipped) . '</p></div>';
                        if (!empty($errors)) {
                            $msg .= '<div class="notice notice-warning" style="margin-top:8px;"><p>Errores en el CSV:</p><ul>';
                            foreach ($errors as $e) {
                                $msg .= '<li>' . esc_html($e) . '</li>';
                            }
                            $msg .= '</ul></div>';
                        }
                    } else {
                        $msg = '<div class="notice notice-error"><p>Error subiendo el archivo.</p></div>';
                    }
                } else {
                    $msg = '<div class="notice notice-error"><p>No se ha seleccionado archivo.</p></div>';
                }
            }
        }

        // List existing associations
        $asocs = get_posts(array('post_type'=>'asociacion','post_status'=>'publish','numberposts'=>-1));
        ?>
        <div class="wrap">
            <h1>Asociaciones</h1>
            <?php if (!empty($msg)) echo $msg; ?>
            <h2>Importar desde CSV</h2>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('gaa_asoc_import','gaa_asoc_nonce'); ?>
                <p><input type="file" name="gaa_asoc_file" accept=".csv"></p>
                <p class="description">CSV con cabeceras preferidas: nombre,nif,telefono,direccion,contacto</p>
                <p><button class="button button-primary" name="gaa_import_asoc">Importar</button></p>
            </form>

            <h2>Asociaciones existentes</h2>
                <table class="widefat fixed striped"><thead><tr><th>Nombre</th><th>NIF</th><th>Contacto</th><th>Email contacto</th><th>Teléfono</th></tr></thead><tbody>
                <?php if ($asocs): foreach($asocs as $a): $nid = $a->ID; $nif = get_post_meta($nid,'_gaa_assoc_nif',true); $tel = get_post_meta($nid,'_gaa_assoc_telefono',true); $cont = get_post_meta($nid,'_gaa_assoc_contacto',true); $cont_email = get_post_meta($nid,'_gaa_assoc_contact_email',true); ?>
                    <tr><td><?php echo esc_html(get_the_title($nid)); ?></td><td><?php echo esc_html($nif); ?></td><td><?php echo esc_html($cont); ?></td><td><?php echo esc_html($cont_email); ?></td><td><?php echo esc_html($tel); ?></td></tr>
                <?php endforeach; else: ?><tr><td colspan="5">No hay asociaciones.</td></tr><?php endif; ?></tbody></table>
        </div>
        <?php
    }
    
    public static function get_usuario_admision() {
        return get_option('gaa_usuario_admision', '');
    }
}