package rscweb.io;

import java.io.IOException;
import java.io.OutputStream;
import java.io.Writer;

public class PrintWriter extends java.io.PrintWriter {
   public PrintWriter(Writer out) {
      super(out);
   }

   public PrintWriter(OutputStream out) {
      super(out);
   }

   public PrintWriter(File file, String csn) throws IOException {
      super(FileSystem.create(file.getPath()));
   }
}
