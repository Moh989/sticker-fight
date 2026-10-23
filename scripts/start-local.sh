#!/usr/bin/env bash
set -euo pipefail
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_ROOT"
if [ ! -f .env ] || [ ! -d vendor ]; then
  echo 'Install the project using README.md first.'
  exit 1
fi
if [ -d .runtime/mysql-local ]; then
  MYSQL_SERVER_BIN="$(command -v mysqld || true)"
  if [ -z "$MYSQL_SERVER_BIN" ] && [ -x /opt/homebrew/opt/mysql/bin/mysqld ]; then
    MYSQL_SERVER_BIN=/opt/homebrew/opt/mysql/bin/mysqld
  fi
  if [ -n "$MYSQL_SERVER_BIN" ] && ! [ -S .runtime/mysql.sock ]; then
    "$MYSQL_SERVER_BIN" --no-defaults --datadir="$PROJECT_ROOT/.runtime/mysql-local" --socket="$PROJECT_ROOT/.runtime/mysql.sock" --port=3307 --bind-address=127.0.0.1 --mysqlx=0 --log-error="$PROJECT_ROOT/.runtime/mysql-server.log" --pid-file="$PROJECT_ROOT/.runtime/mysql.pid" &
    for attempt in $(seq 1 20); do
      if [ -S .runtime/mysql.sock ]; then break; fi
      sleep .25
    done
  fi
fi
mkdir -p .runtime
LOCAL_PHP_INI_DIR="$PROJECT_ROOT/.runtime/php"
mkdir -p "$LOCAL_PHP_INI_DIR"
cat > "$LOCAL_PHP_INI_DIR/99-sticker-local.ini" <<'PHPINI'
upload_max_filesize=12M
post_max_size=16M
memory_limit=512M
PHPINI
export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$LOCAL_PHP_INI_DIR"
if [ ! -f public/build/manifest.json ]; then npm run build; fi
php artisan queue:work --sleep=2 --tries=3 --timeout=210 > .runtime/queue.log 2>&1 &
QUEUE_WORKER_PID=$!
trap 'kill "$QUEUE_WORKER_PID" 2>/dev/null || true' EXIT INT TERM
php artisan serve --host=127.0.0.1 --port="${PORT:-8000}" --no-reload
