#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
DEMO_ROOT=${RESPAWN_LOCAL_DEMO_ROOT:-"$ROOT/build/local-demo"}
POND_PORT=${RESPAWN_LOCAL_POND_PORT:-8731}
DASHBOARD_PORT=${RESPAWN_LOCAL_DASHBOARD_PORT:-8732}
case "$DEMO_ROOT" in "$ROOT"/build/local-demo|"$ROOT"/build/local-demo-tests/*) ;; *)
    printf '%s\n' 'Refusing to inspect process IDs outside the named Local Pond runtime.' >&2; exit 2;; esac
if [ -L "$DEMO_ROOT" ] || [ ! -f "$DEMO_ROOT/.respawn-local-demo" ] \
    || [ "$(cat "$DEMO_ROOT/.respawn-local-demo")" != 'respawn-local-pond-dev-v1' ]; then
    printf '%s\n' 'Refusing to stop processes without the Local Pond ownership marker.' >&2; exit 2
fi
case "$POND_PORT" in ''|*[!0-9]*) printf '%s\n' 'Invalid Pond port.' >&2; exit 2;; esac
case "$DASHBOARD_PORT" in ''|*[!0-9]*) printf '%s\n' 'Invalid dashboard port.' >&2; exit 2;; esac
if [ "$POND_PORT" -lt 1024 ] || [ "$POND_PORT" -gt 65535 ] \
    || [ "$DASHBOARD_PORT" -lt 1024 ] || [ "$DASHBOARD_PORT" -gt 65535 ]; then
    printf '%s\n' 'Invalid port range.' >&2; exit 2
fi

stop_named() {
    name=$1
    expected=$2
    pid_file="$DEMO_ROOT/processes/$name.pid"
    [ -f "$pid_file" ] || return 0
    pid=$(cat "$pid_file")
    case "$pid" in ''|*[!0-9]*) printf 'Refusing invalid %s PID file.\n' "$name" >&2; return 1;; esac
    if [ ! -r "/proc/$pid/cmdline" ]; then rm -f -- "$pid_file"; return 0; fi
    command_line=$(tr '\000' ' ' < "/proc/$pid/cmdline")
    case "$command_line" in *"$expected"*) kill -TERM "$pid"; rm -f -- "$pid_file";; *)
        printf 'Refusing to stop PID %s; it is not the named Local Pond %s process.\n' "$pid" "$name" >&2; return 1;; esac
}

stop_named dashboard "127.0.0.1:$DASHBOARD_PORT"
stop_named worker '/development/local-pond/bin/worker.php'
stop_named pond "127.0.0.1:$POND_PORT"
printf '%s\n' 'Local Pond, worker and dashboard stopped. Persistent demo state was retained.'