package rscweb.zip;

/*
 * A pure-Java DEFLATE (RFC 1951) decoder. TeaVM's classlib has no
 * java.util.zip at all, and the client cannot live without it: the Landscape
 * and Sprites archives stay compressed in memory and are inflated entry by
 * entry (raw deflate), and saved settings are gzip. This is the one shim that
 * is an algorithm rather than an adapter.
 *
 * Decodes the whole stream in one call. Both call sites want exactly that:
 * MemoryArchive hands over a complete compressed entry and drains the result,
 * and GZIPInputStream wraps a fully-buffered settings document. Nothing in
 * the client streams a partial deflate body.
 *
 * Verified against the JDK's Deflater as a byte-oracle (random and
 * pathological inputs, all three block types) -- see ZipShimTest.
 */
final class InflaterCore {

   private final byte[] in;
   private final int inEnd;
   private int pos;
   private int bitBuf;
   private int bitCount;

   private byte[] out;
   private int outLen;

   /* Base lengths and extra bits for literal/length codes 257-285. */
   private static final int[] LENGTH_BASE = {
      3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 15, 17, 19, 23, 27, 31,
      35, 43, 51, 59, 67, 83, 99, 115, 131, 163, 195, 227, 258
   };
   private static final int[] LENGTH_EXTRA = {
      0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2,
      3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 0
   };

   /* Base distances and extra bits for distance codes 0-29. */
   private static final int[] DIST_BASE = {
      1, 2, 3, 4, 5, 7, 9, 13, 17, 25, 33, 49, 65, 97, 129, 193,
      257, 385, 513, 769, 1025, 1537, 2049, 3073, 4097, 6145, 8193, 12289, 16385, 24577
   };
   private static final int[] DIST_EXTRA = {
      0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6,
      7, 7, 8, 8, 9, 9, 10, 10, 11, 11, 12, 12, 13, 13
   };

   /* Order in which code lengths for the code-length alphabet are stored. */
   private static final int[] CLEN_ORDER = {
      16, 17, 18, 0, 8, 7, 9, 6, 10, 5, 11, 4, 12, 3, 13, 2, 14, 1, 15
   };

   private InflaterCore(byte[] in, int off, int len, int sizeHint) {
      this.in = in;
      this.pos = off;
      this.inEnd = off + len;
      this.out = new byte[Math.max(sizeHint, 64)];
   }

   /** Decodes one complete raw-deflate stream. sizeHint may be 0. */
   static byte[] inflate(byte[] in, int off, int len, int sizeHint) throws DataFormatException {
      InflaterCore d = new InflaterCore(in, off, len, sizeHint);
      d.run();
      byte[] result = new byte[d.outLen];
      System.arraycopy(d.out, 0, result, 0, d.outLen);
      return result;
   }

   /** Bytes of input consumed, for gzip trailer positioning. */
   static int inflateCounting(byte[] in, int off, int len, int sizeHint, byte[][] resultHolder)
         throws DataFormatException {
      InflaterCore d = new InflaterCore(in, off, len, sizeHint);
      d.run();
      byte[] result = new byte[d.outLen];
      System.arraycopy(d.out, 0, result, 0, d.outLen);
      resultHolder[0] = result;
      /* Bits still buffered belong to unconsumed bytes. */
      return (d.pos - off) - d.bitCount / 8;
   }

   private void run() throws DataFormatException {
      boolean last;
      do {
         last = bits(1) == 1;
         int type = bits(2);
         if (type == 0) {
            stored();
         } else if (type == 1) {
            fixed();
         } else if (type == 2) {
            dynamic();
         } else {
            throw new DataFormatException("invalid block type 3");
         }
      } while (!last);
   }

   // ------------------------------------------------------------------
   // bit input

   private int bits(int n) throws DataFormatException {
      while (bitCount < n) {
         if (pos >= inEnd) {
            throw new DataFormatException("unexpected end of deflate input");
         }
         bitBuf |= (in[pos++] & 0xff) << bitCount;
         bitCount += 8;
      }
      int v = bitBuf & ((1 << n) - 1);
      bitBuf >>>= n;
      bitCount -= n;
      return v;
   }

   // ------------------------------------------------------------------
   // output

   private void ensure(int extra) {
      if (outLen + extra > out.length) {
         int cap = out.length;
         while (cap < outLen + extra) {
            cap <<= 1;
         }
         byte[] bigger = new byte[cap];
         System.arraycopy(out, 0, bigger, 0, outLen);
         out = bigger;
      }
   }

   private void emit(int b) {
      ensure(1);
      out[outLen++] = (byte) b;
   }

   private void copy(int dist, int len) throws DataFormatException {
      if (dist < 1 || dist > outLen) {
         throw new DataFormatException("invalid distance " + dist + " at output " + outLen);
      }
      ensure(len);
      int src = outLen - dist;
      /* Byte-at-a-time on purpose: dist < len overlaps are RLE and must read freshly written bytes. */
      for (int i = 0; i < len; i++) {
         out[outLen++] = out[src++];
      }
   }

   // ------------------------------------------------------------------
   // block types

