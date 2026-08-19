package rscweb.jtools;

import java.io.InputStream;
import java.io.OutputStream;

public interface JavaCompiler {
   int run(InputStream in, OutputStream out, OutputStream err, String... arguments);
}
