#!/bin/sh
set -eu
export XDEBUG_MODE=off
find module pond tests tools development/local-pond -name '*.php' -print | while IFS= read -r file; do php -l "$file"; done
php tests/run.php
php tests/preflight.php
php tests/native-chain.php
php tests/build-chain.php
php tests/corrective.php
php tests/revisions.php
php tests/revision-capacity.php
php tests/storage-readonly.php
php tests/storage-ownership.php
php tests/freepbx-gui.php
php tests/protection.php
php tests/pond.php
php tests/pond-ordering.php
php tools/preflight.php --role=pond
php tools/preflight.php --role=recovery
php tests/federation.php
python3 tests/local-pond.py --contracts-only
python3 tests/measurement.py
python3 tests/schema.py
python3 tests/canonicalization.py
node --check website/assets/app.js
node --check development/local-pond/public/assets/app.js
sh -n development/local-pond/bin/start.sh
sh -n development/local-pond/bin/stop.sh
sh -n development/local-pond/bin/reset.sh
python3 tools/build_site.py
python3 tools/package.py
python3 tests/package.py
python3 -c "import xml.etree.ElementTree as E; E.parse('module/module.xml')"
python3 -m json.tool contracts/v1.schema.json > /dev/null
python3 tests/site.py
if python3 -c "from playwright.sync_api import sync_playwright; p = sync_playwright().start(); b = p.chromium.launch(); b.close(); p.stop()" >/dev/null 2>&1; then
	python3 tests/local-pond.py
elif [ "${CI:-}" = "true" ]; then
	printf '%s\n' 'FAIL: Local Pond browser tests require a launchable Playwright Chromium in CI.'
	exit 1
else
	printf '%s\n' 'SKIP: Local Pond browser tests unavailable because Playwright Chromium could not launch in this environment.'
fi
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	git diff --check
else
	printf '%s\n' 'SKIP: git diff --check requires a Git working tree.'
fi