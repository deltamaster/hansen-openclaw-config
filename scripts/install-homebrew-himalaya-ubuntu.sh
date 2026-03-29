#!/usr/bin/env bash
# Install Homebrew (Linuxbrew) on Ubuntu/Debian and install Himalaya CLI via brew.
# Run as the user who will own brew (e.g. openclaw on the gateway host). Requires curl, sudo.
# Docs: https://docs.brew.sh/Homebrew-on-Linux — https://github.com/soywod/himalaya
set -euo pipefail

BREW_PREFIX="${HOMEBREW_PREFIX:-/home/linuxbrew/.linuxbrew}"

if [[ ! -x "${BREW_PREFIX}/bin/brew" ]]; then
  echo "Installing Homebrew to ${BREW_PREFIX} ..."
  curl -fsSL -o /tmp/brew-install.sh https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh
  chmod +x /tmp/brew-install.sh
  NONINTERACTIVE=1 /bin/bash /tmp/brew-install.sh
fi

# Ensure login/interactive shells see brew (append once)
if ! grep -qF "${BREW_PREFIX}/bin/brew shellenv bash" "${HOME}/.bashrc" 2>/dev/null; then
  printf '\n# Homebrew (Linuxbrew)\neval "$(%s/bin/brew shellenv bash)"\n' "${BREW_PREFIX}" >> "${HOME}/.bashrc"
  echo "Appended brew shellenv to ~/.bashrc"
fi

eval "$("${BREW_PREFIX}/bin/brew" shellenv bash)"

if ! command -v himalaya >/dev/null 2>&1; then
  echo "Installing himalaya ..."
  brew install himalaya
else
  echo "himalaya already present: $(command -v himalaya)"
fi

brew --version
himalaya --version
