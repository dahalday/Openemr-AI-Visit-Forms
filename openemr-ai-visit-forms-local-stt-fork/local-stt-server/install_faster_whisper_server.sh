#!/usr/bin/env bash
set -euo pipefail

SERVER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VENV_DIR="$SERVER_DIR/.venv"

if ! command -v python3 >/dev/null 2>&1; then
  echo "python3 is required" >&2
  exit 1
fi

if ! command -v ffmpeg >/dev/null 2>&1; then
  echo "ffmpeg is required. Install it first, for example: sudo apt install ffmpeg" >&2
  exit 1
fi

python3 -m venv "$VENV_DIR"
"$VENV_DIR/bin/python" -m pip install --upgrade pip wheel
"$VENV_DIR/bin/python" -m pip install -r "$SERVER_DIR/requirements.txt"

echo "Installed faster-whisper server environment in $VENV_DIR"
echo "Start it with:"
echo "$SERVER_DIR/start_faster_whisper_server.sh"
