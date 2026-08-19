package rscweb.imageio;

import rscweb.awt.image.BufferedImage;

public class IIOImage {
   private final BufferedImage image;

   public IIOImage(BufferedImage image, Object thumbnails, Object metadata) {
      this.image = image;
   }

   public BufferedImage getRenderedImage() {
      return image;
   }
}
