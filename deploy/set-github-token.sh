#!/bin/bash
# Saves a read-only GitHub token so this server can pull the private TriageDesk repository.
# The token is read from the keyboard without being shown, and is only used for this one
# repository (git's credential store, scoped by URL, in /root/.git-credentials, mode 600).
#   sudo bash /opt/triagedesk/deploy/set-github-token.sh
# Run it again with a new token when the old one expires.
set -e
URL=https://github.com/qiyuanlabs-ai/triagedesk.git
read -r -s -p "Paste the GitHub token and press Enter: " token
echo
[ -n "$token" ] || { echo "Nothing entered, nothing changed." >&2; exit 1; }
git config --global "credential.$URL.helper" store
git config --global "credential.$URL.useHttpPath" true
touch /root/.git-credentials && chmod 600 /root/.git-credentials
grep -v "@github.com/qiyuanlabs-ai/triagedesk.git" /root/.git-credentials > /root/.git-credentials.new || true
printf 'https://x-access-token:%s@github.com/qiyuanlabs-ai/triagedesk.git\n' "$token" >> /root/.git-credentials.new
mv /root/.git-credentials.new /root/.git-credentials && chmod 600 /root/.git-credentials
unset token
git ls-remote "$URL" HEAD >/dev/null && echo "Saved. The server can read the repository." \
  || { echo "Saved, but GitHub refused it. Check the token has Contents: Read-only on qiyuanlabs-ai/triagedesk." >&2; exit 1; }
