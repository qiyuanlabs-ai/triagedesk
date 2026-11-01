#!/bin/bash
# Saves the AI provider's API key in /opt/triagedesk/.env and restarts TriageDesk.
# The key is read from the keyboard without being shown, and is never printed.
#   sudo bash /opt/triagedesk/deploy/set-api-key.sh            # Anthropic (default)
#   sudo bash /opt/triagedesk/deploy/set-api-key.sh OPENAI_API_KEY openai
set -e
VAR="${1:-ANTHROPIC_API_KEY}"
PROVIDER="${2:-}"
DIR=/opt/triagedesk
cd "$DIR"

read -r -s -p "Paste the $VAR and press Enter: " key
echo
[ -n "$key" ] || { echo "Nothing entered, nothing changed." >&2; exit 1; }
case "$key" in *[[:space:]\|\&\\]*) echo "That does not look like an API key, nothing changed." >&2; exit 1 ;; esac

if grep -q "^#\? *$VAR=" .env; then
  sed -i "s|^#\? *$VAR=.*|$VAR=$key|" .env
else
  echo "$VAR=$key" >> .env
fi
[ -n "$PROVIDER" ] && sed -i "s|^AI_PROVIDER=.*|AI_PROVIDER=$PROVIDER|" .env
chmod 600 .env
unset key

docker compose up -d --force-recreate web worker >/dev/null
echo "Saved. TriageDesk restarted with the new key."
