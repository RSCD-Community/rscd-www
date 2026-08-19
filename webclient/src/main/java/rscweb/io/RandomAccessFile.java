package rscweb.io;

import java.io.IOException;

/*
 * Compile surface for the desktop video recorder; constructing one in the
 * browser fails, and nothing web-side ever does.
 */
public class RandomAccessFile {
   public RandomAccessFile(File file, String mode) throws IOException {
      throw new IOException("RandomAccessFile is not available in the browser");
   }

   public RandomAccessFile(String name, String mode) throws IOException {
      throw new IOException("RandomAccessFile is not available in the browser");
   }

   public void seek(long pos) throws IOException {
   }

   public long getFilePointer() throws IOException {
      return 0;
   }

   public void setLength(long newLength) throws IOException {
   }

   public long length() throws IOException {
      return 0;
   }

   public int read() throws IOException {
      return -1;
   }

   public int read(byte[] b) throws IOException {
      return -1;
   }

   public int read(byte[] b, int off, int len) throws IOException {
      return -1;
   }

   public void readFully(byte[] b) throws IOException {
      throw new IOException("unreadable");
   }

   public void write(int b) throws IOException {
   }

   public void write(byte[] b) throws IOException {
   }

   public void write(byte[] b, int off, int len) throws IOException {
   }

   public void writeBytes(String s) throws IOException {
   }

   public void writeInt(int v) throws IOException {
   }

   public void writeShort(int v) throws IOException {
   }

   public int skipBytes(int n) throws IOException {
      return 0;
   }

   public void close() throws IOException {
   }
}
