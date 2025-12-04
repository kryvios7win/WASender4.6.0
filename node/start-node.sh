#!/bin/bash

# Iniciar servidor Node.js

cd /home/u695379688/domains/seu-dominio.com/public_html/node

# Verificar se o Node.js está instalado
if ! command -v node &> /dev/null; then
    echo "Node.js não está instalado"
    exit 1
fi

# Iniciar com PM2 (recomendado) ou diretamente
if command -v pm2 &> /dev/null; then
    pm2 start whatsapp-server.js --name wasender
    pm2 save
    pm2 startup
else
    nohup node whatsapp-server.js > server.log 2>&1 &
    echo $! > server.pid
fi

echo "Servidor Node.js iniciado"