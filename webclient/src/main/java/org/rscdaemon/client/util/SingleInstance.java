package org.rscdaemon.client.util;

/*
 * The browser's SingleInstance. prepare-sources.sh leaves the real one behind
 * and this compiles in its place.
 *
 * Not part of the rscweb.* shim layer, because this is not a JDK class being
 * stood in for -- it is one of our own that cannot exist here. The desktop
 * version binds a loopback ServerSocket so a second launch can hand its
 * rscd:// link to the client already open. A page has neither: it cannot
 * listen on a port, no second process is launching, and the OS scheme handler
 * never points at a tab. A browser's own tab handling is the equivalent
 * feature and belongs to the browser.
 *
 * So handoff() answers "nobody took it" and listen() does nothing, which
 * leaves the client on exactly the path it takes today. Kept API-identical to
 * the real one on purpose: mudclient is shared source and must not need to
 * know which build it is in.
 */
public final class SingleInstance {

   public interface Joiner {
      boolean join(String uri);
   }

   private SingleInstance() {
   }

   public static boolean handoff(String uri) {
      return false;
   }

   public static void listen(Joiner joiner) {
   }

   public static void stop() {
   }
}
