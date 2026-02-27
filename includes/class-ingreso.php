<?php
class GAA_Ingreso {
    private static $smtp_phpmailer_hook = null;
    
    public static function init() {
        // Shortcodes
        add_shortcode('gaa_formulario_ingreso', array(__CLASS__, 'formulario_ingreso'));
        add_shortcode('gaa_validar_email', array(__CLASS__, 'validar_email'));
        add_shortcode('gaa_gestion_solicitudes', array(__CLASS__, 'shortcode_gestion_solicitudes'));
        
        // Procesar formularios
        add_action('template_redirect', array(__CLASS__, 'procesar_solicitud'));
        add_action('template_redirect', array(__CLASS__, 'procesar_validacion'));
        
        // CPT Socio
        add_action('init', array(__CLASS__, 'registrar_cpt_socio'));
        
        // Meta boxes en admin
        add_action('add_meta_boxes', array(__CLASS__, 'meta_boxes'));
        add_action('save_post', array(__CLASS__, 'guardar_meta'));
        // AJAX handlers para gestión de solicitudes (solo para usuarios autenticados)
        add_action('wp_ajax_gaa_get_solicitud', array(__CLASS__, 'ajax_get_solicitud'));
        add_action('wp_ajax_gaa_set_aprobacion', array(__CLASS__, 'ajax_set_aprobacion'));
        add_action('wp_ajax_gaa_delete_solicitud', array(__CLASS__, 'ajax_delete_solicitud'));
    }

    // Envío de welcome según driver
    public static function send_welcome_email($socio_id) {
        $email = get_post_meta($socio_id, '_gaa_email', true);
        $nombre = get_post_meta($socio_id, '_gaa_nombre', true);
        if (empty($email)) return false;

        $mail_driver = get_option('gaa_mail_driver', 'wp_mail');
        $welcome_body = get_option('gaa_welcome_body', '<p>Bienvenido/a, gracias por unirte.</p>');
        $welcome_footer = get_option('gaa_welcome_footer', '<p>Atentamente,<br>' . get_bloginfo('name') . '</p>');
        $subject = apply_filters('gaa_welcome_subject', 'Bienvenido/a a ' . get_bloginfo('name'));
        $from_email = get_option('gaa_from_email', get_option('admin_email'));
        $from_name = get_option('gaa_from_name', get_bloginfo('name'));

        // Construir pie de protección de datos
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

        $message = $welcome_body . $welcome_footer . $dp_html;
        $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . $from_name . ' <' . $from_email . '>');

