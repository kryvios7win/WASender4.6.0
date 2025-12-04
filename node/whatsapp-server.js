const WebSocket = require('ws');
const express = require('express');
const bodyParser = require('body-parser');
const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode');
const mysql = require('mysql2/promise');

const app = express();
app.use(bodyParser.json());

const wss = new WebSocket.Server({ port: 3000 });
const clients = new Map();

// Configuração do banco de dados
const dbConfig = {
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'u695379688_user',
    password: process.env.DB_PASS || 'Alakazam1311787535',
    database: process.env.DB_NAME || 'u695379688_mysql',
    port: process.env.DB_PORT || 3306
};

// API REST para status
app.get('/api/session/:sessionId', async (req, res) => {
    try {
        const connection = await mysql.createConnection(dbConfig);
        const [rows] = await connection.execute(
            'SELECT * FROM wasender_sessions WHERE session_id = ?',
            [req.params.sessionId]
        );
        connection.end();
        
        if (rows.length > 0) {
            res.json(rows[0]);
        } else {
            res.status(404).json({ error: 'Sessão não encontrada' });
        }
    } catch (error) {
        res.status(500).json({ error: error.message });
    }
});

// Iniciar servidor HTTP
const HTTP_PORT = process.env.HTTP_PORT || 3001;
app.listen(HTTP_PORT, () => {
    console.log(`Servidor HTTP rodando na porta ${HTTP_PORT}`);
});

