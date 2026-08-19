package rscweb.net;

import java.io.IOException;

public class InetAddress {
   private final String host;

   private InetAddress(String host) {
      this.host = host;
   }

   public static InetAddress getByName(String host) throws IOException {
      return new InetAddress(host);
   }

   public static InetAddress getLocalHost() throws IOException {
      return new InetAddress("localhost");
   }

   public String getHostName() {
      return host;
   }

   public String getHostAddress() {
      return host;
   }

   public String toString() {
      return host;
   }
}
