package rscweb.zip;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;

/*
 * Buffers the whole underlying stream, decodes the gzip member, verifies the
 * CRC32 and length trailer, and serves the plaintext. The client only ever
 * gzips complete settings documents of a few KB, so whole-buffer decode is
 * the honest shape here, not a shortcut.
 */
public class GZIPInputStream extends InputStream {
   public static final int GZIP_MAGIC = 0x8b1f;

   private static final int FTEXT = 1;
   private static final int FHCRC = 2;
   private static final int FEXTRA = 4;
   private static final int FNAME = 8;
   private static final int FCOMMENT = 16;

   private final byte[] data;
   private int pos;

   public GZIPInputStream(InputStream in) throws IOException {
      this(in, 512);
   }

   public GZIPInputStream(InputStream in, int size) throws IOException {
      ByteArrayOutputStream buf = new ByteArrayOutputStream();
      byte[] chunk = new byte[4096];
      int n;
      while ((n = in.read(chunk)) > 0) {
         buf.write(chunk, 0, n);
      }
      byte[] raw = buf.toByteArray();

      if (raw.length < 18 || (raw[0] & 0xff) != 0x1f || (raw[1] & 0xff) != 0x8b) {
         throw new IOException("not a gzip stream");
      }
      if (raw[2] != 8) {
         throw new IOException("unsupported gzip compression method " + raw[2]);
      }
      int flg = raw[3] & 0xff;
      int off = 10;
      if ((flg & FEXTRA) != 0) {
         int xlen = (raw[off] & 0xff) | ((raw[off + 1] & 0xff) << 8);
         off += 2 + xlen;
      }
      if ((flg & FNAME) != 0) {
         while (raw[off++] != 0) {
         }
      }
      if ((flg & FCOMMENT) != 0) {
         while (raw[off++] != 0) {
         }
      }
      if ((flg & FHCRC) != 0) {
         off += 2;
      }

      byte[][] holder = new byte[1][];
      int consumed;
      try {
         consumed = InflaterCore.inflateCounting(raw, off, raw.length - off - 8, (raw.length - off) * 3, holder);
      } catch (DataFormatException e) {
         throw new IOException("corrupt gzip body: " + e.getMessage());
      }
      this.data = holder[0];

      int trailer = off + consumed;
      if (trailer + 8 > raw.length) {
         throw new IOException("truncated gzip trailer");
      }
      CRC32 crc = new CRC32();
      crc.update(data);
      long storedCrc = readInt(raw, trailer) & 0xffffffffL;
      long storedLen = readInt(raw, trailer + 4) & 0xffffffffL;
      if (crc.getValue() != storedCrc) {
         throw new IOException("gzip CRC mismatch");
      }
      if ((data.length & 0xffffffffL) != storedLen) {
         throw new IOException("gzip length mismatch");
      }
   }

   private static int readInt(byte[] b, int off) {
      return (b[off] & 0xff) | ((b[off + 1] & 0xff) << 8)
            | ((b[off + 2] & 0xff) << 16) | ((b[off + 3] & 0xff) << 24);
   }

   public int read() {
      return pos < data.length ? data[pos++] & 0xff : -1;
   }

   public int read(byte[] b, int off, int len) {
      if (pos >= data.length) {
         return -1;
      }
      int n = Math.min(len, data.length - pos);
      System.arraycopy(data, pos, b, off, n);
      pos += n;
      return n;
   }

   public int available() {
      return data.length - pos;
   }
}
