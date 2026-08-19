package rscweb.awt;

import rscweb.awt.image.ImageObserver;
import rscweb.awt.image.ImageProducer;
import rscweb.awt.image.MemoryImageSource;

/*
 * Concrete where the JDK's is abstract: an image here is always an ARGB int[]
 * plus dimensions, because every pixel in the client flows through exactly
 * that shape (PixelGrabber out, ImageProducer in). An Image not yet decoded
 * (Toolkit.createImage(byte[]) racing the browser's async decoder) has
 * pixels == null and width/height == -1, which is precisely what the real
 * Image reports before its producer has run -- MediaTracker covers the wait.
 */
public class Image {
   public static final int SCALE_DEFAULT = 1;
   public static final int SCALE_FAST = 2;
   public static final int SCALE_SMOOTH = 4;
   public static final int SCALE_REPLICATE = 8;
   public static final int SCALE_AREA_AVERAGING = 16;

   public int[] pixels;
   public int width = -1;
   public int height = -1;

   public Image() {
   }

   public Image(int width, int height) {
      this.width = width;
      this.height = height;
      this.pixels = new int[width * height];
   }

   public int getWidth(ImageObserver observer) {
      return width;
   }

   public int getHeight(ImageObserver observer) {
      return height;
   }

   public ImageProducer getSource() {
      return new MemoryImageSource(width, height, pixels, 0, width);
   }

   public Graphics getGraphics() {
      return new Graphics();
   }

   public Image getScaledInstance(int width, int height, int hints) {
      Image scaled = new Image(width, height);
      if (pixels != null && this.width > 0 && this.height > 0) {
         for (int y = 0; y < height; y++) {
            int sy = y * this.height / height;
            for (int x = 0; x < width; x++) {
               scaled.pixels[y * width + x] = pixels[sy * this.width + x * this.width / width];
            }
         }
      }
      return scaled;
   }

   public void flush() {
   }

   /*
    * Pull pixels in from wherever this image is really backed, if anywhere.
    * A plain image owns its int[] and has nothing to do; an image backed by a
    * canvas (drawLetter's glyph scratch) materialises pixels here, so that
    * PixelGrabber can read drawing that until now only existed on the canvas.
    */
   public void sync() {
   }
}
