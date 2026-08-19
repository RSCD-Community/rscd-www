package rscweb.web;

import java.io.ByteArrayInputStream;
import java.io.IOException;
import java.io.InputStream;
import org.teavm.jso.JSObject;
import org.teavm.jso.ajax.XMLHttpRequest;
import org.teavm.jso.typedarrays.Int8Array;
import rscweb.net.HttpURLConnection;
import rscweb.net.URL;

/*
 * URL.openStream over XMLHttpRequest. The request goes out the moment the
 * connection is made -- the client only ever GETs -- and the first caller to
 * ask for a result poll-sleeps until DONE. A network failure surfaces as
 * status 0, reported like any HTTP error.
 */
public final class XhrFetch implements URL.Fetcher {

   @Override
   public HttpURLConnection open(URL url) {
      return new XhrConnection(url);
   }

   private static final class XhrConnection extends HttpURLConnection {

      private final String url;
      private volatile boolean done;
      private int status;
      private byte[] body = new byte[0];

      XhrConnection(URL target) {
         super(target);
         this.url = target.toExternalForm();
         XMLHttpRequest xhr = new XMLHttpRequest();
         xhr.open("GET", url, true);
         xhr.setResponseType("arraybuffer");
         xhr.setOnReadyStateChange(() -> {
            if (xhr.getReadyState() == XMLHttpRequest.DONE) {
               status = xhr.getStatus();
               JSObject response = xhr.getResponse();
               if (response != null) {
                  body = new Int8Array(WebEnv.asArrayBuffer(response)).toJavaArray();
               }
               done = true;
            }
         });
         xhr.send();
      }

      private void await() throws IOException {
         while (!done) {
            try {
               Thread.sleep(5);
            } catch (InterruptedException e) {
               Thread.currentThread().interrupt();
               throw new IOException("interrupted");
            }
         }
      }

      @Override
      public int getResponseCode() throws IOException {
         await();
         return status;
      }

      @Override
      public InputStream getInputStream() throws IOException {
         await();
         if (status < 200 || status >= 400) {
            throw new IOException("HTTP " + status + " for " + url);
         }
         return new ByteArrayInputStream(body);
      }

      @Override
      public int getContentLength() {
         try {
            await();
         } catch (IOException e) {
            return -1;
         }
         return body.length;
      }
   }
}
