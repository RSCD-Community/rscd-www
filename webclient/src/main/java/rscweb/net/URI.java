package rscweb.net;

public class URI {
   private final String spec;

   public URI(String spec) {
      this.spec = spec;
   }

   public String getPath() {
      return spec;
   }

   public URL toURL() {
      return new URL(spec);
   }

   public String toString() {
      return spec;
   }
}
