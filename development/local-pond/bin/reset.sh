#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
export RESPAWN_LOCAL_DEMO_ROOT="$ROOT/build/local-demo"
php "$(dirname -- "$0")/reset.php" --confirm=LOCAL-DEMO-RESET