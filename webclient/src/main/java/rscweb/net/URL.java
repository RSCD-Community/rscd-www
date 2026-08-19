package rscweb.net;

import java.io.IOException;
import java.io.InputStream;

/*
 * URLs resolve through a fetch-backed provider (installed by WebMain).
 * openStream blocks the calling green thread until the response arrives,
 * which is exactly the contract the client's download code expects.
 */
public class URL {

   public interface Fetcher {
      HttpURLConnection open(URL url) throws IOException;
   }

   private static Fetcher fetcher;

   public static void setFetcher(Fetcher f) {
      fetcher = f;
   }

   private final String spec;

   public URL(String spec) {
      this.spec = spec;
   }

   public URL(URL context, String spec) {
      if (spec.contains("://")) {
         this.spec = spec;
      } else {
         String base = context.spec;
         this.spec = base.substring(0, base.lastIndexOf('/') + 1) + spec;
      }
   }

   public String toExternalForm() {
      return spec;
   }

   public String toString() {
      return spec;
   }

   public URLConnection openConnection() throws IOException {
      if (fetcher == null) {
         throw new IOException("no URL fetcher installed");
      }
      return fetcher.open(this);
   }

   public InputStream openStream() throws IOException {
      return openConnection().getInputStream();
   }

   public String getHost() {
      String s = spec.substring(spec.indexOf("://") + 3);
      int slash = s.indexOf('/');
      String hostport = slash < 0 ? s : s.substring(0, slash);
      int colon = hostport.indexOf(':');
      return colon < 0 ? hostport : hostport.substring(0, colon);
   }

   public String getProtocol() {
      return spec.substring(0, spec.indexOf("://"));
   }

   public String getPath() {
      String s = spec.substring(spec.indexOf("://") + 3);
      int slash = s.indexOf('/');
      return slash < 0 ? "" : s.substring(slash);
   }
}
