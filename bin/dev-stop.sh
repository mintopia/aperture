#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

COMPOSE_FILES=(-f docker-compose.yaml)
SERVICES=(vite reverb horizon scheduler aperture ssh-proxy redis db)
STATE_DIR="${ROOT_DIR}/.dev-env"
PID_DIR="${STATE_DIR}/pids"
MODE_FILE="${STATE_DIR}/mode"
START_MODE="${DEV_START_MODE:-auto}"
TRAEFIK_CONTAINER="${DEV_TRAEFIK_CONTAINER:-traefik}"
STOP_TIMEOUT="${DEV_STOP_TIMEOUT:-10}"

compose_available() {
    if docker compose version >/dev/null 2>&1; then
        COMPOSE=(docker compose)
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE=(docker-compose)
    else
        return 1
    fi

    if ! docker info >/dev/null 2>&1; then
        return 1
    fi

    return 0
}

is_pid_running() {
    local pid_file="$1"
    [[ -f "${pid_file}" ]] || return 1

    local pid
    pid="$(cat "${pid_file}" 2>/dev/null || true)"
    [[ "${pid}" =~ ^[0-9]+$ ]] || return 1
    kill -0 "${pid}" >/dev/null 2>&1
}

stop_local_service() {
    local service="$1"
    local pid_file="${PID_DIR}/${service}.pid"
    local log_file="${STATE_DIR}/logs/${service}.log"

    local pid
    pid="$(cat "${pid_file}" 2>/dev/null || true)"
    if [[ "${pid}" == external:* ]]; then
        echo "[local] ${service} is externally managed (${pid#external:}); leaving it running."
        rm -f "${pid_file}"
        return 0
    fi
    if [[ ! "${pid}" =~ ^[0-9]+$ ]] || ! kill -0 "${pid}" >/dev/null 2>&1; then
        rm -f "${pid_file}"
        echo "[local] ${service} not running."
        return 0
    fi

    echo "[local] stopping ${service} (pid ${pid})..."
    kill "${pid}" >/dev/null 2>&1 || true

    local elapsed=0
    while kill -0 "${pid}" >/dev/null 2>&1; do
        if (( elapsed >= STOP_TIMEOUT )); then
            echo "[local] ${service} did not stop in ${STOP_TIMEOUT}s, killing."
            kill -9 "${pid}" >/dev/null 2>&1 || true
            break
        fi
        sleep 1
        elapsed=$((elapsed + 1))
    done

    rm -f "${pid_file}"
    if [[ -f "${log_file}" ]]; then
        echo "[local] ${service} log: ${log_file}"
    fi
}

RUN_MODE=""
if [[ -f "${MODE_FILE}" ]]; then
    RUN_MODE="$(cat "${MODE_FILE}" 2>/dev/null || true)"
fi

if [[ "${RUN_MODE}" == "local" || -z "${RUN_MODE}" ]]; then
    if [[ -d "${PID_DIR}" ]]; then
        stop_local_service "vite"
        stop_local_service "reverb"
        stop_local_service "horizon"
        stop_local_service "scheduler"
        stop_local_service "aperture"
        stop_local_service "ssh-proxy"
    fi
fi

if [[ "${RUN_MODE}" == "compose" || -z "${RUN_MODE}" ]]; then
    if compose_available; then
        if [[ "${START_MODE}" == "traefik" ]]; then
            COMPOSE_FILES+=(-f docker-compose.override.traefik.yml)
        elif [[ "${START_MODE}" == "ports" ]]; then
            COMPOSE_FILES+=(-f docker-compose.override.ports.yml)
        elif docker ps --format '{{.Names}}' | grep -Fxq "${TRAEFIK_CONTAINER}"; then
            COMPOSE_FILES+=(-f docker-compose.override.traefik.yml)
        else
            COMPOSE_FILES+=(-f docker-compose.override.ports.yml)
        fi
        "${COMPOSE[@]}" "${COMPOSE_FILES[@]}" stop "${SERVICES[@]}" || true
    fi
fi

if [[ -d "${PID_DIR}" ]]; then
    rm -f "${PID_DIR}"/*.pid
fi

rm -f "${MODE_FILE}"

echo "Dev environment stopped."
