<?php
// qrcode.php
require_once 'config.php';
require_once 'index.php'; // Para ter acesso às classes

$sessionId = $_GET['session'] ?? '';

if (empty($sessionId)) {
    // Criar imagem de erro
    $im = imagecreate(300, 300);
    $background = imagecolorallocate($im, 255, 255, 255);
    $textColor = imagecolorallocate($im, 0, 0, 0);
    imagestring($im, 5, 50, 140, 'Sessão não especificada', $textColor);
    header('Content-Type: image/png');
    imagepng($im);
    imagedestroy($im);
    exit;
}

// Buscar QR Code do banco
$session = Database::selectOne(
    "SELECT qr_code FROM wasender_sessions WHERE session_id = ?",
    [$sessionId]
);

if ($session && !empty($session['qr_code'])) {
    $qrData = $session['qr_code'];
    
    // Remover cabeçalho base64 se existir
    if (strpos($qrData, 'data:image/png;base64,') === 0) {
        $qrData = substr($qrData, 22);
    } elseif (strpos($qrData, 'data:image/jpeg;base64,') === 0) {
        $qrData = substr($qrData, 23);
    }
    
    header('Content-Type: image/png');
    echo base64_decode($qrData);
} else {
    // Imagem de aguardo
    $im = imagecreate(300, 300);
    $background = imagecolorallocate($im, 255, 255, 255);
    $textColor = imagecolorallocate($im, 0, 0, 0);
    imagestring($im, 3, 70, 140, 'Aguardando QR Code...', $textColor);
    header('Content-Type: image/png');
    imagepng($im);
    imagedestroy($im);
}