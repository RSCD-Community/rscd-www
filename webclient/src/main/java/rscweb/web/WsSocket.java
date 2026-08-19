package rscweb.web;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.util.ArrayDeque;
import org.teavm.jso.typedarrays.Int8Array;
import org.teavm.jso.websocket.WebSocket;
import rscweb.net.Socket;

/*
 * The client's TCP socket, carried over the server's WebSocket bridge. The
 * bridge speaks binary frames whose payloads are the raw RSC stream, so both
 * directions here are plain byte pass-through -- the codec on the server side
 * unwraps frames back into the exact bytes GameConnection would have written
 * to a TCP socket.
 *
 * A browser page cannot dial an arbitrary TCP port, so connect(host, port)
 * maps to a ws:// URL. Three sources, most specific first:
 *
 *   1. ?ws= / window.RSCD_WS -- the page we launched from, describing its own
 *      world. Scoped to that world; see overrideFor.
 *   2. The world's own registry listing, for a world chosen from the Worlds
 *      screen. Scoped to the world it was recorded for; see advertisedFor.
 *   3. The port+1 convention (SERVER_PORT 43594 -> WS_PORT 43595, the shipped
 *      defaults on both sides), for a world that advertises nothing.
 *
 * Every one of them declines rather than guesses when its scope does not
 * match, so the worst outcome is a failed connection to the right world and
 * never a successful one to the wrong world.
 *
 * Threading: all callbacks run on the JS event loop and only write fields.
 * The green threads that block in read() yield through Thread.sleep, which
 * is how a fiber hands control back to the event loop under TeaVM.
 */
public final class WsSocket implements Socket.Provider {

   /* From ?ws= -- a complete ws:// or wss:// URL. */
   public static String overrideUrl;
   /* The world that URL belongs to, from ?server= and ?port=. Null/0 means the
      override was given without a scope and applies to every world. */
   public static String overrideHost;
   public static int overridePort;

   @Override
   public Socket.Endpoint connect(String host, int port) throws IOException {
      String url = overrideFor(host, port);
      if (url == null) {
         url = advertisedFor(host, port);
      }
      if (url == null) {
         url = scheme() + host + ":" + (port + 1);
      }
      return new WsEndpoint(url);
   }

   /*
    * What the world itself said, via its registry listing (WorldList reads
    * ws_url; remember() puts it in Config).
    *
    * This is the only way a browser can reach a world it did not launch from.
    * The page override below knows exactly one bridge -- its own -- so before
    * this, picking any other operator's world from the Worlds screen left the
    * client guessing port+1, which is right only for a world whose bridge is
    * exposed directly. It is wrong for every world fronted behind TLS, and
    * fronting behind TLS is mandatory for anyone whose site is https. So the
    * common case for a federated browser player was the failing one.
    *
    * Scoped against DEFAULT_TARGET rather than trusted flat. Config.WS_URL and
    * DEFAULT_TARGET are written together by remember() and read back together
    * at startup, so they either describe the same world or are both stale; a
    * mismatch means we are dialling something the recorded URL does not
    * describe, and the honest answer there is the default, not a guess.
    */
   private static String advertisedFor(String host, int port) {
      String url = org.rscdaemon.client.util.Config.WS_URL;
      if (url == null || url.length() == 0) {
         return null;
      }
      String target = org.rscdaemon.client.util.Config.DEFAULT_TARGET;
      return target != null && target.equalsIgnoreCase(host + ":" + port) ? url : null;
   }

   /*
    * An override describes one operator's bridge, not a general route.
    *
    * The page that carries ?ws= knows where its OWN world's bridge is fronted
    * and has no way to know anyone else's. Applying it to every connect was
    * safe only while the Worlds screen could not be reached; now that a player
    * can pick another operator's world from inside the client, an unscoped
    * override would dial our bridge for their world -- a wrong connection that
    * looks like a working one, which is worse than no connection.
    *
    * So a scoped override answers for its own world and declines for every
    * other, leaving them on the port+1 default.
    */
   private static String overrideFor(String host, int port) {
      if (overrideUrl == null) {
         return null;
      }
      if (overrideHost == null || overridePort <= 0) {
         return overrideUrl;
      }
      return overrideHost.equalsIgnoreCase(host) && overridePort == port ? overrideUrl : null;
   }

   /*
    * Follow the page's own protocol.
    *
    * A hard-coded ws:// works from a file:// or http:// page and is refused
    * outright from an https:// one -- mixed content is blocked before the
    * socket is opened, so every world on a TLS site would fail identically
    * and for a reason nothing in the client could report usefully. wss:// on
    * an https page is the only thing that can work there, and it is the
    * deployment the README already describes: the same reverse proxy that
    * terminates TLS for the site forwards the raw stream to ws_port.
    *
    * An explicit ?ws= still wins, which is the escape hatch for a bridge that
    * does not sit at port+1 or is fronted on a different host.
    */
   private static String scheme() {
      String protocol = org.teavm.jso.browser.Window.current().getLocation().getProtocol();
      return "https:".equalsIgnoreCase(protocol) ? "wss://" : "ws://";
   }

