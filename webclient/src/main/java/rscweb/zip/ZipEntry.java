package rscweb.zip;

public class ZipEntry {
   public static final int STORED = 0;
   public static final int DEFLATED = 8;

   private final String name;
   private long size = -1;
   private long compressedSize = -1;
   private int method = -1;

   public ZipEntry(String name) {
      this.name = name;
   }

   public String getName() {
      return name;
   }

   public boolean isDirectory() {
      return name.endsWith("/");
   }

   public long getSize() {
      return size;
   }

   public void setSize(long size) {
      this.size = size;
   }

   public long getCompressedSize() {
      return compressedSize;
   }

   public void setCompressedSize(long compressedSize) {
      this.compressedSize = compressedSize;
   }

   public int getMethod() {
      return method;
   }

   public void setMethod(int method) {
      this.method = method;
   }
}
