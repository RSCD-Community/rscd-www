package rscweb.web;

import org.rscdaemon.client.GameWindow;
import org.rscdaemon.client.WebResize;
import org.teavm.jso.JSBody;
import org.teavm.jso.browser.Location;
import org.teavm.jso.browser.Window;
import org.teavm.jso.canvas.CanvasRenderingContext2D;
import org.teavm.jso.dom.events.EventListener;
import org.teavm.jso.dom.html.HTMLCanvasElement;
import org.teavm.jso.dom.html.HTMLDocument;
import org.teavm.jso.dom.html.HTMLElement;
import rscweb.awt.Component;
import rscweb.awt.Dimension;
import rscweb.awt.FontMetrics;
import rscweb.awt.Frame;
import rscweb.awt.Graphics;
import rscweb.awt.Image;
import rscweb.awt.Toolkit;

/*
 * Wires every shim seam to the browser before mudclient.main runs, so the
 * client boots down the exact desktop path and finds a working "screen",
 * "network" and "disk" wherever it looks.
 *
 * Configuration rides the client's own property-override path (Config
 * checks rscd.server / rscd.port / rscd.cacheurl before settings.ini):
 *   ?server=host   game server (default: the page's own host)
 *   ?port=n        game TCP port the server thinks in (default 43594)
 *   ?ws=url        full ws:// bridge URL (default: server host, port+1)
 *   ?cache=url     asset base (default: this origin + /cache_data)
 *   ?fonts=url     baked .jf font base (default: this origin + /media/fonts)
 *   ?target=host   skip the Worlds screen and sign in to this server
 */
public final class WebLaunch {

   static HTMLCanvasElement canvas;
   static CanvasRenderingContext2D ctx;

   /*
    * The vanilla frame interior, and what the page returns to when fullscreen
    * ends. 512x345 is the size every fixed dialog in the game was drawn for;
    * mudclient.applyPendingResize treats 512x334 as its floor for the same
    * reason. The canvas carries one extra row for the frame's token bottom
    * inset -- see Frame.getInsets.
    */
   private static final int BASE_WIDTH = 512;
   private static final int BASE_HEIGHT = 345;
   /* mudclient.CHAT_TABS_HEIGHT. The interior carries one row more than the
      strip uses, which is why the vanilla interior is 345 and not 346. */
   private static final int CHAT_STRIP = 12;
   /* The fullscreen button's strip, reserved above the canvas in CSS
      (#rscd-stage's padding-top in both index.html and rscd.css) so the
      button never sits on top of game pixels. Only matters in fullscreen --
      windowed mode just grows the page under the strip, no framebuffer
      change needed. Keep this in sync with the CSS padding-top value. */
   private static final int TOOLBAR_HEIGHT = 26;

   /*
    * The windowed size, which is the vanilla frame unless ?size=WxH says
    * otherwise. An operator embedding the client at a fixed larger size sets
    * it once here rather than needing a fullscreen gesture; it is also the
    * seam that makes the resize path testable without one.
    */
   private static int baseWidth = BASE_WIDTH;
   private static int baseHeight = BASE_HEIGHT;
   /*
    * A size the game has not accepted yet, to be retried on the next paint.
    *
    * It starts true so that the page's own idea of the windowed size is always
    * asserted once, not only when ?size= asked for something. mudclient.main
    * opens the window at the window_width/window_height it finds in settings,
    * which on desktop is the size the user last dragged the window to and
    * exactly what they want back. In a browser it is neither: settings live in
    * localStorage, and applyPendingResize persists every fullscreen size into
    * them. So one visit that went fullscreen on a 2560-wide monitor left the
    * *next* plain page load opening a 2560-wide canvas inside a page that had
    * no such thing -- measured, not assumed: a run with no ?size= at all came
    * back 1024x701 because an earlier ?size=1024x700 run had saved it. In a
    * browser there is no dragged window for a remembered size to correspond
    * to, so the page owns it.
    *
    * It is also set again whenever a size fails to land, which is what makes a
    * fullscreen gesture during boot work. The client takes several seconds to
    * come up and the button is live the whole time; press it early and there
    * is no frame yet to resize, no further event coming, and -- before this --
    * a fullscreen browser window with a 512-wide game sitting in the middle of
    * it for good. Retrying on paint costs nothing once a size has landed,
    * because it stops being pending.
    */
   private static boolean sizePending = true;

