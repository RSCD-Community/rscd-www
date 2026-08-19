package rscweb.web;

import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.util.HashMap;
import java.util.Map;
import org.teavm.jso.browser.Storage;
import org.teavm.jso.browser.Window;

/*
 * The "disk" behind rscweb.io.File: localStorage. Only settings.ini ever
 * flows through it -- assets live in memory, scripting is off on web -- so a
 * byte-per-char latin-1 string per file is plenty. Sandboxed pages that deny
 * localStorage degrade to session-lifetime memory, which for a settings file
 * just means defaults every visit.
 */
public final class LocalStorageFS implements rscweb.io.FileSystem.Resolver {

   private static final String PREFIX = "rscd.file.";

   private final Storage storage = Window.current().getLocalStorage();
   private final Map<String, String> memory = new HashMap<>();

   private String get(String path) {
      return storage != null ? storage.getItem(PREFIX + path) : memory.get(path);
   }

   private void put(String path, String value) {
      if (storage != null) {
         storage.setItem(PREFIX + path, value);
      } else {
         memory.put(path, value);
      }
   }

   @Override
   public boolean exists(String path) {
      return get(path) != null;
   }

   @Override
   public InputStream open(String path) throws IOException {
      String value = get(path);
      if (value == null) {
         throw new IOException("no such file: " + path);
      }
      byte[] bytes = new byte[value.length()];
      for (int i = 0; i < bytes.length; i++) {
         bytes[i] = (byte)value.charAt(i);
      }
      return new ByteArrayInputStream(bytes);
   }

   @Override
   public OutputStream create(String path) {
      return new ByteArrayOutputStream() {
         @Override
         public void close() {
            byte[] bytes = toByteArray();
            StringBuilder sb = new StringBuilder(bytes.length);
            for (byte b : bytes) {
               sb.append((char)(b & 0xff));
            }
            put(path, sb.toString());
         }
      };
   }
}
