package rscweb.awt;

import rscweb.awt.image.ImageProducer;
import rscweb.awt.image.PixelCollector;

/*
 * One toolkit, one delegate. WebMain installs the browser-backed delegate
 * before the client boots; until then createImage(byte[]) fails loudly rather
 * than silently handing back an empty image.
 */
public class Toolkit {

   public interface Delegate {
      /* Decode PNG/JPEG/GIF bytes into img (async fill is fine -- callers wait via MediaTracker). */
      void decodeImage(byte[] data, int offset, int length, Image target);

      Dimension screenSize();
   }

   private static final Toolkit INSTANCE = new Toolkit();
   private static Delegate delegate;

   public static void setDelegate(Delegate d) {
      delegate = d;
   }

   public static Toolkit getDefaultToolkit() {
      return INSTANCE;
   }

   public Image createImage(ImageProducer producer) {
      PixelCollector collector = new PixelCollector();
      producer.startProduction(collector);
      return collector.toImage();
   }

   public Image createImage(byte[] imagedata) {
      return createImage(imagedata, 0, imagedata.length);
   }

   public Image createImage(byte[] imagedata, int imageoffset, int imagelength) {
      if (delegate == null) {
         throw new IllegalStateException("Toolkit delegate not installed");
      }
      Image image = new Image();
      delegate.decodeImage(imagedata, imageoffset, imagelength, image);
      return image;
   }

   public rscweb.awt.datatransfer.Clipboard getSystemClipboard() {
      return CLIPBOARD;
   }

   private static final rscweb.awt.datatransfer.Clipboard CLIPBOARD =
      new rscweb.awt.datatransfer.Clipboard();

   public Dimension getScreenSize() {
      return delegate != null ? delegate.screenSize() : new Dimension(1024, 768);
   }

   public FontMetrics getFontMetrics(Font font) {
      return new FontMetrics(font);
   }

   public void sync() {
   }

   public void beep() {
   }
}
