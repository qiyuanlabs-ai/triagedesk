#!/bin/bash
# Saves an AI provider's API key in /opt/triagedesk/.env and restarts TriageDesk.
# The key is read from the keyboard without being shown, and is never printed.
#   sudo bash /opt/triagedesk/deploy/set-api-key.sh            # Anthropic
#   sudo bash /opt/triagedesk/deploy/set-api-key.sh openai     # OpenAI
set -e
case "${1:-anthropic}" in
  anthropic) VAR=ANTHROPIC_API_KEY ;;
  openai) VAR=OPENAI_API_KEY ;;
  gemini) VAR=GEMINI_API_KEY ;;
  *_API_KEY) VAR="$1" ;;
  *) echo "Unknown provider '$1'. Use anthropic, openai or gemini." >&2; exit 1 ;;
esac
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
chmod 600 .env
unset key

docker compose up -d --force-recreate web worker >/dev/null
echo "Saved $VAR. TriageDesk restarted with it."
