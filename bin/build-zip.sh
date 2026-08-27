#!/usr/bin/env bash
# Build promo-engine.zip ready for "Plugins → Add New → Upload".
set -euo pipefail

cd "$(dirname "$0")/.."
rm -f promo-engine.zip
zip -rq promo-engine.zip promo-engine \
	-x "promo-engine/.DS_Store" -x "*/.DS_Store"
echo "Built $(pwd)/promo-engine.zip ($(du -h promo-engine.zip | cut -f1))"
