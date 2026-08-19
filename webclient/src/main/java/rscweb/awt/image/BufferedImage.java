package rscweb.awt.image;

import java.util.Hashtable;

import rscweb.awt.Graphics2D;
import rscweb.awt.Image;

public class BufferedImage extends Image {
   public static final int TYPE_INT_RGB = 1;
   public static final int TYPE_INT_ARGB = 2;
   public static final int TYPE_INT_BGR = 4;
   public static final int TYPE_3BYTE_BGR = 5;
   public static final int TYPE_BYTE_GRAY = 10;

   private final int type;

   public BufferedImage(int width, int height, int type) {
      super(width, height);
      this.type = type;
   }

   /*
    * The aliasing constructor Skin uses: the raster's int[] IS this image's
    * pixel store (the game framebuffer). Drawing on createGraphics() must
    * land in that shared array.
    */
   public BufferedImage(ColorModel cm, WritableRaster raster, boolean premultiplied,
         Hashtable<?, ?> properties) {
      this.pixels = raster.pixels;
      this.width = raster.width;
      this.height = raster.height;
      this.type = TYPE_INT_RGB;
   }

   public int getType() {
      return type;
   }

   public int getWidth() {
      return width;
   }

   public int getHeight() {
      return height;
   }

   public int getRGB(int x, int y) {
      return pixels[y * width + x];
   }

   public void setRGB(int x, int y, int rgb) {
      pixels[y * width + x] = rgb;
   }

   public int[] getRGB(int startX, int startY, int w, int h, int[] rgbArray, int offset, int scansize) {
      if (rgbArray == null) {
         rgbArray = new int[offset + h * scansize];
      }
      for (int row = 0; row < h; row++) {
         System.arraycopy(pixels, (startY + row) * width + startX, rgbArray, offset + row * scansize, w);
      }
      return rgbArray;
   }

   public void setRGB(int startX, int startY, int w, int h, int[] rgbArray, int offset, int scansize) {
      for (int row = 0; row < h; row++) {
         System.arraycopy(rgbArray, offset + row * scansize, pixels, (startY + row) * width + startX, w);
      }
   }

   public Graphics2D createGraphics() {
      return new rscweb.awt.RasterGraphics(pixels, width, height);
   }

   public WritableRaster getRaster() {
      return new WritableRaster(pixels, width, height);
   }
}
