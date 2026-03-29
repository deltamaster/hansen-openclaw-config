#!/bin/bash
set -euo pipefail
PUB="/tmp/openclaw-hetzner-ed25519.pub"
if [[ ! -f "$PUB" ]]; then
  echo "Missing $PUB" >&2
  exit 1
fi
if ! id -u openclaw >/dev/null 2>&1; then
  useradd -m -s /bin/bash -G sudo openclaw
fi
usermod -aG sudo openclaw
mkdir -p /home/openclaw/.ssh
chmod 700 /home/openclaw/.ssh
install -m 600 -o openclaw -g openclaw "$PUB" /home/openclaw/.ssh/authorized_keys
chown -R openclaw:openclaw /home/openclaw/.ssh
printf '%s\n' 'openclaw ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/99-openclaw
chmod 440 /etc/sudoers.d/99-openclaw
visudo -cf /etc/sudoers.d/99-openclaw
rm -f "$PUB"
echo "openclaw user ready"
