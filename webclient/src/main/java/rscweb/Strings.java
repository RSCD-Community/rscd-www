package rscweb;

/*
 * Replaces the deprecated String.getBytes(int, int, byte[], int) -- the
 * ASCII fast-copy the packet writers use -- which TeaVM's String lacks.
 * Same truncate-to-low-byte semantics as the original.
 */
public final class Strings {
   private Strings() {
   }

   public static void copyAscii(String s, byte[] dst, int dstBegin) {
      int n = s.length();
      for (int i = 0; i < n; i++) {
         dst[dstBegin + i] = (byte) s.charAt(i);
      }
   }
}
