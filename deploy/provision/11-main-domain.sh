#!/usr/bin/env bash
# Stage K: the main domain vcare.web.id gets HTTPS (Let's Encrypt) and a neutral page in place of the nginx test page.
# Backs up the vhost and the page; a failed `nginx -t` restores both.
set -euo pipefail

D=vcare.web.id
V=/etc/nginx/sites-available/vcare.web.id
ROOT=/var/www/vcare.web.id
[ -e "$V" ] || V=$(readlink -f /etc/nginx/sites-enabled/vcare.web.id)
cp -a "$V" "$V.pre-https"
cp -a "$ROOT/index.html" "$ROOT/index.html.pre-https" 2>/dev/null || true
restore() { cp -a "$V.pre-https" "$V"; [ -f "$ROOT/index.html.pre-https" ] && cp -a "$ROOT/index.html.pre-https" "$ROOT/index.html"; nginx -t >/dev/null 2>&1 && systemctl reload nginx; }

echo "== 1. serve the ACME path on port 80 (site unchanged otherwise)"
cat > "$V" <<'CONF'
server {
    listen 80;
    listen [::]:80;
    server_name vcare.web.id www.vcare.web.id;
    root /var/www/vcare.web.id;
    index index.html index.htm;

    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { try_files $uri $uri/ =404; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
}
CONF
nginx -t 2>&1 | tail -1 || { restore; exit 1; }
systemctl reload nginx

echo "== 2. certificate"
certbot certonly --webroot -w /var/www/letsencrypt -d "$D" --dry-run --non-interactive --agree-tos 2>&1 | grep -E "successful|fail|Error|Detail" | head -3
certbot certonly --webroot -w /var/www/letsencrypt -d "$D" --non-interactive --agree-tos --deploy-hook "systemctl reload nginx" 2>&1 | grep -E "Successfully|saved|expires|Error|fail" | head -3

echo "== 3. https vhost and a neutral page"
cat > "$ROOT/index.html" <<'HTML'
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VCare</title>
    <style>
        html { height: 100%; }
        body { height: 100%; margin: 0; display: grid; place-items: center; font-family: system-ui, sans-serif; background: #f4f6f9; color: #1f2937; }
        h1 { margin: 0; font-size: 2rem; font-weight: 600; letter-spacing: .02em; }
    </style>
</head>
<body>
    <h1>VCare</h1>
</body>
</html>
HTML
chown www-data:www-data "$ROOT/index.html"; chmod 644 "$ROOT/index.html"

cat > "$V" <<'CONF'
server {
    listen 80;
    listen [::]:80;
    server_name vcare.web.id www.vcare.web.id;

    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { return 301 https://vcare.web.id$request_uri; }
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name vcare.web.id www.vcare.web.id;

    ssl_certificate /etc/letsencrypt/live/vcare.web.id/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/vcare.web.id/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    include snippets/cloudflare-realip.conf;

    root /var/www/vcare.web.id;
    index index.html index.htm;

    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { try_files $uri $uri/ =404; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
}
CONF
if ! nginx -t 2>&1 | tail -2; then restore; echo "nginx -t failed: vhost and page restored." >&2; exit 1; fi
systemctl reload nginx

echo "== checks (origin)"
curl -s -o /dev/null -w "vcare https: %{http_code}, verify=%{ssl_verify_result}\n" --resolve "$D:443:127.0.0.1" "https://$D/"
curl -s -o /dev/null -w "vcare http : %{http_code} -> %{redirect_url}\n" --resolve "$D:80:127.0.0.1" "http://$D/"
for h in accounting.vcare.web.id demo-accounting.vcare.web.id; do curl -s -o /dev/null -w "$h: %{http_code}\n" --resolve "$h:443:127.0.0.1" "https://$h/"; done
curl -sk -o /dev/null -w "ipm https: %{http_code}\n" --resolve ipm.vcare.web.id:443:127.0.0.1 https://ipm.vcare.web.id/