   private WebLaunch() {
   }

   public static void install() {
      Window window = Window.current();
      Location loc = window.getLocation();
      String qs = loc.getSearch();

      /*
       * Empty by default, which is what puts a first-time player on the
       * Worlds screen rather than a sign-in box for whichever server happens
       * to host the page -- the same deliberate choice the desktop client
       * makes. An operator who is hosting this page *for* their own server
       * says so explicitly with ?target=, and their players land on sign-in.
       */
      String target = param(qs, "target", "");
      if (!target.isEmpty()) {
         System.setProperty("rscd.target", target);
      }

      String size = param(qs, "size", "");
      int by = size.indexOf('x');
      if (by > 0) {
         try {
            int w = Integer.parseInt(size.substring(0, by));
            int h = Integer.parseInt(size.substring(by + 1));
            if (w > 0 && h > 0) {
               baseWidth = w;
               baseHeight = h;
            }
         } catch (NumberFormatException ignored) {
            /* A malformed ?size= is not worth refusing to boot over. */
         }
      }

      System.setProperty("rscd.server", param(qs, "server", loc.getHostName()));
      System.setProperty("rscd.port", param(qs, "port", "43594"));
      /*
       * The world list, from this origin by default rather than the client's
       * built-in api.rscd-community.org.
       *
       * The desktop client fetches the community API directly and is right to:
       * a Java HTTP client does not care which host answers. A browser does. A
       * page on one origin reading JSON from another needs that origin's
       * consent as an Access-Control-Allow-Origin header, and without it the
       * browser discards a perfectly good 200 before the client sees a byte --
       * which is exactly what happened on the live site: the API answered, the
       * Worlds screen said "Could not reach".
       *
       * rscd-www answers /worlds.json from the same origin as this page (see
       * RSCD\Controller\Worlds, which caches the API rather than reimplementing
       * it), so there is no cross-origin hop left to be refused. A page copied
       * onto a host that does not serve it says where to look with ?api=.
       */
      System.setProperty("rscd.api",
            param(qs, "api", loc.getProtocol() + "//" + loc.getHost() + "/worlds.json"));
      System.setProperty("rscd.cacheurl", param(qs, "cache", loc.getProtocol() + "//" + loc.getHost() + "/cache_data"));
      /*
       * The bridge URL, and the world it belongs to.
       *
       * Scoping matters as soon as the Worlds screen is reachable, which it
       * now is. ?ws= describes ONE world's bridge -- the host that served this
       * page knows its own and nobody else's. Left unscoped, a player who
       * opened our page and then joined a different operator's world would
       * have their game traffic dialled at OUR bridge, and would either fail
       * confusingly or land in the wrong world entirely.
       *
       * So an override carrying a scope applies only to the world it names,
       * and every other world falls back to the documented port+1 default --
       * which fails honestly for an operator who has not fronted a bridge yet,
       * rather than silently pointing somewhere wrong. An override with no
       * scope still applies to everything, which is the single-world embed
       * case the parameter was written for.
       *
       * The registry half now exists too: WorldList reads a per-world ws_url
       * beside cache_url and WsSocket consults it when no scoped override
       * matches, so a world that advertises one is reachable from anybody's
       * page. This override stays ahead of it because a page knows how its own
       * bridge is fronted right now, without waiting on a heartbeat. See
       * README.
       */
      /*
       * ?ws= wins, then window.RSCD_WS, then the port+1 default.
       *
       * The global exists because a hosting page can know something the query
       * string does not carry. rscd-www renders /play/browser itself and knows
       * where its own bridge is fronted, so it writes the URL into the page --
       * which means a link someone copied and pasted, with no ?ws= on it, still
       * connects. Putting it in the query instead would make every shared URL a
       * broken one. The standalone page has no server rendering it and keeps
       * using ?ws=.
       */
      /*
       * The scope comes from the same place the URL did.
       *
       * ?server=/?port= describe the world this page was opened for, so they
       * scope a ?ws= given beside them. A page opened with no world at all --
       * /play/browser with a bare path, where the player is heading for the
       * Worlds screen -- has no query to read, and taking the scope from
       * location.hostname would be a guess: the site and the game server do
       * not have to be the same host. So the page states both, and a page that
       * states neither leaves the override unscoped exactly as before.
       */
      WsSocket.overrideUrl = param(qs, "ws", pageBridgeUrl());
      WsSocket.overrideHost = param(qs, "server", pageBridgeHost());
      WsSocket.overridePort = intParam(qs, "port", pageBridgePort());

      HTMLDocument doc = window.getDocument();
      canvas = (HTMLCanvasElement)doc.createElement("canvas");
      canvas.setWidth(512);
      canvas.setHeight(346);
      HTMLElement holder = doc.getElementById("rscd-game");
      (holder != null ? holder : doc.getBody()).appendChild(canvas);
      ctx = (CanvasRenderingContext2D)canvas.getContext("2d");
      ctx.setFillStyle("rgb(0,0,0)");
      ctx.fillRect(0, 0, 512, 346);

      Component.setBackend(new Component.Backend() {
         @Override
         public Graphics graphicsFor(Component c) {
            if (DomEvents.target == null && c instanceof Frame) {
               DomEvents.target = c;
            }
            /*
             * wheelTarget the same way: eagerly, off the first paint, rather
             * than waiting on an offscreenImage call. GameFrame.aGameWindow is
             * set in its constructor -- before anything can paint -- but the
             * boot screens never ask for a back buffer at all, measured for
             * WebResize.report() below, so a backend hook gated on
             * offscreenImage never fires until well after the wheel is usable.
             */
            if (DomEvents.wheelTarget == null) {
               GameWindow window = WebResize.windowFor(c);
               if (window != null) {
                  DomEvents.wheelTarget = window;
               }
            }
            /*
             * A size decided before the game could take it lands here instead.
             * The frame exists before the GameWindow does, and resizing one
             * without telling the other is precisely the split that leaves a
             * big canvas with a small picture in it -- so retry every paint
             * until both halves are available, and let applySize decide when
             * that is. syncSize rather than the base size, because by now the
             * page may already be fullscreen.
             */
            if (sizePending) {
               syncSize();
            }
            fitCanvasTo(c);
            return new CanvasGraphics(canvas, ctx);
         }

         @Override
         public Image offscreenImage(Component c, int width, int height) {
            return new WebCanvasImage(width, height);
         }
      });

      Toolkit.setDelegate(new Toolkit.Delegate() {
         @Override
         public void decodeImage(byte[] data, int offset, int length, Image target) {
            WebImages.decode(data, offset, length, target);
         }

         @Override
         public Dimension screenSize() {
            return new Dimension(window.getInnerWidth(), window.getInnerHeight());
         }
      });

      FontMetrics.setMeasurer(new WebFonts());
      rscweb.awt.RasterGraphics.setTextRasterizer(new CanvasTextRasterizer());
      rscweb.net.Socket.setProvider(new WsSocket());
      rscweb.net.URL.setFetcher(new XhrFetch());
      DomEvents.install(canvas);
      MobileKeyboard.install();

      /*
       * Fullscreen. The page asks for it (index.html owns the button, so the
       * request happens inside a real user gesture, which is the only thing
       * browsers will honour); this side only listens for the result and
       * follows the viewport. Both spellings of the event are bound because
       * Safari still reports the prefixed one.
       */
      EventListener<org.teavm.jso.dom.events.Event> follow = e -> syncSize();
      window.addEventListener("resize", follow);

      /*
       * A fullscreen change is followed up on a short delay as well as handled
       * immediately, because the two events involved do not arrive in a
       * dependable order.
       *
       * Leaving fullscreen fires both `resize` and `fullscreenchange`, and a
       * browser is free to send the resize first -- reporting the windowed
       * viewport while document.fullscreenElement is still set. syncSize then
       * reads "still fullscreen" and sizes the game to the whole page instead
       * of back to 512x345, and if the fullscreenchange that would have
       * corrected it has already been coalesced away, the client is simply
       * left large. That is the shape of the reported symptom: fullscreen
       * exits, the client does not shrink.
       *
       * Re-asserting after the transition settles fixes it without having to
       * guess the ordering, because syncSize is idempotent -- it computes the
       * size from the current state rather than from the event.
       */
      EventListener<org.teavm.jso.dom.events.Event> settle = e -> {
         syncSize();
         resyncSoon();
      };
      doc.addEventListener("fullscreenchange", settle);
      doc.addEventListener("webkitfullscreenchange", settle);

      /* Last, and after the fetcher: it goes to the network for the baked
         fonts, and the client asks for them the moment main() starts. */
      rscweb.io.FileSystem.setResolver(BakedFontFS.load(
            param(qs, "fonts", loc.getProtocol() + "//" + loc.getHost() + "/media/fonts"),
            new LocalStorageFS()));
   }

