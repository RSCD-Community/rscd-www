package rscweb.zip;

/*
 * java.util.zip.Inflater's surface over the one-shot InflaterCore. The whole
 * input arrives in a single setInput -- which is how MemoryArchive drives it
 * -- so the first inflate() decodes everything and later calls just drain the
 * result buffer through the caller's chunks.
 */
public class Inflater {
   private final boolean nowrap;
   private byte[] input;
   private int inputOff;
   private int inputLen;
   private byte[] result;
   private int drained;

   public Inflater() {
      this(false);
   }

   public Inflater(boolean nowrap) {
      this.nowrap = nowrap;
   }

   public void setInput(byte[] b) {
      setInput(b, 0, b.length);
   }

   public void setInput(byte[] b, int off, int len) {
      this.input = b;
      this.inputOff = off;
      this.inputLen = len;
      this.result = null;
      this.drained = 0;
   }

   public boolean needsInput() {
      return input == null;
   }

   public boolean needsDictionary() {
      return false;
   }

   public boolean finished() {
      return result != null && drained >= result.length;
   }

   public int inflate(byte[] b) throws DataFormatException {
      return inflate(b, 0, b.length);
   }

   public int inflate(byte[] b, int off, int len) throws DataFormatException {
      if (result == null) {
         if (input == null) {
            return 0;
         }
         int bodyOff = inputOff;
         int bodyLen = inputLen;
         if (!nowrap) {
            /* zlib wrapper: 2-byte header, 4-byte Adler32 trailer. */
            if (bodyLen < 6) {
               throw new DataFormatException("zlib stream too short");
            }
            bodyOff += 2;
            bodyLen -= 6;
         }
         result = InflaterCore.inflate(input, bodyOff, bodyLen, bodyLen * 3);
      }
      int n = Math.min(len, result.length - drained);
      System.arraycopy(result, drained, b, off, n);
      drained += n;
      return n;
   }

   public void reset() {
      input = null;
      result = null;
      drained = 0;
   }

   public void end() {
      reset();
   }
}
