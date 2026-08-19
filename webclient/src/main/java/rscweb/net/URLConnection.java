package rscweb.net;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;

public abstract class URLConnection {
   protected final URL url;

   protected URLConnection(URL url) {
      this.url = url;
   }

   public abstract InputStream getInputStream() throws IOException;

   public OutputStream getOutputStream() throws IOException {
      throw new IOException("output not supported");
   }

   public void setRequestProperty(String key, String value) {
   }

   public void setDoOutput(boolean dooutput) {
   }

   public void setDoInput(boolean doinput) {
   }

   public void setUseCaches(boolean usecaches) {
   }

   public void setConnectTimeout(int timeout) {
   }

   public void setReadTimeout(int timeout) {
   }

   public int getContentLength() {
      return -1;
   }

   public String getHeaderField(String name) {
      return null;
   }

   public void connect() throws IOException {
   }
}