   private static final class WsEndpoint implements Socket.Endpoint {

      private static final int CONNECTING = 0;
      private static final int OPEN = 1;
      private static final int CLOSED = 2;

      /* Matches GameWindow.makeSocket's setSoTimeout(30000) on the desktop
         build -- without it, a server that accepts the WebSocket but never
         writes back (e.g. a restart racing the connect) leaves this read
         loop spinning forever with no error, which reads to a player as the
         client being frozen at "Please wait... Connecting to server." */
      private static final long READ_TIMEOUT_MS = 30000;

      /* Same idea, one layer earlier: a WebSocket handshake that never
         resolves at all -- proxy misconfiguration, a URL nothing is
         listening on, a network that swallows the upgrade -- leaves onOpen,
         onError and onClose all unfired. Without this, the constructor's
         "wait for OPEN" loop below spins forever with nothing to catch,
         which is indistinguishable from the read-loop hang the timeout
         above was written for, just one step earlier in the sequence. */
      private static final long CONNECT_TIMEOUT_MS = 30000;

      private final WebSocket ws;
      private volatile int state = CONNECTING;
      private final ArrayDeque<byte[]> incoming = new ArrayDeque<>();
      private int headOffset;

      WsEndpoint(String url) throws IOException {
         WebSocket socket;
         try {
            socket = new WebSocket(url);
         } catch (Throwable t) {
            throw new IOException("websocket refused: " + url);
         }
         this.ws = socket;
         ws.setBinaryType("arraybuffer");
         ws.onOpen(e -> state = OPEN);
         ws.onError(e -> state = CLOSED);
         ws.onClose(e -> state = CLOSED);
         ws.onMessage(e -> incoming.addLast(new Int8Array(e.getDataAsArray()).toJavaArray()));

         long deadline = System.currentTimeMillis() + CONNECT_TIMEOUT_MS;
         while (state == CONNECTING) {
            if (System.currentTimeMillis() > deadline) {
               throw new IOException("websocket connect timed out: " + url);
            }
            sleep();
         }
         if (state != OPEN) {
            throw new IOException("websocket connect failed: " + url);
         }
      }

      private static void sleep() throws IOException {
         try {
            Thread.sleep(5);
         } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            throw new IOException("interrupted");
         }
      }

      private int available() {
         int n = -headOffset;
         for (byte[] chunk : incoming) {
            n += chunk.length;
         }
         return n;
      }

      @Override
      public InputStream input() {
         return new InputStream() {
            @Override
            public int read() throws IOException {
               byte[] one = new byte[1];
               int n = read(one, 0, 1);
               return n < 0 ? -1 : one[0] & 0xff;
            }

            @Override
            public int read(byte[] b, int off, int len) throws IOException {
               if (len == 0) {
                  return 0;
               }
               long deadline = System.currentTimeMillis() + READ_TIMEOUT_MS;
               while (incoming.isEmpty()) {
                  if (state == CLOSED) {
                     return -1;
                  }
                  if (System.currentTimeMillis() > deadline) {
                     throw new IOException("read timed out");
                  }
                  sleep();
               }

               int copied = 0;
               while (copied < len && !incoming.isEmpty()) {
                  byte[] head = incoming.peekFirst();
                  int take = Math.min(len - copied, head.length - headOffset);
                  System.arraycopy(head, headOffset, b, off + copied, take);
                  copied += take;
                  headOffset += take;
                  if (headOffset == head.length) {
                     incoming.removeFirst();
                     headOffset = 0;
                  }
               }
               return copied;
            }

            @Override
            public int available() {
               return WsEndpoint.this.available();
            }
         };
      }

      @Override
      public OutputStream output() {
         return new OutputStream() {
            @Override
            public void write(int b) throws IOException {
               write(new byte[]{(byte)b}, 0, 1);
            }

            @Override
            public void write(byte[] b, int off, int len) throws IOException {
               if (state != OPEN) {
                  throw new IOException("websocket closed");
               }
               byte[] slice;
               if (off == 0 && len == b.length) {
                  slice = b;
               } else {
                  slice = new byte[len];
                  System.arraycopy(b, off, slice, 0, len);
               }
               ws.send(Int8Array.copyFromJavaArray(slice));
            }
         };
      }

      @Override
      public void close() {
         state = CLOSED;
         try {
            ws.close();
         } catch (Throwable ignored) {
         }
      }
   }
}
