#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "[1/5] Demarrage PostgreSQL/Redis..."
docker compose -f docker-compose.yml up -d

echo "[2/5] Installation dependances PHP..."
composer install --no-interaction

echo "[3/5] Creation base si necessaire..."
php bin/console doctrine:database:create --if-not-exists

echo "[4/5] Application des migrations..."
php bin/console doctrine:migrations:migrate --no-interaction

echo "[5/5] Validation conteneur Symfony..."
php bin/console lint:container

echo
echo "Bootstrap termine."
echo "- Lance le serveur: php -S 127.0.0.1:8000 -t public"
echo "- Swagger UI: http://127.0.0.1:8000/api/doc"

