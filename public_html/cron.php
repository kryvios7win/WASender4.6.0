<?php
// cron.php
require_once 'config.php';

// Verificar chave de segurança
$cron_key = 'WASENDER_CRON_KEY_2024'; // Altere para uma chave secreta
if (!isset($_GET['key']) || $_GET['key'] !== $cron_key) {
    die('Acesso negado');
}

echo "Iniciando tarefas agendadas...\n";

// Conexão com o banco de dados
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    die("Erro de conexão: " . $db->connect_error);
}

// 1. Limpar sessões antigas (mais de 7 dias)
echo "1. Limpando sessões antigas...\n";
$query = "DELETE FROM wasender_sessions WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
$db->query($query);
echo "   Sessões removidas: " . $db->affected_rows . "\n";

// 2. Limpar logs antigos (mais de 30 dias)
echo "2. Limpando logs antigos...\n";
$query = "DELETE FROM wasender_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
$db->query($query);
echo "   Logs removidos: " . $db->affected_rows . "\n";

// 3. Atualizar status de mensagens pendentes antigas para falha
echo "3. Atualizando status de mensagens...\n";
$query = "UPDATE wasender_messages SET status = 'failed' 
          WHERE status = 'pending' 
          AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)";
$db->query($query);
echo "   Mensagens atualizadas: " . $db->affected_rows . "\n";

// 4. Limpar mensagens agendadas já processadas (com status 'sent' e com mais de 7 dias)
echo "4. Limpando mensagens agendadas antigas...\n";
$query = "DELETE FROM wasender_scheduled 
          WHERE status = 'sent' 
          AND schedule_time < DATE_SUB(NOW(), INTERVAL 7 DAY)";
$db->query($query);
echo "   Mensagens agendadas removidas: " . $db->affected_rows . "\n";

// 5. Limpar mensagens de chat antigas (mais de 90 dias) - ajuste conforme necessário
echo "5. Limpando mensagens de chat antigas...\n";
$query = "DELETE FROM wasender_chat_messages 
          WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)";
$db->query($query);
echo "   Mensagens de chat removidas: " . $db->affected_rows . "\n";

$db->close();

// 6. Limpar arquivos temporários (se houver)
echo "6. Limpando arquivos temporários...\n";
$tempDirs = [
    __DIR__ . '/temp/',
    __DIR__ . '/sessions/',
    __DIR__ . '/whatsapp/queue/'
];

foreach ($tempDirs as $dir) {
    if (is_dir($dir)) {
        $files = glob($dir . '*');
        $count = 0;
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < time() - 86400) { // 24 horas
                unlink($file);
                $count++;
            }
        }
        echo "   Limpos $count arquivos em " . basename($dir) . "\n";
    }
}

echo "Tarefas concluídas!\n";
?>