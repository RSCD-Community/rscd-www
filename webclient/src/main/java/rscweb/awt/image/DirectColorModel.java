package rscweb.awt.image;

/*
 * The client's GameImage builds one of these over its int[] back buffer with
 * plain 0xff0000/0xff00/0xff masks and no alpha, so getRGB must force the
 * alpha byte on -- the real DirectColorModel reports such pixels as opaque
 * too, and the canvas blit relies on it.
 */
public class DirectColorModel extends ColorModel {
   private final int rmask;
   private final int gmask;
   private final int bmask;
   private final int amask;

   public DirectColorModel(int bits, int rmask, int gmask, int bmask) {
      this(bits, rmask, gmask, bmask, 0);
   }

   public DirectColorModel(int bits, int rmask, int gmask, int bmask, int amask) {
      this.rmask = rmask;
      this.gmask = gmask;
      this.bmask = bmask;
      this.amask = amask;
   }

   public int getRGB(int pixel) {
      return amask == 0 ? (0xff000000 | pixel) : pixel;
   }
}
