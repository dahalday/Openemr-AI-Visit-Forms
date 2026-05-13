#!/usr/bin/env bash
set -euo pipefail

SERVER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VENV_DIR="$SERVER_DIR/.venv"

if [[ ! -x "$VENV_DIR/bin/python" ]]; then
  echo "Virtual environment not found. Run install_faster_whisper_server.sh first." >&2
  exit 1
fi

exec "$VENV_DIR/bin/python" "$SERVER_DIR/faster_whisper_server.py" --host "${FASTER_WHISPER_HOST:-127.0.0.1}" --port "${FASTER_WHISPER_PORT:-9010}"
