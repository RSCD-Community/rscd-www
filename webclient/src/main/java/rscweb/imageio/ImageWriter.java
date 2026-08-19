package rscweb.imageio;

import java.io.IOException;

public abstract class ImageWriter {
   public void setOutput(Object output) {
   }

   public abstract void write(Object metadata, IIOImage image, ImageWriteParam param) throws IOException;

   public ImageWriteParam getDefaultWriteParam() {
      return new ImageWriteParam();
   }

   public void dispose() {
   }
}
