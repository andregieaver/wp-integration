#!/usr/bin/env bash
# Build an installable plugin zip for Plugins -> Add New -> Upload Plugin.
#
# The zip must contain a single top-level folder named exactly wp-agent-bridge,
# because WordPress unpacks it straight into wp-content/plugins/ and the folder
# name becomes the plugin slug.
set -euo pipefail

cd "$(dirname "$0")"
out="wp-agent-bridge.zip"

rm -f "$out"
zip -rq "$out" wp-agent-bridge \
	-x '*.DS_Store' -x '*/node_modules/*' -x '*/.git/*'

echo "Built $out"
unzip -l "$out" | tail -n +4 | head -n 3
