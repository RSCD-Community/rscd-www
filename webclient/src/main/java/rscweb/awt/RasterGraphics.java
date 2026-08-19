package rscweb.awt;

import rscweb.awt.image.ImageObserver;

/*
 * The software Graphics2D behind BufferedImage.createGraphics(): every
 * operation lands directly in the image's ARGB int[]. This is what makes the
 * Skin-themed surfaces (Worlds screen, themed panels) exist at all in the
 * browser -- they are drawn with Graphics2D over the framebuffer, and a
 * canvas context can't reach into a Java array.
 *
 * The destination is treated as opaque RGB (it is the game framebuffer);
 * source colors may carry alpha and are blended over it. Clip is a single
 * rectangle, which is all the client ever sets.
 *
 * Text needs a real font engine, which only the browser has: a pluggable
 * TextRasterizer supplies per-string alpha masks (canvas-drawn on web, absent
 * headless, where strings silently don't render -- same as every other
 * headless surface).
 */
public class RasterGraphics extends Graphics2D {

   public interface TextRasterizer {
      Mask rasterize(String text, Font font);
   }

   public static final class Mask {
      public int[] alpha;
      public int width;
      public int height;
      public int ascent;
   }

   private static TextRasterizer textRasterizer;

   public static void setTextRasterizer(TextRasterizer r) {
      textRasterizer = r;
   }

   private final int[] px;
   private final int W;
   private final int H;
   private int tx;
   private int ty;
   private int clipX0;
   private int clipY0;
   private int clipX1;
   private int clipY1;
   private Color color = Color.black;
   private GradientPaint gradient;
   private Font font;
   private float compAlpha = 1.0F;

   public RasterGraphics(int[] pixels, int width, int height) {
      this.px = pixels;
      this.W = width;
      this.H = height;
      this.clipX1 = width;
      this.clipY1 = height;
   }

   private RasterGraphics(RasterGraphics o) {
      this.px = o.px;
      this.W = o.W;
      this.H = o.H;
      this.tx = o.tx;
      this.ty = o.ty;
      this.clipX0 = o.clipX0;
      this.clipY0 = o.clipY0;
      this.clipX1 = o.clipX1;
      this.clipY1 = o.clipY1;
      this.color = o.color;
      this.gradient = o.gradient;
      this.font = o.font;
      this.compAlpha = o.compAlpha;
   }

   /* ---- state ---- */

   @Override
   public Graphics create() {
      return new RasterGraphics(this);
   }

   @Override
   public void dispose() {
   }

   @Override
   public void translate(int x, int y) {
      tx += x;
      ty += y;
   }

   @Override
   public Color getColor() {
      return color;
   }

   @Override
   public void setColor(Color c) {
      if (c != null) {
         color = c;
         gradient = null;
      }
   }

   @Override
   public void setPaint(Object paint) {
      if (paint instanceof Color) {
         setColor((Color)paint);
      } else if (paint instanceof GradientPaint) {
         gradient = (GradientPaint)paint;
      }
   }

   @Override
   public void setComposite(AlphaComposite comp) {
      compAlpha = comp == null ? 1.0F : comp.getAlpha();
   }

   @Override
   public Font getFont() {
      return font;
   }

   @Override
   public void setFont(Font f) {
      font = f;
   }

   @Override
   public FontMetrics getFontMetrics() {
      return new FontMetrics(font);
   }

   @Override
   public FontMetrics getFontMetrics(Font f) {
      return new FontMetrics(f);
   }

   @Override
   public void clipRect(int x, int y, int width, int height) {
      clipX0 = Math.max(clipX0, x + tx);
      clipY0 = Math.max(clipY0, y + ty);
      clipX1 = Math.min(clipX1, x + tx + width);
      clipY1 = Math.min(clipY1, y + ty + height);
   }

   @Override
   public void setClip(int x, int y, int width, int height) {
      clipX0 = Math.max(0, x + tx);
      clipY0 = Math.max(0, y + ty);
      clipX1 = Math.min(W, x + tx + width);
      clipY1 = Math.min(H, y + ty + height);
   }

