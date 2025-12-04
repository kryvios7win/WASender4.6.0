<?php
// node-api.php
require_once 'config.php';

// Verificar se é admin (exemplo simples, ajuste conforme sua lógica de autenticação)
if (!isset($_SESSION['wasender_user_id']) || $_SESSION['wasender_user_id'] !== 'admin') {
    http_response_code(403);
    die('Acesso negado');
}

$action = $_GET['action'] ?? 'status';
$lines = $_GET['lines'] ?? 50;

// Diretórios
$nodeDir = __DIR__ . '/../node';
$logFile = $nodeDir . '/logs/server.log';
$pidFile = $nodeDir . '/server.pid';

switch ($action) {
    case 'status':
        echo getNodeStatus();
        break;
        
    case 'start':
        echo startNodeServer();
        break;
        
    case 'stop':
        echo stopNodeServer();
        break;
        
    case 'restart':
        echo stopNodeServer();
        sleep(2);
        echo startNodeServer();
        break;
        
    case 'logs':
        echo getLogs($lines);
        break;
        
    case 'processes':
        echo getProcesses();
        break;
        
    default:
        echo "Ação inválida";
}

function getNodeStatus() {
    global $pidFile;
    
    if (!file_exists($pidFile)) {
        return '<div class="status status-stopped"><i class="fas fa-times-circle"></i> Servidor Node.js PARADO</div>';
    }
    
    $pid = trim(file_get_contents($pidFile));
    if (isProcessRunning($pid)) {
        return '<div class="status status-running"><i class="fas fa-check-circle"></i> Servidor Node.js RODANDO (PID: ' . $pid . ')</div>';
    } else {
        return '<div class="status status-stopped"><i class="fas fa-times-circle"></i> Servidor Node.js PARADO (PID antigo: ' . $pid . ')</div>';
    }
}

function startNodeServer() {
    global $nodeDir;
    
    // Verificar se já está rodando
    if (isNodeRunning()) {
        return "Servidor já está rodando!";
    }
    
    // Certificar-se de que o diretório de logs existe
    if (!file_exists($nodeDir . '/logs')) {
        mkdir($nodeDir . '/logs', 0777, true);
    }
    
    // Iniciar servidor em background
    $command = "cd " . escapeshellarg($nodeDir) . " && nohup node whatsapp-server.js > logs/server.log 2>&1 & echo $! > server.pid";
    exec($command, $output, $returnCode);
    
    if ($returnCode === 0) {
        // Aguardar um pouco para verificar se realmente iniciou
        sleep(2);
        if (isNodeRunning()) {
            return "✅ Servidor Node.js iniciado com sucesso!";
        } else {
            return "⚠️ Comando executado, mas o servidor pode não ter iniciado. Verifique os logs.";
        }
    } else {
        return "❌ Erro ao iniciar servidor. Código de erro: $returnCode";
    }
}

function stopNodeServer() {
    global $pidFile, $nodeDir;
    
    if (!file_exists($pidFile)) {
        // Tentar parar por nome
        exec("pkill -f whatsapp-server.js 2>/dev/null");
        return "✅ Servidor Node.js parado (forçado).";
    }
    
    $pid = trim(file_get_contents($pidFile));
    
    // Parar processo
    exec("kill " . escapeshellarg($pid) . " 2>/dev/null");
    // Forçar parada se necessário
    exec("pkill -f whatsapp-server.js 2>/dev/null");
    
    // Remover PID file
    unlink($pidFile);
    
    // Registrar no log
    file_put_contents($nodeDir . '/logs/stop.log', 
        date('Y-m-d H:i:s') . " - Servidor parado (PID: $pid)\n", FILE_APPEND);
    
    return "✅ Servidor Node.js parado.";
}

function getLogs($lines = 50) {
    global $logFile;
    
    if (!file_exists($logFile)) {
        return "Arquivo de log não encontrado.";
    }
    
    // Ler últimas linhas
    $logs = `tail -n $lines $logFile 2>/dev/null`;
    if (empty($logs)) {
        return "Log vazio ou não acessível.";
    }
    
    return htmlspecialchars($logs);
}

function getProcesses() {
    // Mostrar processos Node.js
    $processes = `ps aux | grep -E "(node|whatsapp)" | grep -v grep 2>/dev/null`;
    if (empty($processes)) {
        return "Nenhum processo encontrado.";
    }
    
    return htmlspecialchars($processes);
}

function isProcessRunning($pid) {
    if (empty($pid)) {
        return false;
    }
    
    // Verificar se processo existe
    exec("ps -p " . escapeshellarg($pid) . " 2>/dev/null", $output, $returnCode);
    return $returnCode === 0;
}

function isNodeRunning() {
    // Verificar se há processos Node.js rodando
    exec("pgrep -f whatsapp-server.js 2>/dev/null", $output, $returnCode);
    return !empty($output);
}
?>