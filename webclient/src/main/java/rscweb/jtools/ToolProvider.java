package rscweb.jtools;

/*
 * Returns null, the same answer a JRE-without-JDK gives on the desktop; the
 * client already has to cope with that (no compiler -> scripting disabled),
 * and that existing path is what keeps SkullOrca off the web client.
 */
public final class ToolProvider {
   private ToolProvider() {
   }

   public static JavaCompiler getSystemJavaCompiler() {
      return null;
   }
}
