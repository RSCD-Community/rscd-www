package rscweb.imageio;

public class ImageWriteParam {
   public static final int MODE_EXPLICIT = 2;

   public boolean canWriteCompressed() {
      return false;
   }

   public void setCompressionMode(int mode) {
   }

   public void setCompressionQuality(float quality) {
   }
}
