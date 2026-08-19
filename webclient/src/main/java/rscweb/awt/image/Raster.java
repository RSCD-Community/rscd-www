package rscweb.awt.image;

import rscweb.awt.Point;

/*
 * Only the shape the client builds: an int[]-backed raster over the game's
 * framebuffer (Skin.imageOver) or over a BufferedImage's own pixels. The
 * int[] is shared, never copied -- Skin draws through a Graphics2D over this
 * raster and expects the framebuffer to change underneath.
 */
public class Raster {
   final int[] pixels;
   final int width;
   final int height;

   Raster(int[] pixels, int width, int height) {
      this.pixels = pixels;
      this.width = width;
      this.height = height;
   }

   public static WritableRaster createWritableRaster(SinglePixelPackedSampleModel sm,
         DataBuffer db, Point location) {
      return new WritableRaster(((DataBufferInt) db).getData(), sm.width, sm.height);
   }

   public DataBuffer getDataBuffer() {
      return new DataBufferInt(pixels, pixels.length);
   }

   public int getWidth() {
      return width;
   }

   public int getHeight() {
      return height;
   }
}