   private void stored() throws DataFormatException {
      /* Discard bits to the byte boundary; LEN/NLEN follow unencoded. */
      bitBuf = 0;
      bitCount = 0;
      if (pos + 4 > inEnd) {
         throw new DataFormatException("truncated stored block header");
      }
      int len = (in[pos] & 0xff) | ((in[pos + 1] & 0xff) << 8);
      int nlen = (in[pos + 2] & 0xff) | ((in[pos + 3] & 0xff) << 8);
      pos += 4;
      if ((len ^ 0xffff) != nlen) {
         throw new DataFormatException("stored block LEN/NLEN mismatch");
      }
      if (pos + len > inEnd) {
         throw new DataFormatException("truncated stored block");
      }
      ensure(len);
      System.arraycopy(in, pos, out, outLen, len);
      pos += len;
      outLen += len;
   }

   private Huffman fixedLit;
   private Huffman fixedDist;

   private void fixed() throws DataFormatException {
      if (fixedLit == null) {
         int[] litLens = new int[288];
         for (int i = 0; i < 144; i++) {
            litLens[i] = 8;
         }
         for (int i = 144; i < 256; i++) {
            litLens[i] = 9;
         }
         for (int i = 256; i < 280; i++) {
            litLens[i] = 7;
         }
         for (int i = 280; i < 288; i++) {
            litLens[i] = 8;
         }
         fixedLit = new Huffman(litLens);
         int[] distLens = new int[30];
         for (int i = 0; i < 30; i++) {
            distLens[i] = 5;
         }
         fixedDist = new Huffman(distLens);
      }
      block(fixedLit, fixedDist);
   }

   private void dynamic() throws DataFormatException {
      int hlit = bits(5) + 257;
      int hdist = bits(5) + 1;
      int hclen = bits(4) + 4;

      int[] clenLens = new int[19];
      for (int i = 0; i < hclen; i++) {
         clenLens[CLEN_ORDER[i]] = bits(3);
      }
      Huffman clen = new Huffman(clenLens);

      int[] lens = new int[hlit + hdist];
      int i = 0;
      while (i < lens.length) {
         int sym = decode(clen);
         if (sym < 16) {
            lens[i++] = sym;
         } else if (sym == 16) {
            if (i == 0) {
               throw new DataFormatException("repeat with no previous code length");
            }
            int prev = lens[i - 1];
            int rep = 3 + bits(2);
            while (rep-- > 0) {
               lens[i++] = prev;
            }
         } else if (sym == 17) {
            int rep = 3 + bits(3);
            i += rep;
         } else {
            int rep = 11 + bits(7);
            i += rep;
         }
      }
      if (i != lens.length) {
         throw new DataFormatException("code length repeat overran table");
      }

      int[] litLens = new int[hlit];
      System.arraycopy(lens, 0, litLens, 0, hlit);
      int[] distLens = new int[hdist];
      System.arraycopy(lens, hlit, distLens, 0, hdist);
      block(new Huffman(litLens), new Huffman(distLens));
   }

   private void block(Huffman lit, Huffman dist) throws DataFormatException {
      while (true) {
         int sym = decode(lit);
         if (sym < 256) {
            emit(sym);
         } else if (sym == 256) {
            return;
         } else {
            int li = sym - 257;
            if (li >= LENGTH_BASE.length) {
               throw new DataFormatException("invalid length symbol " + sym);
            }
            int len = LENGTH_BASE[li] + bits(LENGTH_EXTRA[li]);
            int ds = decode(dist);
            if (ds >= DIST_BASE.length) {
               throw new DataFormatException("invalid distance symbol " + ds);
            }
            int d = DIST_BASE[ds] + bits(DIST_EXTRA[ds]);
            copy(d, len);
         }
      }
   }

   // ------------------------------------------------------------------
   // canonical Huffman decoding (zlib's counts-and-offsets walk)

   private int decode(Huffman h) throws DataFormatException {
      int code = 0;
      int first = 0;
      int index = 0;
      for (int len = 1; len <= 15; len++) {
         code |= bits(1);
         int count = h.count[len];
         if (code - first < count) {
            return h.symbol[index + (code - first)];
         }
         index += count;
         first = (first + count) << 1;
         code <<= 1;
      }
      throw new DataFormatException("invalid Huffman code");
   }

   private static final class Huffman {
      final int[] count = new int[16];
      final int[] symbol;

      Huffman(int[] lengths) throws DataFormatException {
         for (int len : lengths) {
            count[len]++;
         }
         count[0] = 0;
         int left = 1;
         for (int len = 1; len <= 15; len++) {
            left = (left << 1) - count[len];
            if (left < 0) {
               throw new DataFormatException("over-subscribed Huffman code");
            }
         }
         int[] offsets = new int[16];
         for (int len = 1; len < 15; len++) {
            offsets[len + 1] = offsets[len] + count[len];
         }
         symbol = new int[lengths.length];
         for (int sym = 0; sym < lengths.length; sym++) {
            if (lengths[sym] != 0) {
               symbol[offsets[lengths[sym]]++] = sym;
            }
         }
      }
   }
}