        // Enforce allowed From domains if configurado
        $enforce = get_option('gaa_enforce_from_domain', 0);
        $allowed_raw = get_option('gaa_allowed_from_domains', '');
        $allowed = array();
        if (!empty($allowed_raw)) {
            $parts = preg_split('/\r?\n/', $allowed_raw);
            foreach ($parts as $p) { $d = trim(strtolower($p)); if (!empty($d)) $allowed[] = preg_replace('/^www\./','',$d); }
        }
        if ($enforce) {
            $from_domain = '';
            if (strpos($from_email, '@') !== false) $from_domain = strtolower(substr($from_email, strpos($from_email, '@') + 1));
            $from_domain = preg_replace('/^www\./','',$from_domain);
            if (!empty($from_domain) && !in_array($from_domain, $allowed, true)) {
                // Reemplazar por admin_email del sitio
                $fallback = get_option('admin_email');
                $from_email = $fallback;
                $from_name = get_bloginfo('name');
                $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . $from_name . ' <' . $from_email . '>');
            }
        }

        // Si el admin ha elegido usar MailPoet para el email de alta, intentar obtener la plantilla y usar su HTML
        $use_mp_alta = get_option('gaa_use_mailpoet_alta', 0);
        $mp_tpl_id = get_option('gaa_mailpoet_alta_id', '');
        if ($use_mp_alta && !empty($mp_tpl_id) && class_exists('MailPoet\\API\\API')) {
            try {
                $mp = \MailPoet\API\API::MP();
                $tpl = null;
                if (is_object($mp)) {
                    if (method_exists($mp, 'templates')) {
                        $svc = $mp->templates();
                        if (is_object($svc) && method_exists($svc, 'get')) $tpl = $svc->get($mp_tpl_id);
                        elseif (is_object($svc) && method_exists($svc, 'getById')) $tpl = $svc->getById($mp_tpl_id);
                    }
                    if (!$tpl && method_exists($mp, 'getTemplate')) $tpl = $mp->getTemplate($mp_tpl_id);
                }
                if (!$tpl) {
                    $post = get_post($mp_tpl_id);
                    if ($post) $tpl = $post;
                }
                $mp_html = '';
                if ($tpl) {
                    if (is_array($tpl)) {
                        $mp_html = !empty($tpl['html']) ? $tpl['html'] : (!empty($tpl['content']) ? $tpl['content'] : '');
                    } elseif (is_object($tpl)) {
                        if (!empty($tpl->html)) $mp_html = $tpl->html;
                        elseif (!empty($tpl->content)) $mp_html = $tpl->content;
                        elseif (!empty($tpl->post_content)) $mp_html = $tpl->post_content;
                    }
                }
                if (!empty($mp_html)) {
                    // Añadir pie de protección y enviar usando el HTML de MailPoet
                    $send_html = $mp_html . $dp_html;
                    // Intentar usar MailPoet para enviar si existe un método de envío simple
                    $sent = false;
                    if (is_object($mp) && method_exists($mp, 'send')) {
                        try {
                            $mp->send(array('to' => $email, 'subject' => $subject, 'html' => $send_html));
                            $sent = true;
                        } catch (\Throwable $__e) {
                            $sent = false;
                        }
                    }
                    // Fallback: usar wp_mail con el HTML de MailPoet
                    if (!$sent) {
                        if ($mail_driver === 'smtp') {
                            // dejar que la lógica SMTP de abajo maneje el envío; sustituir message
                            $message = $send_html;
                        } else {
                            $sent = wp_mail($email, $subject, $send_html, $headers);
                        }
                    }
                    if ($sent) { update_post_meta($socio_id, '_gaa_email_enviado', 1); return true; }
                    // si no se ha enviado, continuar con la ruta normal
                }
            } catch (\Throwable $e) {
                // No bloquear: continuamos con envío convencional
            }
        }

        // Si driver smtp, configurar phpmailer_init temporalmente
        if ($mail_driver === 'smtp') {
            $host = get_option('gaa_smtp_host', '');
            $port = get_option('gaa_smtp_port', 587);
            $user = get_option('gaa_smtp_user', '');
            $pass = get_option('gaa_smtp_pass', '');
            $secure = get_option('gaa_smtp_secure', 'tls');

            // Crear hook y almacenarlo para poder quitarlo luego
            self::$smtp_phpmailer_hook = function($phpmailer) use ($host, $port, $user, $pass, $secure, $from_email, $from_name) {
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
            add_action('phpmailer_init', self::$smtp_phpmailer_hook);

            $sent = wp_mail($email, $subject, $message, $headers);

            // Quitar hook
            if (self::$smtp_phpmailer_hook) remove_action('phpmailer_init', self::$smtp_phpmailer_hook);

            if ($sent) {
                update_post_meta($socio_id, '_gaa_email_enviado', 1);
                return true;
            }
            return false;
        }

        // Por defecto usar wp_mail
        $sent = wp_mail($email, $subject, $message, $headers);
        if ($sent) update_post_meta($socio_id, '_gaa_email_enviado', 1);
        return $sent;
    }

    // AJAX: devolver datos de la solicitud
    public static function ajax_get_solicitud() {
        if (!(current_user_can('manage_options') || \GAA_Roles::es_admision())) wp_send_json_error(array('msg'=>'Sin permiso'));
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gaa_admin_nonce')) wp_send_json_error(array('msg'=>'Nonce inválido'));
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) wp_send_json_error(array('msg'=>'ID inválido'));
        $post = get_post($id);
        if (!$post || $post->post_type !== 'socio') wp_send_json_error(array('msg'=>'Solicitud no encontrada'));
        $data = array(
            'nombre' => get_post_meta($id, '_gaa_nombre', true),
            'email' => get_post_meta($id, '_gaa_email', true),
            'telefono' => get_post_meta($id, '_gaa_telefono', true),
            'direccion' => get_post_meta($id, '_gaa_direccion', true),
            'profesion' => get_post_meta($id, '_gaa_profesion', true),
            'fecha_nacimiento' => get_post_meta($id, '_gaa_fecha_nacimiento', true),
            'disponible_ayuda' => get_post_meta($id, '_gaa_disponible_ayuda', true),
            'alcance_colaboracion' => get_post_meta($id, '_gaa_alcance_colaboracion', true),
            'mensaje' => '',
            // Asociación
            'is_association' => get_post_meta($id, '_gaa_is_association', true),
            'assoc_nombre' => get_post_meta($id, '_gaa_assoc_nombre', true),
            'assoc_nif' => get_post_meta($id, '_gaa_assoc_nif', true),
            'assoc_telefono' => get_post_meta($id, '_gaa_assoc_telefono', true),
            'assoc_direccion' => get_post_meta($id, '_gaa_assoc_direccion', true),
            'assoc_contacto' => get_post_meta($id, '_gaa_assoc_contacto', true),
            'assoc_contacto_same' => get_post_meta($id, '_gaa_assoc_contacto_same', true),
            'assoc_rep_email' => get_post_meta($id, '_gaa_assoc_rep_email', true),
            'assoc_rep_nif' => get_post_meta($id, '_gaa_assoc_rep_nif', true),
            'assoc_rep_telefono' => get_post_meta($id, '_gaa_assoc_rep_telefono', true),
            'assoc_id' => get_post_meta($id, '_gaa_assoc_id', true),
        );
        wp_send_json_success(array('data'=>$data));
    }

    // AJAX: borrar solicitud
    public static function ajax_delete_solicitud() {
        if (!(current_user_can('manage_options') || \GAA_Roles::es_admision())) wp_send_json_error(array('msg'=>'Sin permiso'));
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gaa_admin_nonce')) wp_send_json_error(array('msg'=>'Nonce inválido'));
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) wp_send_json_error(array('msg'=>'ID inválido'));
        $post = get_post($id);
        if (!$post || $post->post_type !== 'socio') wp_send_json_error(array('msg'=>'Solicitud no encontrada'));
        $deleted = wp_delete_post($id, true);
        if ($deleted) wp_send_json_success(array('msg'=>'borrado'));
        wp_send_json_error(array('msg'=>'No se pudo borrar'));
    }

    // AJAX: aprobar / rechazar solicitud
    public static function ajax_set_aprobacion() {
        if (!(current_user_can('manage_options') || \GAA_Roles::es_admision())) wp_send_json_error(array('msg'=>'Sin permiso'));
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gaa_admin_nonce')) wp_send_json_error(array('msg'=>'Nonce inválido'));
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $decision = isset($_POST['decision']) ? sanitize_text_field($_POST['decision']) : '';
        if (!$id || !in_array($decision, array('approve','reject'))) wp_send_json_error(array('msg'=>'Parámetros inválidos'));
        if ($decision === 'approve') {
            update_post_meta($id, '_gaa_aprobado', '1');
            // enviar bienvenida
            $sent = self::send_welcome_email($id);
            wp_send_json_success(array('sent'=> $sent));
        } else {
            update_post_meta($id, '_gaa_aprobado', '');
            wp_send_json_success(array('msg'=>'rechazado'));
        }
    }

    // Shortcode front-end para gestionar solicitudes
    public static function shortcode_gestion_solicitudes($atts) {
        // No mostrar la gestión si el plugin no está correctamente configurado
        $usuario_admision = get_option('gaa_usuario_admision');
        $mail_ok = get_option('gaa_mail_test_ok');
        if (empty($usuario_admision) || empty($mail_ok)) return '';

        if (!is_user_logged_in()) return '<p>Debes iniciar sesión para gestionar solicitudes.</p>';
        // Solo permitir al usuario de admisión o administradores
        if (!(current_user_can('manage_options') || \GAA_Roles::es_admision())) return '<p>No tienes permisos para ver esta página.</p>';

        $nonce = wp_create_nonce('gaa_admin_nonce');

        // Recuperar solicitudes (limitado a pendientes por defecto)
        $args = array('post_type'=>'socio','post_status'=>'any','posts_per_page'=>20,'orderby'=>'date','order'=>'DESC');
        $q = new WP_Query($args);

        ob_start();
        ?>
        <div class="gaa-frontend-solicitudes">
            <h3>Solicitudes de ingreso</h3>
            <table style="width:100%; border-collapse:collapse;">
                <thead><tr><th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Nombre</th><th style="padding:6px; border-bottom:1px solid #ddd;">Email</th><th style="padding:6px; border-bottom:1px solid #ddd;">Estado</th><th style="padding:6px; border-bottom:1px solid #ddd;">Acciones</th></tr></thead>
                <tbody>
                <?php if ($q->have_posts()): while ($q->have_posts()): $q->the_post(); $id=get_the_ID(); $nombre=get_post_meta($id,'_gaa_nombre',true); $email=get_post_meta($id,'_gaa_email',true); $aprobado=get_post_meta($id,'_gaa_aprobado',true); ?>
                    <tr data-id="<?php echo esc_attr($id); ?>">
                        <td style="padding:6px;"><?php echo esc_html($nombre ? $nombre : get_the_title()); ?></td>
                        <td style="padding:6px;"><?php echo esc_html($email); ?></td>
                        <td style="padding:6px;"><?php echo $aprobado==='1'?'<strong style="color:green">Aprobado</strong>':'<span style="color:orange">Pendiente</span>'; ?></td>
                        <td style="padding:6px;"><button class="gaa-show btn">Mostrar</button></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="4">No hay solicitudes.</td></tr>
                <?php endif; wp_reset_postdata(); ?>
                </tbody>
            </table>

            <div id="gaa_modal_front" style="display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:9999;">
                <div style="background:#fff; width:90%; max-width:700px; margin:4% auto; padding:20px; border-radius:4px; position:relative;">
                    <button id="gaa_modal_close_front" style="position:absolute; right:10px; top:10px;">Cerrar</button>
                    <div id="gaa_modal_content_front">Cargando...</div>
                    <div style="margin-top:12px;">
                        <button id="gaa_approve_front" class="button button-primary">Admitir</button>
                        <button id="gaa_reject_front" class="button">No admitir</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var nonce = '<?php echo $nonce; ?>';
            function by(s, ctx){return (ctx||document).querySelector(s);} function onAll(s, fn){Array.prototype.forEach.call(document.querySelectorAll(s), fn);} 
            onAll('.gaa-show', function(btn){ btn.addEventListener('click', function(){ var tr=this.closest('tr'); var id=tr.getAttribute('data-id'); var modal=by('#gaa_modal_front'); var content=by('#gaa_modal_content_front'); content.innerHTML='Cargando...'; modal.style.display='block'; var xhr=new XMLHttpRequest(); xhr.open('POST', ajaxUrl); xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded'); xhr.onload=function(){ if(xhr.status===200){ try{ var res=JSON.parse(xhr.responseText);}catch(e){res={ok:false,msg:'Respuesta inválida'};} if(res.ok){ var d=res.data; var html='<p><strong>Nombre:</strong> '+(d.nombre||'')+'</p>'; html+='<p><strong>Email:</strong> '+(d.email||'')+'</p>'; html+='<p><strong>Teléfono:</strong> '+(d.telefono||'')+'</p>'; html+='<p><strong>Dirección:</strong> '+(d.direccion||'')+'</p>'; html+='<p><strong>Profesión:</strong> '+(d.profesion||'')+'</p>'; content.innerHTML=html; by('#gaa_approve_front').setAttribute('data-id', id); by('#gaa_reject_front').setAttribute('data-id', id); } else { content.innerHTML='<p>Error: '+res.msg+'</p>'; } } else { content.innerHTML='<p>Error cargando.</p>'; } }; xhr.send('action=gaa_get_solicitud&id='+encodeURIComponent(id)+'&nonce='+encodeURIComponent(nonce)); }); });
            by('#gaa_modal_close_front').addEventListener('click', function(){ by('#gaa_modal_front').style.display='none'; });
            function sendDecisionFront(id, decision){ var xhr=new XMLHttpRequest(); xhr.open('POST', ajaxUrl); xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded'); xhr.onload=function(){ if(xhr.status===200){ try{var res=JSON.parse(xhr.responseText);}catch(e){res={ok:false};} if(res.ok){ location.reload(); } else { alert('Error: '+(res.msg||'')); } } }; xhr.send('action=gaa_set_aprobacion&id='+encodeURIComponent(id)+'&decision='+encodeURIComponent(decision)+'&nonce='+encodeURIComponent(nonce)); }
            by('#gaa_approve_front').addEventListener('click', function(){ var id=this.getAttribute('data-id'); sendDecisionFront(id,'approve'); });
            by('#gaa_reject_front').addEventListener('click', function(){ var id=this.getAttribute('data-id'); sendDecisionFront(id,'reject'); });
        })();
        </script>
        <?php
        return ob_get_clean();
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
        // CPT para asociaciones (listado de asociaciones pre-existentes)
        register_post_type('asociacion', array(
            'labels' => array(
                'name' => 'Asociaciones',
                'singular_name' => 'Asociación',
                'add_new' => 'Añadir Asociación',
                'edit_item' => 'Editar Asociación'
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'capability_type' => 'post',
            'supports' => array('title'),
            'menu_icon' => 'dashicons-building'
        ));
    }
    
    // FORMULARIO DE INGRESO (paso 1)
    public static function formulario_ingreso() {
        // No mostrar el formulario si la configuración del plugin no está completa
        $usuario_admision = get_option('gaa_usuario_admision');
        $mail_ok = get_option('gaa_mail_test_ok');
        if (empty($usuario_admision) || empty($mail_ok)) {
            return ''; // Plugin no configurado: no mostrar formulario
        }
        if (isset($_GET['enviado']) && $_GET['enviado'] == 'ok') {
            return '<div style="background:#d4edda; color:#155724; padding:20px; border-radius:4px;">
                <h3>¡Solicitud recibida!</h3>
                <p>Revisa tu email para validar tu dirección y continuar el proceso.</p>
            </div>';
        }

        $error_msg = '';
        if (isset($_GET['error']) && $_GET['error'] === 'email_mismatch') {
            $error_msg = '<div style="background:#f8d7da; color:#721c24; padding:12px; border-radius:4px; margin-bottom:12px;">Los correos electrónicos no coinciden. Escribe manualmente la confirmación y evita pegar.</div>';
        }
        
        ob_start();
        ?>
        <div style="max-width:600px; margin:0 auto; padding:20px;">
            <h2>Solicitud de Ingreso</h2>
            <form method="post">
                <?php wp_nonce_field('gaa_solicitud_ingreso', 'gaa_nonce'); ?>
                <?php if (!empty($error_msg)) echo $error_msg; ?>
                
                <p>
                    <label>Nombre:</label>
                    <input type="text" name="nombre" required style="width:100%; padding:8px;">
                </p>
                
                <p>
                    <label>Apellidos:</label>
                    <input type="text" name="apellidos" required style="width:100%; padding:8px;">
                </p>

                <?php $gaa_allow_assoc = get_option('gaa_allow_associations', 1); ?>
                <?php if ($gaa_allow_assoc): ?>
                <p>
                    <label>Tipo de solicitud:</label>
                    <label style="margin-left:8px;"><input type="radio" name="tipo_solicitud" value="individual" checked> A título individual</label>
                    <label style="margin-left:8px;"><input type="radio" name="tipo_solicitud" value="asociacion"> Como asociación</label>
                </p>

                <div id="gaa_asociacion_fields" style="display:none; border:1px dashed #ddd; padding:10px; margin-bottom:12px;">
                    <h4>Datos de la asociación</h4>
                    <p>
                        <label>Seleccionar asociación existente: </label>
                        <select name="assoc_select" style="width:100%; padding:8px; margin-bottom:8px;">
                            <option value="">Crear nueva asociación...</option>
                            <?php $existing_asocs = get_posts(array('post_type'=>'asociacion','post_status'=>'publish','numberposts'=>-1)); foreach($existing_asocs as $ea): 
                                $ea_email = get_post_meta($ea->ID, '_gaa_assoc_contact_email', true);
                                $label = get_the_title($ea->ID);
                                if (!empty($ea_email)) $label .= ' — ' . $ea_email;
                            ?>
                                <option value="<?php echo esc_attr($ea->ID); ?>" title="<?php echo esc_attr($label); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                        </select>
                    </p>
                    <p><label>Nombre de la asociación: <input type="text" name="assoc_nombre" style="width:100%; padding:8px;"></label></p>
                    <p><label>NIF/CIF: <input type="text" name="assoc_nif" style="width:100%; padding:8px;"></label></p>
                    <p><label>Teléfono asociación: <input type="text" name="assoc_telefono" style="width:100%; padding:8px;"></label></p>
                    <p><label>Dirección asociación: <textarea name="assoc_direccion" rows="2" style="width:100%; padding:8px;"></textarea></label></p>
                    <p><label>Persona de contacto en la asociación: <input type="text" name="assoc_contacto" style="width:100%; padding:8px;"></label></p>
                    <p><label>Email del representante: <input type="email" name="assoc_rep_email" style="width:100%; padding:8px;"></label></p>
                    <p><label>NIF/CIF del representante: <input type="text" name="assoc_rep_nif" style="width:100%; padding:8px;"></label></p>
                    <p><label>Teléfono del representante: <input type="text" name="assoc_rep_telefono" style="width:100%; padding:8px;"></label></p>
                    <p><label><input type="checkbox" id="gaa_assoc_contact_same" name="assoc_contact_same" value="1"> La persona de contacto soy yo (copiar mis datos)</label></p>
                </div>
                <?php else: ?>
                    <input type="hidden" name="tipo_solicitud" value="individual">
                <?php endif; ?>
                
                <p>
                    <label>Email:</label>
                    <input type="email" name="email" id="gaa_email" required style="width:100%; padding:8px;">
                </p>

                <p>
                    <label>Confirmar Email:</label>
                    <input type="email" name="email_confirm" id="gaa_email_confirm" required style="width:100%; padding:8px;" onpaste="return false" oncut="return false" ondrop="return false">
                    <small>No se permite pegar en este campo; escríbelo manualmente.</small>
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
                
                <p style="display:flex; gap:12px; align-items:center;">
                    <label style="flex:1;">Fecha de nacimiento:
                        <input type="date" name="fecha_nacimiento" max="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:8px;">
                    </label>
                    <label style="flex:0 0 220px;">
                        <input type="checkbox" name="disponible_ayuda" value="1"> Puedo ayudar a la asociación
                    </label>
                </p>

                <p>
                    <label>Alcance de la posible colaboración (opcional):</label>
                    <textarea name="alcance_colaboracion" rows="3" style="width:100%; padding:8px;" placeholder="Describe cómo podrías colaborar"></textarea>
                </p>
                
                <p>
                    <label>
                        <input type="checkbox" name="acepto" required>
                        Acepto los estatutos de la asociación
                        <?php $estatutos_url = esc_url(get_option('gaa_estatutos_url', '')); if ($estatutos_url): ?> — <a href="<?php echo $estatutos_url; ?>" target="_blank" rel="noopener">Ver aquí</a><?php endif; ?>
                    </label>
                </p>
                
                <p>
                    <button type="submit" name="enviar_solicitud" style="background:#0073aa; color:#fff; padding:12px 24px; border:none; border-radius:4px; cursor:pointer;">
                        Enviar solicitud
                    </button>
                </p>
            </form>
        </div>
        <?php $privacy = esc_url(get_option('gaa_privacy_url', '')); if ($privacy): ?>
            <p style="max-width:600px; margin:8px auto 0; font-size:0.95em;"><a href="<?php echo $privacy; ?>" target="_blank" rel="noopener">Política de tratamiento de la información y protección de datos</a></p>
        <?php endif; ?>
        <script>
        (function(){
            var f = document.getElementById('gaa_email_confirm');
            if (f) {
                ['paste','cut','drop','copy'].forEach(function(ev){ f.addEventListener(ev, function(e){ e.preventDefault(); }); });
                f.addEventListener('keydown', function(e){ if ((e.ctrlKey||e.metaKey) && (e.key === 'v' || e.key === 'V')) e.preventDefault(); });
            }
            // Toggle association fields
            var tipoRadios = document.getElementsByName('tipo_solicitud');
            var assocWrap = document.getElementById('gaa_asociacion_fields');
            function updateAssoc(){
                var val = 'individual';
                for(var i=0;i<tipoRadios.length;i++){ if(tipoRadios[i].checked) { val = tipoRadios[i].value; break; } }
                if(assocWrap) assocWrap.style.display = (val === 'asociacion') ? 'block' : 'none';
            }
            for(var i=0;i<tipoRadios.length;i++) tipoRadios[i].addEventListener('change', updateAssoc);
            updateAssoc();
            // If contact same checkbox is checked, copy applicant name into assoc_contacto on submit
            var form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(){
                        var same = document.getElementById('gaa_assoc_contact_same');
                        if (same && same.checked) {
                            var nombre = document.querySelector('input[name="nombre"]') ? document.querySelector('input[name="nombre"]').value : '';
                            var apellidos = document.querySelector('input[name="apellidos"]') ? document.querySelector('input[name="apellidos"]').value : '';
                            var full = (nombre + ' ' + apellidos).trim();
                            var contacto = document.querySelector('input[name="assoc_contacto"]');
                            if (contacto) contacto.value = full;
                            // también copiar email y teléfono del solicitante
                            var email = document.querySelector('input[name="email"]') ? document.querySelector('input[name="email"]').value : '';
                            var tel = document.querySelector('input[name="telefono"]') ? document.querySelector('input[name="telefono"]').value : '';
                            var repEmail = document.querySelector('input[name="assoc_rep_email"]');
                            if (repEmail && email) repEmail.value = email;
                            var repTel = document.querySelector('input[name="assoc_rep_telefono"]');
                            if (repTel && tel) repTel.value = tel;
                        }
                    });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    
    // PROCESAR SOLICITUD (paso 1)
    public static function procesar_solicitud() {
        if (!isset($_POST['enviar_solicitud'])) return;
        if (!wp_verify_nonce($_POST['gaa_nonce'], 'gaa_solicitud_ingreso')) return;
        
        $email = sanitize_email($_POST['email']);
        $email_confirm = isset($_POST['email_confirm']) ? sanitize_email($_POST['email_confirm']) : '';
        $nombre = sanitize_text_field($_POST['nombre']);
        $apellidos = sanitize_text_field($_POST['apellidos']);
        
        // Validar que el email y la confirmación coincidan
        if (empty($email_confirm) || $email !== $email_confirm) {
            $current = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : home_url('/');
            wp_safe_redirect( add_query_arg('error', 'email_mismatch', $current) );
            exit;
        }
        
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
                '_gaa_fecha_nacimiento' => isset($_POST['fecha_nacimiento']) ? sanitize_text_field($_POST['fecha_nacimiento']) : '',
                '_gaa_acepto' => isset($_POST['acepto']) ? 1 : 0,
                '_gaa_token' => $token,
                '_gaa_disponible_ayuda' => isset($_POST['disponible_ayuda']) && $_POST['disponible_ayuda'] == '1' ? 1 : 0,
                '_gaa_alcance_colaboracion' => isset($_POST['alcance_colaboracion']) ? sanitize_textarea_field($_POST['alcance_colaboracion']) : '',
                // Asociación vs Individual
                '_gaa_is_association' => (isset($_POST['tipo_solicitud']) && $_POST['tipo_solicitud'] === 'asociacion') ? 1 : 0,
                '_gaa_assoc_nombre' => isset($_POST['assoc_nombre']) ? sanitize_text_field($_POST['assoc_nombre']) : '',
                '_gaa_assoc_nif' => isset($_POST['assoc_nif']) ? sanitize_text_field($_POST['assoc_nif']) : '',
                '_gaa_assoc_telefono' => isset($_POST['assoc_telefono']) ? sanitize_text_field($_POST['assoc_telefono']) : '',
                '_gaa_assoc_direccion' => isset($_POST['assoc_direccion']) ? sanitize_textarea_field($_POST['assoc_direccion']) : '',
                '_gaa_assoc_contacto' => isset($_POST['assoc_contacto']) ? sanitize_text_field($_POST['assoc_contacto']) : '',
                '_gaa_assoc_contacto_same' => isset($_POST['assoc_contact_same']) && $_POST['assoc_contact_same'] == '1' ? 1 : 0,
                '_gaa_assoc_rep_email' => isset($_POST['assoc_rep_email']) ? sanitize_email($_POST['assoc_rep_email']) : '',
                '_gaa_assoc_rep_nif' => isset($_POST['assoc_rep_nif']) ? sanitize_text_field($_POST['assoc_rep_nif']) : '',
                '_gaa_assoc_rep_telefono' => isset($_POST['assoc_rep_telefono']) ? sanitize_text_field($_POST['assoc_rep_telefono']) : '',
            ),
        ));

        // Si el solicitante eligió una asociación existente, guardar relación
        if (!empty($_POST['tipo_solicitud']) && $_POST['tipo_solicitud']==='asociacion' && !empty($_POST['assoc_select'])) {
            update_post_meta($socio_id, '_gaa_assoc_id', intval($_POST['assoc_select']));
        }

        // Redirigir al formulario con indicador de envío
        if ($socio_id && !is_wp_error($socio_id)) {
            // Enviar email de validación al solicitante
            $validate_url = add_query_arg(array('gaa_validar' => '1', 'token' => $token), home_url('/'));
            $subject = 'Valida tu email - ' . get_bloginfo('name');
            $message = '<p>Hola ' . esc_html($nombre) . ',</p>' .
                '<p>Hemos recibido tu solicitud de ingreso. Para continuar, por favor valida tu dirección de email haciendo clic en el siguiente enlace:</p>' .
                '<p><a href="' . esc_url($validate_url) . '">' . esc_url($validate_url) . '</a></p>' .
                '<p>Si no realizaste esta solicitud, ignora este mensaje.</p>';
            $from_email = get_option('gaa_from_email', get_option('admin_email'));
            $from_name = get_option('gaa_from_name', get_bloginfo('name'));
            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . $from_name . ' <' . $from_email . '>');
            wp_mail($email, $subject, $message, $headers);

            // Guardar marca de envío para depuración
            update_post_meta($socio_id, '_gaa_email_enviado', 1);

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

    // Meta boxes
    public static function meta_boxes() {
        add_meta_box('gaa_socio_meta', 'Solicitud - Gestión', array(__CLASS__, 'render_meta_box'), 'socio', 'side', 'high');
    }

    public static function render_meta_box($post) {
        $email = get_post_meta($post->ID, '_gaa_email', true);
        $nombre = get_post_meta($post->ID, '_gaa_nombre', true);
        $aprobado = get_post_meta($post->ID, '_gaa_aprobado', true);
        wp_nonce_field('gaa_socio_meta', 'gaa_socio_meta_nonce');
        ?>
        <p><strong>Nombre:</strong> <?php echo esc_html($nombre); ?></p>
        <p><strong>Email:</strong> <?php echo esc_html($email); ?></p>
        <p>
            <label><input type="checkbox" name="gaa_aprobado" value="1" <?php checked($aprobado, '1'); ?>> Marcar como aprobado</label>
        </p>
        <p class="description">Marcar aprobado enviará el mensaje de bienvenida según la configuración.</p>
        <?php
    }

    // Guardar metadatos al guardar el post
    public static function guardar_meta($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (get_post_type($post_id) !== 'socio') return;

        if (!isset($_POST['gaa_socio_meta_nonce']) || !wp_verify_nonce($_POST['gaa_socio_meta_nonce'], 'gaa_socio_meta')) return;

        $prev = get_post_meta($post_id, '_gaa_aprobado', true);
        $new = isset($_POST['gaa_aprobado']) && $_POST['gaa_aprobado'] == '1' ? '1' : '';
        update_post_meta($post_id, '_gaa_aprobado', $new);

        // Si se ha pasado de no aprobado a aprobado, enviar welcome
        if ($prev !== '1' && $new === '1') {
            self::send_welcome_email($post_id);
        }
    }

}

