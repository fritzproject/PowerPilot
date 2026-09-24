#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$1"
OUT="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$OUT" "$STAGE/usr/local/emhttp/plugins/powerpilot/src" "$STAGE/usr/local/emhttp/plugins/powerpilot/event" "$STAGE/etc/rc.d" "$STAGE/install"
install -m 0644 "$ROOT/plugin/webui/powerpilot.page" "$STAGE/usr/local/emhttp/plugins/powerpilot/powerpilot.page"
install -m 0644 "$ROOT/plugin/webui/api.php" "$STAGE/usr/local/emhttp/plugins/powerpilot/api.php"
install -m 0644 "$ROOT/plugin/src/engine.php" "$STAGE/usr/local/emhttp/plugins/powerpilot/src/engine.php"
install -m 0644 "$ROOT/plugin/src/network.php" "$STAGE/usr/local/emhttp/plugins/powerpilot/src/network.php"
install -m 0755 "$ROOT/plugin/rc.powerpilot" "$STAGE/etc/rc.d/rc.powerpilot"
install -m 0755 "$ROOT/plugin/event/started" "$STAGE/usr/local/emhttp/plugins/powerpilot/event/started"
install -m 0755 "$ROOT/plugin/event/stopping" "$STAGE/usr/local/emhttp/plugins/powerpilot/event/stopping"
cat > "$STAGE/install/doinst.sh" <<'Doinst'
#!/bin/sh
chmod 0755 /etc/rc.d/rc.powerpilot
/etc/rc.d/rc.powerpilot start || logger -t powerpilot "Plugin installed; service start failed"
Doinst
chmod 0755 "$STAGE/install/doinst.sh"
PACKAGE="powerpilot-$VERSION.txz"
tar --owner=0 --group=0 --numeric-owner -C "$STAGE" -cJf "$OUT/$PACKAGE" .
HASH="$(sha256sum "$OUT/$PACKAGE" | cut -d ' ' -f 1)"
sed -e "s/@VERSION@/$VERSION/g" -e "s/@SHA256@/$HASH/g" "$ROOT/plugin/powerpilot.plg.in" > "$OUT/powerpilot.plg"
echo "Created $OUT/$PACKAGE"
echo "SHA256 $HASH"
