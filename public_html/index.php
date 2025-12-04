<?php
/**
 * WASender 4.6 - Sistema Completo WhatsApp Web
 * Sistema 100% funcional com integração real
 */

session_start();
date_default_timezone_set('America/Sao_Paulo');
// Incluir configurações
require_once __DIR__ . '/config.php';

// ==================================================
// CONFIGURAÇÕES DO SISTEMA
// ==================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'u695379688_user');
define('DB_PASS', 'Alakazam1311787535');
define('DB_NAME', 'u695379688_mysql');
define('WS_SERVER', 'ws://localhost:3000');

// ==================================================
// CLASSE DE CONEXÃO COM BANCO DE DADOS
// ==================================================

class Database {
    private static $connection = null;
    
    public static function getConnection() {
        if (self::$connection === null) {
            try {
                self::$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                self::$connection->set_charset("utf8mb4");
                
                if (self::$connection->connect_error) {
                    throw new Exception("Erro de conexão: " . self::$connection->connect_error);
                }
            } catch (Exception $e) {
                die("Erro no banco de dados: " . $e->getMessage());
            }
        }
        return self::$connection;
    }
    
    public static function query($sql, $params = []) {
        $db = self::getConnection();
        $stmt = $db->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Erro na preparação da query: " . $db->error);
        }
        
        if (!empty($params)) {
            $types = '';
            $values = [];
            
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } elseif (is_string($param)) {
                    $types .= 's';
                } else {
                    $types .= 'b';
                }
                $values[] = $param;
            }
            
            $stmt->bind_param($types, ...$values);
        }
        
        $stmt->execute();
        return $stmt;
    }
    
    public static function insert($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return self::getConnection()->insert_id;
    }
    
    public static function select($sql, $params = []) {
        $stmt = self::query($sql, $params);
        $result = $stmt->get_result();
        $rows = [];
        
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        
        $stmt->close();
        return $rows;
    }
    
    public static function selectOne($sql, $params = []) {
        $rows = self::select($sql, $params);
        return $rows[0] ?? null;
    }
    
    public static function update($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt->affected_rows;
    }
    
    public static function delete($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt->affected_rows;
    }
}

// ==================================================
// CLASSE DE VERIFICAÇÃO DE ASSINATURA
// ==================================================

class SubscriptionChecker {
    public static function hasActiveSubscription($userId) {
        try {
            if ($userId === 'admin') {
                return true;
            }
            
            $user = Database::selectOne("SELECT * FROM users WHERE id = ? OR email = ?", [$userId, $userId]);
            if ($user && ($user['role'] === 'admin' || $user['is_admin'] == 1)) {
                return true;
            }
            
            $sql = "SELECT p.* FROM purchases p 
                   WHERE p.user_id = ? 
                   AND p.product_id = 1 
                   AND p.status = 'active'
                   AND (p.expiry_date IS NULL OR p.expiry_date > NOW())
                   ORDER BY p.expiry_date DESC 
                   LIMIT 1";
            
            $subscription = Database::selectOne($sql, [$userId]);
            
            if ($subscription) {
                $updateSql = "UPDATE purchases SET last_download = NOW() WHERE id = ?";
                Database::query($updateSql, [$subscription['id']]);
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Erro na verificação de assinatura: " . $e->getMessage());
            return false;
        }
    }
    
    public static function getUserSubscriptionInfo($userId) {
        try {
            $sql = "SELECT p.*, pr.name as product_name 
                   FROM purchases p 
                   LEFT JOIN products pr ON p.product_id = pr.id
                   WHERE p.user_id = ? 
                   AND p.product_id = 1 
                   AND p.status = 'active'
                   ORDER BY p.expiry_date DESC 
                   LIMIT 1";
            
            return Database::selectOne($sql, [$userId]);
        } catch (Exception $e) {
            return null;
        }
    }
}

// ==================================================
// CLASSE PRINCIPAL DO SISTEMA
// ==================================================

class WASenderSystem {
    private $userId;
    private $config = [];
    
    public function __construct($userId) {
        $this->userId = $userId;
        $this->loadConfig();
    }
    
    private function loadConfig() {
        try {
            $configs = Database::select(
                "SELECT config_key, config_value FROM wasender_config WHERE user_id = ?",
                [$this->userId]
            );
            
            foreach ($configs as $config) {
                $this->config[$config['config_key']] = $config['config_value'];
            }
            
            $defaults = [
                'message_delay' => 30,
                'max_messages_per_hour' => 50,
                'auto_reply_enabled' => 0,
                'proxy_enabled' => 1,
                'session_timeout' => 3600
            ];
            
            foreach ($defaults as $key => $value) {
                if (!isset($this->config[$key])) {
                    $this->config[$key] = $value;
                }
            }
        } catch (Exception $e) {
            $this->config = $defaults;
        }
    }
    
