#!/usr/bin/env bash
# Stage C: one system user, one directory tree and one PHP-FPM pool per environment. ipm's pool (www) is not touched.
# The config is tested before the graceful reload; a failed test restores the files and stops.
set -euo pipefail

ENVS=("accounting:acct:384M:4" "accounting-staging:acct-stg:256M:2")
POOLDIR=/etc/php/8.4/fpm/pool.d
created=()

for spec in "${ENVS[@]}"; do
    IFS=: read -r name user mem children <<<"$spec"
    id -u "$user" >/dev/null 2>&1 || useradd --system --home-dir "/var/www/${name}" --shell /usr/sbin/nologin --user-group "$user"

    install -d -o "$user" -g "$user" -m 0750 "/var/www/${name}" "/var/www/${name}/releases" "/var/www/${name}/shared" "/var/www/${name}/current/public"
    install -d -o "$user" -g "$user" -m 0750 "/var/log/${name}"
    # nginx (www-data) must reach public/ to serve static files, but not the application code beside it.
    chmod 0751 "/var/www/${name}"
    chown "$user:www-data" "/var/www/${name}/current" "/var/www/${name}/current/public"
    chmod 0750 "/var/www/${name}/current"; chmod 0755 "/var/www/${name}/current/public"
    chmod 0711 "/var/www/${name}/current"
    [ -f "/var/www/${name}/current/public/index.html" ] || printf '<!doctype html><meta charset="utf-8"><title>%s</title><p>Belum ada rilis yang dipasang.</p>\n' "$name" > "/var/www/${name}/current/public/index.html"
    chown "$user:www-data" "/var/www/${name}/current/public/index.html"; chmod 0644 "/var/www/${name}/current/public/index.html"

    conf="${POOLDIR}/${name}.conf"
    [ -f "$conf" ] && cp -a "$conf" "${conf}.bak"
    cat > "$conf" <<CONF
[${name}]
user = ${user}
group = ${user}
listen = /run/php/${name}-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = ${children}
pm.start_servers = 1
pm.min_spare_servers = 1
pm.max_spare_servers = 2
pm.max_requests = 500
request_terminate_timeout = 120s
catch_workers_output = yes

; Per-pool settings only; the machine-wide php.ini belongs to the other site as well and is left alone.
php_admin_value[memory_limit] = ${mem}
php_admin_value[upload_max_filesize] = 12M
php_admin_value[post_max_size] = 16M
php_admin_value[max_execution_time] = 120
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /var/log/${name}/php-fpm.log
php_admin_value[date.timezone] = UTC
CONF
    created+=("$conf")

    cat > "/etc/logrotate.d/${name}" <<LOG
/var/log/${name}/*.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
LOG
done

if ! php-fpm8.4 -t 2>&1 | tail -3; then
    for conf in "${created[@]}"; do
        if [ -f "${conf}.bak" ]; then mv "${conf}.bak" "$conf"; else rm -f "$conf"; fi
    done
    echo "FPM config test failed: files restored, nothing reloaded." >&2
    exit 1
fi

systemctl reload php8.4-fpm
sleep 2
systemctl is-active php8.4-fpm
ls -l /run/php | awk 'NR>1{print $1, $3":"$4, $9}'
ps -eo user,cmd | grep -E "php-fpm: pool" | grep -v grep | sort | uniq -c
echo "== ipm"
curl -sk -o /dev/null -w "ipm https: %{http_code}\n" --resolve ipm.vcare.web.id:443:127.0.0.1 https://ipm.vcare.web.id/