   @Override
   public void setClip(Shape clip) {
      if (clip == null) {
         clipX0 = 0;
         clipY0 = 0;
         clipX1 = W;
         clipY1 = H;
      } else if (clip instanceof Rectangle) {
         Rectangle r = (Rectangle)clip;
         setClip(r.x, r.y, r.width, r.height);
      }
   }

   @Override
   public Shape getClip() {
      return getClipBounds();
   }

   @Override
   public Rectangle getClipBounds() {
      return new Rectangle(clipX0 - tx, clipY0 - ty, clipX1 - clipX0, clipY1 - clipY0);
   }

   /* ---- blending core ---- */

   /* Source ARGB over the opaque destination at raster index i. */
   private void blend(int i, int argb, int extraAlpha) {
      int sa = (argb >>> 24) * extraAlpha / 255;
      if (compAlpha < 1.0F) {
         sa = (int)(sa * compAlpha);
      }
      if (sa <= 0) {
         return;
      }
      if (sa >= 255) {
         px[i] = argb | 0xff000000;
         return;
      }
      int d = px[i];
      int r = ((d >> 16 & 0xff) * (255 - sa) + (argb >> 16 & 0xff) * sa) / 255;
      int g = ((d >> 8 & 0xff) * (255 - sa) + (argb >> 8 & 0xff) * sa) / 255;
      int b = ((d & 0xff) * (255 - sa) + (argb & 0xff) * sa) / 255;
      px[i] = 0xff000000 | r << 16 | g << 8 | b;
   }

   private void plot(int x, int y, int argb) {
      if (x >= clipX0 && x < clipX1 && y >= clipY0 && y < clipY1) {
         blend(y * W + x, argb, 255);
      }
   }

   /* The paint's color at device pixel (x, y): flat, or projected onto the
      gradient axis and lerped, clamped at the ends (acyclic form). */
   private int paintAt(int x, int y) {
      GradientPaint gp = gradient;
      if (gp == null) {
         return color.getRGB();
      }
      float dx = gp.x2 - gp.x1;
      float dy = gp.y2 - gp.y1;
      float len2 = dx * dx + dy * dy;
      float t = len2 <= 0 ? 0 : ((x - tx - gp.x1) * dx + (y - ty - gp.y1) * dy) / len2;
      if (gp.cyclic) {
         t = Math.abs(t % 2.0F);
         if (t > 1.0F) {
            t = 2.0F - t;
         }
      } else {
         t = Math.max(0.0F, Math.min(1.0F, t));
      }
      int c1 = gp.color1.getRGB();
      int c2 = gp.color2.getRGB();
      int a = (int)((c1 >>> 24) + t * ((c2 >>> 24) - (c1 >>> 24)));
      int r = (int)((c1 >> 16 & 0xff) + t * ((c2 >> 16 & 0xff) - (c1 >> 16 & 0xff)));
      int g = (int)((c1 >> 8 & 0xff) + t * ((c2 >> 8 & 0xff) - (c1 >> 8 & 0xff)));
      int b = (int)((c1 & 0xff) + t * ((c2 & 0xff) - (c1 & 0xff)));
      return a << 24 | r << 16 | g << 8 | b;
   }

   /* ---- primitives ---- */

   @Override
   public void fillRect(int x, int y, int width, int height) {
      int x0 = Math.max(clipX0, x + tx);
      int y0 = Math.max(clipY0, y + ty);
      int x1 = Math.min(clipX1, x + tx + width);
      int y1 = Math.min(clipY1, y + ty + height);
      boolean flat = gradient == null;
      int argb = flat ? color.getRGB() : 0;
      for (int yy = y0; yy < y1; yy++) {
         int row = yy * W;
         for (int xx = x0; xx < x1; xx++) {
            blend(row + xx, flat ? argb : paintAt(xx, yy), 255);
         }
      }
   }

   @Override
   public void clearRect(int x, int y, int width, int height) {
      Color old = color;
      GradientPaint oldG = gradient;
      color = Color.black;
      gradient = null;
      fillRect(x, y, width, height);
      color = old;
      gradient = oldG;
   }

   @Override
   public void drawRect(int x, int y, int width, int height) {
      drawLine(x, y, x + width, y);
      drawLine(x, y + height, x + width, y + height);
      drawLine(x, y, x, y + height);
      drawLine(x + width, y, x + width, y + height);
   }

