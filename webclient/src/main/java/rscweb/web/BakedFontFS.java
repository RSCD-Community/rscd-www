package rscweb.web;

import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.util.HashMap;
import java.util.Map;
import rscweb.io.FileSystem;
import rscweb.net.HttpURLConnection;
import rscweb.net.URL;
import rscweb.net.URLConnection;

/*
 * The baked interface fonts, over HTTP instead of off disk.
 *
 * GameWindow looks for media/fonts/<slot>.jf and falls back to rasterising
 * the AWT font when the file isn't there; on the desktop that lookup is
 * File.isFile() + FileInputStream, which in this module both land on
 * rscweb.io.FileSystem. So the game code needs no web-specific branch: fetch
 * the same eight files, answer for them here, and the unmodified loader
 * takes the baked path and draws glyph-for-glyph what the desktop client
 * draws. Nothing served, nothing broken -- exists() says no and the client
 * rasterises as before.
 *
 * The fetch happens once at boot, all eight requests in flight together,
 * because exists() is called from deep inside the client's font loading and
 * is far better off answering from memory. Non-font paths pass through to
 * the delegate (localStorage, i.e. settings.ini).
 */
public final class BakedFontFS implements FileSystem.Resolver {

   /* GameWindow's slot order; the names are the file names. */
   private static final String[] SLOTS = {
      "h11p", "h12b", "h12p", "h13b", "h14b", "h16b", "h20b", "h24b"
   };

   private static final String SUFFIX = ".jf";

   private final FileSystem.Resolver delegate;
   private final Map<String, byte[]> baked = new HashMap<>();

   private BakedFontFS(FileSystem.Resolver delegate) {
      this.delegate = delegate;
   }

   /*
    * Blocks until every request has answered -- one round trip, before the
    * canvas shows anything but the black it was cleared to. Requires
    * URL.setFetcher to have run already.
    */
   public static BakedFontFS load(String baseUrl, FileSystem.Resolver delegate) {
      BakedFontFS fs = new BakedFontFS(delegate);
      String base = baseUrl.endsWith("/") ? baseUrl.substring(0, baseUrl.length() - 1) : baseUrl;

      URLConnection[] pending = new URLConnection[SLOTS.length];
      for (int i = 0; i < SLOTS.length; i++) {
         try {
            pending[i] = new URL(base + "/" + SLOTS[i] + SUFFIX).openConnection();
         } catch (IOException e) {
            pending[i] = null;
         }
      }

      for (int i = 0; i < SLOTS.length; i++) {
         byte[] payload = body(pending[i]);
         if (payload != null && payload.length > 0) {
            fs.baked.put(SLOTS[i] + SUFFIX, payload);
         }
      }

      System.out.println(fs.baked.isEmpty()
            ? "rscd: no baked fonts at " + base + " -- rasterising text on the canvas"
            : "rscd: baked fonts " + fs.baked.size() + "/" + SLOTS.length + " from " + base);
      return fs;
   }

   private static byte[] body(URLConnection connection) {
      if (connection == null) {
         return null;
      }
      try {
         if (connection instanceof HttpURLConnection
               && ((HttpURLConnection)connection).getResponseCode() != HttpURLConnection.HTTP_OK) {
            return null;
         }
         InputStream in = connection.getInputStream();
         try {
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            byte[] chunk = new byte[4096];
            int read;
            while ((read = in.read(chunk)) > 0) {
               out.write(chunk, 0, read);
            }
            return out.toByteArray();
         } finally {
            in.close();
         }
      } catch (IOException e) {
         return null;
      }
   }

   /*
    * Matched on the file name, not the whole path: the client builds the
    * font path from its install directory, which in a browser resolves off
    * a synthetic "/" root and carries whatever separators that leaves. There
    * is exactly one font directory here, so the name is the identity.
    */
   private byte[] find(String path) {
      if (path == null || !path.endsWith(SUFFIX)) {
         return null;
      }
      int slash = path.lastIndexOf('/');
      return baked.get(slash < 0 ? path : path.substring(slash + 1));
   }

   @Override
   public boolean exists(String path) {
      return find(path) != null || delegate.exists(path);
   }

   @Override
   public InputStream open(String path) throws IOException {
      byte[] payload = find(path);
      return payload != null ? new ByteArrayInputStream(payload) : delegate.open(path);
   }

   @Override
   public OutputStream create(String path) throws IOException {
      return delegate.create(path);
   }
}
