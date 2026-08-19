package rscweb.awt.image;

import java.util.Hashtable;

import rscweb.awt.Image;

/*
 * Not a JDK class: the consumer Toolkit.createImage(ImageProducer) uses to
 * turn a producer into our concrete Image. On SINGLEFRAMEDONE (animated
 * MemoryImageSource) it keeps referencing the producer's live array via the
 * copy made in setPixels each delivery, so newPixels() refreshes the Image.
 */
public class PixelCollector implements ImageConsumer {
   private final Image image = new Image();
   private ColorModel model = ColorModel.getRGBdefault();

   public Image toImage() {
      return image;
   }

   public void setDimensions(int width, int height) {
      image.width = width;
      image.height = height;
      if (image.pixels == null || image.pixels.length != width * height) {
         image.pixels = new int[width * height];
      }
   }

   public void setProperties(Hashtable<?, ?> props) {
   }

   public void setColorModel(ColorModel model) {
      this.model = model;
   }

   public void setHints(int hintflags) {
   }

   public void setPixels(int x, int y, int w, int h, ColorModel model, byte[] pixels, int off, int scansize) {
      for (int row = 0; row < h; row++) {
         for (int col = 0; col < w; col++) {
            image.pixels[(y + row) * image.width + x + col] =
                  model.getRGB(pixels[off + row * scansize + col] & 0xff);
         }
      }
   }

   public void setPixels(int x, int y, int w, int h, ColorModel model, int[] pixels, int off, int scansize) {
      for (int row = 0; row < h; row++) {
         for (int col = 0; col < w; col++) {
            image.pixels[(y + row) * image.width + x + col] =
                  model.getRGB(pixels[off + row * scansize + col]);
         }
      }
   }

   public void imageComplete(int status) {
   }
}
