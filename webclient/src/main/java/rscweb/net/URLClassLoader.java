package rscweb.net;

import java.io.IOException;

/*
 * Scripting-only (ScriptRunner loads compiled script classes through this).
 * The web client never enables scripting -- SkullOrca is a downloadable-client
 * feature -- so this exists to satisfy the compiler and to fail loudly if
 * something ever reaches it.
 */
public class URLClassLoader extends ClassLoader {
   public URLClassLoader(URL[] urls) {
   }

   public URLClassLoader(URL[] urls, ClassLoader parent) {
   }

   public static URLClassLoader newInstance(URL[] urls) {
      return new URLClassLoader(urls);
   }

   public Class<?> loadClass(String name) throws ClassNotFoundException {
      throw new ClassNotFoundException("no class loading in the browser: " + name);
   }

   public void close() throws IOException {
   }
}
