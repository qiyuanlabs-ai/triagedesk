# Deploying with Docker next to the Easy!Appointments MCP demo

This is how the public demo runs: on the same Lightsail instance as the
[Easy!Appointments MCP demo](https://github.com/qiyuanlabs-ai/easyappointments-mcp), sharing its
HTTPS front end. For a machine of its own, see [deploy-lightsail.md](deploy-lightsail.md).

## How it fits together

- TriageDesk runs as two containers from one image: `web` (FrankenPHP on port 8080) and `worker`
  (the queue). Neither publishes a port on the host. The database is SQLite on the `data` volume.
- The MCP demo's `mcp` container already owns ports 80 and 443 and gets certificates from
  Let's Encrypt. `deploy/ea-mcp-proxy.yml` is a Compose override for that project. It puts `mcp`
  on the shared Docker network `qiyuan-edge` and starts Caddy with `deploy/caddy/Caddyfile`, which
  imports the demo's own Caddyfile unchanged and adds one site that proxies to `triagedesk-web:8080`.
- Nothing in the MCP demo's repository changes. The override is listed in `COMPOSE_FILE` in
  `/opt/ea-mcp-server/.env`, so the demo's own update commands keep it.

## Before you start

1. Add a DNS record: `A triagedesk-demo.qiyuanlabs.ai -> the instance's static IP` (DNS only, not proxied).
2. Set a monthly spend limit in the Anthropic Console (Settings, Limits). The app also caps tokens
   per run and per day, and limits chats and triage runs per visitor IP.

## First install

The repository is private, so the server pulls it with a read-only deploy key that never leaves the server.

1. In the Lightsail browser SSH, create the key and print its public half:

   ```sh
   sudo sh -c 'mkdir -p /root/.ssh && chmod 700 /root/.ssh
   [ -f /root/.ssh/triagedesk_deploy ] || ssh-keygen -q -t ed25519 -N "" -C "triagedesk deploy key" -f /root/.ssh/triagedesk_deploy
   grep -q "^Host github-triagedesk" /root/.ssh/config 2>/dev/null || printf "Host github-triagedesk\n  HostName github.com\n  User git\n  IdentityFile /root/.ssh/triagedesk_deploy\n  IdentitiesOnly yes\n" >> /root/.ssh/config
   grep -q "^github.com ssh-ed25519" /root/.ssh/known_hosts 2>/dev/null || echo "github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl" >> /root/.ssh/known_hosts
   cat /root/.ssh/triagedesk_deploy.pub'
   ```

2. On GitHub, open the repository's Settings, then Deploy keys, then Add deploy key. Paste the line
   that starts with `ssh-ed25519`, and leave "Allow write access" unticked.

3. Back in the SSH window, clone and install:

   ```sh
   sudo git clone git@github-triagedesk:qiyuanlabs-ai/triagedesk.git /opt/triagedesk && sudo sh /opt/triagedesk/deploy/install-lightsail.sh
   ```

The script checks free memory and disk first (and adds a 2 GB swap file if there is no swap), then
builds and starts TriageDesk, validates the combined Caddy config with the MCP demo's own image,
restarts only the `mcp` container, and checks the MCP demo still answers the same way. If it does
not, the script undoes the change and stops. It also adds a cron job that resets the demo data at
03:30 every night.

Use another host name by putting `TRIAGEDESK_HOST=...` after `sudo` on the first run.

## Update

```sh
sudo sh /opt/triagedesk/deploy/install-lightsail.sh
```

It pulls the latest code, rebuilds, and keeps `.env` and the data.

Then add the API key. It is read from the keyboard without being shown:

```sh
sudo bash /opt/triagedesk/deploy/set-api-key.sh
```

For OpenAI instead: `sudo bash /opt/triagedesk/deploy/set-api-key.sh OPENAI_API_KEY openai`.

## Everyday commands

```sh
cd /opt/triagedesk
docker compose ps                     # both containers should be up, web healthy
docker compose logs --tail 100 web worker
docker compose exec web php artisan migrate:fresh --seed --force   # reset the demo now
```

## Undo

```sh
cd /opt/ea-mcp-server
sudo sed -i 's|:/opt/triagedesk/deploy/ea-mcp-proxy.yml||' .env
sudo docker compose up -d --no-deps mcp
cd /opt/triagedesk && sudo docker compose down
```

Add `-v` to the last command to delete TriageDesk's data as well.
