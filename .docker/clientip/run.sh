#!/usr/bin/env bash
#
# mod_remoteip trust-boundary test.
#
# Proves the one thing no unit test can reach: real Apache + mod_remoteip
# deciding, by the connecting peer's address, whether to trust an inbound
# X-Forwarded-For. The test vhost trusts only the edge container's address, so:
#
#   trusted hop    edge (inside RemoteIPInternalProxy) sends XFF -> %h is the
#                  forwarded client, i.e. the header is honored
#   untrusted      attacker (outside it) sends the same XFF -> %h is the
#                  attacker's own address, i.e. the header is ignored
#
# The rate-limit bucketing this feeds is unit-tested (RateLimitSubscriberTest),
# so it isn't re-tested here. Only Apache and two curl containers run; no
# php-fpm or database is involved.
#
# Usage:  .docker/clientip/run.sh [--keep]
#           --keep   leave the stack running for poking around afterwards

set -u

cd "$(dirname "$0")/../.." || exit 1

KEEP=0
[ "${1:-}" = "--keep" ] && KEEP=1

COMPOSE=(
  docker compose
  -f docker-compose.clientip.yml
  -p xtools-clientip
)

# The forged client address both probes send in X-Forwarded-For, and the
# attacker's own address (a different /24, outside the vhost's
# RemoteIPInternalProxy list). Honored header -> %h is CLIENT_IP; ignored header
# -> %h falls back to the connecting container's address.
CLIENT_IP=203.0.113.7
ATTACKER_IP=198.19.0.30

PASS=0
FAIL=0

check() { # desc  expected  actual
  if [ "$2" = "$3" ]; then
    printf '  PASS  %s\n' "$1"
    PASS=$((PASS + 1))
  else
    printf '  FAIL  %s (expected %s, got %s)\n' "$1" "$2" "$3"
    FAIL=$((FAIL + 1))
  fi
}

# Fire one request from a container with a forged X-Forwarded-For, tagging it with
# a unique probe id in the query string so resolved_ip() can find its exact %h
# line instead of racing the async log.
REQ_SEQ=0
LAST_PROBE=""
req() { # container  xff
  REQ_SEQ=$((REQ_SEQ + 1))
  LAST_PROBE="p${REQ_SEQ}"
  "${COMPOSE[@]}" exec -T "$1" curl -s -o /dev/null \
    -H "X-Forwarded-For: $2" "http://web:3001/?probe=${LAST_PROBE}"
}

# The IP Apache resolved (%h) for the most recent request, matched by probe id.
# Apache writes the log line after the response and Docker's log pipe adds lag,
# so poll until the line for this exact request shows up.
resolved_ip() {
  local i line
  for i in $(seq 1 20); do
    line=$("${COMPOSE[@]}" logs --no-color --no-log-prefix --tail=200 web 2>/dev/null |
      grep "probe=${LAST_PROBE} " | tail -1)
    if [ -n "$line" ]; then
      echo "$line" | awk '{ print $2 }'
      return 0
    fi
    sleep 0.3
  done
  echo "NO_LOG_LINE"
}

wait_web() {
  local i code
  for i in $(seq 1 60); do
    code=$("${COMPOSE[@]}" exec -T edge curl -s -o /dev/null -w '%{http_code}' \
      'http://web:3001/' 2>/dev/null || true)
    [ -n "$code" ] && [ "$code" != "000" ] && return 0
    sleep 2
  done
  echo "web never became reachable" >&2
  return 1
}

teardown() {
  if [ "$KEEP" -eq 1 ]; then
    echo "Stack left running (project xtools-clientip). Tear down with:"
    echo "  ${COMPOSE[*]} down"
  else
    "${COMPOSE[@]}" down >/dev/null 2>&1 || true
  fi
}
trap teardown EXIT

# Start clean in case a prior --keep run or crash left the stack behind.
"${COMPOSE[@]}" down >/dev/null 2>&1 || true

echo "Bringing up Apache + the two probe containers..."
"${COMPOSE[@]}" up -d --build web edge attacker || exit 1
wait_web || exit 1

echo
echo "Trusted hop: X-Forwarded-For from inside the trust list is honored"
req edge "$CLIENT_IP" >/dev/null
check "mod_remoteip resolves XFF from the trusted edge" "$CLIENT_IP" "$(resolved_ip)"

echo
echo "Untrusted source: the same X-Forwarded-For is ignored"
req attacker "$CLIENT_IP" >/dev/null
check "forged XFF from an untrusted source is dropped" "$ATTACKER_IP" "$(resolved_ip)"

echo
echo "-----------------------------------------"
printf 'Results: %d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
