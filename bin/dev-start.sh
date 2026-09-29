#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/dev-common.sh"
exec docker compose up -d --build --wait "$@"
