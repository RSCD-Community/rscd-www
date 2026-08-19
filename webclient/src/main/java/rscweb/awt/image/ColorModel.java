package rscweb.awt.image;

public class ColorModel {
   public static ColorModel getRGBdefault() {
      return new DirectColorModel(32, 0x00ff0000, 0x0000ff00, 0x000000ff, 0xff000000);
   }

   public int getRGB(int pixel) {
      return pixel;
   }
}
