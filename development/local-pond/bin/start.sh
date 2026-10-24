#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
LOCAL="$ROOT/development/local-pond"
export RESPAWN_LOCAL_DEMO_ROOT=${RESPAWN_LOCAL_DEMO_ROOT:-"$ROOT/build/local-demo"}
export RESPAWN_LOCAL_POND_PORT=${RESPAWN_LOCAL_POND_PORT:-8731}
export RESPAWN_LOCAL_DASHBOARD_PORT=${RESPAWN_LOCAL_DASHBOARD_PORT:-8732}
PHP_BIN=${PHP_BIN:-php}

case "$RESPAWN_LOCAL_DEMO_ROOT" in "$ROOT"/build/local-demo|"$ROOT"/build/local-demo-tests/*|/tmp/respawn-local-demo-integration-*) ;; *)
    printf '%s\n' 'Refusing runtime state outside the named build/local-demo area.' >&2; exit 2;; esac
case "$RESPAWN_LOCAL_POND_PORT" in ''|*[!0-9]*) printf '%s\n' 'Pond port must be numeric.' >&2; exit 2;; esac
case "$RESPAWN_LOCAL_DASHBOARD_PORT" in ''|*[!0-9]*) printf '%s\n' 'Dashboard port must be numeric.' >&2; exit 2;; esac
if [ "$RESPAWN_LOCAL_POND_PORT" -lt 1024 ] || [ "$RESPAWN_LOCAL_POND_PORT" -gt 65535 ] \
    || [ "$RESPAWN_LOCAL_DASHBOARD_PORT" -lt 1024 ] || [ "$RESPAWN_LOCAL_DASHBOARD_PORT" -gt 65535 ]; then
    printf '%s\n' 'Ports must be between 1024 and 65535.' >&2; exit 2
fi
if [ "$RESPAWN_LOCAL_POND_PORT" = "$RESPAWN_LOCAL_DASHBOARD_PORT" ]; then
    printf '%s\n' 'Pond and dashboard ports must differ.' >&2; exit 2
fi

"$PHP_BIN" "$LOCAL/bin/init.php"
mkdir -p "$RESPAWN_LOCAL_DEMO_ROOT/processes" "$RESPAWN_LOCAL_DEMO_ROOT/logs"
chmod 700 "$RESPAWN_LOCAL_DEMO_ROOT/processes" "$RESPAWN_LOCAL_DEMO_ROOT/logs"

check_port() {
    "$PHP_BIN" -r '$socket = @fsockopen("127.0.0.1", (int) $argv[1], $errno, $error, 0.1); if ($socket) { fclose($socket); exit(1); }' "$1"
}
check_port "$RESPAWN_LOCAL_POND_PORT" || { printf '%s\n' 'Development Pond port is already in use.' >&2; exit 3; }
check_port "$RESPAWN_LOCAL_DASHBOARD_PORT" || { printf '%s\n' 'Dashboard port is already in use.' >&2; exit 3; }

POND_PORT=$RESPAWN_LOCAL_POND_PORT
DASHBOARD_PORT=$RESPAWN_LOCAL_DASHBOARD_PORT
nohup "$PHP_BIN" -S "127.0.0.1:$POND_PORT" -t "$LOCAL/public" "$LOCAL/pond-router.php" \
    >>"$RESPAWN_LOCAL_DEMO_ROOT/logs/pond.log" 2>&1 </dev/null &
POND_PID=$!
nohup "$PHP_BIN" -S "127.0.0.1:$DASHBOARD_PORT" -t "$LOCAL/public" "$LOCAL/dashboard-router.php" \
    >>"$RESPAWN_LOCAL_DEMO_ROOT/logs/dashboard.log" 2>&1 </dev/null &
DASHBOARD_PID=$!
nohup "$PHP_BIN" "$LOCAL/bin/worker.php" \
    >>"$RESPAWN_LOCAL_DEMO_ROOT/logs/worker.log" 2>&1 </dev/null &
WORKER_PID=$!
printf '%s\n' "$POND_PID" > "$RESPAWN_LOCAL_DEMO_ROOT/processes/pond.pid"
printf '%s\n' "$DASHBOARD_PID" > "$RESPAWN_LOCAL_DEMO_ROOT/processes/dashboard.pid"
printf '%s\n' "$WORKER_PID" > "$RESPAWN_LOCAL_DEMO_ROOT/processes/worker.pid"
chmod 600 "$RESPAWN_LOCAL_DEMO_ROOT/processes/"*.pid

ready=0
attempt=0
while [ "$attempt" -lt 40 ]; do
    if curl --silent --fail --max-time 1 "http://127.0.0.1:$POND_PORT/health" >/dev/null \
        && curl --silent --fail --max-time 1 "http://127.0.0.1:$DASHBOARD_PORT/" >/dev/null; then
        ready=1
        break
    fi
    attempt=$((attempt + 1))
done
if [ "$ready" -ne 1 ]; then
    printf '%s\n' 'Startup failed; stop only these named processes with bin/stop.sh.' >&2
    exit 4
fi
printf 'Respawn Development Pond demonstration is running.\nBrowser: http://127.0.0.1:%s/\nStop: %s/bin/stop.sh\nReset: %s/bin/reset.sh\n' \
    "$DASHBOARD_PORT" "$LOCAL" "$LOCAL"