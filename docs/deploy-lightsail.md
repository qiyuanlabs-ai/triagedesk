# Deploying TriageDesk on AWS Lightsail

One Lightsail instance runs everything: Nginx, PHP-FPM, the queue worker, the scheduler and the SQLite database. Model calls go to the Anthropic API (or whichever provider you set), not to AWS.

Expected cost: **$12/month** for the instance, plus model usage, which you cap in the provider's console (step 1) and in the app (step 6).

## 1. Before you start

- **An Anthropic API key** from console.anthropic.com, under API keys.
- **A monthly spend limit** on that account: in the Anthropic Console, open Settings, then Limits, and set a monthly limit you are comfortable losing if the demo is abused (for example $20). The app's own limits are a second line of defence, not the only one. OpenAI and Google AI Studio have the same kind of limit if you use them instead.
- Optional: a domain name for HTTPS.

## 2. Create the instance

In the Lightsail console:

1. **Create instance** → Linux/Unix → **OS only: Ubuntu 24.04 LTS**.
2. Plan: **$12/month (2 GB RAM, 2 vCPUs, 60 GB SSD)**. The 1 GB plan works for serving but Composer and the queue worker make it tight.
3. Name it `triagedesk` and create it.
4. **Networking** tab: create a **static IP** and attach it (free while attached). In the IPv4 firewall, keep SSH (22) and HTTP (80) and add **HTTPS (443)**.
5. Optional: point your domain's A record at the static IP.

Connect with the browser SSH button or `ssh ubuntu@<static-ip>` using the key Lightsail gives you.

## 3. Install the server packages

Ubuntu 24.04 ships PHP 8.3, which is what TriageDesk needs.

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx git unzip sqlite3 composer \
  php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-intl php8.3-zip php8.3-bcmath

# 1 GB of swap so Composer never runs out of memory
sudo fallocate -l 1G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

## 4. Get the code

```bash
sudo mkdir -p /var/www && sudo chown ubuntu:www-data /var/www
cd /var/www
git clone https://github.com/<you>/triagedesk.git
cd triagedesk
composer install --no-dev --optimize-autoloader
```

The front-end assets need Node 20.19 or newer, which Ubuntu 24.04 does not ship. The simplest route is to build them on your own machine and copy them up:

```bash
# on your machine, in the project folder
npm ci && npm run build
scp -r public/build ubuntu@<static-ip>:/var/www/triagedesk/public/
```

## 5. Configure the environment

```bash
cp .env.example .env
php artisan key:generate
nano .env
```

Set these values (everything not listed can stay as in `.env.example`):

```dotenv
APP_NAME=TriageDesk
APP_ENV=production
APP_DEBUG=false
APP_URL=https://demo.example.com        # or http://<static-ip> until you have a domain

LOG_LEVEL=warning

DB_CONNECTION=sqlite
DB_DATABASE=/var/www/triagedesk/database/database.sqlite

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=                         # optional second provider
AI_ANTHROPIC_DAILY_LIMIT_USD=3
AI_OPENAI_DAILY_LIMIT_USD=6

REFUND_AUTO_APPROVE_LIMIT=50

DEMO_MODE=true
DEMO_ACCESS_CODE=                       # set one to allow live runs
DEMO_RECORDINGS_PATH=/var/www/triagedesk/storage/app/recordings.json
```

Create the database and warm the caches:

```bash
touch database/database.sqlite
php artisan migrate --seed --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

sudo chown -R ubuntu:www-data storage bootstrap/cache database
sudo chmod -R ug+rwX storage bootstrap/cache database
chmod 600 .env
```

The `database` folder must be writable by `www-data` because SQLite writes a journal file next to the database.

## 6. Nginx and PHP-FPM

Agent runs in the chat are synchronous and can take a minute, so raise the timeouts:

```bash
sudo sed -i 's/^;\?request_terminate_timeout.*/request_terminate_timeout = 180/' /etc/php/8.3/fpm/pool.d/www.conf
sudo systemctl restart php8.3-fpm
```

Create `/etc/nginx/sites-available/triagedesk`:

```nginx
server {
    listen 80;
    server_name demo.example.com;          # or the static IP
    root /var/www/triagedesk/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 180;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable it:

```bash
sudo ln -s /etc/nginx/sites-available/triagedesk /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### HTTPS

Once the domain resolves to the static IP:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d demo.example.com
```

Certbot renews the certificate automatically.

## 7. Queue worker

Triage and resuming after an approval run on the queue. Create `/etc/systemd/system/triagedesk-queue.service`:

```ini
[Unit]
Description=TriageDesk queue worker
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/triagedesk
ExecStart=/usr/bin/php artisan queue:work --tries=1 --timeout=180 --sleep=2 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now triagedesk-queue
sudo systemctl status triagedesk-queue
```

## 8. Scheduler (nightly reset)

With `DEMO_MODE=true` the scheduler rebuilds the demo data at midnight server time.

```bash
sudo crontab -u www-data -e
```

Add:

```cron
* * * * * cd /var/www/triagedesk && php artisan schedule:run >> /dev/null 2>&1
```

## 9. Check it works

1. Open the site and sign in as a teammate.
2. Press **Simulate a new ticket**. Within a few seconds it should show a category and priority, and proposals should appear in the approval inbox.
3. If nothing happens, check `sudo journalctl -u triagedesk-queue -n 50` and `storage/logs/laravel.log`. A wrong or missing API key shows up there and as a red error on the ticket.

## Updating

```bash
cd /var/www/triagedesk
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

Copy a fresh `public/build` from your machine if the front end changed.

## Keeping the bill small

| Layer | Setting |
|---|---|
| Model provider | Monthly spend limit in the provider's console (step 1) |
| App, visitors | Replay mode by default; live runs need `DEMO_ACCESS_CODE`. Then `DEMO_CHATS_PER_IP_PER_DAY`, `DEMO_TRIAGES_PER_IP_PER_DAY` |
| App, per run | `AI_RUN_TOKEN_LIMIT` stops a run that loops; `#[MaxSteps(8)]` caps tool steps |
| App, whole site | `AI_ANTHROPIC_DAILY_LIMIT_USD` and `AI_OPENAI_DAILY_LIMIT_USD`, in dollars from real token usage, checked before every model call; `DEMO_LIVE_RUNS_PER_DAY` |
| Models | Triage uses the provider's cheapest model; prompt caching is on for instructions and tool definitions |
| AWS | Optional: AWS Budgets alert at $15 for the Lightsail bill |

Lightsail lets you take a snapshot of the instance before risky changes (Snapshots tab); snapshots cost a little per GB-month.

## Optional: AWS Bedrock instead of the Anthropic API

Only if Bedrock is available on your AWS account. Install the AWS SDK and point the provider at Bedrock:

```bash
composer require aws/aws-sdk-php
```

```dotenv
AI_SUPPORT_PROVIDERS=bedrock
AI_TRIAGE_PROVIDERS=bedrock
AWS_BEDROCK_REGION=us-east-1
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
```

Add a `bedrock` entry with a `daily_limit_usd` under `providers` in `config/triagedesk.php`. The key belongs to an IAM user whose only permissions are `bedrock:InvokeModel` and `bedrock:InvokeModelWithResponseStream` on the models you enabled. Enable model access in the Bedrock console first, in the same region. This path has not been tested against a live Bedrock account.
