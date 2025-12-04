#!/bin/bash

# Parar servidor Node.js

if command -v pm2 &> /dev/null; then
    pm2 stop wasender
    pm2 delete wasender
else
    if [ -f "server.pid" ]; then
        kill $(cat server.pid)
        rm server.pid
    fi
fi

echo "Servidor Node.js parado"