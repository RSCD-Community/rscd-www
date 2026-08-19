#!/bin/bash
# Build the browser client and deploy it into the site.
#
# serve.sh assembles target/webapp for local testing; this does the same build
# and then copies the two files a visitor actually needs into rscd-www/browser/,
# which is what the Play Game page links to. The two are deliberately separate
# paths: target/ is maven's and gets wiped by `mvn clean`, and the site must not
# lose its client every time someone rebuilds.
#
# Why browser/ and not play/browser/: the site's .htaccess only rewrites a
# request to index.php when the path is not a real file or directory, and
# `play` is a route (app/routes/app.json, scope "play%"). A real play/
# directory on disk would therefore stop /play reaching the Play controller at
# all -- Apache would serve the directory instead. /browser collides with no
# route.
#
# The page's other two dependencies are already served at the site root and are
# not copied here:
#   /cache_data   the game assets (rscd-www ships them; WebLaunch's default)
#   /media/fonts  baked interface fonts, optional -- see Fonts in README.md
#
# Usage: build.sh [path-to-client-src]
#        default ../../rscd-community-client/src
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="${1:-$HERE/../../rscd-community-client/src}"
DEST="$HERE/../browser"
FONTS="$HERE/../../rscd-community-client/media/fonts"

if [ ! -d "$SRC" ]; then
    echo "no client sources at $SRC" >&2
    echo "usage: build.sh [path-to-client-src]" >&2
    exit 1
fi

"$HERE/prepare-sources.sh" "$SRC"
(cd "$HERE" && mvn -q process-classes)
"$HERE/serve.sh" -n

mkdir -p "$DEST"
cp "$HERE/target/webapp/rscd-client.js" "$DEST/rscd-client.js"
cp "$HERE/web/index.html" "$DEST/index.html"
# The site's own /play/browser page loads this from here too, so the button
# behaves the same whichever page the client is embedded in.
cp "$HERE/web/rscd-page.js" "$DEST/rscd-page.js"

# The source map is a development aid: 250KB that only means anything with the
# generated sources beside it, which are not deployed. Leave it out rather than
# ship a map every visitor's devtools will try to fetch and fail on.
rm -f "$DEST/rscd-client.js.map"

# Fonts, if any have been baked, go to the site root because that is where the
# client looks for them by default (origin + /media/fonts) and the page has no
# way to say otherwise without a query string on every link.
if compgen -G "$FONTS/*.jf" > /dev/null; then
    mkdir -p "$HERE/../media/fonts"
    cp "$FONTS"/*.jf "$HERE/../media/fonts/"
    echo "fonts: $(ls "$FONTS"/*.jf | wc -l) baked -> media/fonts/"
else
    echo "fonts: none baked yet -- canvas text (see README)"
fi

echo "deployed $(du -h "$DEST/rscd-client.js" | cut -f1) to $DEST"
echo "the Play Game page shows its browser buttons as soon as this file exists"
