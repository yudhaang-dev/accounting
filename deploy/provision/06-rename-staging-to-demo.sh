#!/usr/bin/env bash
# Stage F: the second environment's domain becomes demo-accounting.vcare.web.id; Let's Encrypt account gets the contact email.
# The old name stays served until the new certificate works. Failed `nginx -t` restores the vhost.
set -euo pipefail

EMAIL=$1
OLD=staging-accounting.vcare.web.id
NEW=demo-accounting.vcare.web.id
F=/etc/nginx/sites-available/accounting-staging

echo "== certbot account contact"
certbot update_account --email "$EMAIL" --no-eff-email --non-interactive 2>&1 | grep -iE "updated|error|fail" | head -3

cp -a "$F" "${F}.pre-demo"
restore() { mv "${F}.pre-demo" "$F"; nginx -t >/dev/null 2>&1 && systemctl reload nginx; }

echo "== serve both names"
sed -i "s/server_name ${OLD};/server_name ${OLD} ${NEW};/" "$F"
nginx -t 2>&1 | tail -1 || { restore; exit 1; }
systemctl reload nginx

echo "== certificate for ${NEW}"
certbot certonly --webroot -w /var/www/letsencrypt -d "$NEW" --dry-run --non-interactive --agree-tos 2>&1 | grep -E "successful|fail|Error|Detail" | head -3
certbot certonly --webroot -w /var/www/letsencrypt -d "$NEW" --non-interactive --agree-tos --deploy-hook "systemctl reload nginx" 2>&1 | grep -E "Successfully|saved|expires|Error|fail" | head -3

echo "== switch the vhost to the new name and certificate"
sed -i "s#server_name ${OLD} ${NEW};#server_name ${NEW};#; s#/etc/letsencrypt/live/${OLD}/#/etc/letsencrypt/live/${NEW}/#g; s#^\# ${OLD}:#\# ${NEW}:#" "$F"
if ! nginx -t 2>&1 | tail -2; then restore; echo "nginx -t failed, vhost restored." >&2; exit 1; fi
systemctl reload nginx

echo "== checks (origin)"
curl -s -o /dev/null -w "${NEW}: %{http_code}, verify=%{ssl_verify_result}\n" --resolve "${NEW}:443:127.0.0.1" "https://${NEW}/"
curl -s -o /dev/null -w "accounting.vcare.web.id: %{http_code}\n" --resolve accounting.vcare.web.id:443:127.0.0.1 https://accounting.vcare.web.id/
curl -sk -o /dev/null -w "ipm https: %{http_code}\n" --resolve ipm.vcare.web.id:443:127.0.0.1 https://ipm.vcare.web.id/
certbot certificates 2>/dev/null | grep -E "Certificate Name|Expiry" | paste - - | sed 's/ \+/ /g'
