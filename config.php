<?php
// config.php

// ============================================
// CONFIGURAÇÕES DO BANCO DE DADOS
// ============================================
define('DB_HOST', 'localhost');
define('DB_USER', 'u695379688_user');
define('DB_PASS', 'Alakazam1311787535');
define('DB_NAME', 'u695379688_mysql');

// ============================================
// CONFIGURAÇÕES DO SISTEMA
// ============================================
define('SITE_URL', 'https://seudominio.com'); // Altere para seu domínio
define('SITE_NAME', 'WASender 4.6');
define('VERSION', '4.6.0');

// ============================================
// CONFIGURAÇÕES DE SEGURANÇA
// ============================================
define('SESSION_TIMEOUT', 3600); // 1 hora
define('MAX_LOGIN_ATTEMPTS', 5);
define('ENCRYPTION_KEY', 'wasender_secure_key_2024'); // Altere para uma chave única

// ============================================
// CONFIGURAÇÕES DO WHATSAPP NODE.JS
// ============================================
define('NODE_SERVER_HOST', 'localhost');
define('NODE_SERVER_PORT', '3000');
define('NODE_SERVER_URL', 'http://localhost:3000');
define('WS_SERVER', 'ws://localhost:3000');

// ============================================
// CONFIGURAÇÕES DE AMBIENTE
// ============================================
define('ENVIRONMENT', 'production'); // production, development
define('DEBUG_MODE', false);

// ============================================
// TIMEZONE
// ============================================
date_default_timezone_set('America/Sao_Paulo');

// ============================================
// INICIALIZAÇÃO (opcional, se necessário)
// ============================================
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Iniciar sessão se não estiver iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>