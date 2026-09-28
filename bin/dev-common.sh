#!/usr/bin/env bash
# Sourced by the dev-*.sh wrappers: picks the compose override files.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

files=(docker-compose.yaml)
if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "${DEV_TRAEFIK_CONTAINER:-traefik}"; then
    files+=(docker-compose.override.yml)
else
    files+=(docker-compose.override.ports.yml)
fi
[[ "${DEV_START_MODE:-}" == traefik ]] && files=(docker-compose.yaml docker-compose.override.yml)
[[ "${DEV_START_MODE:-}" == ports ]] && files=(docker-compose.yaml docker-compose.override.ports.yml)

COMPOSE_FILE="$(IFS=:; echo "${files[*]}")"
export COMPOSE_FILE
