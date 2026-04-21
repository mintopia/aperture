#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${ROOT_DIR}"

STATE_DIR="${ROOT_DIR}/.dev-env"
PID_DIR="${STATE_DIR}/pids"
MODE_FILE="${STATE_DIR}/mode"
START_MODE="${DEV_START_MODE:-auto}"
TRAEFIK_CONTAINER="${DEV_TRAEFIK_CONTAINER:-traefik}"
APP_HOSTNAME="${PUBLIC_APP_HOSTNAME:-hallowed-rincewind.ws.cloudagent.mintopia.net}"
REVERB_HOSTNAME="${PUBLIC_REVERB_HOSTNAME:-reverb.hallowed-rincewind.ws.cloudagent.mintopia.net}"
VITE_HOSTNAME="${PUBLIC_VITE_HOSTNAME:-vite.hallowed-rincewind.ws.cloudagent.mintopia.net}"
SSH_PROXY_HOSTNAME="${PUBLIC_SSH_PROXY_HOSTNAME:-ssh-proxy.hallowed-rincewind.ws.cloudagent.mintopia.net}"
CURL_TIMEOUT="${DEV_HEALTH_CURL_TIMEOUT:-5}"
TCP_TIMEOUT="${DEV_HEALTH_TCP_TIMEOUT:-3}"
LOCAL_APP_HOST="${DEV_APP_HOST:-127.0.0.1}"
LOCAL_APP_PORT="${DEV_APP_PORT:-8000}"
LOCAL_REVERB_HOST="${DEV_REVERB_HOST:-127.0.0.1}"
LOCAL_REVERB_PORT="${DEV_REVERB_PORT:-8080}"
LOCAL_VITE_HOST="${DEV_VITE_HOST:-127.0.0.1}"
LOCAL_VITE_PORT="${DEV_VITE_PORT:-5173}"
LOCAL_VITE_SCHEME="${DEV_VITE_SCHEME:-https}"
LOCAL_SSH_PROXY_HOST="${DEV_SSH_PROXY_HOST:-127.0.0.1}"
LOCAL_SSH_PROXY_PORT="${DEV_SSH_PROXY_PORT:-8022}"

PASS_COUNT=0
FAIL_COUNT=0

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

is_traefik_running() {
    docker ps --format '{{.Names}}' 2>/dev/null | grep -Fxq "${TRAEFIK_CONTAINER}"
}

determine_compose_mode() {
    case "${START_MODE}" in
        auto)
            if is_traefik_running; then
                echo "traefik"
            else
                echo "ports"
            fi
            ;;
        traefik|ports)
            echo "${START_MODE}"
            ;;
        *)
            echo "Error: DEV_START_MODE must be one of: auto, traefik, ports." >&2
            exit 1
            ;;
    esac
}

determine_runtime_mode() {
    if [[ -f "${MODE_FILE}" ]]; then
        local recorded
        recorded="$(cat "${MODE_FILE}" 2>/dev/null || true)"
        if [[ "${recorded}" == "local" || "${recorded}" == "compose" ]]; then
            echo "${recorded}"
            return
        fi
    fi

    if compose_available; then
        echo "compose"
    else
        echo "local"
    fi
}

mark_pass() {
    local message="$1"
    PASS_COUNT=$((PASS_COUNT + 1))
    echo "PASS: ${message}"
}

mark_fail() {
    local message="$1"
    FAIL_COUNT=$((FAIL_COUNT + 1))
    echo "FAIL: ${message}"
}

check_tcp() {
    local name="$1"
    local host="$2"
    local port="$3"

    if timeout "${TCP_TIMEOUT}" bash -c ":</dev/tcp/${host}/${port}" >/dev/null 2>&1; then
        mark_pass "${name} TCP ${host}:${port} reachable"
    else
        mark_fail "${name} TCP ${host}:${port} unreachable"
    fi
}

check_http() {
    local name="$1"
    local url="$2"
    local status

    status="$(curl -k -sS --max-time "${CURL_TIMEOUT}" --output /dev/null --write-out '%{http_code}' "${url}" || true)"
    if [[ -n "${status}" && "${status}" != "000" ]]; then
        mark_pass "${name} HTTP ${url} reachable (status ${status})"
    else
        mark_fail "${name} HTTP ${url} unreachable"
    fi
}

