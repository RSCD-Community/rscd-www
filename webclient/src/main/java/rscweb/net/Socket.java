package rscweb.net;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;

/*
 * The client's game socket, carried over a browser WebSocket. WebMain
 * installs the provider (rscweb.web.WsSocketProvider): each Socket becomes a
 * binary WebSocket to the same host, and the returned streams block the green
 * thread until frames arrive -- StreamClass's reader thread works unmodified.
 */
public class Socket {

   public interface Provider {
      Endpoint connect(String host, int port) throws IOException;
   }

   public interface Endpoint {
      InputStream input();

      OutputStream output();

      void close() throws IOException;
   }

   private static Provider provider;

   public static void setProvider(Provider p) {
      provider = p;
   }

   private final Endpoint endpoint;
   private boolean closed;

   public Socket(String host, int port) throws IOException {
      if (provider == null) {
         throw new IOException("no socket provider installed");
      }
      this.endpoint = provider.connect(host, port);
   }

   public Socket(InetAddress address, int port) throws IOException {
      this(address.getHostName(), port);
   }

   public InputStream getInputStream() throws IOException {
      return endpoint.input();
   }

   public OutputStream getOutputStream() throws IOException {
      return endpoint.output();
   }

   public void setTcpNoDelay(boolean on) {
   }

   public void setSoTimeout(int timeout) {
   }

   public void setKeepAlive(boolean on) {
   }

   public boolean isClosed() {
      return closed;
   }

   public boolean isConnected() {
      return !closed;
   }

   public synchronized void close() throws IOException {
      if (!closed) {
         closed = true;
         endpoint.close();
      }
   }
}
