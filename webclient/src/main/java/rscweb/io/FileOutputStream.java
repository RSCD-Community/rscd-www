package rscweb.io;

import java.io.IOException;
import java.io.OutputStream;

public class FileOutputStream extends OutputStream {
   private final OutputStream out;

   public FileOutputStream(String path) throws IOException {
      this.out = FileSystem.create(path);
   }

   public FileOutputStream(File file) throws IOException {
      this(file.getPath());
   }

   public void write(int b) throws IOException {
      out.write(b);
   }

   public void write(byte[] b, int off, int len) throws IOException {
      out.write(b, off, len);
   }

   public void flush() throws IOException {
      out.flush();
   }

   public void close() throws IOException {
      out.close();
   }
}
