#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/dev-common.sh"
docker compose down
exec docker compose up -d --build --wait "$@"