   @Override
   public void drawLine(int x1, int y1, int x2, int y2) {
      int argb = color.getRGB();
      int ax = x1 + tx;
      int ay = y1 + ty;
      int bx = x2 + tx;
      int by = y2 + ty;
      if (ay == by) {
         int from = Math.max(clipX0, Math.min(ax, bx));
         int to = Math.min(clipX1 - 1, Math.max(ax, bx));
         if (ay >= clipY0 && ay < clipY1) {
            int row = ay * W;
            for (int xx = from; xx <= to; xx++) {
               blend(row + xx, argb, 255);
            }
         }
         return;
      }
      if (ax == bx) {
         int from = Math.max(clipY0, Math.min(ay, by));
         int to = Math.min(clipY1 - 1, Math.max(ay, by));
         if (ax >= clipX0 && ax < clipX1) {
            for (int yy = from; yy <= to; yy++) {
               blend(yy * W + ax, argb, 255);
            }
         }
         return;
      }
      /* Bresenham for the rare diagonal. */
      int dx = Math.abs(bx - ax);
      int dy = -Math.abs(by - ay);
      int sx = ax < bx ? 1 : -1;
      int sy = ay < by ? 1 : -1;
      int err = dx + dy;
      int cx = ax;
      int cy = ay;
      while (true) {
         if (cx >= clipX0 && cx < clipX1 && cy >= clipY0 && cy < clipY1) {
            blend(cy * W + cx, argb, 255);
         }
         if (cx == bx && cy == by) {
            break;
         }
         int e2 = 2 * err;
         if (e2 >= dy) {
            err += dy;
            cx += sx;
         }
         if (e2 <= dx) {
            err += dx;
            cy += sy;
         }
      }
   }

   @Override
   public void drawRoundRect(int x, int y, int width, int height, int arcWidth, int arcHeight) {
      drawRect(x, y, width, height);
   }

   @Override
   public void fillRoundRect(int x, int y, int width, int height, int arcWidth, int arcHeight) {
      fillRect(x, y, width, height);
   }

   @Override
   public void fillOval(int x, int y, int width, int height) {
      double a = width / 2.0;
      double b = height / 2.0;
      double cx = x + tx + a;
      double cy = y + ty + b;
      int y0 = Math.max(clipY0, y + ty);
      int y1 = Math.min(clipY1, y + ty + height);
      for (int yy = y0; yy < y1; yy++) {
         double rel = (yy + 0.5 - cy) / b;
         double span = a * Math.sqrt(Math.max(0, 1 - rel * rel));
         int from = Math.max(clipX0, (int)Math.ceil(cx - span));
         int to = Math.min(clipX1, (int)Math.floor(cx + span));
         int row = yy * W;
         for (int xx = from; xx < to; xx++) {
            blend(row + xx, gradient == null ? color.getRGB() : paintAt(xx, yy), 255);
         }
      }
   }

   @Override
   public void drawOval(int x, int y, int width, int height) {
      /* Parametric walk; fine at the button-sized ovals the client draws. */
      int argb = color.getRGB();
      double a = width / 2.0;
      double b = height / 2.0;
      double cx = x + tx + a;
      double cy = y + ty + b;
      int steps = Math.max(16, (width + height) * 2);
      for (int i = 0; i < steps; i++) {
         double ang = 2 * Math.PI * i / steps;
         int xx = (int)Math.round(cx + a * Math.cos(ang) - 0.5);
         int yy = (int)Math.round(cy + b * Math.sin(ang) - 0.5);
         if (xx >= clipX0 && xx < clipX1 && yy >= clipY0 && yy < clipY1) {
            blend(yy * W + xx, argb, 255);
         }
      }
   }

   @Override
   public void drawPolygon(int[] xPoints, int[] yPoints, int nPoints) {
      for (int i = 0; i < nPoints; i++) {
         int j = (i + 1) % nPoints;
         drawLine(xPoints[i], yPoints[i], xPoints[j], yPoints[j]);
      }
   }

