#!/usr/bin/env bash
# Stage B: PostgreSQL 18 from PGDG, localhost only, two databases (production, staging) with their own roles.
# Passwords are generated here and stay in /etc/accounting/*.db.pass (root only); they are never printed.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

if [ ! -f /etc/apt/sources.list.d/pgdg.list ]; then
    install -d /usr/share/postgresql-common/pgdg
    curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc
    echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(. /etc/os-release && echo $VERSION_CODENAME)-pgdg main" > /etc/apt/sources.list.d/pgdg.list
    apt-get update -q >/dev/null
fi

# Only the PostgreSQL packages: nothing else on the machine is upgraded.
apt-get install -y -q postgresql-18 >/dev/null

install -d -m 0755 /etc/postgresql/18/main/conf.d
cat > /etc/postgresql/18/main/conf.d/90-accounting.conf <<'CONF'
# 4 GB server shared with other sites. The lock table is sized for posting and the test suite (ADR-0003, TODO D4c).
listen_addresses = 'localhost'
max_connections = 80
shared_buffers = 512MB
effective_cache_size = 1536MB
work_mem = 8MB
maintenance_work_mem = 128MB
max_locks_per_transaction = 256
log_min_duration_statement = 500
CONF
systemctl restart postgresql@18-main
systemctl is-active postgresql@18-main

install -d -m 0700 /etc/accounting
for env in accounting accounting_staging; do
    file="/etc/accounting/${env}.db.pass"
    if [ ! -f "$file" ]; then
        (umask 077; openssl rand -base64 24 | tr -d '/+=\n' > "$file")
    fi
    pass=$(cat "$file")
    sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<SQL
DO \$\$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${env}') THEN
    CREATE ROLE ${env} LOGIN PASSWORD '${pass}';
  END IF;
END \$\$;
SQL
    sudo -u postgres psql -tA -c "SELECT 1 FROM pg_database WHERE datname='${env}'" | grep -q 1 || sudo -u postgres createdb -O "${env}" "${env}"
    sudo -u postgres psql -d "${env}" -q -c "CREATE EXTENSION IF NOT EXISTS btree_gist"
    sudo -u postgres psql -q -c "REVOKE ALL ON DATABASE ${env} FROM PUBLIC"
done

echo "== check"
sudo -u postgres psql -tA -c "select version()" | cut -c1-40
sudo -u postgres psql -tA -c "show max_locks_per_transaction" -c "show shared_buffers"
sudo -u postgres psql -tA -c "select datname from pg_database where datname like 'accounting%' order by 1"
ss -tln | awk '$4 ~ /:5432$/ {print "listen: "$4}'
ls -l /etc/accounting | awk 'NR>1{print $1, $3, $9}'
echo "== ipm / memory"
curl -sk -o /dev/null -w "ipm https: %{http_code}\n" --resolve ipm.vcare.web.id:443:127.0.0.1 https://ipm.vcare.web.id/
free -m | sed -n 2,3p