   /*
    * Fullscreen resizes the game, it does not stretch it.
    *
    * The obvious browser move -- leave the canvas at 512x346 and scale it up
    * with CSS -- is the wrong one here, and not only because it blurs. The
    * client already supports being resized, properly: applyPendingResize
    * grows the framebuffer, re-points the camera and rebuilds the menus
    * against the new edges, and scales nothing. A CSS upscale would throw
    * that away, hand the player the same 512x346 of world stretched over
    * their monitor, and desynchronise every mouse coordinate from what is
    * drawn -- DomEvents posts offsetX/offsetY straight through as game
    * pixels, which is exact only while the canvas is 1:1.
    *
    * So the canvas is always 1:1 with its CSS box, at whatever size the
    * viewport allows, and the game genuinely draws more world. No coordinate
    * mapping is needed anywhere as a direct result.
    *
    * Deliberately not scaling by devicePixelRatio: it would sharpen the
    * image on a HiDPI screen and break that 1:1 relationship in the same
    * stroke, which costs more than it buys.
    */
   static void syncSize() {
      Window window = Window.current();
      boolean full = fullscreenActive();
      /* A size the game could not take yet is not dropped -- graphicsFor
         retries it on the next paint, which is the only moment at which the
         answer can have changed. */
      sizePending = !applySize(full ? window.getInnerWidth() : baseWidth,
                               full ? window.getInnerHeight() - 1 - TOOLBAR_HEIGHT : baseHeight);
   }

