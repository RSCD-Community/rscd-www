package rscweb.zip;

public class CRC32 {
   private static final int[] TABLE = new int[256];

   static {
      for (int n = 0; n < 256; n++) {
         int c = n;
         for (int k = 0; k < 8; k++) {
            c = (c & 1) != 0 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
         }
         TABLE[n] = c;
      }
   }

   private int crc;

   public void update(int b) {
      int c = ~crc;
      c = TABLE[(c ^ b) & 0xff] ^ (c >>> 8);
      crc = ~c;
   }

   public void update(byte[] b) {
      update(b, 0, b.length);
   }

   public void update(byte[] b, int off, int len) {
      int c = ~crc;
      for (int i = 0; i < len; i++) {
         c = TABLE[(c ^ b[off + i]) & 0xff] ^ (c >>> 8);
      }
      crc = ~c;
   }

   public long getValue() {
      return crc & 0xffffffffL;
   }

   public void reset() {
      crc = 0;
   }
}
