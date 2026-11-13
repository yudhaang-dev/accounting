#!/usr/bin/env bash
# Stage H (D4c): scheduled logical backups of both databases and a weekly restore test. Installs a script and two systemd timers.
set -euo pipefail

install -d -o postgres -g postgres -m 0700 /var/backups/accounting

cat > /usr/local/sbin/accounting-backup <<'SH'
#!/usr/bin/env bash
# accounting-backup backup | verify   (runs as postgres)
set -Eeuo pipefail
cd /   # the caller's directory may not be reachable by the postgres user
umask 077

DIR=/var/backups/accounting
DBS=(accounting accounting_staging)
DAILY=14
WEEKLY=8
STATUS=$DIR/last-run.status
mode=${1:-backup}
stamp=$(date +%Y%m%d-%H%M%S)

# Newest first; keeps `keep` files of a directory and removes the rest with their checksums. An empty directory is fine.
prune() {
    find "$1" -maxdepth 1 -name '*.dump' -printf '%T@ %p\n' | sort -rn | tail -n +$(($2 + 1)) | cut -d' ' -f2- | while read -r old; do rm -f "$old" "$old.sha256"; done
}

fail() { echo "FAILED $mode $stamp: $1" | tee "$STATUS" >&2; exit 1; }
trap 'fail "line $LINENO"' ERR

backup() {
    for db in "${DBS[@]}"; do
        install -d -m 0700 "$DIR/$db/daily" "$DIR/$db/weekly"
        tmp="$DIR/$db/.$db-$stamp.partial"
        out="$DIR/$db/daily/$db-$stamp.dump"
        pg_dump --format=custom --compress=6 --file="$tmp" "$db"
        pg_restore --list "$tmp" >/dev/null          # the archive must be readable before it counts as a backup
        mv "$tmp" "$out"
        (cd "$DIR/$db/daily" && sha256sum "$(basename "$out")" > "$(basename "$out").sha256")
        [ "$(date +%u)" = 7 ] && cp -al "$out" "$out.sha256" "$DIR/$db/weekly/" || true
        prune "$DIR/$db/daily" "$DAILY"
        prune "$DIR/$db/weekly" "$WEEKLY"
        echo "backup $db: $(basename "$out") $(du -h "$out" | cut -f1)"
    done
    echo "OK backup $stamp" > "$STATUS"
}

verify() {
    for db in "${DBS[@]}"; do
        latest=$(find "$DIR/$db/daily" -maxdepth 1 -name '*.dump' -printf '%T@ %p\n' | sort -rn | head -1 | cut -d' ' -f2-)
        [ -n "$latest" ] || fail "no backup of $db to verify"
        (cd "$DIR/$db/daily" && sha256sum --check --quiet "$(basename "$latest").sha256")
        scratch="restore_test_${db}"
        dropdb --if-exists "$scratch"
        createdb "$scratch"
        pg_restore --no-owner --exit-on-error --dbname="$scratch" "$latest"
        tables=$(psql -tA -d "$scratch" -c "select count(*) from information_schema.tables where table_schema='public'")
        report="tables=$tables"
        if [ "$(psql -tA -d "$scratch" -c "select to_regclass('public.gl_journal_lines') is not null")" = t ]; then
            # The ledger must still be internally consistent after a restore (GL-FR-05): lines balance, balances equal the lines.
            diff=$(psql -tA -d "$scratch" -c "select coalesce(sum(debit) - sum(credit), 0) from gl_journal_lines")
            bal=$(psql -tA -d "$scratch" -c "select coalesce((select sum(debit) from gl_account_balances), 0) - coalesce((select sum(debit) from gl_journal_lines), 0)")
            [ "$diff" = 0.000000 ] || fail "$db: restored journal lines do not balance ($diff)"
            [ "$bal" = 0.000000 ] || fail "$db: restored balances differ from the lines ($bal)"
            report="$report journal_lines_balance=ok balances_match=ok"
        fi
        dropdb "$scratch"
        echo "restore test $db ($(basename "$latest")): $report"
    done
    echo "OK verify $stamp" > "$STATUS"
}

case "$mode" in
    backup) backup ;;
    verify) verify ;;
    *) echo "usage: accounting-backup backup|verify" >&2; exit 2 ;;
esac
SH
chmod 755 /usr/local/sbin/accounting-backup

for unit in backup verify; do
    case $unit in backup) desc="Backup of the accounting databases"; when="*-*-* 02:30:00";; verify) desc="Restore test of the latest accounting backups"; when="Sun *-*-* 04:00:00";; esac
    cat > /etc/systemd/system/accounting-$unit.service <<UNIT
[Unit]
Description=$desc
After=postgresql@18-main.service

[Service]
Type=oneshot
User=postgres
Group=postgres
Nice=15
IOSchedulingClass=idle
ExecStart=/usr/local/sbin/accounting-backup $unit
UNIT
    cat > /etc/systemd/system/accounting-$unit.timer <<UNIT
[Unit]
Description=Schedule: $desc

[Timer]
OnCalendar=$when
RandomizedDelaySec=300
Persistent=true

[Install]
WantedBy=timers.target
UNIT
done

systemctl daemon-reload
systemctl enable --now accounting-backup.timer accounting-verify.timer >/dev/null
systemctl list-timers 'accounting-*' --no-pager | head -4

echo "== first run"
sudo -u postgres /usr/local/sbin/accounting-backup backup
sudo -u postgres /usr/local/sbin/accounting-backup verify
cat /var/backups/accounting/last-run.status
ls -l /var/backups/accounting /var/backups/accounting/accounting/daily | awk 'NR>1{print $1, $3":"$4, $5, $9}'
curl -sk -o /dev/null -w "ipm https: %{http_code}\n" --resolve ipm.vcare.web.id:443:127.0.0.1 https://ipm.vcare.web.id/