   /*
    * Report a new interior exactly as GameFrame does on desktop: resize the
    * frame, then tell the game. GameFrame.resize adds the bottom inset, so
    * the component -- and with it the canvas, through fitCanvasTo -- lands on
    * height + 1, and the interior the game is told about is height again.
    *
    * The clamps below duplicate applyPendingResize's, and that duplication is
    * load-bearing rather than sloppy. The canvas follows the *frame*, which
    * nothing clamps; the game clamps only the size it draws at. Let the two
    * disagree and they do: a 300-wide viewport gives a 300-wide canvas while
    * the game keeps drawing its 512-wide minimum into it, and the right-hand
    * third of the interface is silently cut off. Measured in headless Chrome,
    * not reasoned about -- ?size=300x200 produced exactly that.
    *
    * Clamping here keeps the surface and the renderer in agreement at every
    * size. A viewport below the floor gets a canvas at the floor and scroll
    * bars, which is the honest outcome: the vanilla layout has a minimum and
    * a small window cannot repeal it.
    */
   private static boolean applySize(int width, int height) {
      Component frame = DomEvents.target;
      if (frame == null || width <= 0 || height <= 0) {
         return false;
      }
      /* mudclient.applyPendingResize: width 512..2560, and a height that
         leaves 334..1440 once the chat strip is taken out of it. */
      int w = Math.max(512, Math.min(width, 2560));
      int h = Math.max(334 + (CHAT_STRIP - 1),
                       Math.min(height, 1440 + (CHAT_STRIP - 1)));
      /*
       * Tell the game first, and only grow the surface if it listened. The
       * other order gives a canvas the renderer does not know about, which
       * is a big black border rather than a bigger view.
       */
      if (!WebResize.report(frame, w, h)) {
         return false;
      }
      frame.resize(w, h);
      /*
       * Size the surface here rather than leaving it to the next paint.
       *
       * Waiting was the bug behind "it exits fullscreen but does not shrink".
       * The only thing that pulls the canvas onto the frame's size during a
       * game is applyPendingResize asking for a fresh Graphics, and that ask
       * sits behind `if (this.gameGraphics != null)` -- so every moment before
       * the framebuffer exists (the world chooser, the asset load, a
       * reload-for-world) shrinks the frame and leaves the canvas at its
       * fullscreen dimensions, with the game drawing its 512-wide picture into
       * one corner of it. Nothing later corrects it either: the size has
       * already been consumed, so the next applyPendingResize returns at its
       * equality check.
       *
       * Read the size back off the frame rather than reusing w/h: GameFrame
       * overrides resize to add its insets, so the component is h + 1 and the
       * canvas has to carry that extra row too. Passing the interior instead
       * loses it -- measured, a clean exit landed on 512x345 where the launch
       * size is 512x346.
       *
       * fitCanvasTo is idempotent and only touches the element on a real
       * change, so doing it unconditionally here costs nothing when the paint
       * path would have got there anyway.
       */
      fitCanvasTo(frame);
      return true;
   }

