<?php
class GAA_Roles {
    
    public static function init() {
        // nada por ahora
    }
    
    public static function es_admision($user_id = null) {
        if (!$user_id) $user_id = get_current_user_id();
        if (!is_user_logged_in()) return false;
        
        $admision_id = get_option('gaa_usuario_admision');
        return $user_id == $admision_id || user_can($user_id, 'manage_options');
    }
}