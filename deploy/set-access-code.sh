#!/bin/bash
# Sets the code that switches the public demo into live mode (real model calls), and restarts TriageDesk.
# Visitors without it only see recorded runs. Changing the code signs everyone out of live mode.
#   sudo bash /opt/triagedesk/deploy/set-access-code.sh          # type a code, or press Enter for a random one
#   sudo bash /opt/triagedesk/deploy/set-access-code.sh --off    # no code: nobody can start live runs
set -e
DIR=/opt/triagedesk
cd "$DIR"

if [ "${1:-}" = "--off" ]; then
  code=""
else
  read -r -s -p "Type the new access code (6 to 64 letters, digits, . _ -), or press Enter to make one up: " code
  echo
  if [ -z "$code" ]; then
    code="$(tr -dc 'a-z0-9' < /dev/urandom | head -c 12)"
    echo "Your new access code: $code"
  fi
  case "$code" in
    *[!A-Za-z0-9._-]*) echo "Only letters, digits, dot, underscore and dash, please. Nothing changed." >&2; exit 1 ;;
  esac
  [ "${#code}" -ge 6 ] && [ "${#code}" -le 64 ] || { echo "Use 6 to 64 characters. Nothing changed." >&2; exit 1; }
fi

if grep -q "^#\? *DEMO_ACCESS_CODE=" .env; then
  sed -i "s|^#\? *DEMO_ACCESS_CODE=.*|DEMO_ACCESS_CODE=$code|" .env
else
  echo "DEMO_ACCESS_CODE=$code" >> .env
fi
chmod 600 .env
unset code

docker compose up -d --force-recreate web worker >/dev/null
if [ "${1:-}" = "--off" ]; then
  echo "Live mode is off for everyone. TriageDesk restarted."
else
  echo "Saved. Enter the code in the dark bar at the top of the demo to switch on live runs."
fi
