<?php
/**
 * Plugin Name: Gestion Ingresos Asociacion
 * Description: Gestión de ingresos de socios con validación por email
 * Version: 1.0
 */

if (!defined('ABSPATH')) exit;

define('GAA_DIR', plugin_dir_path(__FILE__));

require_once GAA_DIR . 'includes/class-roles.php';
require_once GAA_DIR . 'includes/class-ingreso.php';
require_once GAA_DIR . 'includes/class-configuracion.php';

register_activation_hook(__FILE__, 'gaa_activate');
function gaa_activate() {
    gaa_crear_paginas();
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, 'gaa_deactivate');
function gaa_deactivate() {
    flush_rewrite_rules();
}

add_action('plugins_loaded', 'gaa_init');
function gaa_init() {
    GAA_Roles::init();
    GAA_Ingreso::init();
    GAA_Configuracion::init();
}

function gaa_crear_paginas() {
    // Página de solicitud de ingreso
    if (!get_page_by_path('solicitar-ingreso')) {
        wp_insert_post(array(
            'post_title' => 'Solicitar Ingreso',
            'post_name' => 'solicitar-ingreso',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '[gaa_formulario_ingreso]'
        ));
    }
    
    // Página de validación de email
    if (!get_page_by_path('validar-email')) {
        wp_insert_post(array(
            'post_title' => 'Validar Email',
            'post_name' => 'validar-email',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '[gaa_validar_email]'
        ));
    }
}