package rscweb.awt.image;

import java.util.ArrayList;
import java.util.Hashtable;
import java.util.List;

/*
 * The client's whole frame path is: render into an int[], wrap it in one of
 * these, Toolkit.createImage it once, and thereafter poke newPixels() every
 * frame. Consumers registered here therefore stay registered (animated
 * semantics), and newPixels() re-delivers the same array -- which the browser
 * back-end turns into a putImageData.
 */
public class MemoryImageSource implements ImageProducer {
   private final int width;
   private final int height;
   private final int[] pixels;
   private final int offset;
   private final int scansize;
   private ColorModel model = ColorModel.getRGBdefault();
   private boolean animated;
   private final List<ImageConsumer> consumers = new ArrayList<ImageConsumer>();

   public MemoryImageSource(int w, int h, ColorModel cm, int[] pix, int off, int scan) {
      this.width = w;
      this.height = h;
      this.model = cm;
      this.pixels = pix;
      this.offset = off;
      this.scansize = scan;
   }

   public MemoryImageSource(int w, int h, int[] pix, int off, int scan) {
      this.width = w;
      this.height = h;
      this.pixels = pix;
      this.offset = off;
      this.scansize = scan;
   }

   public void setAnimated(boolean animated) {
      this.animated = animated;
   }

   public void setFullBufferUpdates(boolean fullbuffers) {
   }

   public void addConsumer(ImageConsumer ic) {
      if (!consumers.contains(ic)) {
         consumers.add(ic);
      }
      deliver(ic);
   }

   public boolean isConsumer(ImageConsumer ic) {
      return consumers.contains(ic);
   }

   public void removeConsumer(ImageConsumer ic) {
      consumers.remove(ic);
   }

   public void startProduction(ImageConsumer ic) {
      addConsumer(ic);
   }

   public void requestTopDownLeftRightResend(ImageConsumer ic) {
      deliver(ic);
   }

   public void newPixels() {
      for (int i = 0; i < consumers.size(); i++) {
         deliver(consumers.get(i));
      }
   }

   public void newPixels(int x, int y, int w, int h) {
      newPixels();
   }

   private void deliver(ImageConsumer ic) {
      ic.setDimensions(width, height);
      ic.setProperties(new Hashtable<Object, Object>());
      ic.setColorModel(model);
      ic.setHints(ImageConsumer.TOPDOWNLEFTRIGHT | ImageConsumer.COMPLETESCANLINES | ImageConsumer.SINGLEPASS);
      ic.setPixels(0, 0, width, height, model, pixels, offset, scansize);
      ic.imageComplete(animated ? ImageConsumer.SINGLEFRAMEDONE : ImageConsumer.STATICIMAGEDONE);
   }
}
