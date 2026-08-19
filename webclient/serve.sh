#!/bin/bash
# Assembles target/webapp into something a static file server can hand to a
# browser, and (unless -n) serves it. Everything it does is a copy or a
# symlink -- the build itself is prepare-sources.sh + mvn process-classes,
# which must have run first.
#
# The things the page needs beside rscd-client.js:
#   index.html    the page itself
#   rscd-page.js  the fullscreen button's behaviour, shared with rscd-www's
#                 /play/browser so the two pages cannot drift apart
#   cache_data/   the game cache (rscd-www ships one)
#   media/fonts/  the baked .jf interface fonts, if any exist yet -- without
#                 them the client rasterises text on the canvas instead,
#                 which works but is not pixel-identical to the desktop
#
# Usage: serve.sh [port]        default 8090
#        serve.sh -n            assemble only, don't serve
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
WEBAPP="$HERE/target/webapp"
CACHE="$HERE/../cache_data"
FONTS="$HERE/../../rscd-community-client/media/fonts"

if [ ! -f "$WEBAPP/rscd-client.js" ]; then
    echo "no $WEBAPP/rscd-client.js -- build first:" >&2
    echo "  ./prepare-sources.sh ../../rscd-community-client/src && mvn process-classes" >&2
    exit 1
fi

cp "$HERE/web/index.html" "$WEBAPP/index.html"
cp "$HERE/web/rscd-page.js" "$WEBAPP/rscd-page.js"

if [ -d "$CACHE" ]; then
    ln -sfn "$(cd "$CACHE" && pwd)" "$WEBAPP/cache_data"
else
    echo "warning: no cache at $CACHE -- pass ?cache=<url> or the client has no assets" >&2
fi

# Only wire the fonts up when bakes actually exist. An empty or
# source-only media/fonts (FontBaker.java and friends live there too) must
# not become a directory of 404s the client waits on at every boot.
mkdir -p "$WEBAPP/media"
rm -f "$WEBAPP/media/fonts"
if compgen -G "$FONTS/*.jf" > /dev/null; then
    ln -sfn "$(cd "$FONTS" && pwd)" "$WEBAPP/media/fonts"
    echo "fonts: $(ls "$FONTS"/*.jf | wc -l) baked"
else
    echo "fonts: none baked yet -- canvas text (see README)"
fi

if [ "${1:-}" = "-n" ]; then
    echo "assembled $WEBAPP"
    exit 0
fi

PORT="${1:-8090}"
echo "serving $WEBAPP on http://localhost:$PORT/index.html"
cd "$WEBAPP" && exec python3 -m http.server "$PORT"
