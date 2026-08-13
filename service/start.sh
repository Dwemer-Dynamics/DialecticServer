#!/bin/bash

PORT=12347
SERVER_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVICE_LOG="$SERVER_ROOT/log/service.log"
FALLBACK_SERVICE_LOG="/tmp/dialectic_service.log"
LOG_DIR="$(dirname "$SERVICE_LOG")"
LOCK_FILE="/tmp/dialectic_background_processor.lock"

# Ensure new files are group-readable/writable for dwemer + www-data workflows.
umask 0002

# Prevent concurrent web requests from starting duplicate manager loops before
# the heartbeat listener has finished binding its port.
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "An instance of the DIALECTIC background processor is already starting or running."
    exit 1
fi

mkdir -p "$LOG_DIR" 2>/dev/null
if ! touch "$SERVICE_LOG" 2>/dev/null; then
    SERVICE_LOG="$FALLBACK_SERVICE_LOG"
    touch "$SERVICE_LOG" 2>/dev/null || true
fi

# Check if port is in use
if nc -z localhost "$PORT" 2>/dev/null; then
    echo "An instance of the script is already running."
    exit 1
fi

# Keep one socket bound for the lifetime of the manager. The previous one-shot
# listener briefly released the port after every health probe, which produced
# false connection-refused warnings while the processor was healthy.
nc -lk -p "$PORT" </dev/null &>/dev/null &
LISTENER_PID=$!

# Set trap for clean exit (still useful for SIGTERM, etc.)
trap "kill $LISTENER_PID 2>/dev/null" EXIT

# Main loop
while true; do 
    php "$SERVER_ROOT/service/manager.php" &>> "$SERVICE_LOG"
    sleep 5
done
