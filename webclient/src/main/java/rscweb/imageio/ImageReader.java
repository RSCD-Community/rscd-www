package rscweb.imageio;

import java.io.IOException;

import rscweb.awt.image.BufferedImage;

public abstract class ImageReader {
   public void setInput(Object input) {
   }

   public abstract BufferedImage read(int imageIndex, ImageReadParam param) throws IOException;

   public int getWidth(int imageIndex) throws IOException {
      return 0;
   }

   public int getHeight(int imageIndex) throws IOException {
      return 0;
   }

   public ImageReadParam getDefaultReadParam() {
      return new ImageReadParam();
   }

   public void dispose() {
   }
}
