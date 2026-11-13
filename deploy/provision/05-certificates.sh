#!/usr/bin/env bash
# Stage E: Let's Encrypt certificates by webroot (certbot never edits nginx), then the vhosts switch to them.
set -euo pipefail

DOMAINS=("accounting:accounting.vcare.web.id" "accounting-staging:staging-accounting.vcare.web.id")

echo "== dry run (Let's Encrypt staging)"
for spec in "${DOMAINS[@]}"; do
    d=${spec#*:}
    certbot certonly --webroot -w /var/www/letsencrypt -d "$d" --dry-run --non-interactive --agree-tos --register-unsafely-without-email 2>&1 | grep -E "simulated|Congratulations|fail|Error|error|Detail|Hint" | head -5 \
        || true
done

if [ "${1:-}" != "issue" ]; then
    echo "dry run only; run again with 'issue' to obtain the certificates."
    exit 0
fi

echo "== issue"
for spec in "${DOMAINS[@]}"; do
    name=${spec%%:*}; d=${spec#*:}
    certbot certonly --webroot -w /var/www/letsencrypt -d "$d" --non-interactive --agree-tos --register-unsafely-without-email \
        --deploy-hook "systemctl reload nginx" 2>&1 | grep -E "Successfully|Certificate is saved|expire|Error|fail" | head -4

    f=/etc/nginx/sites-available/$name
    cp -a "$f" "${f}.pre-le"
    sed -i "s#ssl_certificate /etc/nginx/ssl-placeholder.crt;#ssl_certificate /etc/letsencrypt/live/${d}/fullchain.pem;#; s#ssl_certificate_key /etc/nginx/ssl-placeholder.key;#ssl_certificate_key /etc/letsencrypt/live/${d}/privkey.pem;#" "$f"
done

if ! nginx -t 2>&1 | tail -2; then
    for spec in "${DOMAINS[@]}"; do name=${spec%%:*}; mv "/etc/nginx/sites-available/${name}.pre-le" "/etc/nginx/sites-available/${name}"; done
    echo "nginx -t failed: vhosts restored to the placeholder certificate." >&2
    exit 1
fi
systemctl reload nginx

echo "== renewal"
systemctl is-active certbot.timer || systemctl list-timers --all 2>/dev/null | grep -i certbot
certbot renew --dry-run --non-interactive 2>&1 | grep -E "success|fail|Congratulations" | head -4
certbot certificates 2>/dev/null | grep -E "Certificate Name|Domains|Expiry"

echo "== checks"
for d in accounting.vcare.web.id staging-accounting.vcare.web.id; do
    curl -s -o /dev/null -w "$d (origin, real cert): %{http_code}, verify=%{ssl_verify_result}\n" --resolve "$d:443:127.0.0.1" "https://$d/"
done
curl -sk -o /dev/null -w "ipm https: %{http_code}\n" --resolve ipm.vcare.web.id:443:127.0.0.1 https://ipm.vcare.web.id/
