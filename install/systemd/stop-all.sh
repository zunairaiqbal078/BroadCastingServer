#!/bin/bash
#
# stop-all.sh — CastPanel SHOUTcast Stop All
# Detiene todos los procesos sc_serv2 corriendo
#

echo "Deteniendo todos los procesos sc_serv2 ..."
pkill -f sc_serv2 2>/dev/null || true
sleep 1
echo "Todos los procesos sc_serv2 detenidos."
