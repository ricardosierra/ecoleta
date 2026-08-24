#!/usr/bin/env bash
#
# Roda a suíte PHP com o PHPUnit distribuído como .phar — sem Composer e sem
# vendor/ no repositório.
#
# Composer ficaria de fora do deploy de qualquer jeito (só `out/` sobe por FTP),
# mas um composer.json na raiz sugere um autoloader que este backend não tem:
# ele é PHP 8 puro com `require_once`, e a hospedagem compartilhada não roda
# `composer install`. Um .phar baixado sob demanda mantém a suíte com uma
# dependência só, verificável e descartável.
#
# O binário fica em tools/ (fora do Git) e o SHA-256 é conferido a cada
# execução: um .phar trocado no caminho executaria com os privilégios de quem
# roda os testes.
set -euo pipefail

PHPUNIT_VERSION="11.5.56"
PHPUNIT_SHA256="915fa161f496dc04a45cd6032855879bca0bab644048cd0516982dffe678e9f1"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS_DIR="${ROOT_DIR}/tools"
PHAR="${TOOLS_DIR}/phpunit-${PHPUNIT_VERSION}.phar"

checksum() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | cut -d' ' -f1
  else
    shasum -a 256 "$1" | cut -d' ' -f1
  fi
}

if [ ! -f "${PHAR}" ]; then
  mkdir -p "${TOOLS_DIR}"
  echo "Baixando PHPUnit ${PHPUNIT_VERSION}..." >&2
  curl -fsSL --retry 3 -o "${PHAR}.tmp" "https://phar.phpunit.de/phpunit-${PHPUNIT_VERSION}.phar"
  mv "${PHAR}.tmp" "${PHAR}"
fi

ACTUAL="$(checksum "${PHAR}")"
if [ "${ACTUAL}" != "${PHPUNIT_SHA256}" ]; then
  echo "SHA-256 do phpunit.phar não confere." >&2
  echo "  esperado: ${PHPUNIT_SHA256}" >&2
  echo "  obtido:   ${ACTUAL}" >&2
  echo "Apague ${PHAR} e rode de novo; se persistir, o download foi adulterado." >&2
  exit 1
fi

exec php "${PHAR}" --configuration "${ROOT_DIR}/phpunit.xml" "$@"
