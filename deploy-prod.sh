#!/usr/bin/env bash
# Деплой single-tenant B24 local-app на Netangels (zorintest).
#
# Что делает:
#   1. rsync www/ → ~/<DOMAIN>/www/<slug>/ (DocumentRoot, без env.php/data/.git)
#   2. composer install на сервере, если в проекте есть composer.json
#
# env.php создаётся ОДИН раз через init.php в браузере, не деплоем.
# data/ — приватный store внутри www/<slug>/, защищён <?php exit;?>-префиксом
#         в .php-файлах. Создаётся init.php / runtime'ом. Из rsync исключён,
#         чтобы прод-state не затирался.
#
# Slug = имя папки проекта (см. /data/kb/rule-default-hosting-netangels.md).
# Инфра-секреты — /data/config/env/netangels.env + /data/config/ssh/netangels_ed25519.

set -euo pipefail

if [ ! -f /data/config/env/netangels.env ]; then
  echo "Ошибка: /data/config/env/netangels.env не найден." >&2
  exit 1
fi
set -a; source /data/config/env/netangels.env; set +a

for v in NETANGELS_HOST NETANGELS_USER NETANGELS_SSH_KEY NETANGELS_DOMAIN; do
  if [ -z "${!v:-}" ]; then echo "Ошибка: $v не задан в netangels.env" >&2; exit 1; fi
done

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
SLUG="$(basename "$PROJECT_DIR")"
SRC_WWW="${PROJECT_DIR}/www"

# Safety: slug должен быть простым идентификатором — иначе можем удалить чужое.
if ! [[ "$SLUG" =~ ^[a-zA-Z0-9_-]+$ ]]; then
  echo "Ошибка: slug '${SLUG}' содержит недопустимые символы. Только [a-zA-Z0-9_-]." >&2
  exit 1
fi

if [ ! -d "$SRC_WWW" ]; then
  echo "Ошибка: ${SRC_WWW} не найден — шаблон ожидает www/ в корне проекта." >&2
  exit 1
fi

REMOTE_WWW="${NETANGELS_DOMAIN}/www/${SLUG}"

SSH_CMD="ssh -i ${NETANGELS_SSH_KEY} -o StrictHostKeyChecking=no -o LogLevel=ERROR"
HOST="${NETANGELS_USER}@${NETANGELS_HOST}"

echo "=== Slug:    ${SLUG}"
echo "=== URL:     https://${NETANGELS_DOMAIN}/${SLUG}/"
echo "=== Remote:  ~/${REMOTE_WWW}/"
echo ""

echo "=== 1. Папка на сервере ==="
$SSH_CMD "$HOST" "mkdir -p '${REMOTE_WWW}'"

echo "=== 2. rsync www/ → ~/${REMOTE_WWW}/ ==="
rsync -avz --delete \
  --exclude 'env.php' \
  --exclude 'data/' \
  --exclude '.git/' \
  --exclude '*.example' \
  --exclude '.DS_Store' \
  -e "${SSH_CMD}" \
  "${SRC_WWW}/" \
  "${HOST}:${REMOTE_WWW}/"

if [ -f "${PROJECT_DIR}/composer.json" ]; then
  echo "=== 3. composer install ==="
  $SSH_CMD "$HOST" "cd '${REMOTE_WWW}' && /usr/bin/php ~/bin/composer install --no-dev --optimize-autoloader"
fi

echo ""
echo "✓ Деплой завершён"
echo "  Приложение:    https://${NETANGELS_DOMAIN}/${SLUG}/"
echo "  Инициализация: https://${NETANGELS_DOMAIN}/${SLUG}/init.php  (открыть 1 раз, потом удалить init.php через панель)"