   @Override
   public void fillPolygon(int[] xPoints, int[] yPoints, int nPoints) {
      if (nPoints < 3) {
         return;
      }
      int minY = Integer.MAX_VALUE;
      int maxY = Integer.MIN_VALUE;
      for (int i = 0; i < nPoints; i++) {
         minY = Math.min(minY, yPoints[i] + ty);
         maxY = Math.max(maxY, yPoints[i] + ty);
      }
      minY = Math.max(minY, clipY0);
      maxY = Math.min(maxY, clipY1 - 1);
      int[] xs = new int[nPoints];
      for (int yy = minY; yy <= maxY; yy++) {
         int n = 0;
         for (int i = 0; i < nPoints; i++) {
            int j = (i + 1) % nPoints;
            int ya = yPoints[i] + ty;
            int yb = yPoints[j] + ty;
            if (ya == yb) {
               continue;
            }
            if (yy >= Math.min(ya, yb) && yy < Math.max(ya, yb)) {
               int xa = xPoints[i] + tx;
               int xb = xPoints[j] + tx;
               xs[n++] = xa + (yy - ya) * (xb - xa) / (yb - ya);
            }
         }
         /* insertion sort; n is tiny */
         for (int i = 1; i < n; i++) {
            int v = xs[i];
            int k = i - 1;
            while (k >= 0 && xs[k] > v) {
               xs[k + 1] = xs[k];
               k--;
            }
            xs[k + 1] = v;
         }
         int row = yy * W;
         for (int i = 0; i + 1 < n; i += 2) {
            int from = Math.max(clipX0, xs[i]);
            int to = Math.min(clipX1, xs[i + 1]);
            for (int xx = from; xx < to; xx++) {
               blend(row + xx, gradient == null ? color.getRGB() : paintAt(xx, yy), 255);
            }
         }
      }
   }

   /* ---- text ---- */

   @Override
   public void drawString(String str, int x, int y) {
      TextRasterizer tr = textRasterizer;
      if (tr == null || str == null || str.isEmpty()) {
         return;
      }
      Mask mask = tr.rasterize(str, font);
      if (mask == null) {
         return;
      }
      int argb = color.getRGB();
      int ox = x + tx;
      int oy = y + ty - mask.ascent;
      for (int my = 0; my < mask.height; my++) {
         int yy = oy + my;
         if (yy < clipY0 || yy >= clipY1) {
            continue;
         }
         int row = yy * W;
         int mrow = my * mask.width;
         for (int mx = 0; mx < mask.width; mx++) {
            int xx = ox + mx;
            if (xx < clipX0 || xx >= clipX1) {
               continue;
            }
            int a = mask.alpha[mrow + mx];
            if (a > 0) {
               blend(row + xx, argb, a);
            }
         }
      }
   }

   /* ---- images ---- */

   @Override
   public boolean drawImage(Image img, int x, int y, ImageObserver observer) {
      if (img == null) {
         return true;
      }
      img.sync();
      if (img.pixels == null || img.width <= 0) {
         return false;
      }
      int ox = x + tx;
      int oy = y + ty;
      for (int sy = 0; sy < img.height; sy++) {
         int yy = oy + sy;
         if (yy < clipY0 || yy >= clipY1) {
            continue;
         }
         int row = yy * W;
         int srow = sy * img.width;
         for (int sx = 0; sx < img.width; sx++) {
            int xx = ox + sx;
            if (xx >= clipX0 && xx < clipX1) {
               blend(row + xx, img.pixels[srow + sx], 255);
            }
         }
      }
      return true;
   }

   @Override
   public boolean drawImage(Image img, int x, int y, int width, int height, ImageObserver observer) {
      if (img == null) {
         return true;
      }
      img.sync();
      if (img.pixels == null || img.width <= 0) {
         return false;
      }
      if (width == img.width && height == img.height) {
         return drawImage(img, x, y, observer);
      }
      int ox = x + tx;
      int oy = y + ty;
      for (int dy = 0; dy < height; dy++) {
         int yy = oy + dy;
         if (yy < clipY0 || yy >= clipY1) {
            continue;
         }
         int sy = dy * img.height / height;
         int row = yy * W;
         int srow = sy * img.width;
         for (int dx = 0; dx < width; dx++) {
            int xx = ox + dx;
            if (xx >= clipX0 && xx < clipX1) {
               blend(row + xx, img.pixels[srow + dx * img.width / width], 255);
            }
         }
      }
      return true;
   }

