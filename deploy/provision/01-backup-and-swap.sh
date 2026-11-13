#!/usr/bin/env bash
# Stage A: backup of what later stages change, baseline of the other site, swap. Only adds; touches nothing of ipm.
set -euo pipefail

stamp=$(date +%Y%m%d-%H%M%S)
install -d -m 0700 /root/backup-accounting-provision
tar -czf "/root/backup-accounting-provision/etc-${stamp}.tar.gz" /etc/nginx /etc/php/8.4/fpm /etc/php/8.2/fpm /etc/fstab /etc/sysctl.d 2>/dev/null
echo "backup: $(ls /root/backup-accounting-provision | tail -1)"

echo "== baseline"
curl -sk -o /dev/null -w "ipm https: %{http_code}\n" --resolve ipm.vcare.web.id:443:127.0.0.1 https://ipm.vcare.web.id/
systemctl is-active nginx mysql php8.4-fpm

echo "== swap 2G"
if ! swapon --show | grep -q /swapfile; then
    [ -f /swapfile ] || { fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null; }
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
echo 'vm.swappiness=10' > /etc/sysctl.d/90-accounting-swap.conf
sysctl -q -p /etc/sysctl.d/90-accounting-swap.conf
swapon --show
free -m | sed -n 2,3p