check_pid() {
    local service="$1"
    local pid_file="${PID_DIR}/${service}.pid"
    if [[ ! -f "${pid_file}" ]]; then
        mark_fail "${service} pid file missing (${pid_file})"
        return
    fi

    local pid
    pid="$(cat "${pid_file}" 2>/dev/null || true)"
    if [[ "${pid}" == external:* ]]; then
        mark_pass "${service} externally managed (${pid#external:})"
        return
    fi
    if [[ ! "${pid}" =~ ^[0-9]+$ ]]; then
        mark_fail "${service} pid file invalid (${pid_file})"
        return
    fi

    if kill -0 "${pid}" >/dev/null 2>&1; then
        mark_pass "${service} process running (pid ${pid})"
    else
        mark_fail "${service} process not running (stale pid ${pid})"
    fi
}

RUNTIME_MODE="$(determine_runtime_mode)"
echo "Dev health runtime: ${RUNTIME_MODE}"

if [[ "${RUNTIME_MODE}" == "local" ]]; then
    echo
    echo "Local process checks"
    check_pid "aperture"
    check_pid "reverb"
    check_pid "horizon"
    check_pid "scheduler"
    check_pid "vite"
    check_pid "ssh-proxy"

    echo
    echo "Local endpoint checks"
    check_tcp "app" "${LOCAL_APP_HOST}" "${LOCAL_APP_PORT}"
    check_http "app" "http://${LOCAL_APP_HOST}:${LOCAL_APP_PORT}"
    check_tcp "reverb" "${LOCAL_REVERB_HOST}" "${LOCAL_REVERB_PORT}"
    check_http "reverb" "http://${LOCAL_REVERB_HOST}:${LOCAL_REVERB_PORT}"
    check_tcp "vite" "${LOCAL_VITE_HOST}" "${LOCAL_VITE_PORT}"
    check_http "vite" "${LOCAL_VITE_SCHEME}://${LOCAL_VITE_HOST}:${LOCAL_VITE_PORT}"
    check_tcp "ssh-proxy" "${LOCAL_SSH_PROXY_HOST}" "${LOCAL_SSH_PROXY_PORT}"
    check_http "ssh-proxy" "http://${LOCAL_SSH_PROXY_HOST}:${LOCAL_SSH_PROXY_PORT}/health"
else
    MODE="$(determine_compose_mode)"
    echo "Compose health check mode: ${MODE} (DEV_START_MODE=${START_MODE}, TRAEFIK_CONTAINER=${TRAEFIK_CONTAINER})"

    echo
    echo "Hostname checks (Traefik routes)"
    check_tcp "app" "${APP_HOSTNAME}" "443"
    check_http "app" "https://${APP_HOSTNAME}"
    check_tcp "reverb" "${REVERB_HOSTNAME}" "443"
    check_http "reverb" "https://${REVERB_HOSTNAME}"
    check_tcp "vite" "${VITE_HOSTNAME}" "443"
    check_http "vite" "https://${VITE_HOSTNAME}"
    check_tcp "ssh-proxy" "${SSH_PROXY_HOSTNAME}" "443"
    check_http "ssh-proxy" "https://${SSH_PROXY_HOSTNAME}/health"

    if [[ "${MODE}" != "traefik" ]]; then
        echo
        echo "Local fallback checks (ports mode)"
        check_tcp "app" "127.0.0.1" "8000"
        check_http "app" "http://127.0.0.1:8000"
        check_tcp "reverb" "127.0.0.1" "8080"
        check_http "reverb" "http://127.0.0.1:8080"
        check_tcp "vite" "127.0.0.1" "5173"
        check_http "vite" "http://127.0.0.1:5173"
        check_tcp "ssh-proxy" "127.0.0.1" "8022"
        check_http "ssh-proxy" "http://127.0.0.1:8022/health"
    fi
fi

echo
echo "Summary: ${PASS_COUNT} passed, ${FAIL_COUNT} failed."
if [[ "${FAIL_COUNT}" -gt 0 ]]; then
    exit 1
fi
