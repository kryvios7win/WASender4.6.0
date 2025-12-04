#!/bin/bash

# Script de deploy para Hostinger

echo "=== DEPLOY WASENDER ==="

# Configurações
PROJECT_DIR="/home/u695379688/domains/seu-dominio.com/public_html"
BACKUP_DIR="/home/u695379688/backups"
DATE=$(date +%Y%m%d_%H%M%S)

# Criar backup
echo "Criando backup..."
mkdir -p $BACKUP_DIR
tar -czf $BACKUP_DIR/wasender_backup_$DATE.tar.gz $PROJECT_DIR

# Atualizar código
echo "Atualizando código..."
cd $PROJECT_DIR
git pull origin main

# Configurar permissões
echo "Configurando permissões..."
chmod -R 755 $PROJECT_DIR
chmod -R 777 $PROJECT_DIR/sessions
chmod -R 777 $PROJECT_DIR/logs
chmod -R 777 $PROJECT_DIR/temp
chmod -R 777 $PROJECT_DIR/qrcodes
chmod -R 777 $PROJECT_DIR/whatsapp

# Instalar dependências Node.js
echo "Instalando dependências Node.js..."
cd $PROJECT_DIR/node
npm install --production

# Reiniciar serviços
echo "Reiniciando serviços..."
pm2 restart wasender || pm2 start $PROJECT_DIR/node/whatsapp-server.js --name wasender

echo "Deploy concluído!"