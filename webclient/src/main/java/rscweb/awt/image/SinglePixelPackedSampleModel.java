package rscweb.awt.image;

public class SinglePixelPackedSampleModel {
   final int width;
   final int height;

   public SinglePixelPackedSampleModel(int dataType, int w, int h, int[] bitMasks) {
      this.width = w;
      this.height = h;
   }
}