   /*
    * Re-run syncSize once the fullscreen transition has finished moving.
    *
    * Two passes rather than one: the first catches the common case where the
    * viewport has settled within a frame or two, the second covers a slower
    * compositor or a window manager that animates the change. Both are
    * harmless when nothing has changed -- applySize recomputes from the
    * current state, and applyPendingResize returns immediately when the size
    * it is handed is the size it already has.
    */
   private static void resyncSoon() {
      Window window = Window.current();
      window.setTimeout(() -> syncSize(), 250);
      window.setTimeout(() -> syncSize(), 750);
   }

   @JSBody(script = "return !!(document.fullscreenElement || document.webkitFullscreenElement);")
   private static native boolean fullscreenActive();

   /* The hosting page's bridge URL, or null when it did not set one. An empty
      string counts as unset, so a template that renders a blank value behaves
      the same as one that omits the line entirely. */
   @JSBody(script = "return (typeof window.RSCD_WS === 'string' && window.RSCD_WS.length) ? window.RSCD_WS : null;")
   private static native String pageBridgeUrl();

   /* Which world that URL belongs to. Both must be present for the override to
      be scoped; either one missing leaves it unscoped, which is the behaviour
      of every page written before these were added. */
   @JSBody(script = "return (typeof window.RSCD_WS_HOST === 'string' && window.RSCD_WS_HOST.length) ? window.RSCD_WS_HOST : null;")
   private static native String pageBridgeHost();

   @JSBody(script = "var p = window.RSCD_WS_PORT; p = parseInt(p, 10); return (p > 0 && p < 65536) ? p : 0;")
   private static native int pageBridgePort();

   /*
    * The frame knows its size before it first paints; follow it. Setting a
    * canvas dimension clears the surface, so only touch it on a real change,
    * and repaint the void black rather than page-background white.
    */
   private static void fitCanvasTo(Component c) {
      Dimension size = c.getSize();
      if (size.width > 0 && size.height > 0
            && (canvas.getWidth() != size.width || canvas.getHeight() != size.height)) {
         canvas.setWidth(size.width);
         canvas.setHeight(size.height);
         ctx.setFillStyle("rgb(0,0,0)");
         ctx.fillRect(0, 0, size.width, size.height);
      }
   }

   private static String param(String query, String name, String fallback) {
      if (query != null && query.length() > 1) {
         String[] pairs = (query.startsWith("?") ? query.substring(1) : query).split("&");
         for (String pair : pairs) {
            int eq = pair.indexOf('=');
            if (eq > 0 && pair.substring(0, eq).equals(name)) {
               String value = pair.substring(eq + 1);
               if (!value.isEmpty()) {
                  return value;
               }
            }
         }
      }
      return fallback;
   }

   /* A malformed number is treated as absent rather than fatal, matching how
      every other parameter here degrades. */
   private static int intParam(String query, String name, int fallback) {
      String value = param(query, name, null);
      if (value != null) {
         try {
            return Integer.parseInt(value.trim());
         } catch (NumberFormatException ignored) {
            /* fall through to the default */
         }
      }
      return fallback;
   }
}
