# scripts/fresh.sh
#!/usr/bin/env bash
set -euo pipefail
lando destroy -y
lando start
