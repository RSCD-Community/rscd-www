package rscweb.awt.datatransfer;

/*
 * A clipboard that can never be read. The browser only exposes the real one
 * asynchronously (navigator.clipboard.readText, behind a permission prompt),
 * and the client reads it synchronously -- so this throws, and paste() treats
 * it the same as a headless desktop: silently does nothing.
 */
public class Clipboard {
   public Object getData(DataFlavor flavor) throws Exception {
      throw new UnsupportedOperationException("no synchronous clipboard in a browser");
   }
}
