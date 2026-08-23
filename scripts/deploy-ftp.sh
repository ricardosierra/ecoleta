#!/usr/bin/env bash

# Compila o site estático e publica o conteúdo de out/ via FTP.
#
# ORDEM OBRIGATÓRIA DO DEPLOY:
#   1. php db/migrate.php migrate    (por SSH, com o usuário de DDL)
#   2. npm run deploy:ftp            (este script)
#
# Nesta ordem o banco fica à frente do código por alguns minutos, o que a API
# tolera. Na ordem inversa os arquivos novos consultam colunas que ainda não
# existem e o dashboard responde 503 até alguém rodar a migration. Ver
# docs/deploy.md.
#
# Uso: npm run deploy:ftp
#      MIGRATIONS_APPLIED=1 npm run deploy:ftp    (CI / não interativo)
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

# ─── As migrations já rodaram? ───────────────────────────────────────────────
# O script não consegue conferir sozinho: a hospedagem compartilhada não aceita
# conexão MySQL de fora, então quem responde é quem está rodando o deploy.
REQUIRED_SCHEMA_VERSION="$(
  sed -n 's/.*ECOLETA_SCHEMA_VERSION[[:space:]]*=[[:space:]]*\([0-9]\{1,\}\).*/\1/p' \
    "$ROOT_DIR/public/api/schema.php" 2>/dev/null | head -1
)"
REQUIRED_SCHEMA_VERSION="${REQUIRED_SCHEMA_VERSION:-?}"

if [[ "${MIGRATIONS_APPLIED:-}" != "1" ]]; then
  echo
  echo "Esta build exige o schema na versão ${REQUIRED_SCHEMA_VERSION}."
  echo "Antes de publicar os arquivos, aplique as migrations no servidor:"
  echo
  echo "    ssh <conta>@<host>"
  echo "    cd <checkout do repositório> && php db/migrate.php status"
  echo "    php db/migrate.php migrate"
  echo

  if [[ -t 0 ]]; then
    read -r -p "As migrations já foram aplicadas? [s/N] " answer
    case "$answer" in
      s|S|sim|SIM|y|Y|yes|YES) ;;
      *)
        echo "Deploy cancelado. Rode as migrations e tente de novo." >&2
        exit 1
        ;;
    esac
  else
    echo "Execução não interativa: defina MIGRATIONS_APPLIED=1 para confirmar." >&2
    exit 1
  fi
fi

FTP_HOST="${FTP_HOST%/}"
FTP_UPLOAD_PATH="${FTP_UPLOAD_PATH#/}"
FTP_UPLOAD_PATH="${FTP_UPLOAD_PATH%/}"
FTP_UPLOAD_PATH="${FTP_UPLOAD_PATH:-.}"

mkdir -p "$ROOT_DIR/public/api"
# Segredos do dashboard NÃO usam o prefixo NEXT_PUBLIC_: esse prefixo faz o
# Next.js embutir o valor no bundle servido ao navegador.
#
# DB_DDL_USER/DB_DDL_PASS ficam de fora de propósito. Este arquivo vai para
# dentro do webroot; quem tem permissão de DDL só precisa dela no CLI, e o
# usuário aqui embaixo só faz SELECT/INSERT/UPDATE/DELETE.
cat <<EOF > "$ROOT_DIR/public/api/env.php"
<?php
define('DB_HOST', '${DB_HOST:-}');
define('DB_NAME', '${DB_NAME:-}');
define('DB_USER', '${DB_USER:-}');
define('DB_PASS', '${DB_PASS:-}');
define('DASHBOARD_ROOT_LOGIN', '${DASHBOARD_ROOT_LOGIN:-admin}');
define('DASHBOARD_INSTALL_TOKEN', '${DASHBOARD_INSTALL_TOKEN:-}');
define('NEXT_PUBLIC_POWERBI_URL', '${NEXT_PUBLIC_POWERBI_URL:-}');
EOF

if [[ -n "${DASHBOARD_INSTALL_TOKEN:-}" ]]; then
  echo "Atenção: DASHBOARD_INSTALL_TOKEN está definido — api/install.php ficará acessível neste deploy."
  echo "         Crie o usuário root e remova o token do .env antes do próximo deploy."
fi

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

