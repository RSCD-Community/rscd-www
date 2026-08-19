package rscweb.awt;

import java.util.ArrayList;
import java.util.List;

/*
 * Waits for images whose pixels are still being decoded by the browser. The
 * poll-sleep is fine here: TeaVM green threads yield to the event loop during
 * Thread.sleep, which is exactly when the decoder callback gets to run.
 */
public class MediaTracker {
   public static final int LOADING = 1;
   public static final int ABORTED = 2;
   public static final int ERRORED = 4;
   public static final int COMPLETE = 8;

   private final List<Image> images = new ArrayList<Image>();

   public MediaTracker(Component comp) {
   }

   public void addImage(Image image, int id) {
      images.add(image);
   }

   public void waitForAll() throws InterruptedException {
      for (Image image : images) {
         while (image.pixels == null) {
            Thread.sleep(10);
         }
      }
   }

   public void waitForID(int id) throws InterruptedException {
      waitForAll();
   }

   public boolean isErrorAny() {
      return false;
   }

   public int statusAll(boolean load) {
      for (Image image : images) {
         if (image.pixels == null) {
            return LOADING;
         }
      }
      return COMPLETE;
   }

   public void removeImage(Image image) {
      images.remove(image);
   }
}
