# rscd webclient — play in the browser

The community client, compiled to JavaScript with TeaVM and talking to a
stock rscd-server through its in-process WebSocket bridge. One server, two
clients: the downloadable client (with SkullOrca scripting) connects to the
TCP game port, this one connects to the `ws_port` right next to it, and the
server cannot tell the players apart.

The game sources are **not** copied here. `prepare-sources.sh` pulls them
from `rscd-community-client/src` at build time and rewrites their JDK
surface onto the `rscweb.*` shim layer — the repo client is never modified,
and client-side renames don't break the build (the rewrites are import- and
package-based, not name-based).

## Build

Needs a JDK (11+) and Maven. TeaVM 0.15 comes from Maven Central.

```
./prepare-sources.sh ../../rscd-community-client/src
mvn process-classes
```

TeaVM writes `rscd-client.js` into `target/webapp/`. `./serve.sh` puts the
rest of the deployment beside it — `index.html`, the cache, the fonts — and
starts a static server on port 8090:

```
./serve.sh          # assemble and serve on http://localhost:8090/index.html
./serve.sh 9000     # ... on another port
./serve.sh -n       # assemble only, for a real web server to pick up
```

## Deploy into the site

`./build.sh` does the whole build and copies the result into `rscd-www/browser/`,
which is what the Play Game page links to:

```
./build.sh                              # ../../rscd-community-client/src
./build.sh /path/to/other/client/src
```

Three files land there — `rscd-client.js`, `index.html` and `rscd-page.js`.
The cache and the fonts are not copied: the client looks for them at the site
root (`/cache_data`, `/media/fonts`), where rscd-www already serves the first
and `build.sh` puts the second if any bakes exist.

**Two pages host the client, and only one of them is the site's.**

`web/index.html` is standalone — its own stylesheet, no navigation, nothing
outside this directory. That is what makes it useful to an operator hosting
the client on something that is not rscd-www, and it is the page `serve.sh`
serves for local testing.

Reached from rscd-community.org it was a dead end: it looked like no other
page, and the only way back to the site was the browser's Back button. So
rscd-www renders the same canvas itself at **`/play/browser`**
(`RSCD\Controller\Play::httpGetBrowser`, `ui/html/play/browser.html`), inside
the normal view — the 2003 frame, the nav, the footer and the stylesheet all
come for free and keep matching the site when any of them change. The Play
Game buttons link there, not to `browser/index.html`.

Both pages load `rscd-page.js` for the fullscreen button, so the two cannot
drift apart in behaviour. The client itself is unaware of either: it mounts on
`#rscd-game` and reads its options from `location.search`, and every default it
computes (`/cache_data`, `/media/fonts`, `/worlds.json`) is origin-absolute, so
it does not care what path the page is served from.

The game canvas is 512 wide and rscd-www's content pane is 518 — both are the
same 2003 measurement, which is why it fits at all. It fits with six pixels to
spare, so the stage sits outside the usual container padding; see the
`.play-browser` rules in `ui/css/rscd.css`.

`browser/` is deliberately not `play/browser/`. The site's `.htaccess` only
rewrites to `index.php` when the path is not a real file or directory, and
`play` is a route — a real `play/` directory would stop `/play` ever reaching
the Play controller.

Nothing under `browser/` is in git (see `rscd-www/.gitignore`); it is build
output. A checkout that has not run `build.sh` has no browser client, and the
Play page leaves its **Play in Browser** buttons out rather than linking to a
404 — `Play::browserClientAvailable()` is the one check, and it looks for
`browser/rscd-client.js`.

## Serve

The page needs these reachable over HTTP(S), all relative to itself:

| what             | where                             | note                                |
|------------------|-----------------------------------|-------------------------------------|
| `index.html`     | anywhere                          | copies from `web/`                  |
| `rscd-client.js` | same directory as index.html      | built by maven                      |
| game cache       | `./cache_data` (or `?cache=` URL) | rscd-www already serves one         |
| baked fonts      | `./media/fonts` (or `?fonts=`)    | optional, see Fonts below           |

Page options (query string): `?server=host` `?port=n` (game TCP port, used
to derive the ws port as port+1) `?ws=url` (full ws:// or wss:// URL,
overrides the derivation) `?cache=url` `?fonts=url` `?api=url` (the worlds
list) `?size=WxH`.

`?target=host` skips the Worlds screen and goes straight to sign-in for that
server. Without it the client opens on Worlds and picks nothing for the
player, which is the deliberate default: a page that quietly preselected
whoever hosts it would be taking back the choice this client exists to give
away. An operator hosting the page *for* their own server opts in with
`?target=`.

`?size=WxH` opens at a size other than the vanilla 512x345 — the same resize
the desktop client does when its window is dragged, so the game draws more
world rather than scaling anything up. The **Fullscreen** button on the page
does the same thing against the whole viewport. Note that the browser build
deliberately ignores the `window_width`/`window_height` it finds in settings:
those are the desktop's remembered window, and a browser page has no window
for them to correspond to.

Server side, `conf/server/Conf.xml` needs `ws_port` (default 43595; 0
disables the bridge). The bridge speaks plain `ws://`. The client picks its
scheme from the page: an https page dials `wss://`, because a browser blocks
mixed content before the socket is opened and every world would fail
identically. Providing that `wss://` is the reverse proxy's job — terminate
TLS in the same proxy that fronts rscd-www and forward the raw stream to the
ws_port.

### Finding the bridge for someone else's world

