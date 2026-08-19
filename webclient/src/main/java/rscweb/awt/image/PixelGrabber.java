package rscweb.awt.image;

import rscweb.awt.Image;

/*
 * Grabs straight out of our concrete Image (or via a producer for the
 * generic ctor). The client uses this to lift sprite sheets and the splash
 * into int[]s; grabPixels blocks until the image has pixels, cooperating
 * with the browser decoder the same way MediaTracker does.
 */
public class PixelGrabber implements ImageConsumer {
   private final Image source;
   private final ImageProducer producer;
   private final int x;
   private final int y;
   private final int w;
   private final int h;
   private final int[] dest;
   private final int off;
   private final int scansize;
   private boolean done;

   public PixelGrabber(Image img, int x, int y, int w, int h, int[] pix, int off, int scansize) {
      this.source = img;
      this.producer = null;
      this.x = x;
      this.y = y;
      this.w = w;
      this.h = h;
      this.dest = pix;
      this.off = off;
      this.scansize = scansize;
   }

   public PixelGrabber(ImageProducer ip, int x, int y, int w, int h, int[] pix, int off, int scansize) {
      this.source = null;
      this.producer = ip;
      this.x = x;
      this.y = y;
      this.w = w;
      this.h = h;
      this.dest = pix;
      this.off = off;
      this.scansize = scansize;
   }

   public boolean grabPixels() throws InterruptedException {
      if (producer != null) {
         producer.startProduction(this);
         return done;
      }
      /* Canvas-backed images have no pixels until asked -- see Image.sync(). */
      source.sync();
      while (source.pixels == null) {
         Thread.sleep(10);
      }
      for (int row = 0; row < h; row++) {
         System.arraycopy(source.pixels, (y + row) * source.width + x, dest, off + row * scansize, w);
      }
      return true;
   }

   public boolean grabPixels(long ms) throws InterruptedException {
      return grabPixels();
   }

   public int getStatus() {
      return ImageObserver.ALLBITS;
   }

   public void setDimensions(int width, int height) {
   }

   public void setProperties(java.util.Hashtable<?, ?> props) {
   }

   public void setColorModel(ColorModel model) {
   }

   public void setHints(int hintflags) {
   }

   public void setPixels(int px, int py, int pw, int ph, ColorModel model, byte[] pixels, int poff, int pscan) {
      for (int row = 0; row < ph; row++) {
         int sy = py + row;
         if (sy < y || sy >= y + h) {
            continue;
         }
         for (int col = 0; col < pw; col++) {
            int sx = px + col;
            if (sx < x || sx >= x + w) {
               continue;
            }
            dest[off + (sy - y) * scansize + (sx - x)] = model.getRGB(pixels[poff + row * pscan + col] & 0xff);
         }
      }
   }

   public void setPixels(int px, int py, int pw, int ph, ColorModel model, int[] pixels, int poff, int pscan) {
      for (int row = 0; row < ph; row++) {
         int sy = py + row;
         if (sy < y || sy >= y + h) {
            continue;
         }
         for (int col = 0; col < pw; col++) {
            int sx = px + col;
            if (sx < x || sx >= x + w) {
               continue;
            }
            dest[off + (sy - y) * scansize + (sx - x)] = model.getRGB(pixels[poff + row * pscan + col]);
         }
      }
   }

   public void imageComplete(int status) {
      done = true;
   }
}
