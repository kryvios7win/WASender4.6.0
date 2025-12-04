<?php
// node-manager.php
require_once 'config.php';

// Verificar se é admin (exemplo simples, ajuste conforme sua lógica de autenticação)
if (!isset($_SESSION['wasender_user_id']) || $_SESSION['wasender_user_id'] !== 'admin') {
    die('Acesso restrito a administradores');
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciador Node.js - WASender</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #25D366;
            padding-bottom: 10px;
        }
        .btn {
            padding: 10px 20px;
            margin: 5px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-start {
            background: #25D366;
            color: white;
        }
        .btn-stop {
            background: #dc3545;
            color: white;
        }
        .btn-restart {
            background: #ffc107;
            color: black;
        }
        .btn-logs {
            background: #17a2b8;
            color: white;
        }
        .status {
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
        }
        .status-running {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status-stopped {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .log-container {
            background: #333;
            color: #0f0;
            padding: 15px;
            font-family: 'Courier New', monospace;
            height: 400px;
            overflow-y: auto;
            border-radius: 5px;
            margin-top: 20px;
        }
        .process-container {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }
        .section-title {
            margin-top: 30px;
            color: #555;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-server"></i> Gerenciador do Servidor Node.js</h1>
        
        <div id="controlPanel" style="margin-bottom: 20px;">
            <button class="btn btn-start" onclick="manageNode('start')">
                <i class="fas fa-play"></i> Iniciar Servidor
            </button>
            <button class="btn btn-stop" onclick="manageNode('stop')">
                <i class="fas fa-stop"></i> Parar Servidor
            </button>
            <button class="btn btn-restart" onclick="manageNode('restart')">
                <i class="fas fa-redo"></i> Reiniciar Servidor
            </button>
            <button class="btn btn-logs" onclick="refreshLogs()">
                <i class="fas fa-sync-alt"></i> Atualizar Logs
            </button>
        </div>
        
        <div id="nodeStatus" class="status">
            Carregando status do servidor...
        </div>
        
        <h3 class="section-title"><i class="fas fa-info-circle"></i> Status do Servidor</h3>
        <div id="nodeProcesses" class="process-container">
            Carregando informações de processos...
        </div>
        
        <h3 class="section-title"><i class="fas fa-file-alt"></i> Logs do Servidor Node.js</h3>
        <div id="nodeLogs" class="log-container">
            Carregando logs...
        </div>
        
        <h3 class="section-title"><i class="fas fa-cogs"></i> Configurações Rápidas</h3>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px;">
            <p><strong>Porta do Servidor:</strong> <span id="serverPort">3000</span></p>
            <p><strong>Host:</strong> <span id="serverHost">localhost</span></p>
            <p><strong>Diretório:</strong> <code id="serverDir"><?php echo __DIR__; ?>/node</code></p>
            <p><strong>Comando de inicialização:</strong> <code>node whatsapp-server.js</code></p>
        </div>
    </div>
    
    <script>
    // URL base para as APIs
    const baseUrl = window.location.origin + window.location.pathname.replace('node-manager.php', '');
    
    async function manageNode(action) {
        if (!confirm(`Tem certeza que deseja ${action} o servidor Node.js?`)) {
            return;
        }
        
        try {
            const response = await fetch(`${baseUrl}node-api.php?action=${action}`);
            const result = await response.text();
            alert(result);
            loadStatus();
            loadProcesses();
            refreshLogs();
        } catch (error) {
            alert('Erro ao executar ação: ' + error.message);
        }
    }
    
    async function loadStatus() {
        try {
            const response = await fetch(`${baseUrl}node-api.php?action=status`);
            const html = await response.text();
            document.getElementById('nodeStatus').innerHTML = html;
        } catch (error) {
            document.getElementById('nodeStatus').innerHTML = 'Erro ao carregar status: ' + error.message;
        }
    }
    
    async function loadProcesses() {
        try {
            const response = await fetch(`${baseUrl}node-api.php?action=processes`);
            const text = await response.text();
            document.getElementById('nodeProcesses').innerHTML = text || 'Nenhum processo encontrado.';
        } catch (error) {
            document.getElementById('nodeProcesses').innerHTML = 'Erro ao carregar processos: ' + error.message;
        }
    }
    
    async function refreshLogs() {
        try {
            const response = await fetch(`${baseUrl}node-api.php?action=logs&lines=100`);
            const logs = await response.text();
            document.getElementById('nodeLogs').innerHTML = logs || 'Nenhum log disponível.';
            // Rolagem automática para o final
            const logContainer = document.getElementById('nodeLogs');
            logContainer.scrollTop = logContainer.scrollHeight;
        } catch (error) {
            document.getElementById('nodeLogs').innerHTML = 'Erro ao carregar logs: ' + error.message;
        }
    }
    
    // Atualizar a cada 10 segundos
    setInterval(() => {
        loadStatus();
        loadProcesses();
    }, 10000);
    
    // Carregar inicialmente
    loadStatus();
    loadProcesses();
    refreshLogs();
    </script>
</body>
</html>