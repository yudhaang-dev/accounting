#!/usr/bin/env bash
# Stage I (D4d): queue worker and scheduler per environment, as systemd units. They start by themselves once a release exists
# (current/artisan), so nothing fails or flaps before the first deploy.
set -euo pipefail

ENVS=("accounting:acct" "accounting-staging:acct-stg")

for spec in "${ENVS[@]}"; do
    name=${spec%%:*}; user=${spec#*:}
    dir=/var/www/$name/current

    cat > /etc/systemd/system/accounting-queue-$name.service <<UNIT
[Unit]
Description=Queue worker of $name
After=network-online.target postgresql@18-main.service
ConditionPathExists=$dir/artisan

[Service]
User=$user
Group=$user
WorkingDirectory=$dir
ExecStart=/usr/bin/php -d memory_limit=192M artisan queue:work --sleep=3 --tries=3 --max-time=3600 --max-jobs=1000 --memory=128
Restart=always
RestartSec=5
# After a deploy \`php artisan queue:restart\` makes the worker exit once its job is done; systemd starts it on the new code.
MemoryMax=320M
NoNewPrivileges=yes
PrivateTmp=yes
ProtectHome=yes
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
UNIT

    cat > /etc/systemd/system/accounting-queue-$name.path <<UNIT
[Unit]
Description=Start the queue worker of $name when a release exists

[Path]
PathExists=$dir/artisan
Unit=accounting-queue-$name.service

[Install]
WantedBy=multi-user.target
UNIT

    cat > /etc/systemd/system/accounting-scheduler-$name.service <<UNIT
[Unit]
Description=Scheduler tick of $name (outbox:relay, recurring entries, scheduled reversals, idempotency purge)
After=postgresql@18-main.service
ConditionPathExists=$dir/artisan

[Service]
Type=oneshot
User=$user
Group=$user
WorkingDirectory=$dir
ExecStart=/usr/bin/php -d memory_limit=192M artisan schedule:run --no-interaction
MemoryMax=320M
NoNewPrivileges=yes
PrivateTmp=yes
ProtectHome=yes
TimeoutStartSec=300
UNIT

    cat > /etc/systemd/system/accounting-scheduler-$name.timer <<UNIT
[Unit]
Description=Every minute: scheduler of $name

[Timer]
OnCalendar=minutely
AccuracySec=1s

[Install]
WantedBy=timers.target
UNIT
done

systemctl daemon-reload
for spec in "${ENVS[@]}"; do
    name=${spec%%:*}
    systemctl enable --now accounting-queue-$name.path accounting-scheduler-$name.timer >/dev/null 2>&1
done

echo "== units"
for spec in "${ENVS[@]}"; do
    name=${spec%%:*}
    printf "%-44s %s\n" "accounting-queue-$name.path" "$(systemctl is-active accounting-queue-$name.path)"
    printf "%-44s %s\n" "accounting-scheduler-$name.timer" "$(systemctl is-active accounting-scheduler-$name.timer)"
    printf "%-44s %s\n" "accounting-queue-$name.service" "$(systemctl is-active accounting-queue-$name.service || true)"
done
systemd-analyze verify /etc/systemd/system/accounting-queue-*.service /etc/systemd/system/accounting-scheduler-*.service 2>&1 | head -5 || true
systemctl list-timers 'accounting-scheduler-*' --no-pager | sed -n 1,3p
curl -sk -o /dev/null -w "ipm https: %{http_code}\n" --resolve ipm.vcare.web.id:443:127.0.0.1 https://ipm.vcare.web.id/