   @Override
   public boolean drawImage(Image img, int x, int y, Color bgcolor, ImageObserver observer) {
      return drawImage(img, x, y, observer);
   }

   /*
    * Source-rect to dest-rect blit: the base Graphics stub for this overload
    * is a no-op ("return true" and nothing drawn), which is why the world map
    * opened, decoded and reported READY but painted nothing behind the "you
    * are here" marker -- WorldMapPanel.drawMap is the only caller of this
    * particular overload anywhere in the client, and it always passes
    * dx1<=dx2, sx1<=sx2, so flips are handled for contract correctness but
    * are not expected to matter in practice.
    */
   @Override
   public boolean drawImage(Image img, int dx1, int dy1, int dx2, int dy2,
         int sx1, int sy1, int sx2, int sy2, ImageObserver observer) {
      if (img == null) {
         return true;
      }
      img.sync();
      if (img.pixels == null || img.width <= 0) {
         return false;
      }

      boolean flipX = dx2 < dx1;
      boolean flipY = dy2 < dy1;
      int destX0 = Math.min(dx1, dx2);
      int destW = Math.abs(dx2 - dx1);
      int destY0 = Math.min(dy1, dy2);
      int destH = Math.abs(dy2 - dy1);
      int srcX0 = Math.min(sx1, sx2);
      int srcW = Math.abs(sx2 - sx1);
      int srcY0 = Math.min(sy1, sy2);
      int srcH = Math.abs(sy2 - sy1);
      if (destW <= 0 || destH <= 0 || srcW <= 0 || srcH <= 0) {
         return true;
      }

      int ox = destX0 + tx;
      int oy = destY0 + ty;

      for (int dy = 0; dy < destH; dy++) {
         int yy = oy + dy;
         if (yy < clipY0 || yy >= clipY1) {
            continue;
         }
         int sampleDy = flipY ? destH - 1 - dy : dy;
         int sy = srcY0 + sampleDy * srcH / destH;
         if (sy < 0) {
            sy = 0;
         } else if (sy >= img.height) {
            sy = img.height - 1;
         }
         int row = yy * W;
         int srow = sy * img.width;
         for (int dx = 0; dx < destW; dx++) {
            int xx = ox + dx;
            if (xx < clipX0 || xx >= clipX1) {
               continue;
            }
            int sampleDx = flipX ? destW - 1 - dx : dx;
            int sx = srcX0 + sampleDx * srcW / destW;
            if (sx < 0) {
               sx = 0;
            } else if (sx >= img.width) {
               sx = img.width - 1;
            }
            blend(row + xx, img.pixels[srow + sx], 255);
         }
      }
      return true;
   }

   @Override
   public void copyArea(int x, int y, int width, int height, int dx, int dy) {
      int[] tmp = new int[width];
      if (dy >= 0) {
         for (int yy = height - 1; yy >= 0; yy--) {
            copyRow(x + tx, y + ty + yy, width, x + tx + dx, y + ty + yy + dy, tmp);
         }
      } else {
         for (int yy = 0; yy < height; yy++) {
            copyRow(x + tx, y + ty + yy, width, x + tx + dx, y + ty + yy + dy, tmp);
         }
      }
   }

   private void copyRow(int sx, int sy, int width, int dx, int dy, int[] tmp) {
      if (sy < 0 || sy >= H || dy < clipY0 || dy >= clipY1) {
         return;
      }
      int sFrom = Math.max(0, sx);
      int sTo = Math.min(W, sx + width);
      if (sTo <= sFrom) {
         return;
      }
      System.arraycopy(px, sy * W + sFrom, tmp, sFrom - sx, sTo - sFrom);
      int from = Math.max(clipX0, dx + (sFrom - sx));
      int to = Math.min(clipX1, dx + (sTo - sx));
      if (to > from) {
         System.arraycopy(tmp, from - dx, px, dy * W + from, to - from);
      }
   }
}
