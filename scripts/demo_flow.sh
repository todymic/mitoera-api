#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
ADMIN_EMAIL="${ADMIN_EMAIL:-backoffice@example.com}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-ChangeMe123!}"
HOLD_TOKEN="${HOLD_TOKEN:-demo-hold-token}"

json_field() {
  local field="$1"
  php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) && array_key_exists($argv[1], $d) ? (is_array($d[$argv[1]]) ? json_encode($d[$argv[1]]) : $d[$argv[1]]) : "";' "$field"
}

echo "[1/9] Inscription utilisateur BO (idempotent)..."
REGISTER_BODY=$(printf '{"email":"%s","password":"%s","displayName":"Backoffice"}' "$ADMIN_EMAIL" "$ADMIN_PASSWORD")
REGISTER_STATUS=$(curl -s -o /tmp/place_register.json -w "%{http_code}" -X POST "$BASE_URL/api/auth/register" -H "Content-Type: application/json" -d "$REGISTER_BODY")
if [[ "$REGISTER_STATUS" != "201" && "$REGISTER_STATUS" != "400" ]]; then
  echo "Echec register (HTTP $REGISTER_STATUS)"
  cat /tmp/place_register.json
  exit 1
fi

echo "[2/9] Promotion ROLE_BACKOFFICE en base..."
docker exec -i place-postgres psql -U postgres -d place_app -c "UPDATE users SET roles='[\"ROLE_BACKOFFICE\",\"ROLE_USER\"]' WHERE email='${ADMIN_EMAIL}';" >/dev/null

echo "[3/9] Login JWT..."
LOGIN_BODY=$(printf '{"email":"%s","password":"%s"}' "$ADMIN_EMAIL" "$ADMIN_PASSWORD")
LOGIN_RESPONSE=$(curl -s -X POST "$BASE_URL/api/auth/login" -H "Content-Type: application/json" -d "$LOGIN_BODY")
JWT_TOKEN=$(printf '%s' "$LOGIN_RESPONSE" | json_field token)
if [[ -z "$JWT_TOKEN" ]]; then
  echo "JWT non recupere. Reponse login:"
  echo "$LOGIN_RESPONSE"
  exit 1
fi

echo "[4/9] Creation API key BACKOFFICE..."
BO_KEY_RESPONSE=$(curl -s -X POST "$BASE_URL/api/api-keys" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -d '{"name":"BO key demo","scope":"backoffice"}')
BO_KEY_ID=$(printf '%s' "$BO_KEY_RESPONSE" | json_field keyId)
BO_KEY_SECRET=$(printf '%s' "$BO_KEY_RESPONSE" | json_field secret)

if [[ -z "$BO_KEY_ID" || -z "$BO_KEY_SECRET" ]]; then
  echo "Impossible de creer la cle BO. Reponse:"
  echo "$BO_KEY_RESPONSE"
  exit 1
fi

echo "[5/9] Creation API key PUBLIC..."
PUB_KEY_RESPONSE=$(curl -s -X POST "$BASE_URL/api/api-keys" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -d '{"name":"Public key demo","scope":"public"}')
PUB_KEY_ID=$(printf '%s' "$PUB_KEY_RESPONSE" | json_field keyId)
PUB_KEY_SECRET=$(printf '%s' "$PUB_KEY_RESPONSE" | json_field secret)

if [[ -z "$PUB_KEY_ID" || -z "$PUB_KEY_SECRET" ]]; then
  echo "Impossible de creer la cle PUBLIC. Reponse:"
  echo "$PUB_KEY_RESPONSE"
  exit 1
fi

echo "[6/9] Creation chart + objets..."
CHART_SLUG="demo-hall-${RANDOM}"
CHART_RESPONSE=$(curl -s -X POST "$BASE_URL/api/charts" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key-Id: $BO_KEY_ID" \
  -H "X-Api-Key-Secret: $BO_KEY_SECRET" \
  -d "{\"name\":\"Demo Hall\",\"slug\":\"$CHART_SLUG\"}")
CHART_ID=$(printf '%s' "$CHART_RESPONSE" | json_field id)

if [[ -z "$CHART_ID" ]]; then
  echo "Impossible de creer le chart. Reponse:"
  echo "$CHART_RESPONSE"
  exit 1
fi

curl -s -X PUT "$BASE_URL/api/charts/$CHART_ID/objects" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key-Id: $BO_KEY_ID" \
  -H "X-Api-Key-Secret: $BO_KEY_SECRET" \
  -d '{"objects":[{"type":"seat","key":"A1","label":"A1","x":10,"y":10},{"type":"seat","key":"A2","label":"A2","x":20,"y":10}]}' >/dev/null

echo "[7/9] Creation event avec chart..."
EVENT_RESPONSE=$(curl -s -X POST "$BASE_URL/api/events" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key-Id: $BO_KEY_ID" \
  -H "X-Api-Key-Secret: $BO_KEY_SECRET" \
  -d "{\"title\":\"Concert Demo\",\"identifier\":\"concert-demo-${RANDOM}\",\"chartId\":\"$CHART_ID\"}")
EVENT_ID=$(printf '%s' "$EVENT_RESPONSE" | json_field id)

if [[ -z "$EVENT_ID" ]]; then
  echo "Impossible de creer l'event. Reponse:"
  echo "$EVENT_RESPONSE"
  exit 1
fi

echo "[8/9] Hold de sieges A1/A2..."
HOLD_RESPONSE=$(curl -s -X POST "$BASE_URL/api/events/$EVENT_ID/hold" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key-Id: $PUB_KEY_ID" \
  -H "X-Api-Key-Secret: $PUB_KEY_SECRET" \
  -d "{\"seatKeys\":[\"A1\",\"A2\"],\"holdToken\":\"$HOLD_TOKEN\"}")
if [[ -z "$(printf '%s' "$HOLD_RESPONSE" | json_field holdToken)" ]]; then
  echo "Echec hold. Reponse:"
  echo "$HOLD_RESPONSE"
  exit 1
fi

echo "[9/9] Book de sieges A1/A2..."
BOOK_RESPONSE=$(curl -s -X POST "$BASE_URL/api/events/$EVENT_ID/book" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key-Id: $PUB_KEY_ID" \
  -H "X-Api-Key-Secret: $PUB_KEY_SECRET" \
  -d "{\"seatKeys\":[\"A1\",\"A2\"],\"holdToken\":\"$HOLD_TOKEN\"}")
if [[ -z "$(printf '%s' "$BOOK_RESPONSE" | json_field bookedAt)" ]]; then
  echo "Echec book. Reponse:"
  echo "$BOOK_RESPONSE"
  exit 1
fi

echo
echo "Scenario OK."
echo "JWT: $JWT_TOKEN"
echo "BACKOFFICE_KEY_ID=$BO_KEY_ID"
echo "BACKOFFICE_KEY_SECRET=$BO_KEY_SECRET"
echo "PUBLIC_KEY_ID=$PUB_KEY_ID"
echo "PUBLIC_KEY_SECRET=$PUB_KEY_SECRET"
echo "EVENT_ID=$EVENT_ID"


