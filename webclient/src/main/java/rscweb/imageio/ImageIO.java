package rscweb.imageio;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.util.Collections;
import java.util.Iterator;

import rscweb.awt.Image;
import rscweb.awt.Toolkit;
import rscweb.awt.image.BufferedImage;
import rscweb.imageio.stream.ImageInputStream;
import rscweb.io.File;

/*
 * read() goes through the same browser decoder as Toolkit.createImage and
 * blocks until the pixels land (green thread; the decoder callback runs while
 * we sleep). write() has no consumer in the web client -- the recorder and
 * sprite tooling stay desktop features -- so it reports failure the way
 * ImageIO does for a missing writer plugin: by returning false, not throwing.
 */
public final class ImageIO {
   private ImageIO() {
   }

   public static BufferedImage read(InputStream input) throws IOException {
      ByteArrayOutputStream buf = new ByteArrayOutputStream();
      byte[] chunk = new byte[4096];
      int n;
      while ((n = input.read(chunk)) > 0) {
         buf.write(chunk, 0, n);
      }
      byte[] bytes = buf.toByteArray();
      Image decoded = Toolkit.getDefaultToolkit().createImage(bytes);
      try {
         while (decoded.pixels == null) {
            Thread.sleep(10);
         }
      } catch (InterruptedException e) {
         throw new IOException("interrupted while decoding image");
      }
      BufferedImage image = new BufferedImage(decoded.width, decoded.height, BufferedImage.TYPE_INT_ARGB);
      System.arraycopy(decoded.pixels, 0, image.pixels, 0, decoded.pixels.length);
      return image;
   }

   public static BufferedImage read(File file) throws IOException {
      throw new IOException("no filesystem in the browser: " + file);
   }

   public static boolean write(BufferedImage im, String formatName, OutputStream output) {
      return false;
   }

   public static boolean write(BufferedImage im, String formatName, File output) {
      return false;
   }

   public static Iterator<ImageWriter> getImageWritersByFormatName(String formatName) {
      return Collections.<ImageWriter>emptyList().iterator();
   }

   public static Iterator<ImageReader> getImageReaders(Object input) {
      return Collections.<ImageReader>emptyList().iterator();
   }

   public static ImageInputStream createImageInputStream(Object input) {
      return null;
   }
}
