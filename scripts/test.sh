#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php vendor/bin/phpunit
if [[ -f tests/js/admin.test.cjs ]]; then node tests/js/admin.test.cjs; fi
