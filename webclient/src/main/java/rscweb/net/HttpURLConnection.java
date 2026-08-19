package rscweb.net;

import java.io.IOException;
import java.io.InputStream;

public abstract class HttpURLConnection extends URLConnection {
   public static final int HTTP_OK = 200;
   public static final int HTTP_NOT_FOUND = 404;

   protected String method = "GET";

   protected HttpURLConnection(URL url) {
      super(url);
   }

   public void setRequestMethod(String method) throws IOException {
      this.method = method;
   }

   public String getRequestMethod() {
      return method;
   }

   public abstract int getResponseCode() throws IOException;

   public InputStream getErrorStream() {
      return null;
   }

   public void disconnect() {
   }
}
