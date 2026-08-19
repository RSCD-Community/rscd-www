package rscweb.awt;

/*
 * GameImage consults the available family names while choosing its Helvetica
 * substitute. In the browser the family list is unknowable, so advertise the
 * families the client looks for and let the canvas/baked-font layer resolve
 * what they actually mean.
 */
public class GraphicsEnvironment {
   private static final GraphicsEnvironment INSTANCE = new GraphicsEnvironment();

   public static GraphicsEnvironment getLocalGraphicsEnvironment() {
      return INSTANCE;
   }

   public String[] getAvailableFontFamilyNames() {
      return new String[] { "Helvetica", "Arial", "sans-serif" };
   }
}