`?ws=` describes the bridge of the world its page belongs to, and nothing else.
That is enough for a player who launched from an operator's own site, and not
enough for one who picks a *different* world from the Worlds screen — which is
the point of the screen. So the client resolves a bridge from three sources,
most specific first, and each declines rather than guesses when its scope does
not match:

| Source | Scope | Used when |
|---|---|---|
| `?ws=` / `window.RSCD_WS` | the `?server=`/`?port=` it was issued with | launching from an operator's own page |
| registry `ws_url` | the world it was listed for | joining any world from the Worlds screen |
| `port + 1` | none needed | a world that advertises nothing |

`ws_url` is read from a world's registry entry, `servers[].worlds[].ws_url`,
falling back to `servers[].ws_url` for a single-world server. It is a complete
URL including scheme (`wss://rscd-community.org/ws`) — not a port, because the
bridges that need advertising at all are exactly the ones *not* at port+1, and
"fronted on :443 at /ws" cannot be written as a number. Absent is not an error:
every world reachable today stays reachable, because that is the third row.

The failure direction is deliberate. An unscoped override would dial *our*
bridge for *their* world — a wrong connection that presents as a working one,
which is harder to diagnose than no connection at all. Declining means the
worst case is a failed connection to the right world.

**The worlds list is fetched from this page's own origin** (`/worlds.json`),
not from the client's built-in `api.rscd-community.org`. `?api=url` points it
elsewhere.

A browser page reading JSON from another origin needs that origin's consent as
an `Access-Control-Allow-Origin` header. The community API did not send one, so
the browser discarded a correct 200 response before the client could read a
byte of it and the Worlds screen said "Could not reach". The desktop client is
unaffected: a Java HTTP client does not care which host answers.

**Since 2026-08-07 `api.rscd-community.org` does send it**, on `worlds.json`
only — `heartbeat.php` is a write endpoint and deliberately does not have it.
So `?api=https://api.rscd-community.org/worlds.json` now works from any origin.
Same-origin remains the default anyway: rscd-www serves `/worlds.json` from
`RSCD\Controller\Worlds`, which caches the upstream API for 60s rather than
reimplementing the registry, and sends `Access-Control-Allow-Origin: *` of its
own. A page copied onto a host that serves neither needs `?api=`.

**CORS elsewhere**: the same applies to the cache if `?cache=` points at
another origin. Same-origin or a proper header is the intended deployment.

## Layout

```
pom.xml               maven module (TeaVM plugin bound to process-classes)
prepare-sources.sh    copy + rewrite the game sources (run before maven)
serve.sh              assemble target/webapp (page, cache, fonts) and serve
src/main/java/rscweb  the shim layer: awt/image/net/io/sound/zip/... stubs,
                      the software renderer (RasterGraphics), the websocket
                      Socket, canvas text, browser bootstrap (web/)
src/main/java/rscweb/build   compile-time TeaVM ReflectionSupplier
src/main/java/rscweb/lang    Classes.forName / publicField shims (see below)
src/main/resources/META-INF  ServiceLoader registration for the supplier
web/index.html        the page
test/                 JVM-side shim tests (plain junit-less mains)
```

## TeaVM 0.15 gotchas encoded in this module

Hard-won; do not simplify these away without re-testing a full boot:

- **Reflection**: XmlObjects' dynamic `Class.forName` can never work as-is —
  TeaVM seeds a forName result once at analysis time, before supplier
  answers land. `rscweb.lang.Classes` holds class literals for exactly the
  def classes; prepare-sources rewrites the one call site onto it. The
  `DefReflectionSupplier` (old `org.teavm.classlib.ReflectionSupplier` SPI —
  the new ReflectionPolicy SPI is dead weight, its answers never reach the
  JS backend) then emits field/ctor metadata for those classes.
- **`Class.getField` classlib bug**: inherited public fields never resolve
  (TClass.findField's visited-set tracks the field name, not the class).
  `Classes.publicField` walks `getDeclaredField` up the chain instead;
  prepare-sources rewrites that call site too.
- **@JSFunctor**: not callable from inside `@JSBody` async callbacks
  ("TypeError: X is not a function"). The pattern that works is in
  `WebImages`: return a plain JS state object, let JS callbacks set fields,
  poll it from Java with `Thread.sleep` (green threads yield to the event
  loop).
- Removing any `META-INF/services` provider class needs a
  `target/classes` cleanup (or `mvn clean`) — maven leaves the stale
  compiled copy and TeaVM fails on it.

## Fonts

This client reads the same baked `.jf` fonts as the desktop client, so the
two draw glyph-for-glyph identical text — no canvas rasteriser in the
middle, no dependence on which Helvetica the viewer's machine happens to
substitute.

It works without a web-specific branch in the game code. `GameWindow`
resolves `media/fonts/<slot>.jf` through `java.io.File` +
`FileInputStream`, which in this module both land on
`rscweb.io.FileSystem`; `BakedFontFS` fetches those eight files at boot
(all requests in flight together, one round trip, before the canvas shows
anything) and answers for them out of memory. Non-font paths pass through
to `LocalStorageFS` as before.

Serve them at `./media/fonts/` — `serve.sh` symlinks
`rscd-community-client/media/fonts` there when it holds bakes. **Nothing
served is a supported state**: `exists()` says no, the unmodified loader
falls through to `GameImage.loadFont`, and text rasterises on the canvas
exactly as it did before. The console line at boot says which happened.

Baking is `media/fonts/FontBaker.java` in the community client, and needs a
machine with real Helvetica — see that directory's README. Verified here
against test bakes: baked and unbaked boots render visibly different login
screens (the baked one bold and correctly metricked), and 8/8 files load.
