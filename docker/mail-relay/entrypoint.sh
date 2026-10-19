#!/bin/sh
set -eu

: "${MAIL_HOSTNAME:?MAIL_HOSTNAME is required}"
: "${MAIL_DOMAINS:?MAIL_DOMAINS is required}"
: "${ALLOWED_SENDER_DOMAINS:?ALLOWED_SENDER_DOMAINS is required}"

load_secret() {
  variable="$1"
  file_variable="${variable}_FILE"
  eval "value=\${$variable:-}"
  eval "secret_file=\${$file_variable:-}"
  if [ -n "$value" ] && [ -n "$secret_file" ]; then
    echo "$variable and $file_variable cannot both be configured" >&2
    exit 1
  fi
  if [ -n "$secret_file" ]; then
    if [ ! -f "$secret_file" ] || [ -L "$secret_file" ] || [ ! -r "$secret_file" ]; then
      echo "$file_variable must reference a readable regular file" >&2
      exit 1
    fi
    value="$(tr -d '\r\n' < "$secret_file")"
  fi
  if [ -z "$value" ]; then
    echo "$variable is required (directly or through $file_variable)" >&2
    exit 1
  fi
  eval "$variable=\$value"
  export "$variable"
}

# Coolify generates and persists independent high-entropy credentials. File
# variables remain supported for operators using an external secret provider.
load_secret LONGOS_RELAY_SHARED_SECRET
load_secret CLOUD_MAIL_RELAY_SHARED_SECRET

if [ "$LONGOS_RELAY_SHARED_SECRET" = "$CLOUD_MAIL_RELAY_SHARED_SECRET" ]; then
  echo "LONGOS_RELAY_SHARED_SECRET and CLOUD_MAIL_RELAY_SHARED_SECRET must be different" >&2
  exit 1
fi
if [ "${#LONGOS_RELAY_SHARED_SECRET}" -lt 32 ] || [ "${#CLOUD_MAIL_RELAY_SHARED_SECRET}" -lt 32 ]; then
  echo "LONGOS_RELAY_SHARED_SECRET and CLOUD_MAIL_RELAY_SHARED_SECRET must each contain at least 32 characters" >&2
  exit 1
fi

DKIM_SELECTOR="${DKIM_SELECTOR:-mail2026}"
INET_PROTOCOLS="${INET_PROTOCOLS:-ipv4}"
SMTP_INET_INTERFACES="${SMTP_INET_INTERFACES:-loopback-only}"
SMTP_MYNETWORKS="${SMTP_MYNETWORKS:-127.0.0.0/8}"
PROCESS_CHECK_INTERVAL_SECONDS="${PROCESS_CHECK_INTERVAL_SECONDS:-5}"

case "$PROCESS_CHECK_INTERVAL_SECONDS" in
  ''|*[!0-9]*)
    echo "PROCESS_CHECK_INTERVAL_SECONDS must be an integer between 2 and 300" >&2
    exit 1
    ;;
esac
if [ "$PROCESS_CHECK_INTERVAL_SECONDS" -lt 2 ] || [ "$PROCESS_CHECK_INTERVAL_SECONDS" -gt 300 ]; then
  echo "PROCESS_CHECK_INTERVAL_SECONDS must be an integer between 2 and 300" >&2
  exit 1
fi

normalize_domain() {
  printf '%s' "$1" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]' | sed 's/\.$//'
}

validate_domain() {
  value="$1"
  label="$2"
  if [ "${#value}" -gt 253 ] || ! printf '%s' "$value" \
    | grep -Eq '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$'; then
    echo "$label contains an invalid DNS domain: $value" >&2
    exit 1
  fi
}

MAIL_HOSTNAME="$(normalize_domain "$MAIL_HOSTNAME")"
validate_domain "$MAIL_HOSTNAME" MAIL_HOSTNAME
if ! printf '%s' "$DKIM_SELECTOR" | grep -Eq '^[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}$'; then
  echo "DKIM_SELECTOR must be a valid DKIM selector" >&2
  exit 1
fi

# Validate every configured value before using it in a filesystem path or an
# OpenDKIM table. Environment configuration must never enable path traversal.
old_ifs="$IFS"
IFS=','
validated_signing_domains=','
for raw_domain in $MAIL_DOMAINS; do
  domain="$(normalize_domain "$raw_domain")"
  validate_domain "$domain" MAIL_DOMAINS
  validated_signing_domains="${validated_signing_domains}${domain},"
done
for raw_domain in $ALLOWED_SENDER_DOMAINS; do
  domain="$(normalize_domain "$raw_domain")"
  validate_domain "$domain" ALLOWED_SENDER_DOMAINS
  case "$validated_signing_domains" in
    *",${domain},"*) ;;
    *)
      echo "ALLOWED_SENDER_DOMAINS contains $domain but MAIL_DOMAINS has no DKIM key for it" >&2
      exit 1
      ;;
  esac
done
IFS="$old_ifs"

