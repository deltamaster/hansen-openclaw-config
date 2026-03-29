#!/usr/bin/env bash
# Fix Debian/Ubuntu sendmail: FQDN qualification + clean systemd start.
# Run on the gateway with sudo. Idempotent: skips insert if confDOMAIN_NAME already present.
# Override: HOST_FQDN=example.com HOST_SHORT=ex sudo ./configure-sendmail-ubuntu.sh
set -euo pipefail

HOST_FQDN="${HOST_FQDN:-de.hansenh.xyz}"
HOST_SHORT="${HOST_SHORT:-de}"

MC="/etc/mail/sendmail.mc"
DOMAIN_LINE="DOMAIN(\`debian-mta')dnl"
INSERT="define(\`confDOMAIN_NAME',\`${HOST_FQDN}')dnl"
MAILNAME="$HOST_FQDN"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo $0" >&2
  exit 1
fi

if grep -qF 'confDOMAIN_NAME' "$MC" 2>/dev/null; then
  echo "sendmail.mc already contains confDOMAIN_NAME (will sync to HOST_FQDN)"
else
  tmp="$(mktemp)"
  python3 - "$MC" "$tmp" "$DOMAIN_LINE" "$INSERT" <<'PY'
import sys
path, out, needle, insert = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4] + "\n"
with open(path) as f:
    lines = f.readlines()
with open(out, "w") as f:
    for line in lines:
        f.write(line)
        if line.rstrip("\n") == needle:
            f.write(insert)
import shutil
shutil.move(out, path)
PY
  echo "Inserted confDOMAIN_NAME into sendmail.mc"
fi

python3 - "$MC" "$HOST_FQDN" <<'PY'
import re, sys
path, fqdn = sys.argv[1], sys.argv[2]
t = open(path).read()
pat = r"define\(`confDOMAIN_NAME',`[^']+'\)dnl"
rep = f"define(`confDOMAIN_NAME',`{fqdn}')dnl"
t2, n = re.subn(pat, rep, t, count=1)
if n == 1 and t2 != t:
    open(path, "w").write(t2)
    print("Updated confDOMAIN_NAME to", fqdn)
PY

echo "$MAILNAME" > /etc/mailname
echo "/etc/mailname -> $(cat /etc/mailname)"

# Qualify hostname for libc/getaddrinfo (stops "unable to qualify my own domain name" in logs).
CLOUD_HOSTS="/etc/cloud/templates/hosts.debian.tmpl"
if [[ -f "$CLOUD_HOSTS" ]]; then
  sed -i "s/^127.0.1.1 .*/127.0.1.1 ${HOST_FQDN} ${HOST_SHORT}/" "$CLOUD_HOSTS"
  echo "Cloud template 127.0.1.1 -> ${HOST_FQDN} ${HOST_SHORT}"
fi
sed -i "s/^127.0.1.1 .*/127.0.1.1 ${HOST_FQDN} ${HOST_SHORT}/" /etc/hosts
echo "/etc/hosts 127.0.1.1 -> ${HOST_FQDN} ${HOST_SHORT}"

(cd /etc/mail && make)

systemctl reset-failed sendmail 2>/dev/null || true
systemctl enable sendmail
systemctl restart sendmail
systemctl --no-pager --full status sendmail || true

echo ""
echo "Listening (expect 127.0.0.1:25 and :587):"
ss -tlnp | grep -E ':25|:587' || true
