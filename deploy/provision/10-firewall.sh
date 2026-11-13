#!/usr/bin/env bash
# Stage J: firewall. ufw denies incoming except SSH and 80/443 from Cloudflare only (IPv4 and IPv6); fail2ban for sshd;
# a catch-all nginx server for unknown hosts; a weekly sync of Cloudflare's ranges (ufw and nginx real-ip together).
# A rollback timer switches ufw off after 3 minutes unless it is cancelled, so a mistake cannot lock the machine.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

apt-get install -y -q fail2ban >/dev/null

# --- sync script (also the way the ranges are refreshed later)
cat > /usr/local/sbin/accounting-cloudflare-sync <<'SH'
#!/usr/bin/env bash
# Refreshes Cloudflare's ranges in ufw (ports 80 and 443) and in nginx's real-ip snippet. A bad download changes nothing.
set -euo pipefail
LIST=/etc/accounting/cloudflare-ranges.txt
SNIP=/etc/nginx/snippets/cloudflare-realip.conf

v4=$(curl -fsS --max-time 20 https://www.cloudflare.com/ips-v4)
v6=$(curl -fsS --max-time 20 https://www.cloudflare.com/ips-v6)
new=$(printf '%s\n%s\n' "$v4" "$v6" | grep -E '^[0-9a-fA-F:.]+/[0-9]{1,3}$' | sort -u)
[ "$(printf '%s\n' "$new" | grep -c .)" -ge 15 ] || { echo "Cloudflare range list looks wrong; nothing changed." >&2; exit 1; }

touch "$LIST"
for r in $new; do
    ufw allow proto tcp from "$r" to any port 80,443 comment cloudflare >/dev/null
done
for r in $(comm -23 <(sort -u "$LIST") <(printf '%s\n' "$new")); do
    ufw delete allow proto tcp from "$r" to any port 80,443 >/dev/null || true
done
printf '%s\n' "$new" > "$LIST"

tmp=$(mktemp)
{
    echo "# The real client address comes from Cloudflare's header, trusted only from Cloudflare's own ranges. Written by accounting-cloudflare-sync."
    for r in $new; do echo "set_real_ip_from $r;"; done
    echo "real_ip_header CF-Connecting-IP;"
    echo "real_ip_recursive on;"
} > "$tmp"
if ! cmp -s "$tmp" "$SNIP"; then
    cp -a "$SNIP" "$SNIP.bak" 2>/dev/null || true
    cp "$tmp" "$SNIP"
    if nginx -t >/dev/null 2>&1; then systemctl reload nginx; else cp -a "$SNIP.bak" "$SNIP"; echo "nginx -t failed; snippet restored." >&2; exit 1; fi
fi
rm -f "$tmp"
echo "cloudflare ranges: $(printf '%s\n' "$new" | grep -c .)"
SH
chmod 755 /usr/local/sbin/accounting-cloudflare-sync

cat > /etc/systemd/system/accounting-cloudflare-sync.service <<'UNIT'
[Unit]
Description=Refresh Cloudflare ranges in ufw and nginx

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/accounting-cloudflare-sync
UNIT
cat > /etc/systemd/system/accounting-cloudflare-sync.timer <<'UNIT'
[Unit]
Description=Weekly: refresh Cloudflare ranges

[Timer]
OnCalendar=weekly
RandomizedDelaySec=1h
Persistent=true

[Install]
WantedBy=timers.target
UNIT

# --- catch-all for hosts nobody serves (Cloudflare always sends a real name)
cat > /etc/nginx/sites-available/00-catchall <<'CONF'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name _;
    ssl_certificate /etc/nginx/ssl-placeholder.crt;
    ssl_certificate_key /etc/nginx/ssl-placeholder.key;
    return 444;
}
CONF
ln -sfn /etc/nginx/sites-available/00-catchall /etc/nginx/sites-enabled/00-catchall
if ! nginx -t 2>&1 | tail -1; then rm -f /etc/nginx/sites-enabled/00-catchall; echo "nginx -t failed; catch-all removed." >&2; exit 1; fi
systemctl reload nginx

# --- fail2ban
cat > /etc/fail2ban/jail.d/accounting.local <<'CONF'
[DEFAULT]
bantime = 1h
findtime = 10m
maxretry = 5

[sshd]
enabled = true
backend = systemd
CONF
systemctl enable --now fail2ban >/dev/null 2>&1
systemctl restart fail2ban

# --- ufw (rules are added while it is still inactive)
sed -i 's/^IPV6=.*/IPV6=yes/' /etc/default/ufw
ufw --force reset >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow 22/tcp comment ssh >/dev/null
/usr/local/sbin/accounting-cloudflare-sync
systemctl daemon-reload
systemctl enable --now accounting-cloudflare-sync.timer >/dev/null 2>&1

# Rollback first, then enable: if nobody cancels this within 3 minutes ufw is switched off again.
systemctl stop accounting-ufw-rollback.timer 2>/dev/null || true
systemd-run --quiet --unit=accounting-ufw-rollback --on-active=180 /usr/sbin/ufw disable
ufw --force enable >/dev/null

echo "== ufw"
ufw status | head -6
echo "rules: $(ufw status | grep -c cloudflare) cloudflare entries"
echo "== rollback timer"
systemctl list-timers accounting-ufw-rollback.timer --no-pager | sed -n 2p
echo "== fail2ban"; fail2ban-client status sshd 2>&1 | sed -n 1,4p
echo "== this connection is still alive"
curl -sk -o /dev/null -w "ipm (local): %{http_code}\n" --resolve ipm.vcare.web.id:443:127.0.0.1 https://ipm.vcare.web.id/
