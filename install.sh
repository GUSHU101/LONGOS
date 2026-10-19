#!/usr/bin/env bash
set -euo pipefail

cat <<'EOF'
LONGOS no longer uses a host installer or a BT Panel plugin.

Production deployment is managed by Coolify:
  1. Select Caddy as the server proxy in Coolify.
  2. Create a Git-based Docker Compose application from this repository.
  3. Use docker-compose.yml and enable Raw Compose deployment.
  4. Set DOMAIN, SAAS_BASE_DOMAIN and ADMIN_EMAIL in Coolify.
  5. Deploy. The container installs or upgrades LONGOS automatically.

See docs/COOLIFY.md. No server-side install command is required.
EOF

