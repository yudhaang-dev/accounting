#!/usr/bin/env bash
# Stage G: mail settings template (secrets left empty, filled by the owner on the server) and a send test that reads it.
set -euo pipefail

install -d -m 0700 /etc/accounting
F=/etc/accounting/mail.env
if [ ! -f "$F" ]; then
    (umask 077; cat > "$F" <<'CONF'
# Mail settings of the production environment (Brevo SMTP). Copied into the application's .env at deploy.
# Fill MAIL_USERNAME and MAIL_PASSWORD here, on the server: sudo nano /etc/accounting/mail.env
MAIL_MAILER=smtp
MAIL_SCHEME=
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@vcare.web.id
MAIL_FROM_NAME="Accounting"
CONF
)
fi
chmod 600 "$F"; chown root:root "$F"

cat > /usr/local/sbin/accounting-mail-test <<'SH'
#!/usr/bin/env bash
# Sends one plain test message with the settings of /etc/accounting/mail.env. Usage: accounting-mail-test recipient@example.com
set -euo pipefail
to=${1:?usage: accounting-mail-test recipient@example.com}
set -a; . /etc/accounting/mail.env; set +a
[ -n "${MAIL_USERNAME:-}" ] && [ -n "${MAIL_PASSWORD:-}" ] || { echo "MAIL_USERNAME and MAIL_PASSWORD are still empty in /etc/accounting/mail.env" >&2; exit 2; }
msg=$(mktemp); trap 'rm -f "$msg"' EXIT
domain=${MAIL_FROM_ADDRESS#*@}
printf 'From: Accounting <%s>\nTo: %s\nSubject: Pengaturan email aplikasi Accounting\nDate: %s\nMessage-ID: <%s.%s@%s>\nMIME-Version: 1.0\nContent-Type: text/plain; charset=utf-8\nContent-Transfer-Encoding: 8bit\n\nHalo,\n\nIni pesan otomatis untuk memeriksa bahwa pengiriman email dari aplikasi Accounting sudah berfungsi.\nTidak ada tindakan yang perlu dilakukan.\n\nTerima kasih,\nAccounting\n' \
    "$MAIL_FROM_ADDRESS" "$to" "$(date -R)" "$(date +%s)" "$RANDOM$RANDOM" "$domain" > "$msg"
curl --silent --show-error --ssl-reqd --max-time 40 "smtp://${MAIL_HOST}:${MAIL_PORT}" \
    --mail-from "$MAIL_FROM_ADDRESS" --mail-rcpt "$to" -u "${MAIL_USERNAME}:${MAIL_PASSWORD}" -T "$msg" && echo "accepted by ${MAIL_HOST}"
SH
chmod 750 /usr/local/sbin/accounting-mail-test
chown root:root /usr/local/sbin/accounting-mail-test

ls -l /etc/accounting/mail.env /usr/local/sbin/accounting-mail-test | awk '{print $1, $3":"$4, $9}'
echo "values set: $(grep -cE '^(MAIL_USERNAME|MAIL_PASSWORD)=.+' $F) of 2 secrets"
curl -V | grep -oE "smtp" | head -1
