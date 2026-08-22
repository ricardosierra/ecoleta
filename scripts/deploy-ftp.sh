#!/usr/bin/env bash

# Compila o site estático e publica o conteúdo de out/ via FTP.
# Uso: npm run deploy:ftp
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/.env}"
CLI_FTP_HOST="${FTP_HOST:-}"
CLI_FTP_USER="${FTP_USER:-}"
CLI_FTP_PASSWORD="${FTP_PASSWORD:-}"
CLI_FTP_UPLOAD_PATH="${FTP_UPLOAD_PATH:-}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Arquivo de ambiente não encontrado: $ENV_FILE" >&2
  exit 1
fi

# O arquivo é local e ignorado pelo Git. `set -a` disponibiliza as chaves ao build.
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

FTP_HOST="${CLI_FTP_HOST:-${FTP_HOST:-}}"
FTP_USER="${CLI_FTP_USER:-${FTP_USER:-}}"
FTP_PASSWORD="${CLI_FTP_PASSWORD:-${FTP_PASSWORD:-}}"
FTP_UPLOAD_PATH="${CLI_FTP_UPLOAD_PATH:-${FTP_UPLOAD_PATH:-.}}"

for required in FTP_HOST FTP_USER FTP_PASSWORD FTP_UPLOAD_PATH; do
  if [[ -z "${!required}" ]]; then
    echo "Variável obrigatória ausente: $required" >&2
    exit 1
  fi
done

FTP_HOST="${FTP_HOST%/}"
FTP_UPLOAD_PATH="${FTP_UPLOAD_PATH#/}"
FTP_UPLOAD_PATH="${FTP_UPLOAD_PATH%/}"
FTP_UPLOAD_PATH="${FTP_UPLOAD_PATH:-.}"

mkdir -p "$ROOT_DIR/public/api"
cat <<EOF > "$ROOT_DIR/public/api/env.php"
<?php
define('DB_HOST', '${DB_HOST:-}');
define('DB_NAME', '${DB_NAME:-}');
define('DB_USER', '${DB_USER:-}');
define('DB_PASS', '${DB_PASS:-}');
define('NEXT_PUBLIC_DASHBOARD_PASSWORD', '${NEXT_PUBLIC_DASHBOARD_PASSWORD:-}');
define('NEXT_PUBLIC_DASHBOARD_USER', '${NEXT_PUBLIC_DASHBOARD_USER:-admin}');
define('NEXT_PUBLIC_POWERBI_URL', '${NEXT_PUBLIC_POWERBI_URL:-}');
EOF

echo "Gerando build de produção..."
(cd "$ROOT_DIR" && npm run build)

echo "Gerando out/api/env.php..."
mkdir -p "$ROOT_DIR/out/api"
cp "$ROOT_DIR/public/api/env.php" "$ROOT_DIR/out/api/env.php"

echo "Validando acesso FTP..."
validation_url="$FTP_HOST/"
if [[ "$FTP_UPLOAD_PATH" != "." ]]; then
  validation_url="$FTP_HOST/$FTP_UPLOAD_PATH/"
fi
curl --fail --silent --show-error --ftp-pasv --connect-timeout 20 \
  --user "$FTP_USER:$FTP_PASSWORD" \
  "$validation_url" >/dev/null

echo "Enviando arquivos para /$FTP_UPLOAD_PATH/..."
total_files=$(find "$ROOT_DIR/out" -type f | wc -l | tr -d ' ')
count=0
find "$ROOT_DIR/out" -type f -print0 | while IFS= read -r -d '' file; do
  count=$((count + 1))
  relative_path="${file#"$ROOT_DIR/out/"}"
  remote_dir="$(dirname "$relative_path")"
  remote_url="$FTP_HOST"
  if [[ "$FTP_UPLOAD_PATH" != "." ]]; then
    remote_url="$remote_url/$FTP_UPLOAD_PATH"
  fi
  if [[ "$remote_dir" != "." ]]; then
    remote_url="$remote_url/$remote_dir"
  fi

  echo "[$count/$total_files] Upload: $relative_path"
  curl --fail --silent --show-error --ftp-pasv --ftp-create-dirs \
    --user "$FTP_USER:$FTP_PASSWORD" \
    --upload-file "$file" \
    "$remote_url/$(basename "$file")"
done

echo "Publicação concluída com sucesso no destino FTP configurado!"

