package rscweb.io;

import java.io.IOException;
import java.io.InputStream;

public class FileInputStream extends InputStream {
   private final InputStream in;

   public FileInputStream(String path) throws IOException {
      this.in = FileSystem.open(path);
   }

   public FileInputStream(File file) throws IOException {
      this(file.getPath());
   }

   public int read() throws IOException {
      return in.read();
   }

   public int read(byte[] b, int off, int len) throws IOException {
      return in.read(b, off, len);
   }

   public int available() throws IOException {
      return in.available();
   }

   public long skip(long n) throws IOException {
      return in.skip(n);
   }

   public void close() throws IOException {
      in.close();
   }
}
