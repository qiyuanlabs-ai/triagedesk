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

In the Lightsail browser SSH, clone the repository into `/opt/triagedesk` and run the installer.

If the repository is public:

```sh
sudo git clone https://github.com/qiyuanlabs-ai/triagedesk.git /opt/triagedesk && sudo sh /opt/triagedesk/deploy/install-lightsail.sh
```

If it is private (the organization turns off deploy keys, so use a read-only token):

1. On GitHub, create a fine-grained personal access token: resource owner `qiyuanlabs-ai`, only the
   `triagedesk` repository, permission Contents: Read-only. If the organization asks for approval of
   fine-grained tokens, an owner approves it under the organization's Personal access tokens settings.
2. Save it on the server. It is read without being shown and is only used for this repository:

   ```sh
   sudo bash -c 'read -r -s -p "Paste the GitHub token and press Enter: " t; echo; U=https://github.com/qiyuanlabs-ai/triagedesk.git; git config --global credential.$U.helper store; git config --global credential.$U.useHttpPath true; touch /root/.git-credentials; chmod 600 /root/.git-credentials; printf "https://x-access-token:%s@github.com/qiyuanlabs-ai/triagedesk.git\n" "$t" >> /root/.git-credentials; unset t'
   ```

3. Clone and install with the same command as for a public repository above.

When the token expires, make a new one and run `sudo bash /opt/triagedesk/deploy/set-github-token.sh`.

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
