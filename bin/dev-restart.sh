#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

echo "Restarting dev environment..."
"${ROOT_DIR}/bin/dev-stop.sh" "$@"
"${ROOT_DIR}/bin/dev-start.sh" "$@"
