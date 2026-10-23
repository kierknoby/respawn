#!/bin/sh
set -eu
export XDEBUG_MODE=off
find module tests tools development/local-pond -name '*.php' -print | while IFS= read -r file; do php -l "$file"; done
php tests/run.php
php tests/preflight.php
php tests/native-chain.php
php tests/build-chain.php
php tests/corrective.php
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
python3 tests/local-pond.py
git diff --check