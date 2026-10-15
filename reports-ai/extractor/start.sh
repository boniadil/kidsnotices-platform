#!/usr/bin/env bash
# Reports AI — file-reading service (PDF, .doc, .ppt) on this computer (Linux / macOS).
# Starts the service (PHP + pdftotext) and a Cloudflare tunnel, then prints the two lines for the
# hosting's .env file: RAI_EXTRACT_URL and RAI_EXTRACT_KEY.
# Needs: php 8.1+, pdftotext (apt install poppler-utils | brew install poppler), cloudflared.
# Keep this terminal open; Ctrl+C stops the service.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
root="$(dirname "$here")"
port="${RAI_EXTRACT_PORT:-11436}"

for bin in php pdftotext cloudflared; do
  command -v "$bin" >/dev/null || { echo "Missing: $bin" >&2; exit 1; }
done
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo "PHP 8.1+ is required" >&2; exit 1; }

key_file="$here/key.txt"
if [ ! -f "$key_file" ]; then
  php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");' > "$key_file"
  chmod 600 "$key_file"
fi
key="$(tr -d '[:space:]' < "$key_file")"

pids=()
cleanup() { for p in "${pids[@]}"; do kill "$p" 2>/dev/null || true; done; }
trap cleanup EXIT INT TERM

(cd "$root" && exec php -d upload_max_filesize=100M -d post_max_size=110M -d memory_limit=1024M -d max_execution_time=0 \
  -S "127.0.0.1:$port" "$here/server.php" 2>"$here/server.log" >/dev/null) &
pids+=($!)
for _ in $(seq 1 30); do
  health="$(curl -fsS -H "Authorization: Bearer $key" "http://127.0.0.1:$port/health" 2>/dev/null || true)"
  [ -n "$health" ] && break
  sleep 0.5
done
[ -n "$health" ] || { echo "The service did not start, see $here/server.log" >&2; exit 1; }
echo "Service: $health"

echo "Opening the tunnel... (10 to 30 seconds)"
: > "$here/tunnel.log"
cloudflared tunnel --no-autoupdate --url "http://127.0.0.1:$port" >"$here/tunnel.log" 2>&1 &
pids+=($!)
url=""
for _ in $(seq 1 90); do
  url="$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$here/tunnel.log" | head -1 || true)"
  [ -n "$url" ] && break
  sleep 1
done
[ -n "$url" ] || { echo "The tunnel did not start, see $here/tunnel.log" >&2; exit 1; }

echo
echo "============ PUT THESE IN THE HOSTING'S .env FILE ============"
echo " RAI_EXTRACT_URL=$url"
echo " RAI_EXTRACT_KEY=$key"
echo "=============================================================="
echo "Keep this terminal open; Ctrl+C stops the service. The address changes at every start."
wait -n "${pids[@]}" || true
echo "The service or the tunnel stopped."