printf '%s\n' "$MAIL_HOSTNAME" > /etc/mailname
mkdir -p /run/opendkim /etc/opendkim /var/lib/dkim
chown opendkim:opendkim /run/opendkim /var/lib/dkim

: > /etc/opendkim/KeyTable
: > /etc/opendkim/SigningTable
printf '127.0.0.1\nlocalhost\n' > /etc/opendkim/TrustedHosts

old_ifs="$IFS"
IFS=','
for raw_domain in $MAIL_DOMAINS; do
  domain="$(normalize_domain "$raw_domain")"
  [ -n "$domain" ] || continue
  key_dir="/var/lib/dkim/$domain"
  mkdir -p "$key_dir"
  if [ ! -s "$key_dir/$DKIM_SELECTOR.private" ]; then
    opendkim-genkey -b 2048 -d "$domain" -D "$key_dir" -s "$DKIM_SELECTOR"
  fi
  chown -R opendkim:opendkim "$key_dir"
  chmod 0700 "$key_dir"
  chmod 0600 "$key_dir/$DKIM_SELECTOR.private"
  printf '%s._domainkey.%s %s:%s:%s/%s.private\n' \
    "$DKIM_SELECTOR" "$domain" "$domain" "$DKIM_SELECTOR" "$key_dir" "$DKIM_SELECTOR" \
    >> /etc/opendkim/KeyTable
  printf '*@%s %s._domainkey.%s\n' "$domain" "$DKIM_SELECTOR" "$domain" \
    >> /etc/opendkim/SigningTable
  printf '%s\n*.%s\n' "$domain" "$domain" >> /etc/opendkim/TrustedHosts
done
IFS="$old_ifs"

cat > /etc/opendkim.conf <<EOF
Syslog                  yes
UMask                   007
Mode                    sv
Canonicalization        relaxed/simple
OversignHeaders         From
UserID                  opendkim
PidFile                 /run/opendkim/opendkim.pid
Socket                  inet:8891@127.0.0.1
KeyTable                refile:/etc/opendkim/KeyTable
SigningTable            refile:/etc/opendkim/SigningTable
ExternalIgnoreList      refile:/etc/opendkim/TrustedHosts
InternalHosts           refile:/etc/opendkim/TrustedHosts
EOF

postconf -e "myhostname = $MAIL_HOSTNAME"
postconf -e "myorigin = \$mydomain"
postconf -e "mydestination = localhost"
postconf -e "inet_interfaces = $SMTP_INET_INTERFACES"
postconf -e "inet_protocols = $INET_PROTOCOLS"
postconf -e "mynetworks = $SMTP_MYNETWORKS"
postconf -e "smtpd_relay_restrictions = permit_mynetworks,reject_unauth_destination"
postconf -e "relayhost ="
postconf -e "smtp_helo_name = \$myhostname"
postconf -e "smtp_tls_security_level = may"
postconf -e "smtp_tls_CAfile = /etc/ssl/certs/ca-certificates.crt"
postconf -e "smtp_tls_loglevel = 1"
postconf -e "disable_dns_lookups = no"
postconf -e "maillog_file = /dev/stdout"
postconf -e "milter_default_action = tempfail"
postconf -e "milter_protocol = 6"
postconf -e "non_smtpd_milters = inet:127.0.0.1:8891"
postconf -e "smtpd_milters = inet:127.0.0.1:8891"

opendkim -x /etc/opendkim.conf
postfix check
postfix start

echo "LONGOS built-in mail relay started. Publish these DKIM TXT records:"
find /var/lib/dkim -maxdepth 3 -type f -name '*.txt' -print -exec cat {} \;

node /app/src/server.mjs &
node_pid=$!
stopping=0

shutdown() {
  [ "$stopping" -eq 0 ] || return 0
  stopping=1
  trap - INT TERM EXIT
  kill "$node_pid" 2>/dev/null || true
  postfix stop 2>/dev/null || true
  pkill opendkim 2>/dev/null || true
  wait "$node_pid" 2>/dev/null || true
}

handle_signal() {
  shutdown
  exit 0
}

trap handle_signal INT TERM
trap shutdown EXIT

# Docker reports an unhealthy container but does not restart it by itself.
# Exit when either signing or queueing dies so the Compose restart policy can
# recover the complete relay instead of leaving a permanently degraded API.
while kill -0 "$node_pid" 2>/dev/null; do
  sleep "$PROCESS_CHECK_INTERVAL_SECONDS" &
  sleep_pid=$!
  wait "$sleep_pid" || true

  kill -0 "$node_pid" 2>/dev/null || break
  if ! postfix status >/dev/null 2>&1 || ! pgrep -x opendkim >/dev/null 2>&1; then
    echo "A required mail relay process exited; restarting the container." >&2
    exit 1
  fi
done

if wait "$node_pid"; then
  exit 0
else
  node_status=$?
  echo "The LONGOS mail relay API exited unexpectedly (status $node_status)." >&2
  exit "$node_status"
fi