// WebSocket Server
wss.on('connection', async (ws, req) => {
    const sessionId = new URL(req.url, `http://${req.headers.host}`).searchParams.get('session');
    
    if (!sessionId) {
        ws.close();
        return;
    }
    
    console.log(`Nova conexão WebSocket: ${sessionId}`);
    
    let connection;
    try {
        connection = await mysql.createConnection(dbConfig);
    } catch (error) {
        console.error('Erro na conexão com o banco:', error);
        ws.close();
        return;
    }
    
    // Configurar cliente WhatsApp
    const client = new Client({
        authStrategy: new LocalAuth({ clientId: sessionId }),
        puppeteer: {
            headless: process.env.NODE_ENV === 'production',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--no-zygote',
                '--disable-gpu'
            ]
        }
    });
    
    // Evento: QR Code
    client.on('qr', async (qr) => {
        console.log(`QR Code recebido para sessão: ${sessionId}`);
        
        try {
            const qrImage = await qrcode.toDataURL(qr);
            
            await connection.execute(
                'UPDATE wasender_sessions SET qr_code = ?, status = "qr_ready" WHERE session_id = ?',
                [qrImage, sessionId]
            );
            
            ws.send(JSON.stringify({
                type: 'qr',
                qr: qrImage,
                session: sessionId
            }));
        } catch (error) {
            console.error('Erro ao processar QR Code:', error);
        }
    });
    
    // Evento: Cliente pronto
    client.on('ready', async () => {
        console.log(`WhatsApp conectado para sessão: ${sessionId}`);
        
        try {
            const user = await client.getInfo();
            
            await connection.execute(
                'UPDATE wasender_sessions SET status = "connected", phone_number = ?, connected_at = NOW() WHERE session_id = ?',
                [user.wid.user, sessionId]
            );
            
            ws.send(JSON.stringify({
                type: 'ready',
                message: 'WhatsApp conectado!',
                phone: user.wid.user
            }));
            
            // Carregar contatos iniciais
            const chats = await client.getChats();
            for (let chat of chats.slice(0, 20)) {
                await saveChat(chat, sessionId, connection);
            }
        } catch (error) {
            console.error('Erro ao salvar sessão:', error);
        }
    });
    
    // Evento: Mensagem recebida
    client.on('message', async (message) => {
        console.log('Mensagem recebida:', message.body);
        
        try {
            // Salvar no banco
            await connection.execute(
                `INSERT INTO wasender_chat_messages 
                 (user_id, session_id, chat_id, message_id, from_me, body, timestamp)
                 VALUES (?, ?, ?, ?, ?, ?, ?)`,
                [
                    sessionId.split('_')[1] || 'unknown',
                    sessionId,
                    message.from,
                    message.id.id,
                    message.fromMe ? 1 : 0,
                    message.body,
                    message.timestamp
                ]
            );
            
            // Enviar via WebSocket
            ws.send(JSON.stringify({
                type: 'message',
                message: {
                    id: message.id.id,
                    from: message.from,
                    body: message.body,
                    timestamp: message.timestamp,
                    fromMe: message.fromMe
                }
            }));
        } catch (error) {
            console.error('Erro ao salvar mensagem:', error);
        }
    });
    
    // Evento: Desconectado
    client.on('disconnected', async (reason) => {
        console.log(`WhatsApp desconectado: ${reason}`);
        
        try {
            await connection.execute(
                'UPDATE wasender_sessions SET status = "disconnected" WHERE session_id = ?',
                [sessionId]
            );
            
            ws.send(JSON.stringify({
                type: 'disconnected',
                reason: reason
            }));
        } catch (error) {
            console.error('Erro ao atualizar status:', error);
        } finally {
            if (connection) await connection.end();
        }
    });
    
    // Inicializar cliente
    client.initialize();
    
    // Lidar com mensagens do WebSocket
    ws.on('message', async (data) => {
        try {
            const message = JSON.parse(data);
            
            switch (message.type) {
                case 'send_message':
                    const result = await client.sendMessage(message.to + '@c.us', message.text);
                    
                    // Salvar no banco
                    await connection.execute(
                        `INSERT INTO wasender_chat_messages 
                         (user_id, session_id, chat_id, message_id, from_me, body, timestamp)
                         VALUES (?, ?, ?, ?, ?, ?, ?)`,
                        [
                            sessionId.split('_')[1] || 'unknown',
                            sessionId,
                            message.to,
                            result.id.id,
                            1,
                            message.text,
                            Math.floor(Date.now() / 1000)
                        ]
                    );
                    
                    ws.send(JSON.stringify({
                        type: 'message_sent',
                        id: result.id.id,
                        to: message.to,
                        text: message.text
                    }));
                    break;
                    
                case 'get_chats':
                    const chats = await client.getChats();
                    ws.send(JSON.stringify({
                        type: 'chats',
                        chats: chats.slice(0, 50).map(chat => ({
                            chat_id: chat.id._serialized,
                            name: chat.name,
                            last_message: chat.lastMessage?.body || '',
                            last_message_time: chat.lastMessage?.timestamp || Date.now()
                        }))
                    }));
                    break;
            }
        } catch (error) {
            console.error('Erro ao processar mensagem WebSocket:', error);
        }
    });
    
    // Fechar conexão
    ws.on('close', async () => {
        console.log(`Conexão WebSocket fechada: ${sessionId}`);
        if (client) await client.destroy();
        if (connection) await connection.end();
    });
    
    // Salvar no mapa de clientes
    clients.set(sessionId, { ws, client });
});

// Função para salvar chat no banco
async function saveChat(chat, sessionId, connection) {
    try {
        await connection.execute(
            `INSERT INTO wasender_chats 
             (user_id, session_id, chat_id, name, is_group, last_message, last_message_time)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE 
             name = VALUES(name), last_message = VALUES(last_message), last_message_time = VALUES(last_message_time)`,
            [
                sessionId.split('_')[1] || sessionId,
                sessionId,
                chat.id._serialized,
                chat.name,
                chat.isGroup ? 1 : 0,
                chat.lastMessage?.body || '',
                chat.lastMessage?.timestamp || Date.now()
            ]
        );
    } catch (error) {
        console.error('Erro ao salvar chat:', error);
    }
}

console.log('Servidor WebSocket WhatsApp rodando na porta 3000');