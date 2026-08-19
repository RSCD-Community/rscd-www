package rscweb.io;

import rscweb.net.URI;

public class File {
   public static final String separator = "/";
   public static final char separatorChar = '/';
   public static final String pathSeparator = ":";
   public static final char pathSeparatorChar = ':';

   private final String path;

   public File(String path) {
      this.path = path;
   }

   public File(String parent, String child) {
      this.path = parent == null || parent.isEmpty() ? child : parent + "/" + child;
   }

   public File(File parent, String child) {
      this(parent == null ? null : parent.getPath(), child);
   }

   public File(URI uri) {
      this(uri.getPath());
   }

   /*
    * Replaces Foo.class.getProtectionDomain().getCodeSource().getLocation()
    * .toURI() -- the "where is my jar" idiom, rewritten here by
    * prepare-sources because a browser has neither jar nor ProtectionDomain.
    * "/" makes every beside-the-jar path resolve somewhere harmless.
    */
   public static URI codeSourceUri() {
      return new URI("/");
   }

   public String getPath() {
      return path;
   }

   public String getAbsolutePath() {
      return path;
   }

   public File getAbsoluteFile() {
      return this;
   }

   public boolean isAbsolute() {
      return path.startsWith("/");
   }

   public URI toURI() {
      return new URI(path);
   }

   public String getName() {
      int i = path.lastIndexOf('/');
      return i < 0 ? path : path.substring(i + 1);
   }

   public File getParentFile() {
      int i = path.lastIndexOf('/');
      return i <= 0 ? null : new File(path.substring(0, i));
   }

   public String getParent() {
      File p = getParentFile();
      return p == null ? null : p.getPath();
   }

   public boolean exists() {
      return FileSystem.exists(path);
   }

   public boolean isDirectory() {
      return false;
   }

   public boolean isFile() {
      return exists();
   }

   public boolean mkdir() {
      return true;
   }

   public boolean mkdirs() {
      return true;
   }

   public boolean delete() {
      return false;
   }

   public long length() {
      return 0;
   }

   public long lastModified() {
      return 0;
   }

   public File[] listFiles() {
      return new File[0];
   }

   public String[] list() {
      return new String[0];
   }

   public boolean canRead() {
      return exists();
   }

   public boolean renameTo(File dest) {
      return false;
   }

   public String toString() {
      return path;
   }
}
