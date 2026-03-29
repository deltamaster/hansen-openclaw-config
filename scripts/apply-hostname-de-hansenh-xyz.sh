#!/usr/bin/env bash
# Apply FQDN de.hansenh.xyz (static hostname, hosts, mail, sendmail). Run as root.
set -euo pipefail
FQDN="de.hansenh.xyz"
SHORT="de"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run: sudo $0" >&2
  exit 1
fi

hostnamectl set-hostname "$FQDN"

sed -i "s/^127.0.1.1 .*/127.0.1.1 ${FQDN} ${SHORT}/" /etc/hosts
sed -i "s/^127.0.1.1 .*/127.0.1.1 ${FQDN} ${SHORT}/" /etc/cloud/templates/hosts.debian.tmpl

echo "$FQDN" > /etc/mailname

MC="/etc/mail/sendmail.mc"
if grep -q "confDOMAIN_NAME" "$MC"; then
  python3 - "$MC" "$FQDN" <<'PY'
import re, sys
path, fqdn = sys.argv[1], sys.argv[2]
t = open(path).read()
pat = r"define\(`confDOMAIN_NAME',`[^']+'\)dnl"
rep = f"define(`confDOMAIN_NAME',`{fqdn}')dnl"
t2, n = re.subn(pat, rep, t, count=1)
if n != 1:
    raise SystemExit(f"sendmail.mc confDOMAIN_NAME replace failed (matches={n})")
open(path, "w").write(t2)
PY
fi

(cd /etc/mail && make)
systemctl restart sendmail

echo "hostname=$(hostname) fqdn=$(hostname -f) short=$(hostname -s)"
echo "mailname=$(cat /etc/mailname)"
grep "^127.0.1.1" /etc/hosts
grep confDOMAIN_NAME /etc/mail/sendmail.mc || true
systemctl is-active sendmail
