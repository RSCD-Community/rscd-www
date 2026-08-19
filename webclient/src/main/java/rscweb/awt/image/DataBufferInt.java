package rscweb.awt.image;

public class DataBufferInt extends DataBuffer {
   private final int[] data;

   public DataBufferInt(int[] data, int size) {
      this.data = data;
   }

   public int[] getData() {
      return data;
   }
}
