#!/bin/bash
# Copies the game client sources into the webclient build and rewrites their
# JDK imports onto the rscweb.* shim layer. The repo sources are never touched,
# and the rewrite is purely textual and deterministic, so rscd1-dev's #154
# rename sweep (class/field/method renames, no package moves) costs us nothing:
# re-run this script against the new tree and the same rewrites apply.
#
# Usage: prepare-sources.sh <client-src-dir> [<out-dir>]
set -euo pipefail

SRC="$(cd "${1:?client src dir required}" && pwd)"
OUT="${2:-$(cd "$(dirname "$0")" && pwd)/target/generated-sources/game}"

rm -rf "$OUT"
mkdir -p "$OUT"
# SingleInstance is left behind and replaced by the stub in this module's own
# src/main/java, same package. It is the one game class that cannot be shimmed
# at the JDK layer: it binds a loopback ServerSocket so a second desktop launch
# can hand its rscd:// link to the client already open, and a page can neither
# listen on a port nor be launched twice. Rewriting its imports would only get
# it as far as compiling something that must never run.
(cd "$SRC" && find . -name '*.java' ! -path './org/rscdaemon/client/tools/*' \
    ! -name 'SingleInstance.java' \
    -exec cp --parents {} "$OUT" \;)

# The whole point of the shim layer: every AWT/imageio/tools/zip reference goes
# to rscweb.*, wholesale. Subpackages (awt.image, awt.event, awt.font,
# imageio.stream) ride along automatically.
find "$OUT" -name '*.java' -print0 | xargs -0 sed -i \
    -e 's/\bjava\.awt\./rscweb.awt./g' \
    -e 's/\bjavax\.imageio\./rscweb.imageio./g' \
    -e 's/\bjavax\.tools\./rscweb.jtools./g' \
    -e 's/\bjava\.util\.zip\./rscweb.zip./g'

# java.net and java.io are rewritten selectively: only the classes that touch
# the real network/filesystem move to rscweb; IOException, the byte streams,
# and friends stay on the (TeaVM-provided) JDK. \b keeps java.io.File from
# eating java.io.FileInputStream's prefix.
find "$OUT" -name '*.java' -print0 | xargs -0 sed -i \
    -e 's/\bjava\.net\.Socket\b/rscweb.net.Socket/g' \
    -e 's/\bjava\.net\.InetAddress\b/rscweb.net.InetAddress/g' \
    -e 's/\bjava\.net\.URLClassLoader\b/rscweb.net.URLClassLoader/g' \
    -e 's/\bjava\.net\.URLConnection\b/rscweb.net.URLConnection/g' \
    -e 's/\bjava\.net\.HttpURLConnection\b/rscweb.net.HttpURLConnection/g' \
    -e 's/\bjava\.net\.URLDecoder\b/rscweb.net.URLDecoder/g' \
    -e 's/\bjava\.net\.URL\b/rscweb.net.URL/g' \
    -e 's/\bjava\.io\.File\b/rscweb.io.File/g' \
    -e 's/\bjava\.io\.FileInputStream\b/rscweb.io.FileInputStream/g' \
    -e 's/\bjava\.io\.FileOutputStream\b/rscweb.io.FileOutputStream/g' \
    -e 's/\bjava\.io\.RandomAccessFile\b/rscweb.io.RandomAccessFile/g' \
    -e 's/\bjava\.nio\.file\.Files\b/rscweb.io.Files/g'

# TeaVM's classlib has ArrayBlockingQueue and LinkedBlockingDeque but not
# LinkedBlockingQueue; a deque used through the BlockingQueue interface is
# FIFO-identical, so substitute it.
find "$OUT" -name '*.java' -print0 | xargs -0 sed -i \
    -e 's/\bjava\.util\.concurrent\.LinkedBlockingQueue\b/java.util.concurrent.LinkedBlockingDeque/g' \
    -e 's/\bLinkedBlockingQueue\b/LinkedBlockingDeque/g'

# Late additions: script-only surfaces that show up as fully-qualified names.
# The getProtectionDomain chain (jar-location discovery) cannot exist in a
# browser and TeaVM has no ProtectionDomain, so the whole expression collapses
# to one shim call.
find "$OUT" -name '*.java' -print0 | xargs -0 sed -i \
    -e 's/\bjavax\.sound\.sampled\./rscweb.sound./g' \
    -e 's/\bjava\.io\.PrintWriter\b/rscweb.io.PrintWriter/g' \
    -e 's/[A-Za-z_][A-Za-z0-9_]*\.class\.getProtectionDomain()\.getCodeSource()\.getLocation()\.toURI()/rscweb.io.File.codeSourceUri()/g'


# TeaVM classlib gaps that are method-level, not package-level: exit() has no
# process to end, the deprecated getBytes overload doesn't exist, and the one
# Swing dialog becomes a console line.
find "$OUT" -name '*.java' -print0 | xargs -0 sed -i \
    -e 's/\bSystem\.exit(/rscweb.Exit.exit(/g' \
    -e 's/\bjavax\.swing\.JOptionPane\b/rscweb.swing.JOptionPane/g' \
    -e 's/\([A-Za-z0-9_]\+\)\.getBytes(0, \1\.length(), /rscweb.Strings.copyAscii(\1, /g'

# TeaVM cannot resolve dynamic Class.forName strings at compile time, so the
# def classes XmlObjects binds by name would be stripped. Classes.forName is a
# literal-backed map of exactly the alias table; the class constants in it are
# what makes TeaVM keep (and emit reflection metadata for) the def classes.
# XmlObjects.java is the only file with a dynamic forName, but the rewrite is
# global-safe: any forName of an unmapped class now throws CNFE at the same
# call site it would have anyway.
# getField additionally hits a TeaVM 0.15 classlib bug: TClass.findField's
# visited-set tracks the field name instead of the class, so the superclass
# recursion bails on its first step and inherited public fields (EntityDef's
# name/description) never resolve. Classes.publicField walks the chain with
# getDeclaredField, which doesn't recurse and is unaffected.
find "$OUT" -name 'XmlObjects.java' -print0 | xargs -0 sed -i \
    -e 's/\bClass\.forName(/rscweb.lang.Classes.forName(/g' \
    -e 's/\btype\.getField(/rscweb.lang.Classes.publicField(type, /g'

echo "prepared $(find "$OUT" -name '*.java' | wc -l) sources into $OUT"