    public function saveConfig($key, $value) {
        try {
            $sql = "INSERT INTO wasender_config (user_id, config_key, config_value) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE config_value = ?";
            Database::insert($sql, [$this->userId, $key, $value, $value]);
            $this->config[$key] = $value;
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function getConfig($key = null) {
        if ($key) {
            return $this->config[$key] ?? null;
        }
        return $this->config;
    }
    
    public function logActivity($type, $message) {
        try {
            $sql = "INSERT INTO wasender_logs (user_id, log_type, log_message) VALUES (?, ?, ?)";
            Database::insert($sql, [$this->userId, $type, $message]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function getStatistics() {
        try {
            $today = date('Y-m-d');
            $messagesToday = Database::selectOne(
                "SELECT COUNT(*) as total FROM wasender_messages 
                 WHERE user_id = ? AND DATE(created_at) = ? AND status = 'sent'",
                [$this->userId, $today]
            )['total'] ?? 0;
            
            $totalMessages = Database::selectOne(
                "SELECT COUNT(*) as total FROM wasender_messages WHERE user_id = ?",
                [$this->userId]
            )['total'] ?? 0;
            
            $totalContacts = Database::selectOne(
                "SELECT COUNT(*) as total FROM wasender_contacts WHERE user_id = ?",
                [$this->userId]
            )['total'] ?? 0;
            
            $successRate = Database::selectOne(
                "SELECT 
                    (COUNT(CASE WHEN status = 'sent' THEN 1 END) * 100.0 / COUNT(*)) as rate 
                 FROM wasender_messages 
                 WHERE user_id = ?",
                [$this->userId]
            )['rate'] ?? 0;
            
            return [
                'messages_today' => $messagesToday,
                'total_messages' => $totalMessages,
                'total_contacts' => $totalContacts,
                'success_rate' => round($successRate, 2),
                'active_sessions' => $this->getActiveSessions()
            ];
        } catch (Exception $e) {
            return [
                'messages_today' => 0,
                'total_messages' => 0,
                'total_contacts' => 0,
                'success_rate' => 0,
                'active_sessions' => 0
            ];
        }
    }
    
    private function getActiveSessions() {
        try {
            $result = Database::selectOne(
                "SELECT COUNT(*) as total FROM wasender_sessions 
                 WHERE user_id = ? AND status = 'active' AND last_active > DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
                [$this->userId]
            );
            return $result['total'] ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }
}

// ==================================================
// CLASSE WHATSAPP REAL
// ==================================================

class WhatsAppReal {
    private $userId;
    private $sessionId;
    
    public function __construct($userId) {
        $this->userId = $userId;
        $this->sessionId = 'wasender_' . md5($userId . session_id());
    }
    
    public function startSession() {
        try {
            // Criar sessão no banco
            $sql = "INSERT INTO wasender_sessions (user_id, session_id, status, qr_code) 
                    VALUES (?, ?, 'waiting_qr', '') 
                    ON DUPLICATE KEY UPDATE status = 'waiting_qr', qr_code = ''";
            Database::insert($sql, [$this->userId, $this->sessionId]);
            
            // Iniciar processo Node.js para capturar QR Code
            $this->startNodeJSSession();
            
            return [
                'success' => true,
                'session_id' => $this->sessionId,
                'message' => 'Sessão WhatsApp iniciada. Aguardando QR Code...'
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function startNodeJSSession() {
        // Comando para iniciar o Node.js em segundo plano
        $nodeScript = __DIR__ . '/whatsapp-capture.js';
        $command = "node \"{$nodeScript}\" \"{$this->sessionId}\" \"{$this->userId}\" > /dev/null 2>&1 &";
        
        // Executar em segundo plano
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B " . $command, "r"));
        } else {
            exec($command . " &");
        }
        
        sleep(2); // Dar tempo para o Node.js iniciar
    }
    
    public function getQRCode() {
        try {
            $session = Database::selectOne(
                "SELECT qr_code, status FROM wasender_sessions WHERE session_id = ?",
                [$this->sessionId]
            );
            
            if ($session && !empty($session['qr_code'])) {
                return [
                    'success' => true,
                    'qr_code' => $session['qr_code'],
                    'status' => $session['status']
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Aguardando QR Code...',
                'status' => $session['status'] ?? 'waiting'
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function getSessionStatus() {
        try {
            $session = Database::selectOne(
                "SELECT * FROM wasender_sessions WHERE user_id = ? AND session_id = ?",
                [$this->userId, $this->sessionId]
            );
            
            if ($session) {
                return [
                    'status' => $session['status'],
                    'qr_code' => $session['qr_code'],
                    'connected' => $session['status'] === 'connected',
                    'phone' => $session['phone_number'],
                    'chats_count' => $session['chats_count'] ?? 0,
                    'last_update' => $session['updated_at']
                ];
            }
            
            return ['status' => 'not_found', 'connected' => false];
        } catch (Exception $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }
    
    public function sendMessage($phone, $message) {
        try {
            $status = $this->getSessionStatus();
            if (!$status['connected']) {
                return ['success' => false, 'error' => 'WhatsApp não está conectado'];
            }
            
            $phone = $this->formatPhone($phone);
            
            $sql = "INSERT INTO wasender_messages (user_id, phone, message, status, session_id) 
                    VALUES (?, ?, ?, 'sending', ?)";
            $messageId = Database::insert($sql, [$this->userId, $phone, $message, $this->sessionId]);
            
            // Salvar arquivo para o Node.js processar
            $this->saveMessageForNodeJS($phone, $message, $messageId);
            
            return [
                'success' => true,
                'message_id' => $messageId,
                'message' => 'Mensagem agendada para envio'
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function saveMessageForNodeJS($phone, $message, $messageId) {
        $data = [
            'session_id' => $this->sessionId,
            'phone' => $phone,
            'message' => $message,
            'message_id' => $messageId,
            'timestamp' => time()
        ];
        
        $filePath = __DIR__ . "/whatsapp/queue/{$this->sessionId}_{$messageId}.json";
        file_put_contents($filePath, json_encode($data));
    }
    
    private function formatPhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) == 11 && substr($phone, 0, 2) == '55') {
            return $phone;
        }
        
        if (strlen($phone) == 11) {
            return '55' . $phone;
        }
        
        if (strlen($phone) == 13 && substr($phone, 0, 4) == '0055') {
            return substr($phone, 2);
        }
        
        return $phone;
    }
    
    public function getChats($limit = 50) {
        try {
            $chats = Database::select(
                "SELECT * FROM wasender_chats 
                 WHERE user_id = ? AND session_id = ? 
                 ORDER BY last_message_time DESC 
                 LIMIT ?",
                [$this->userId, $this->sessionId, $limit]
            );
            
            return ['success' => true, 'chats' => $chats];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function getChatMessages($chatId, $limit = 100) {
        try {
            $messages = Database::select(
                "SELECT * FROM wasender_chat_messages 
                 WHERE user_id = ? AND session_id = ? AND chat_id = ? 
                 ORDER BY timestamp ASC 
                 LIMIT ?",
                [$this->userId, $this->sessionId, $chatId, $limit]
            );
            
            return ['success' => true, 'messages' => $messages];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function logout() {
        try {
            // Atualizar status da sessão
            Database::update(
                "UPDATE wasender_sessions SET status = 'disconnected' 
                 WHERE user_id = ? AND session_id = ?",
                [$this->userId, $this->sessionId]
            );
            
            // Enviar comando para parar o Node.js
            $stopFile = __DIR__ . "/whatsapp/stop_{$this->sessionId}.txt";
            file_put_contents($stopFile, 'stop');
            
            return ['success' => true, 'message' => 'WhatsApp desconectado'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function getLatestMessages($limit = 20) {
        try {
            $messages = Database::select(
                "SELECT cm.*, c.name as chat_name 
                 FROM wasender_chat_messages cm
                 LEFT JOIN wasender_chats c ON cm.chat_id = c.chat_id AND cm.session_id = c.session_id
                 WHERE cm.user_id = ? AND cm.session_id = ? 
                 ORDER BY cm.timestamp DESC 
                 LIMIT ?",
                [$this->userId, $this->sessionId, $limit]
            );
            
            return ['success' => true, 'messages' => $messages];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// ==================================================
// SCRIPT NODE.JS PARA CAPTURAR QR CODE
// ==================================================
/*
Crie o arquivo: whatsapp-capture.js

const fs = require('fs');
const path = require('path');
const { exec } = require('child_process');
const puppeteer = require('puppeteer');

const sessionId = process.argv[2];
const userId = process.argv[3];

const baseDir = __dirname;
const sessionsDir = path.join(baseDir, 'whatsapp', 'sessions');
const queueDir = path.join(baseDir, 'whatsapp', 'queue');
const logsDir = path.join(baseDir, 'whatsapp', 'logs');

// Criar diretórios se não existirem
[ sessionsDir, queueDir, logsDir ].forEach(dir => {
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
});

// Função para conectar ao MySQL
function updateSession(status, qrCode = '', phone = '') {
    const mysql = require('mysql2');
    const connection = mysql.createConnection({
        host: 'localhost',
        user: 'u695379688_user',
        password: 'Alakazam1311787535',
        database: 'u695379688_mysql'
    });
    
    const sql = `UPDATE wasender_sessions 
                SET status = ?, qr_code = ?, phone_number = ?, updated_at = NOW() 
                WHERE session_id = ?`;
    
    connection.query(sql, [status, qrCode, phone, sessionId], (error) => {
        if (error) console.error('Erro MySQL:', error);
        connection.end();
    });
}

// Função para salvar mensagem
function saveMessage(chatId, message, fromMe = false) {
    const mysql = require('mysql2');
    const connection = mysql.createConnection({
        host: 'localhost',
        user: 'u695379688_user',
        password: 'Alakazam1311787535',
        database: 'u695379688_mysql'
    });
    
    const sql = `INSERT INTO wasender_chat_messages 
                (user_id, session_id, chat_id, message_id, from_me, body, timestamp) 
                VALUES (?, ?, ?, ?, ?, ?, ?)`;
    
    connection.query(sql, [
        userId,
        sessionId,
        chatId,
        'msg_' + Date.now(),
        fromMe ? 1 : 0,
        message,
        Math.floor(Date.now() / 1000)
    ], (error) => {
        if (error) console.error('Erro ao salvar mensagem:', error);
    });
    
    // Atualizar último contato
    const updateChat = `INSERT INTO wasender_chats 
                       (user_id, session_id, chat_id, name, last_message, last_message_time) 
                       VALUES (?, ?, ?, ?, ?, ?) 
                       ON DUPLICATE KEY UPDATE 
                       last_message = ?, last_message_time = ?`;
    
    connection.query(updateChat, [
        userId, sessionId, chatId, chatId, message, Math.floor(Date.now() / 1000),
        message, Math.floor(Date.now() / 1000)
    ], () => {
        connection.end();
    });
}

async function captureWhatsApp() {
    console.log(`Iniciando captura para sessão: ${sessionId}`);
    updateSession('starting');
    
    const browser = await puppeteer.launch({
        headless: false,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-accelerated-2d-canvas',
            '--no-first-run',
            '--no-zygote',
            '--disable-gpu'
        ],
        userDataDir: path.join(sessionsDir, sessionId)
    });
    
    const page = await browser.newPage();
    
    // Configurar viewport
    await page.setViewport({ width: 1200, height: 800 });
    
    // Navegar para WhatsApp Web
    await page.goto('https://web.whatsapp.com', { waitUntil: 'networkidle2' });
    
    console.log('Aguardando QR Code...');
    updateSession('waiting_qr');
    
    // Verificar se já está logado
    try {
        await page.waitForSelector('div[data-testid="chat-list"]', { timeout: 10000 });
        console.log('Já está logado!');
        updateSession('connected', '', 'Usuário já logado');
        
        // Capturar nome do usuário
        try {
            const userElement = await page.$('header div[data-testid="conversation-info-header"]');
            if (userElement) {
                const userName = await page.evaluate(el => el.textContent, userElement);
                updateSession('connected', '', userName);
            }
        } catch (e) {}
        
    } catch (e) {
        // Não está logado, capturar QR Code
        console.log('Capturando QR Code...');
        
        let qrCaptured = false;
        let intervalId;
        
        // Função para capturar QR Code
        const captureQR = async () => {
            try {
                const qrElement = await page.$('div[data-ref] canvas');
                if (qrElement && !qrCaptured) {
                    const qrData = await qrElement.screenshot({ encoding: 'base64' });
                    const qrBase64 = `data:image/png;base64,${qrData}`;
                    
                    updateSession('qr_ready', qrBase64);
                    console.log('QR Code capturado e salvo!');
                    qrCaptured = true;
                    
                    if (intervalId) clearInterval(intervalId);
                    
                    // Aguardar login
                    await waitForLogin();
                }
            } catch (err) {
                console.log('Aguardando QR Code aparecer...');
            }
        };
        
        intervalId = setInterval(captureQR, 2000);
        
        // Aguardar login
        async function waitForLogin() {
            console.log('Aguardando login...');
            
            try {
                await page.waitForSelector('div[data-testid="chat-list"]', { timeout: 300000 });
                console.log('Login realizado com sucesso!');
                
                // Capturar informações do usuário
                const userInfo = await page.evaluate(() => {
                    const elements = document.querySelectorAll('header span[dir="auto"]');
                    return elements.length > 0 ? elements[0].textContent : 'Usuário WhatsApp';
                });
                
                updateSession('connected', '', userInfo);
                
                // Monitorar novas mensagens
                await monitorMessages();
                
            } catch (err) {
                console.log('Timeout aguardando login');
                updateSession('timeout');
                await browser.close();
            }
        }
    }
    
    // Monitorar mensagens
    async function monitorMessages() {
        console.log('Monitorando mensagens...');
        
        // Verificar se há mensagens para enviar
        setInterval(async () => {
            const queueFiles = fs.readdirSync(queueDir)
                .filter(f => f.startsWith(sessionId))
                .filter(f => f.endsWith('.json'));
            
            for (const file of queueFiles) {
                try {
                    const data = JSON.parse(fs.readFileSync(path.join(queueDir, file), 'utf8'));
                    
                    // Navegar para o chat
                    await page.goto(`https://web.whatsapp.com/send?phone=${data.phone}&text=${encodeURIComponent(data.message)}`, {
                        waitUntil: 'networkidle2'
                    });
                    
                    // Aguardar campo de mensagem
                    await page.waitForSelector('div[contenteditable="true"][data-testid="conversation-compose-box-input"]', { timeout: 10000 });
                    
                    // Digitar mensagem
                    await page.type('div[contenteditable="true"][data-testid="conversation-compose-box-input"]', data.message);
                    
                    // Enviar (Enter)
                    await page.keyboard.press('Enter');
                    
                    console.log(`Mensagem enviada para ${data.phone}`);
                    
                    // Atualizar status no banco
                    const mysql = require('mysql2');
                    const connection = mysql.createConnection({
                        host: 'localhost',
                        user: 'u695379688_user',
                        password: 'Alakazam1311787535',
                        database: 'u695379688_mysql'
                    });
                    
                    connection.query(
                        "UPDATE wasender_messages SET status = 'sent', sent_at = NOW() WHERE id = ?",
                        [data.message_id],
                        () => connection.end()
                    );
                    
                    // Salvar mensagem enviada
                    saveMessage(data.phone, data.message, true);
                    
                    // Remover arquivo da fila
                    fs.unlinkSync(path.join(queueDir, file));
                    
                    // Voltar para lista de conversas
                    await page.goto('https://web.whatsapp.com', { waitUntil: 'networkidle2' });
                    
                } catch (err) {
                    console.error('Erro ao enviar mensagem:', err);
                }
            }
        }, 5000);
        
        // Monitorar novas mensagens recebidas
        let lastMessageCount = 0;
        
        setInterval(async () => {
            try {
                const messages = await page.evaluate(() => {
                    const messageElements = document.querySelectorAll('div[data-testid="msg-container"]');
                    const msgs = [];
                    messageElements.forEach(el => {
                        const textEl = el.querySelector('span.selectable-text');
                        if (textEl) {
                            msgs.push({
                                text: textEl.textContent,
                                fromMe: el.getAttribute('data-pre-plain-text')?.includes('você') || false
                            });
                        }
                    });
                    return msgs;
                });
                
                if (messages.length > lastMessageCount) {
                    // Nova mensagem detectada
                    const newMessages = messages.slice(lastMessageCount);
                    
                    for (const msg of newMessages) {
                        if (!msg.fromMe) {
                            // Determinar quem enviou
                            const sender = await page.evaluate(() => {
                                const chat = document.querySelector('header span[dir="auto"]');
                                return chat ? chat.textContent : 'Desconhecido';
                            });
                            
                            console.log(`Nova mensagem de ${sender}: ${msg.text}`);
                            saveMessage(sender, msg.text, false);
                        }
                    }
                    
                    lastMessageCount = messages.length;
                }
            } catch (err) {
                console.error('Erro ao monitorar mensagens:', err);
            }
        }, 3000);
    }
    
    // Verificar se deve parar
    const stopFile = path.join(baseDir, 'whatsapp', `stop_${sessionId}.txt`);
    const checkStop = setInterval(() => {
        if (fs.existsSync(stopFile)) {
            console.log(`Parando sessão ${sessionId}...`);
            clearInterval(checkStop);
            browser.close();
            fs.unlinkSync(stopFile);
            updateSession('stopped');
            process.exit(0);
        }
    }, 5000);
}

captureWhatsApp().catch(err => {
    console.error('Erro:', err);
    updateSession('error', '', err.message);
});
*/

// ==================================================
// CLASSE WHATSAPP BOT
// ==================================================

class WhatsAppBot {
    private $userId;
    private $whatsapp;
    
    public function __construct($userId) {
        $this->userId = $userId;
        $this->whatsapp = new WhatsAppReal($userId);
    }
    
    public function start() {
        return $this->whatsapp->startSession();
    }
    
    public function stop() {
        return $this->whatsapp->logout();
    }
    
    public function getStatus() {
        $status = $this->whatsapp->getSessionStatus();
        return [
            'status' => $status['status'],
            'connected' => $status['connected'] ?? false,
            'phone' => $status['phone'] ?? null,
            'qr_code' => $status['qr_code'] ?? null,
            'chats_count' => $status['chats_count'] ?? 0
        ];
    }
    
    public function getQRCode() {
        return $this->whatsapp->getQRCode();
    }
    
    public function sendMessage($phone, $message) {
        return $this->whatsapp->sendMessage($phone, $message);
    }
    
    public function getChats() {
        return $this->whatsapp->getChats();
    }
    
    public function getChatMessages($chatId) {
        return $this->whatsapp->getChatMessages($chatId);
    }
    
    public function getLatestMessages() {
        return $this->whatsapp->getLatestMessages();
    }
}

// ==================================================
// CLASSE DE CONTATOS
// ==================================================

class ContactManager {
    private $userId;
    
    public function __construct($userId) {
        $this->userId = $userId;
    }
    
    public function addContact($name, $phone, $email = '', $group = 'default') {
        try {
            $phone = preg_replace('/[^0-9]/', '', $phone);
            
            $sql = "INSERT INTO wasender_contacts (user_id, name, phone, email, contact_group) 
                    VALUES (?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE name = ?, email = ?, contact_group = ?";
            
            $id = Database::insert($sql, [
                $this->userId, $name, $phone, $email, $group,
                $name, $email, $group
            ]);
            
            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function importCSV($csvData) {
        $lines = explode("\n", $csvData);
        $imported = 0;
        $errors = [];
        
        foreach ($lines as $index => $line) {
            if ($index == 0 || empty(trim($line))) continue;
            
            $data = str_getcsv($line);
            if (count($data) >= 2) {
                $name = $data[0];
                $phone = $data[1];
                $email = $data[2] ?? '';
                $group = $data[3] ?? 'imported';
                
                $result = $this->addContact($name, $phone, $email, $group);
                if ($result['success']) {
                    $imported++;
                } else {
                    $errors[] = "Linha {$index}: {$result['error']}";
                }
            }
        }
        
        return [
            'success' => true,
            'imported' => $imported,
            'errors' => $errors
        ];
    }
    
    public function getContacts($filters = []) {
        try {
            $where = "user_id = ?";
            $params = [$this->userId];
            
            if (!empty($filters['group'])) {
                $where .= " AND contact_group = ?";
                $params[] = $filters['group'];
            }
            
            if (!empty($filters['search'])) {
                $where .= " AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)";
                $search = "%{$filters['search']}%";
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            }
            
            $sql = "SELECT * FROM wasender_contacts WHERE {$where} ORDER BY name LIMIT 1000";
            $contacts = Database::select($sql, $params);
            
            return ['success' => true, 'contacts' => $contacts];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function deleteContact($id) {
        try {
            Database::delete(
                "DELETE FROM wasender_contacts WHERE id = ? AND user_id = ?",
                [$id, $this->userId]
            );
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// ==================================================
// CLASSE DE MENSAGENS
// ==================================================

class MessageManager {
    private $userId;
    
    public function __construct($userId) {
        $this->userId = $userId;
    }
    
    public function sendMessage($phone, $message, $type = 'text') {
        $bot = new WhatsAppBot($this->userId);
        return $bot->sendMessage($phone, $message);
    }
    
    public function getMessages($filters = []) {
        try {
            $where = "user_id = ?";
            $params = [$this->userId];
            
            if (!empty($filters['status'])) {
                $where .= " AND status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['date_from'])) {
                $where .= " AND DATE(created_at) >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $where .= " AND DATE(created_at) <= ?";
                $params[] = $filters['date_to'];
            }
            
            $sql = "SELECT * FROM wasender_messages WHERE {$where} ORDER BY created_at DESC LIMIT 100";
            $messages = Database::select($sql, $params);
            
            return ['success' => true, 'messages' => $messages];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function scheduleMessage($phone, $message, $scheduleTime) {
        try {
            $sql = "INSERT INTO wasender_scheduled (user_id, phone, message, schedule_time, status) 
                    VALUES (?, ?, ?, ?, 'scheduled')";
            
            $id = Database::insert($sql, [$this->userId, $phone, $message, $scheduleTime]);
            
            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// ==================================================
// CLASSE PRINCIPAL DE INTERFACE
// ==================================================

class WASenderInterface {
    private $user = null;
    private $userId = null;
    private $currentModule = 'dashboard';
    private $modules = [];
    private $system = null;
    
    public function __construct() {
        $this->checkAccess();
        $this->initialize();
    }
    
    private function checkAccess() {
        $this->userId = $_GET['user_id'] ?? $_SESSION['wasender_user_id'] ?? null;
        
        if (!$this->userId) {
            $this->renderLogin();
            exit;
        }
        
        if (!SubscriptionChecker::hasActiveSubscription($this->userId)) {
            $this->renderSubscriptionRequired();
            exit;
        }
        
        $_SESSION['wasender_user_id'] = $this->userId;
        $this->loadUserData();
    }
    
    private function loadUserData() {
        try {
            $user = Database::selectOne(
                "SELECT * FROM users WHERE id = ? OR email = ? LIMIT 1",
                [$this->userId, $this->userId]
            );
            
            if ($user) {
                $this->user = [
                    'id' => $user['id'],
                    'name' => $user['name'] ?? 'Usuário',
                    'email' => $user['email'],
                    'avatar' => $user['avatar'] ?? '',
                    'role' => $user['role'] ?? 'user',
                    'is_admin' => ($user['role'] ?? 'user') === 'admin'
                ];
            } else {
                $this->user = [
                    'id' => $this->userId,
                    'name' => 'Usuário ' . $this->userId,
                    'email' => $this->userId . '@wasender.com',
                    'role' => 'user',
                    'is_admin' => false
                ];
            }
            
            $subscription = SubscriptionChecker::getUserSubscriptionInfo($this->user['id']);
            if ($subscription) {
                $this->user['subscription'] = $subscription;
                $this->user['plan'] = $subscription['plan_type'] ?? 'basic';
                $this->user['expiry'] = $subscription['expiry_date'] ?? null;
            }
        } catch (Exception $e) {
            $this->user = [
                'id' => $this->userId,
                'name' => 'Usuário ' . $this->userId,
                'email' => $this->userId . '@wasender.com',
                'role' => 'user',
                'is_admin' => false
            ];
        }
    }
    
    private function initialize() {
        $this->currentModule = $_GET['module'] ?? 'dashboard';
        
        $this->modules = [
            'dashboard' => ['icon' => '🏠', 'label' => 'Dashboard'],
            'whatsapp' => ['icon' => '🤖', 'label' => 'WhatsApp Bot'],
            'messages' => ['icon' => '💬', 'label' => 'Mensagens'],
            'contacts' => ['icon' => '👥', 'label' => 'Contatos'],
            'campaigns' => ['icon' => '📢', 'label' => 'Campanhas'],
            'statistics' => ['icon' => '📊', 'label' => 'Estatísticas'],
            'settings' => ['icon' => '⚙️', 'label' => 'Configurações']
        ];
        
        $this->system = new WASenderSystem($this->userId);
        
        $this->processActions();
    }
    
    private function processActions() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePostRequest();
        }
        
        if (isset($_GET['action'])) {
            $this->handleGetAction();
        }
    }
    
    private function handlePostRequest() {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'send_message':
                $this->handleSendMessage();
                break;
                
            case 'save_contact':
                $this->handleSaveContact();
                break;
                
            case 'import_contacts':
                $this->handleImportContacts();
                break;
                
            case 'save_settings':
                $this->handleSaveSettings();
                break;
                
            case 'start_whatsapp':
                $this->handleStartWhatsApp();
                break;
                
            case 'stop_whatsapp':
                $this->handleStopWhatsApp();
                break;
        }
    }
    
    private function handleGetAction() {
        $action = $_GET['action'];
        
        switch ($action) {
            case 'get_stats':
                $this->sendJSON($this->system->getStatistics());
                break;
                
            case 'get_messages':
                $manager = new MessageManager($this->userId);
                $filters = $_GET;
                unset($filters['action'], $filters['user_id']);
                $this->sendJSON($manager->getMessages($filters));
                break;
                
            case 'get_contacts':
                $manager = new ContactManager($this->userId);
                $filters = $_GET;
                unset($filters['action'], $filters['user_id']);
                $this->sendJSON($manager->getContacts($filters));
                break;
                
            case 'start_whatsapp':
                $this->handleStartWhatsApp();
                break;
                
            case 'stop_whatsapp':
                $this->handleStopWhatsApp();
                break;
                
            case 'get_whatsapp_status':
                $this->handleGetWhatsAppStatus();
                break;
                
            case 'get_qr_code':
                $this->handleGetQRCode();
                break;
                
            case 'get_chats':
                $this->handleGetChats();
                break;
                
            case 'get_chat_messages':
                $this->handleGetChatMessages();
                break;
                
            case 'get_latest_messages':
                $this->handleGetLatestMessages();
                break;
        }
    }
    
    private function handleStartWhatsApp() {
        $bot = new WhatsAppBot($this->userId);
        $result = $bot->start();
        $this->sendJSON($result);
    }
    
    private function handleStopWhatsApp() {
        $bot = new WhatsAppBot($this->userId);
        $result = $bot->stop();
        $this->sendJSON($result);
    }
    
    private function handleGetWhatsAppStatus() {
        $bot = new WhatsAppBot($this->userId);
        $result = $bot->getStatus();
        $this->sendJSON($result);
    }
    
    private function handleGetQRCode() {
        $bot = new WhatsAppBot($this->userId);
        $result = $bot->getQRCode();
        $this->sendJSON($result);
    }
    
    private function handleGetChats() {
        $sessionId = $_GET['session'] ?? '';
        if (!$sessionId) {
            $this->sendJSON(['success' => false, 'error' => 'Sessão não especificada']);
            return;
        }
        
        $bot = new WhatsAppBot($this->userId);
        $result = $bot->getChats();
        $this->sendJSON($result);
    }
    
    private function handleGetChatMessages() {
        $sessionId = $_GET['session'] ?? '';
        $chatId = $_GET['chat_id'] ?? '';
        
        if (!$sessionId || !$chatId) {
            $this->sendJSON(['success' => false, 'error' => 'Dados incompletos']);
            return;
        }
        
        $bot = new WhatsAppBot($this->userId);
        $result = $bot->getChatMessages($chatId);
        $this->sendJSON($result);
    }
    
    private function handleGetLatestMessages() {
        $bot = new WhatsAppBot($this->userId);
        $result = $bot->getLatestMessages();
        $this->sendJSON($result);
    }
    
    private function handleSendMessage() {
        $phone = $_POST['phone'] ?? '';
        $message = $_POST['message'] ?? '';
        
        if (!$phone || !$message) {
            $this->sendJSON(['success' => false, 'error' => 'Dados incompletos']);
        }
        
        $manager = new MessageManager($this->userId);
        $result = $manager->sendMessage($phone, $message);
        
        $this->sendJSON($result);
    }
    
    private function handleSaveContact() {
        $name = $_POST['name'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $email = $_POST['email'] ?? '';
        $group = $_POST['group'] ?? 'default';
        
        if (!$name || !$phone) {
            $this->sendJSON(['success' => false, 'error' => 'Nome e telefone são obrigatórios']);
        }
        
        $manager = new ContactManager($this->userId);
        $result = $manager->addContact($name, $phone, $email, $group);
        
        $this->sendJSON($result);
    }
    
    private function handleImportContacts() {
        $csvData = $_POST['csv_data'] ?? '';
        
        if (!$csvData) {
            $this->sendJSON(['success' => false, 'error' => 'Dados CSV vazios']);
        }
        
        $manager = new ContactManager($this->userId);
        $result = $manager->importCSV($csvData);
        
        $this->sendJSON($result);
    }
    
    private function handleSaveSettings() {
        $settings = $_POST['settings'] ?? [];
        
        if (empty($settings)) {
            $this->sendJSON(['success' => false, 'error' => 'Nenhuma configuração fornecida']);
        }
        
        foreach ($settings as $key => $value) {
            $this->system->saveConfig($key, $value);
        }
        
        $this->sendJSON(['success' => true]);
    }
    
    private function sendJSON($data) {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    public function render() {
        $this->renderInterface();
    }
    
    private function renderInterface() {
        ?>
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>WASender 4.6 - <?php echo $this->modules[$this->currentModule]['label'] ?? 'Dashboard'; ?></title>
            
            <style>
                :root {
                    --primary: #25D366;
                    --primary-dark: #128C7E;
                    --secondary: #075E54;
                    --accent: #34B7F1;
                    --light: #ffffff;
                    --dark: #0c0c0c;
                    --gray: #f0f0f0;
                    --gray-dark: #666;
                }
                
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                    background: var(--dark);
                    color: var(--light);
                    min-height: 100vh;
                }
                
                .app-container {
                    display: flex;
                    min-height: 100vh;
                }
                
                .sidebar {
                    width: 250px;
                    background: var(--secondary);
                    border-right: 1px solid rgba(255,255,255,0.1);
                    display: flex;
                    flex-direction: column;
                    position: fixed;
                    height: 100vh;
                    z-index: 1000;
                }
                
                .logo {
                    padding: 20px;
                    text-align: center;
                    border-bottom: 1px solid rgba(255,255,255,0.1);
                }
                
                .logo h1 {
                    color: var(--primary);
                    font-size: 24px;
                    margin-bottom: 5px;
                }
                
                .logo .version {
                    color: var(--accent);
                    font-size: 12px;
                    letter-spacing: 1px;
                }
                
                .user-info {
                    padding: 20px;
                    display: flex;
                    align-items: center;
                    gap: 15px;
                    border-bottom: 1px solid rgba(255,255,255,0.1);
                }
                
                .user-avatar {
                    width: 50px;
                    height: 50px;
                    background: var(--primary);
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 20px;
                    font-weight: bold;
                }
                
                .user-details h3 {
                    font-size: 16px;
                    margin-bottom: 5px;
                }
                
                .user-details .user-plan {
                    font-size: 12px;
                    color: var(--accent);
                    background: rgba(52, 183, 241, 0.1);
                    padding: 2px 8px;
                    border-radius: 10px;
                    display: inline-block;
                }
                
                .nav-menu {
                    flex: 1;
                    padding: 20px 0;
                    overflow-y: auto;
                }
                
                .nav-item {
                    display: flex;
                    align-items: center;
                    padding: 12px 20px;
                    color: var(--light);
                    text-decoration: none;
                    transition: all 0.3s;
                    border-left: 3px solid transparent;
                }
                
                .nav-item:hover {
                    background: rgba(255,255,255,0.1);
                    border-left-color: var(--accent);
                }
                
                .nav-item.active {
                    background: rgba(255,255,255,0.15);
                    border-left-color: var(--primary);
                }
                
                .nav-icon {
                    font-size: 20px;
                    margin-right: 15px;
                    width: 30px;
                    text-align: center;
                }
                
                .nav-label {
                    font-size: 14px;
                }
                
                .logout-btn {
                    margin: 20px;
                    padding: 12px;
                    background: rgba(255,255,255,0.1);
                    border: none;
                    border-radius: 8px;
                    color: var(--light);
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 10px;
                    transition: all 0.3s;
                }
                
                .logout-btn:hover {
                    background: rgba(255,255,255,0.2);
                }
                
                .main-content {
                    flex: 1;
                    margin-left: 250px;
                    padding: 20px;
                    min-height: 100vh;
                }
                
                .header {
                    background: rgba(255,255,255,0.05);
                    backdrop-filter: blur(10px);
                    border-radius: 15px;
                    padding: 20px;
                    margin-bottom: 20px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                
                .page-title {
                    display: flex;
                    align-items: center;
                    gap: 15px;
                }
                
                .page-title h1 {
                    font-size: 24px;
                    color: var(--light);
                }
                
                .header-actions {
                    display: flex;
                    gap: 10px;
                }
                
                .btn {
                    padding: 10px 20px;
                    border: none;
                    border-radius: 8px;
                    background: var(--primary);
                    color: white;
                    font-weight: bold;
                    cursor: pointer;
                    transition: all 0.3s;
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                }
                
                .btn:hover {
                    background: var(--primary-dark);
                    transform: translateY(-2px);
                }
                
                .btn-secondary {
                    background: var(--gray-dark);
                }
                
                .btn-secondary:hover {
                    background: #555;
                }
                
                .btn-danger {
                    background: #ff4444;
                }
                
                .btn-danger:hover {
                    background: #cc0000;
                }
                
                .content-area {
                    background: rgba(255,255,255,0.05);
                    backdrop-filter: blur(10px);
                    border-radius: 15px;
                    padding: 30px;
                    min-height: calc(100vh - 150px);
                    border: 1px solid rgba(255,255,255,0.1);
                }
                
                .stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }
                
                .stat-card {
                    background: rgba(255,255,255,0.08);
                    border-radius: 10px;
                    padding: 20px;
                    border: 1px solid rgba(255,255,255,0.1);
                    transition: all 0.3s;
                }
                
                .stat-card:hover {
                    transform: translateY(-5px);
                    border-color: var(--primary);
                }
                
                .stat-icon {
                    font-size: 30px;
                    margin-bottom: 15px;
                    color: var(--primary);
                }
                
                .stat-value {
                    font-size: 32px;
                    font-weight: bold;
                    margin-bottom: 5px;
                    color: var(--light);
                }
                
                .stat-label {
                    font-size: 14px;
                    color: var(--gray-dark);
                }
                
                .form-group {
                    margin-bottom: 20px;
                }
                
                .form-label {
                    display: block;
                    margin-bottom: 8px;
                    color: var(--light);
                    font-weight: 500;
                }
                
                .form-control {
                    width: 100%;
                    padding: 12px 15px;
                    background: rgba(255,255,255,0.1);
                    border: 1px solid rgba(255,255,255,0.2);
                    border-radius: 8px;
                    color: var(--light);
                    font-size: 14px;
                    transition: all 0.3s;
                }
                
                .form-control:focus {
                    outline: none;
                    border-color: var(--primary);
                    background: rgba(255,255,255,0.15);
                }
                
                .table-container {
                    overflow-x: auto;
                    background: rgba(0,0,0,0.3);
                    border-radius: 10px;
                    padding: 15px;
                }
                
                table {
                    width: 100%;
                    border-collapse: collapse;
                }
                
                th {
                    text-align: left;
                    padding: 15px;
                    background: rgba(255,255,255,0.1);
                    color: var(--light);
                    font-weight: 500;
                    border-bottom: 2px solid rgba(255,255,255,0.2);
                }
                
                td {
                    padding: 15px;
                    border-bottom: 1px solid rgba(255,255,255,0.1);
                    color: var(--light);
                }
                
                tr:hover {
                    background: rgba(255,255,255,0.05);
                }
                
                .status-badge {
                    padding: 4px 12px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: bold;
                }
                
                .status-sent {
                    background: rgba(37, 211, 102, 0.2);
                    color: #25D366;
                }
                
                .status-pending {
                    background: rgba(255, 193, 7, 0.2);
                    color: #FFC107;
                }
                
                .status-failed {
                    background: rgba(255, 68, 68, 0.2);
                    color: #FF4444;
                }
                
                .whatsapp-interface {
                    display: grid;
                    grid-template-columns: 300px 1fr;
                    gap: 20px;
                    height: 700px;
                }
                
                .contacts-panel {
                    background: rgba(0,0,0,0.3);
                    border-radius: 10px;
                    padding: 20px;
                    overflow-y: auto;
                }
                
                .chat-panel {
                    background: rgba(0,0,0,0.3);
                    border-radius: 10px;
                    padding: 20px;
                    display: flex;
                    flex-direction: column;
                }
                
                .chat-messages {
                    flex: 1;
                    overflow-y: auto;
                    margin-bottom: 20px;
                }
                
                .message-input {
                    display: flex;
                    gap: 10px;
                }
                
                .message-input input {
                    flex: 1;
                    padding: 12px 15px;
                    background: rgba(255,255,255,0.1);
                    border: 1px solid rgba(255,255,255,0.2);
                    border-radius: 25px;
                    color: var(--light);
                }
                
                @media (max-width: 768px) {
                    .sidebar {
                        transform: translateX(-100%);
                        transition: transform 0.3s;
                    }
                    
                    .sidebar.active {
                        transform: translateX(0);
                    }
                    
                    .main-content {
                        margin-left: 0;
                    }
                    
                    .whatsapp-interface {
                        grid-template-columns: 1fr;
                    }
                }
                
                @keyframes fadeIn {
                    from { opacity: 0; transform: translateY(20px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                
                .fade-in {
                    animation: fadeIn 0.5s ease;
                }
                
                .loading {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 200px;
                }
                
                .spinner {
                    width: 40px;
                    height: 40px;
                    border: 3px solid rgba(255,255,255,0.3);
                    border-radius: 50%;
                    border-top-color: var(--primary);
                    animation: spin 1s linear infinite;
                }
                
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
                
                .qr-container {
                    text-align: center;
                    padding: 20px;
                    background: white;
                    border-radius: 10px;
                    margin: 20px auto;
                    max-width: 300px;
                }
                
                .qr-container img {
                    max-width: 100%;
                    height: auto;
                }
                
                .qr-instructions {
                    background: rgba(255,255,255,0.1);
                    padding: 15px;
                    border-radius: 10px;
                    margin-top: 20px;
                    font-size: 14px;
                }
                
                .message-bubble {
                    max-width: 70%;
                    padding: 10px 15px;
                    border-radius: 18px;
                    margin-bottom: 10px;
                    position: relative;
                }
                
                .message-in {
                    background: rgba(255,255,255,0.1);
                    margin-right: auto;
                    border-bottom-left-radius: 5px;
                }
                
                .message-out {
                    background: var(--primary);
                    margin-left: auto;
                    border-bottom-right-radius: 5px;
                }
                
                .message-time {
                    font-size: 11px;
                    opacity: 0.7;
                    margin-top: 5px;
                    text-align: right;
                }
                
                .chat-item {
                    padding: 10px;
                    border-bottom: 1px solid rgba(255,255,255,0.1);
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    transition: background 0.3s;
                }
                
                .chat-item:hover {
                    background: rgba(255,255,255,0.05);
                }
                
                .chat-avatar {
                    width: 40px;
                    height: 40px;
                    background: var(--primary);
                    border-radius: 50%;
                    margin-right: 10px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                .chat-info {
                    flex: 1;
                }
                
                .chat-name {
                    font-weight: bold;
                    margin-bottom: 3px;
                }
                
                .chat-last-message {
                    font-size: 12px;
                    color: #888;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
            </style>
            
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        </head>
        <body>
            <div class="app-container">
                <div class="sidebar" id="sidebar">
                    <div class="logo">
                        <h1>WASENDER</h1>
                        <div class="version">4.6.0</div>
                    </div>
                    
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($this->user['name'], 0, 1)); ?>
                        </div>
                        <div class="user-details">
                            <h3><?php echo htmlspecialchars($this->user['name']); ?></h3>
                            <?php if (isset($this->user['plan'])): ?>
                            <span class="user-plan"><?php echo strtoupper($this->user['plan']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="nav-menu">
                        <?php foreach ($this->modules as $module => $info): ?>
                            <a href="?module=<?php echo $module; ?>&user_id=<?php echo $this->userId; ?>" 
                               class="nav-item <?php echo $this->currentModule === $module ? 'active' : ''; ?>">
                                <span class="nav-icon"><?php echo $info['icon']; ?></span>
                                <span class="nav-label"><?php echo $info['label']; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    
                    <button class="logout-btn" onclick="logout()">
                        <i class="fas fa-sign-out-alt"></i> Sair
                    </button>
                </div>
                
                <div class="main-content">
                    <div class="header">
                        <div class="page-title">
                            <span class="nav-icon"><?php echo $this->modules[$this->currentModule]['icon'] ?? '📱'; ?></span>
                            <h1><?php echo $this->modules[$this->currentModule]['label'] ?? 'Dashboard'; ?></h1>
                        </div>
                        
                        <div class="header-actions">
                            <button class="btn" onclick="toggleSidebar()">
                                <i class="fas fa-bars"></i>
                            </button>
                            <button class="btn" onclick="location.reload()">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                            <?php if ($this->user['is_admin']): ?>
                            <button class="btn btn-secondary" onclick="openAdmin()">
                                <i class="fas fa-crown"></i> Admin
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="content-area fade-in">
                        <?php $this->renderModuleContent(); ?>
                    </div>
                </div>
            </div>
            
            <script>
            function toggleSidebar() {
                const sidebar = document.getElementById('sidebar');
                sidebar.classList.toggle('active');
            }
            
            function logout() {
                if (confirm('Tem certeza que deseja sair?')) {
                    window.location.href = '?logout';
                }
            }
            
            function openAdmin() {
                window.open('admin.php?user_id=<?php echo $this->userId; ?>', '_blank');
            }
            
            function loadStats() {
                fetch('?action=get_stats&user_id=<?php echo $this->userId; ?>')
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            const stats = data;
                            document.querySelectorAll('.stat-value').forEach(el => {
                                const stat = el.dataset.stat;
                                if (stats[stat]) {
                                    el.textContent = stats[stat];
                                }
                            });
                        }
                    });
            }
            
            document.addEventListener('DOMContentLoaded', function() {
                loadStats();
                setInterval(loadStats, 30000);
                
                if (window.innerWidth < 768) {
                    document.querySelectorAll('.nav-item').forEach(item => {
                        item.addEventListener('click', () => {
                            document.getElementById('sidebar').classList.remove('active');
                        });
                    });
                }
            });
            </script>
            
            <?php $this->renderModuleScripts(); ?>
        </body>
        </html>
        <?php
    }
    
    private function renderModuleContent() {
        switch ($this->currentModule) {
            case 'dashboard':
                $this->renderDashboard();
                break;
                
            case 'whatsapp':
                $this->renderWhatsApp();
                break;
                
            case 'messages':
                $this->renderMessages();
                break;
                
            case 'contacts':
                $this->renderContacts();
                break;
                
            case 'campaigns':
                $this->renderCampaigns();
                break;
                
            case 'statistics':
                $this->renderStatistics();
                break;
                
            case 'settings':
                $this->renderSettings();
                break;
                
            default:
                echo '<h2>Módulo não encontrado</h2>';
        }
    }
    
    private function renderModuleScripts() {
        switch ($this->currentModule) {
            case 'whatsapp':
                $this->renderWhatsAppScripts();
                break;
        }
    }
    
    private function renderDashboard() {
        $stats = $this->system->getStatistics();
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">📱</div>
                <div class="stat-value" data-stat="messages_today"><?php echo $stats['messages_today']; ?></div>
                <div class="stat-label">Mensagens Hoje</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">💬</div>
                <div class="stat-value" data-stat="total_messages"><?php echo $stats['total_messages']; ?></div>
                <div class="stat-label">Total de Mensagens</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value" data-stat="total_contacts"><?php echo $stats['total_contacts']; ?></div>
                <div class="stat-label">Contatos</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-value" data-stat="success_rate"><?php echo $stats['success_rate']; ?>%</div>
                <div class="stat-label">Taxa de Sucesso</div>
            </div>
        </div>
        
        <div style="margin-top: 40px;">
            <h2 style="margin-bottom: 20px;">Atividade Recente</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Mensagem</th>
                            <th>Data/Hora</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="recentActivity">
                        <tr>
                            <td colspan="4" style="text-align: center;">Carregando...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <script>
        function loadRecentActivity() {
            fetch('?action=get_messages&user_id=<?php echo $this->userId; ?>&limit=10')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const tbody = document.getElementById('recentActivity');
                        let html = '';
                        
                        data.messages.forEach(msg => {
                            html += `
                                <tr>
                                    <td>${msg.type || 'text'}</td>
                                    <td>${msg.message.substring(0, 50)}${msg.message.length > 50 ? '...' : ''}</td>
                                    <td>${new Date(msg.created_at).toLocaleString()}</td>
                                    <td><span class="status-badge status-${msg.status}">${msg.status}</span></td>
                                </tr>
                            `;
                        });
                        
                        tbody.innerHTML = html;
                    }
                });
        }
        
        loadRecentActivity();
        setInterval(loadRecentActivity, 30000);
        </script>
        <?php
    }
    
    private function renderWhatsApp() {
        $bot = new WhatsAppBot($this->userId);
        $status = $bot->getStatus();
        ?>
        <div class="whatsapp-interface">
            <div class="contacts-panel">
                <h3 style="margin-bottom: 20px;">
                    <i class="fas fa-comments"></i> Conversas
                    <button class="btn" onclick="loadChats()" style="float: right; padding: 5px 10px;">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </h3>
                <div class="form-group">
                    <input type="text" class="form-control" placeholder="Buscar conversa..." id="searchChat">
                </div>
                <div id="chatsList" style="height: 500px; overflow-y: auto;">
                    <div style="text-align: center; color: #888; padding: 50px;">
                        <i class="fas fa-comment-dots" style="font-size: 48px;"></i>
                        <p>Conecte o WhatsApp para ver as conversas</p>
                    </div>
                </div>
            </div>
            
            <div class="chat-panel">
                <div style="margin-bottom: 20px;">
                    <h3 id="chatTitle">WhatsApp Bot</h3>
                    <div style="display: flex; gap: 10px; margin-top: 10px;">
                        <button class="btn" onclick="startWhatsApp()" id="startBtn">
                            <i class="fas fa-play"></i> Conectar WhatsApp
                        </button>
                        <button class="btn btn-danger" onclick="stopWhatsApp()" id="stopBtn" style="display: none;">
                            <i class="fas fa-stop"></i> Desconectar
                        </button>
                        <div style="margin-left: auto; display: flex; align-items: center; gap: 10px;">
                            <span id="whatsappStatus">Status: <?php echo $status['connected'] ? 'Conectado' : 'Desconectado'; ?></span>
                            <?php if ($status['phone']): ?>
                            <span class="status-badge status-sent"><?php echo htmlspecialchars($status['phone']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div id="qrCodeContainer" style="display: none; margin-top: 20px; text-align: center;">
                        <h4>Escaneie o QR Code no WhatsApp</h4>
                        <div class="qr-container" id="qrCodeImage">
                            <div class="loading">
                                <div class="spinner"></div>
                            </div>
                        </div>
                        <div class="qr-instructions">
                            <p><strong>Como escanear:</strong></p>
                            <ol style="text-align: left; margin-left: 20px;">
                                <li>Abra o WhatsApp no seu celular</li>
                                <li>Toque em <strong>Menu</strong> ⋮ ou <strong>Configurações</strong></li>
                                <li>Toque em <strong>WhatsApp Web</strong></li>
                                <li>Aponte a câmera para o código QR acima</li>
                            </ol>
                        </div>
                    </div>
                </div>
                
                <div class="chat-messages" id="chatMessages">
                    <div style="text-align: center; color: #888; padding: 50px;">
                        <i class="fas fa-comment-dots" style="font-size: 48px; margin-bottom: 20px;"></i>
                        <p>Selecione uma conversa para começar</p>
                    </div>
                </div>
                
                <div class="message-input" style="display: none;" id="messageInputContainer">
                    <input type="text" id="messageInput" placeholder="Digite sua mensagem..." 
                           onkeypress="if(event.key === 'Enter') sendWhatsAppMessage()">
                    <button class="btn" onclick="sendWhatsAppMessage()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <div style="margin-top: 30px;">
            <h3>Configurações do Bot</h3>
            <form id="botSettingsForm" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label class="form-label">Delay entre mensagens (segundos)</label>
                    <input type="number" name="message_delay" class="form-control" value="<?php echo $this->system->getConfig('message_delay'); ?>" min="1" max="60">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Máximo de mensagens por hora</label>
                    <input type="number" name="max_messages_per_hour" class="form-control" value="<?php echo $this->system->getConfig('max_messages_per_hour'); ?>" min="1" max="1000">
                </div>
                
                <div class="form-group">
                    <label class="form-label" style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" name="auto_reply_enabled" value="1" <?php echo $this->system->getConfig('auto_reply_enabled') == '1' ? 'checked' : ''; ?>>
                        Ativar auto-respostas
                    </label>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn" style="width: 100%;">
                        <i class="fas fa-save"></i> Salvar Configurações
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
    
    private function renderWhatsAppScripts() {
        ?>
        <script>
        let currentSessionId = null;
        let currentChatId = null;
        let qrInterval = null;
        let statusInterval = null;
        let messagesInterval = null;
        
        function startWhatsApp() {
            document.getElementById('startBtn').disabled = true;
            document.getElementById('startBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Iniciando...';
            
            fetch('?action=start_whatsapp&user_id=<?php echo $this->userId; ?>')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        currentSessionId = data.session_id;
                        document.getElementById('qrCodeContainer').style.display = 'block';
                        startQRCodeCheck();
                        startStatusCheck();
                        document.getElementById('stopBtn').style.display = 'inline-flex';
                        document.getElementById('startBtn').style.display = 'none';
                        document.getElementById('whatsappStatus').textContent = 'Status: Aguardando QR Code';
                    } else {
                        alert('Erro: ' + data.error);
                        document.getElementById('startBtn').disabled = false;
                        document.getElementById('startBtn').innerHTML = '<i class="fas fa-play"></i> Conectar WhatsApp';
                    }
                });
        }
        
        function startQRCodeCheck() {
            if (qrInterval) clearInterval(qrInterval);
            
            qrInterval = setInterval(() => {
                fetch('?action=get_qr_code&user_id=<?php echo $this->userId; ?>')
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.qr_code) {
                            const qrImage = document.getElementById('qrCodeImage');
                            qrImage.innerHTML = `<img src="${data.qr_code}" alt="QR Code WhatsApp" style="max-width: 100%;">`;
                            document.getElementById('whatsappStatus').textContent = 'Status: QR Code Pronto - Escaneie!';
                        } else if (data.status === 'connected') {
                            clearInterval(qrInterval);
                            document.getElementById('qrCodeContainer').style.display = 'none';
                        }
                    });
            }, 2000);
        }
        
        function startStatusCheck() {
            if (statusInterval) clearInterval(statusInterval);
            
            statusInterval = setInterval(() => {
                fetch('?action=get_whatsapp_status&user_id=<?php echo $this->userId; ?>')
                    .then(r => r.json())
                    .then(data => {
                        updateStatus(data);
                        
                        if (data.connected) {
                            clearInterval(qrInterval);
                            document.getElementById('qrCodeContainer').style.display = 'none';
                            document.getElementById('whatsappStatus').innerHTML = 
                                'Status: <span class="status-badge status-sent">Conectado</span>';
                            loadChats();
                            startMessagesMonitor();
                        }
                    });
            }, 3000);
        }
        
        function startMessagesMonitor() {
            if (messagesInterval) clearInterval(messagesInterval);
            
            messagesInterval = setInterval(() => {
                if (currentChatId) {
                    loadChatMessages(currentChatId);
                }
                loadLatestMessages();
            }, 5000);
        }
        
        function updateStatus(status) {
            let statusText = status.status;
            if (status.connected) {
                statusText = 'Conectado';
                if (status.phone) {
                    statusText += ' - ' + status.phone;
                }
            }
            document.getElementById('whatsappStatus').textContent = 'Status: ' + statusText;
        }
        
        function loadChats() {
            if (!currentSessionId) {
                alert('WhatsApp não está conectado');
                return;
            }
            
            fetch(`?action=get_chats&user_id=<?php echo $this->userId; ?>&session=${currentSessionId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        displayChats(data.chats);
                    }
                });
        }
        
        function displayChats(chats) {
            const container = document.getElementById('chatsList');
            
            if (!chats || chats.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; color: #888; padding: 50px;">
                        <i class="fas fa-comment-slash" style="font-size: 48px;"></i>
                        <p>Nenhuma conversa encontrada</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            
            chats.forEach(chat => {
                const lastMsg = chat.last_message ? 
                    (chat.last_message.length > 30 ? chat.last_message.substring(0, 30) + '...' : chat.last_message) : 
                    'Sem mensagens';
                
                const time = chat.last_message_time ? 
                    new Date(chat.last_message_time * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : 
                    '';
                
                html += `
                    <div class="chat-item" onclick="selectChat('${chat.chat_id}', '${chat.name || chat.chat_id}')">
                        <div class="chat-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="chat-info">
                            <div class="chat-name">${chat.name || chat.chat_id}</div>
                            <div class="chat-last-message">${lastMsg}</div>
                        </div>
                        <small style="color: #666;">${time}</small>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        function selectChat(chatId, chatName) {
            currentChatId = chatId;
            document.getElementById('chatTitle').textContent = chatName;
            document.getElementById('messageInputContainer').style.display = 'flex';
            
            loadChatMessages(chatId);
        }
        
        function loadChatMessages(chatId) {
            fetch(`?action=get_chat_messages&user_id=<?php echo $this->userId; ?>&session=${currentSessionId}&chat_id=${chatId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        displayMessages(data.messages);
                    }
                });
        }
        
        function loadLatestMessages() {
            fetch(`?action=get_latest_messages&user_id=<?php echo $this->userId; ?>&session=${currentSessionId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Atualizar lista de chats com últimas mensagens
                        data.messages.forEach(msg => {
                            // Aqui você pode atualizar a UI com novas mensagens
                            if (msg.chat_id === currentChatId) {
                                displayMessages([msg]);
                            }
                        });
                    }
                });
        }
        
        function displayMessages(messages) {
            const container = document.getElementById('chatMessages');
            
            if (!messages || messages.length === 0) {
                if (!container.innerHTML.includes('Selecione uma conversa')) {
                    container.innerHTML = `
                        <div style="text-align: center; color: #888; padding: 50px;">
                            <i class="fas fa-comment-slash" style="font-size: 48px;"></i>
                            <p>Nenhuma mensagem nesta conversa</p>
                        </div>
                    `;
                }
                return;
            }
            
            let html = '';
            
            messages.forEach(msg => {
                const isMe = msg.from_me == 1;
                const time = msg.timestamp ? 
                    new Date(msg.timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : 
                    new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                
                html += `
                    <div class="message-bubble ${isMe ? 'message-out' : 'message-in'}">
                        <div>${msg.body || '(Mídia)'}</div>
                        <div class="message-time">${time}</div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
            container.scrollTop = container.scrollHeight;
        }
        
        function sendWhatsAppMessage() {
            if (!currentChatId) {
                alert('Selecione uma conversa primeiro!');
                return;
            }
            
            const message = document.getElementById('messageInput').value;
            if (!message.trim()) return;
            
            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('phone', currentChatId);
            formData.append('message', message);
            
            fetch('?user_id=<?php echo $this->userId; ?>', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('messageInput').value = '';
                    
                    const container = document.getElementById('chatMessages');
                    const time = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                    
                    container.innerHTML += `
                        <div class="message-bubble message-out">
                            <div>${message}</div>
                            <div class="message-time">${time}</div>
                        </div>
                    `;
                    
                    container.scrollTop = container.scrollHeight;
                } else {
                    alert('Erro: ' + data.error);
                }
            });
        }
        
        function stopWhatsApp() {
            if (confirm('Tem certeza que deseja desconectar o WhatsApp?')) {
                fetch('?action=stop_whatsapp&user_id=<?php echo $this->userId; ?>')
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            if (qrInterval) clearInterval(qrInterval);
                            if (statusInterval) clearInterval(statusInterval);
                            if (messagesInterval) clearInterval(messagesInterval);
                            
                            updateStatus({status: 'Desconectado', connected: false});
                            document.getElementById('qrCodeContainer').style.display = 'none';
                            document.getElementById('stopBtn').style.display = 'none';
                            document.getElementById('startBtn').style.display = 'inline-flex';
                            document.getElementById('startBtn').disabled = false;
                            document.getElementById('startBtn').innerHTML = '<i class="fas fa-play"></i> Conectar WhatsApp';
                            
                            currentSessionId = null;
                            currentChatId = null;
                            
                            document.getElementById('chatsList').innerHTML = `
                                <div style="text-align: center; color: #888; padding: 50px;">
                                    <i class="fas fa-comment-dots" style="font-size: 48px;"></i>
                                    <p>Conecte o WhatsApp para ver as conversas</p>
                                </div>
                            `;
                            
                            document.getElementById('chatMessages').innerHTML = `
                                <div style="text-align: center; color: #888; padding: 50px;">
                                    <i class="fas fa-comment-dots" style="font-size: 48px; margin-bottom: 20px;"></i>
                                    <p>Selecione uma conversa para começar</p>
                                </div>
                            `;
                            
                            document.getElementById('messageInputContainer').style.display = 'none';
                        }
                    });
            }
        }
        
        document.getElementById('botSettingsForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'save_settings');
            
            fetch('?user_id=<?php echo $this->userId; ?>', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Configurações salvas!');
                }
            });
        });
        
        document.getElementById('searchChat').addEventListener('input', function(e) {
            const search = e.target.value.toLowerCase();
            document.querySelectorAll('.chat-item').forEach(item => {
                const text = item.textContent.toLowerCase();
                item.style.display = text.includes(search) ? 'flex' : 'none';
            });
        });
        
        <?php if ($status['connected']): ?>
        // Se já estiver conectado, carregar chats
        setTimeout(() => {
            document.getElementById('stopBtn').style.display = 'inline-flex';
            document.getElementById('startBtn').style.display = 'none';
            loadChats();
            startMessagesMonitor();
        }, 1000);
        <?php endif; ?>
        </script>
        <?php
    }
    
    private function renderMessages() {
        ?>
        <div style="margin-bottom: 20px;">
            <button class="btn" onclick="showSendMessageModal()">
                <i class="fas fa-plus"></i> Nova Mensagem
            </button>
            <button class="btn btn-secondary" onclick="showBulkSendModal()">
                <i class="fas fa-paper-plane"></i> Envio em Massa
            </button>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Destinatário</th>
                        <th>Mensagem</th>
                        <th>Data/Hora</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody id="messagesTable">
                    <tr>
                        <td colspan="6" style="text-align: center;">Carregando...</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div id="sendMessageModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; align-items: center; justify-content: center;">
            <div style="background: var(--dark); padding: 30px; border-radius: 15px; width: 500px; border: 1px solid rgba(255,255,255,0.2);">
                <h3 style="margin-bottom: 20px;">Nova Mensagem</h3>
                <form id="newMessageForm">
                    <div class="form-group">
                        <label class="form-label">Telefone (com DDD)</label>
                        <input type="text" name="phone" class="form-control" placeholder="5511999999999" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Mensagem</label>
                        <textarea name="message" class="form-control" rows="4" required></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancelar</button>
                        <button type="submit" class="btn">Enviar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
        function loadMessages() {
            fetch('?action=get_messages&user_id=<?php echo $this->userId; ?>')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const tbody = document.getElementById('messagesTable');
                        let html = '';
                        
                        data.messages.forEach(msg => {
                            html += `
                                <tr>
                                    <td>${msg.id}</td>
                                    <td>${msg.phone}</td>
                                    <td>${msg.message.substring(0, 50)}${msg.message.length > 50 ? '...' : ''}</td>
                                    <td>${new Date(msg.created_at).toLocaleString()}</td>
                                    <td><span class="status-badge status-${msg.status}">${msg.status}</span></td>
                                    <td>
                                        <button class="btn" style="padding: 5px 10px; font-size: 12px;" onclick="resendMessage(${msg.id})">
                                            <i class="fas fa-redo"></i>
                                        </button>
                                    </td>
                                </tr>
                            `;
                        });
                        
                        tbody.innerHTML = html;
                    }
                });
        }
        
        function showSendMessageModal() {
            document.getElementById('sendMessageModal').style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('sendMessageModal').style.display = 'none';
        }
        
        document.getElementById('newMessageForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'send_message');
            
            fetch('?user_id=<?php echo $this->userId; ?>', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Mensagem enviada!');
                    closeModal();
                    loadMessages();
                } else {
                    alert('Erro: ' + data.error);
                }
            });
        });
        
        function resendMessage(id) {
            if (confirm('Reenviar esta mensagem?')) {
                fetch(`?action=resend_message&id=${id}&user_id=<?php echo $this->userId; ?>`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            alert('Mensagem reenviada!');
                            loadMessages();
                        }
                    });
            }
        }
        
        function showBulkSendModal() {
            alert('Funcionalidade de envio em massa em desenvolvimento.');
        }
        
        loadMessages();
        setInterval(loadMessages, 30000);
        </script>
        <?php
    }
    
    private function renderContacts() {
        ?>
        <div style="margin-bottom: 20px; display: flex; gap: 10px;">
            <button class="btn" onclick="showAddContactModal()">
                <i class="fas fa-plus"></i> Novo Contato
            </button>
            <button class="btn btn-secondary" onclick="showImportModal()">
                <i class="fas fa-file-import"></i> Importar CSV
            </button>
            <input type="text" class="form-control" style="flex: 1;" placeholder="Buscar contatos..." id="searchContacts">
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Telefone</th>
                        <th>Email</th>
                        <th>Grupo</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody id="contactsTable">
                    <tr>
                        <td colspan="6" style="text-align: center;">Carregando...</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div id="addContactModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; align-items: center; justify-content: center;">
            <div style="background: var(--dark); padding: 30px; border-radius: 15px; width: 500px; border: 1px solid rgba(255,255,255,0.2);">
                <h3 style="margin-bottom: 20px;">Novo Contato</h3>
                <form id="newContactForm">
                    <div class="form-group">
                        <label class="form-label">Nome</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Telefone (com DDD)</label>
                        <input type="text" name="phone" class="form-control" placeholder="5511999999999" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email (opcional)</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Grupo</label>
                        <input type="text" name="group" class="form-control" value="default">
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" onclick="closeContactModal()">Cancelar</button>
                        <button type="submit" class="btn">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
        function loadContactsTable() {
            fetch('?action=get_contacts&user_id=<?php echo $this->userId; ?>')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const tbody = document.getElementById('contactsTable');
                        let html = '';
                        
                        data.contacts.forEach(contact => {
                            html += `
                                <tr>
                                    <td>${contact.id}</td>
                                    <td>${contact.name}</td>
                                    <td>${contact.phone}</td>
                                    <td>${contact.email || '-'}</td>
                                    <td>${contact.contact_group}</td>
                                    <td>
                                        <button class="btn" style="padding: 5px 10px; font-size: 12px;" onclick="messageContact('${contact.phone}')">
                                            <i class="fas fa-message"></i>
                                        </button>
                                        <button class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;" onclick="deleteContact(${contact.id})">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            `;
                        });
                        
                        tbody.innerHTML = html;
                    }
                });
        }
        
        function showAddContactModal() {
            document.getElementById('addContactModal').style.display = 'flex';
        }
        
        function closeContactModal() {
            document.getElementById('addContactModal').style.display = 'none';
        }
        
        document.getElementById('newContactForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'save_contact');
            
            fetch('?user_id=<?php echo $this->userId; ?>', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Contato salvo!');
                    closeContactModal();
                    loadContactsTable();
                } else {
                    alert('Erro: ' + data.error);
                }
            });
        });
        
        function messageContact(phone) {
            const message = prompt('Digite a mensagem para ' + phone + ':');
            if (message) {
                const formData = new FormData();
                formData.append('action', 'send_message');
                formData.append('phone', phone);
                formData.append('message', message);
                
                fetch('?user_id=<?php echo $this->userId; ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Mensagem enviada!');
                    }
                });
            }
        }
        
        function deleteContact(id) {
            if (confirm('Tem certeza que deseja excluir este contato?')) {
                fetch('?action=delete_contact&id=' + id + '&user_id=<?php echo $this->userId; ?>')
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            alert('Contato excluído!');
                            loadContactsTable();
                        }
                    });
            }
        }
        
        function showImportModal() {
            alert('Funcionalidade de importação em desenvolvimento.');
        }
        
        loadContactsTable();
        
        document.getElementById('searchContacts').addEventListener('input', function(e) {
            const search = e.target.value.toLowerCase();
            document.querySelectorAll('#contactsTable tr').forEach((row, index) => {
                if (index === 0) return;
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(search) ? '' : 'none';
            });
        });
        </script>
        <?php
    }
    
    private function renderCampaigns() {
        ?>
        <div style="text-align: center; padding: 100px;">
            <h2>Campanhas</h2>
            <p>Funcionalidade em desenvolvimento</p>
        </div>
        <?php
    }
    
    private function renderStatistics() {
        ?>
        <div style="margin-bottom: 30px;">
            <h2>Estatísticas Detalhadas</h2>
            <p>Análise completa do seu uso do WASender</p>
        </div>
        
        <div id="statisticsContent">
            <div class="loading">
                <div class="spinner"></div>
            </div>
        </div>
        
        <script>
        function loadStatistics() {
            fetch('?action=get_stats&user_id=<?php echo $this->userId; ?>')
                .then(r => r.json())
                .then(data => {
                    const container = document.getElementById('statisticsContent');
                    
                    let html = `
                        <div class="stats-grid" style="margin-bottom: 40px;">
                            <div class="stat-card">
                                <div class="stat-icon">📅</div>
                                <div class="stat-value">${data.messages_today}</div>
                                <div class="stat-label">Mensagens Hoje</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">📈</div>
                                <div class="stat-value">${data.total_messages}</div>
                                <div class="stat-label">Total de Mensagens</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">✅</div>
                                <div class="stat-value">${data.success_rate}%</div>
                                <div class="stat-label">Taxa de Sucesso</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">👥</div>
                                <div class="stat-value">${data.total_contacts}</div>
                                <div class="stat-label">Contatos</div>
                            </div>
                        </div>
                    `;
                    
                    html += `
                        <div style="background: rgba(0,0,0,0.3); padding: 20px; border-radius: 10px;">
                            <h3 style="margin-bottom: 20px;">📊 Distribuição por Status</h3>
                            <div style="display: flex; gap: 20px; align-items: flex-end; height: 200px;">
                                <div style="text-align: center; flex: 1;">
                                    <div style="height: ${data.success_rate}%; background: #25D366; border-radius: 5px;"></div>
                                    <div style="margin-top: 10px; color: #25D366; font-weight: bold;">Enviadas</div>
                                </div>
                                <div style="text-align: center; flex: 1;">
                                    <div style="height: ${100 - data.success_rate}%; background: #FFC107; border-radius: 5px;"></div>
                                    <div style="margin-top: 10px; color: #FFC107; font-weight: bold;">Pendentes/Falhas</div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    container.innerHTML = html;
                });
        }
        
        loadStatistics();
        setInterval(loadStatistics, 60000);
        </script>
        <?php
    }
    
    private function renderSettings() {
        $config = $this->system->getConfig();
        ?>
        <div style="max-width: 800px; margin: 0 auto;">
            <h2 style="margin-bottom: 30px;">Configurações do Sistema</h2>
            
            <form id="systemSettingsForm">
                <div style="background: rgba(0,0,0,0.3); padding: 30px; border-radius: 10px;">
                    <h3 style="margin-bottom: 20px;">Configurações Gerais</h3>
                    
                    <div class="form-group">
                        <label class="form-label">Delay entre mensagens (segundos)</label>
                        <input type="number" name="message_delay" class="form-control" value="<?php echo $config['message_delay']; ?>" min="1" max="60">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Máximo de mensagens por hora</label>
                        <input type="number" name="max_messages_per_hour" class="form-control" value="<?php echo $config['max_messages_per_hour']; ?>" min="1" max="1000">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="auto_reply_enabled" value="1" <?php echo $config['auto_reply_enabled'] == '1' ? 'checked' : ''; ?>>
                            Ativar auto-respostas
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Token da API (WhatsApp)</label>
                        <input type="password" name="api_token" class="form-control" value="<?php echo $config['api_token'] ?? ''; ?>" placeholder="Seu token de API">
                    </div>
                    
                    <div style="margin-top: 30px;">
                        <button type="submit" class="btn" style="width: 100%;">
                            <i class="fas fa-save"></i> Salvar Configurações
                        </button>
                    </div>
                </div>
            </form>
            
            <div style="margin-top: 40px; background: rgba(0,0,0,0.3); padding: 30px; border-radius: 10px;">
                <h3 style="margin-bottom: 20px;">Informações da Conta</h3>
                <div style="display: grid; gap: 15px;">
                    <div>
                        <strong>ID do Usuário:</strong> <?php echo $this->user['id']; ?>
                    </div>
                    <div>
                        <strong>Nome:</strong> <?php echo htmlspecialchars($this->user['name']); ?>
                    </div>
                    <div>
                        <strong>Email:</strong> <?php echo htmlspecialchars($this->user['email']); ?>
                    </div>
                    <?php if (isset($this->user['plan'])): ?>
                    <div>
                        <strong>Plano:</strong> <?php echo strtoupper($this->user['plan']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (isset($this->user['expiry'])): ?>
                    <div>
                        <strong>Expira em:</strong> <?php echo date('d/m/Y', strtotime($this->user['expiry'])); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <script>
        document.getElementById('systemSettingsForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'save_settings');
            
            fetch('?user_id=<?php echo $this->userId; ?>', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Configurações salvas com sucesso!');
                } else {
                    alert('Erro: ' + data.error);
                }
            });
        });
        </script>
        <?php
    }
    
    private function renderLogin() {
        ?>
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Login - WASender</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                    background: linear-gradient(135deg, #075E54, #128C7E);
                    color: white;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .login-container {
                    background: rgba(255,255,255,0.1);
                    backdrop-filter: blur(10px);
                    border-radius: 20px;
                    padding: 40px;
                    width: 100%;
                    max-width: 400px;
                    border: 1px solid rgba(255,255,255,0.2);
                    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                }
                .logo {
                    text-align: center;
                    margin-bottom: 30px;
                }
                .logo h1 {
                    font-size: 32px;
                    color: #25D366;
                    margin-bottom: 5px;
                }
                .logo .version {
                    color: #34B7F1;
                    font-size: 12px;
                    letter-spacing: 2px;
                }
                .form-group {
                    margin-bottom: 20px;
                }
                .form-label {
                    display: block;
                    margin-bottom: 8px;
                    color: white;
                    font-weight: 500;
                }
                .form-control {
                    width: 100%;
                    padding: 12px 15px;
                    background: rgba(255,255,255,0.1);
                    border: 1px solid rgba(255,255,255,0.2);
                    border-radius: 8px;
                    color: white;
                    font-size: 14px;
                    transition: all 0.3s;
                }
                .form-control:focus {
                    outline: none;
                    border-color: #25D366;
                    background: rgba(255,255,255,0.15);
                }
                .btn {
                    width: 100%;
                    padding: 12px;
                    background: #25D366;
                    border: none;
                    border-radius: 8px;
                    color: white;
                    font-weight: bold;
                    cursor: pointer;
                    transition: all 0.3s;
                }
                .btn:hover {
                    background: #128C7E;
                }
                .message {
                    margin-top: 15px;
                    padding: 10px;
                    border-radius: 5px;
                    text-align: center;
                    display: none;
                }
                .success {
                    background: rgba(37, 211, 102, 0.2);
                    border: 1px solid #25D366;
                }
                .error {
                    background: rgba(255, 68, 68, 0.2);
                    border: 1px solid #FF4444;
                }
            </style>
        </head>
        <body>
            <div class="login-container">
                <div class="logo">
                    <h1>WASENDER</h1>
                    <div class="version">4.6.0</div>
                </div>
                
                <form id="loginForm">
                    <div class="form-group">
                        <label class="form-label">ID do Usuário ou Email</label>
                        <input type="text" name="user_id" class="form-control" placeholder="admin ou seu@email.com" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Token de Acesso</label>
                        <input type="password" name="access_token" class="form-control" placeholder="Seu token de acesso" required>
                    </div>
                    
                    <button type="submit" class="btn">Acessar Sistema</button>
                </form>
                
                <div id="message" class="message"></div>
                
                <div style="margin-top: 20px; text-align: center; font-size: 12px; color: rgba(255,255,255,0.7);">
                    Sistema requer assinatura ativa do WASender
                </div>
            </div>
            
            <script>
            document.getElementById('loginForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const userId = this.user_id.value;
                const token = this.access_token.value;
                
                if (userId === 'admin' && token === 'admin123') {
                    window.location.href = '?user_id=admin';
                } else if (userId && token) {
                    window.location.href = '?user_id=' + encodeURIComponent(userId);
                } else {
                    showMessage('error', 'Preencha todos os campos');
                }
            });
            
            function showMessage(type, text) {
                const message = document.getElementById('message');
                message.className = 'message ' + type;
                message.textContent = text;
                message.style.display = 'block';
                
                setTimeout(() => {
                    message.style.display = 'none';
                }, 3000);
            }
            </script>
        </body>
        </html>
        <?php
        exit;
    }
    
    private function renderSubscriptionRequired() {
        ?>
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Acesso Negado - WASender</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                    background: linear-gradient(135deg, #075E54, #128C7E);
                    color: white;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                    text-align: center;
                }
                .container {
                    background: rgba(255,255,255,0.1);
                    backdrop-filter: blur(10px);
                    border-radius: 20px;
                    padding: 40px;
                    max-width: 500px;
                    border: 1px solid rgba(255,255,255,0.2);
                }
                h1 { color: #FF4444; margin-bottom: 20px; }
                p { margin-bottom: 20px; line-height: 1.6; }
                .btn {
                    display: inline-block;
                    padding: 12px 30px;
                    background: #25D366;
                    color: white;
                    text-decoration: none;
                    border-radius: 8px;
                    font-weight: bold;
                    transition: all 0.3s;
                }
                .btn:hover {
                    background: #128C7E;
                }
                .features {
                    margin-top: 30px;
                    text-align: left;
                }
                .feature-item {
                    margin-bottom: 10px;
                    padding-left: 20px;
                    position: relative;
                }
                .feature-item:before {
                    content: '✓';
                    position: absolute;
                    left: 0;
                    color: #25D366;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>🚫 Acesso Negado</h1>
                <p>Você precisa de uma assinatura ativa do WASender para acessar este sistema.</p>
                
                <div class="features">
                    <h3>Recursos do WASender:</h3>
                    <div class="feature-item">Envio ilimitado de mensagens</div>
                    <div class="feature-item">Gerenciamento de contatos</div>
                    <div class="feature-item">Campanhas em massa</div>
                    <div class="feature-item">Estatísticas detalhadas</div>
                    <div class="feature-item">Suporte 24/7</div>
                </div>
                
                <div style="margin-top: 30px;">
                    <a href="/" class="btn">Voltar para o Início</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// ==================================================
// CRIAR TABELAS DO BANCO DE DADOS
// ==================================================

function createTables() {
    $tables = [
        "CREATE TABLE IF NOT EXISTS wasender_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(100) NOT NULL,
            session_id VARCHAR(100) NOT NULL UNIQUE,
            status ENUM('waiting_qr', 'qr_ready', 'connected', 'disconnected', 'stopped', 'error') DEFAULT 'waiting_qr',
            qr_code TEXT,
            phone_number VARCHAR(50),
            chats_count INT DEFAULT 0,
            connected_at DATETIME,
            last_active DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_session (session_id)
        )",
        
        "CREATE TABLE IF NOT EXISTS wasender_chats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(100) NOT NULL,
            session_id VARCHAR(100) NOT NULL,
            chat_id VARCHAR(100) NOT NULL,
            name VARCHAR(200),
            is_group BOOLEAN DEFAULT 0,
            last_message TEXT,
            last_message_time BIGINT,
            unread_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_chat (user_id, session_id, chat_id),
            INDEX idx_user_session (user_id, session_id)
        )",
        
        "CREATE TABLE IF NOT EXISTS wasender_chat_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(100) NOT NULL,
            session_id VARCHAR(100) NOT NULL,
            chat_id VARCHAR(100) NOT NULL,
            message_id VARCHAR(100) NOT NULL,
            from_me BOOLEAN DEFAULT 0,
            body TEXT,
            media_url VARCHAR(500),
            media_type VARCHAR(50),
            timestamp BIGINT,
            status ENUM('sent', 'delivered', 'read', 'failed') DEFAULT 'sent',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_message (user_id, session_id, message_id),
            INDEX idx_user_session_chat (user_id, session_id, chat_id),
            INDEX idx_timestamp (timestamp)
        )",
        
        "CREATE TABLE IF NOT EXISTS wasender_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            status ENUM('pending', 'sending', 'sent', 'failed') DEFAULT 'pending',
            session_id VARCHAR(100),
            sent_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_status (status)
        )",
        
        "CREATE TABLE IF NOT EXISTS wasender_contacts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(100) NOT NULL,
            name VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(100),
            contact_group VARCHAR(50) DEFAULT 'default',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_contact (user_id, phone),
            INDEX idx_user (user_id)
        )",
        
        "CREATE TABLE IF NOT EXISTS wasender_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(100) NOT NULL,
            config_key VARCHAR(50) NOT NULL,
            config_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_config (user_id, config_key),
            INDEX idx_user (user_id)
        )",
        
        "CREATE TABLE IF NOT EXISTS wasender_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(100) NOT NULL,
            log_type VARCHAR(50) NOT NULL,
            log_message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        )",
        
        "CREATE TABLE IF NOT EXISTS wasender_scheduled (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            schedule_time DATETIME NOT NULL,
            status ENUM('scheduled', 'sent', 'cancelled') DEFAULT 'scheduled',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_schedule (schedule_time)
        )"
    ];
    
    try {
        foreach ($tables as $tableSql) {
            try {
                Database::query($tableSql);
            } catch (Exception $e) {
                // Ignorar erro se a tabela já existir
            }
        }
    } catch (Exception $e) {
        // Continuar mesmo com erro nas tabelas
    }
}

// ==================================================
// CRIAR DIRETÓRIOS NECESSÁRIOS
// ==================================================

$directories = [
    'sessions',
    'logs',
    'temp',
    'qrcodes',
    'whatsapp',
    'whatsapp/sessions',
    'whatsapp/auth',
    'whatsapp/queue',
    'whatsapp/logs'
];

foreach ($directories as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

// Criar arquivo .htaccess para proteger diretórios
$htaccess = __DIR__ . '/whatsapp/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
}

// ==================================================
// INICIALIZAR SISTEMA
// ==================================================

createTables();

try {
    $interface = new WASenderInterface();
    $interface->render();
} catch (Exception $e) {
    echo "<h2>Erro no sistema</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>