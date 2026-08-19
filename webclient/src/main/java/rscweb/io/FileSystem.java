package rscweb.io;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;

/*
 * The browser has no filesystem; the client mostly stopped needing one (all
 * game data arrives via Assets), but a handful of paths still open files --
 * settings persistence, mainly. WebMain installs a resolver that answers
 * those from fetched bytes / localStorage; anything unhandled fails with a
 * plain IOException, which is what the desktop client gets for a missing
 * file too.
 */
public final class FileSystem {

   public interface Resolver {
      InputStream open(String path) throws IOException;

      OutputStream create(String path) throws IOException;

      boolean exists(String path);
   }

   private static Resolver resolver;

   private FileSystem() {
   }

   public static void setResolver(Resolver r) {
      resolver = r;
   }

   static InputStream open(String path) throws IOException {
      if (resolver == null) {
         throw new IOException("no filesystem in the browser: " + path);
      }
      return resolver.open(path);
   }

   static OutputStream create(String path) throws IOException {
      if (resolver == null) {
         throw new IOException("no filesystem in the browser: " + path);
      }
      return resolver.create(path);
   }

   static boolean exists(String path) {
      return resolver != null && resolver.exists(path);
   }
}
