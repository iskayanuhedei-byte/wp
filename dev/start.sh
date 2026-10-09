#!/usr/bin/env bash
set -euo pipefail
cd /workspace/wp
docker compose -f dev/compose.yaml up -d --wait --wait-timeout 120
docker exec wtsr-wp mkdir -p /var/www/html/wp-content/plugins/thumbnail-source-repair /opt/wtsr-dev
docker cp thumbnail-source-repair/. wtsr-wp:/var/www/html/wp-content/plugins/thumbnail-source-repair/
docker cp dev/setup.php wtsr-wp:/opt/wtsr-dev/setup.php
docker cp dev/runtime-network.php wtsr-wp:/opt/wtsr-dev/runtime-network.php
docker exec wtsr-wp mkdir -p /var/www/html/wp-content/mu-plugins
docker cp dev/development-network.php wtsr-wp:/var/www/html/wp-content/mu-plugins/wtsr-development-network.php
docker exec wtsr-wp chmod 644 /var/www/html/wp-content/mu-plugins/wtsr-development-network.php
docker exec wtsr-wp chown -R www-data:www-data /var/www/html/wp-content/plugins/thumbnail-source-repair /opt/wtsr-dev
docker exec wtsr-wp find /var/www/html/wp-content/plugins/thumbnail-source-repair /opt/wtsr-dev -type d -exec chmod 755 '{}' +
docker exec wtsr-wp find /var/www/html/wp-content/plugins/thumbnail-source-repair /opt/wtsr-dev -type f -exec chmod 644 '{}' +
docker compose -f dev/compose.yaml exec -T --user www-data wordpress php /opt/wtsr-dev/setup.php
curl --fail --silent --show-error http://127.0.0.1:8088/ -o /tmp/wtsr-home.html
python3 - <<'PY'
from pathlib import Path
body = Path('/tmp/wtsr-home.html').read_text()
if 'Thumbnail Source Repair Development' not in body:
    raise SystemExit('Development homepage did not render the expected site.')
print('Development homepage rendered successfully.')
PY
