<?php
// Debug: lista últimos socios y asociaciones con sus metadatos
require_once __DIR__ . '/../wp-load.php';

// Últimos 5 socios
$sols = get_posts(['post_type'=>'socio','numberposts'=>5,'post_status'=>'any']);
foreach($sols as $p){
  echo "SOCIO ID: {$p->ID} - {$p->post_title}\n";
  $meta = get_post_meta($p->ID);
  foreach($meta as $k=>$v){
    $val = is_array($v) ? implode(', ', $v) : $v;
    echo "  $k: $val\n";
  }
  echo "\n";
}
// Últimas 10 asociaciones
$as = get_posts(['post_type'=>'asociacion','numberposts'=>10,'post_status'=>'publish']);
foreach($as as $a){
  echo "ASOCIACION ID: {$a->ID} - {$a->post_title}\n";
  $meta = get_post_meta($a->ID);
  foreach($meta as $k=>$v){
    $val = is_array($v) ? implode(', ', $v) : $v;
    echo "  $k: $val\n";
  }
  echo "\n";
}
