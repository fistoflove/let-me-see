#!/usr/bin/env bash
set -euo pipefail

NAME=${SWOOLE_CONTAINER_NAME:-let-me-see-swoole}
PORT=${SWOOLE_PORT:-9601}
TARGET_PORT=${SWOOLE_LISTEN_PORT:-9501}
IMAGE=${SWOOLE_IMAGE:-openswoole/swoole:php8.3-alpine}

if ! command -v docker >/dev/null 2>&1; then
  echo "docker: command not found" >&2
  exit 1
fi

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)

# Stop any existing container with the same name
if docker ps -aq --filter "name=^${NAME}$" >/dev/null 2>&1 && [ -n "$(docker ps -aq --filter "name=^${NAME}$")" ]; then
  docker stop "$NAME" >/dev/null 2>&1 || true
  docker rm "$NAME" >/dev/null 2>&1 || true
fi

echo "Starting OpenSwoole container '${NAME}' on port ${PORT}..."

docker run -d --rm \
  --name "$NAME" \
  -p "${PORT}:${TARGET_PORT}" \
  -e SWOOLE_LISTEN_PORT="${TARGET_PORT}" \
  -e CHROME_PATH=/usr/bin/chromium \
  -v "${ROOT_DIR}:/app" \
  -w /app \
  "$IMAGE" \
  sh -lc "apk add --no-cache chromium nss freetype harfbuzz ttf-freefont >/dev/null && php swoole_server.php"

echo "Container started. Use 'bin/stop-swoole.sh' to stop it."
