package rscweb.web;

import org.teavm.jso.canvas.CanvasRenderingContext2D;
import org.teavm.jso.canvas.ImageData;
import org.teavm.jso.dom.html.HTMLCanvasElement;
import org.teavm.jso.typedarrays.ArrayBuffer;
import org.teavm.jso.typedarrays.Int32Array;
import org.teavm.jso.typedarrays.Uint8ClampedArray;
import rscweb.awt.Color;
import rscweb.awt.Font;
import rscweb.awt.FontMetrics;
import rscweb.awt.Graphics;
import rscweb.awt.Graphics2D;
import rscweb.awt.Image;
import rscweb.awt.image.ImageObserver;

/*
 * java.awt.Graphics over a 2D canvas context. Extends Graphics2D because the
 * client casts its screen Graphics to Graphics2D in places; the Graphics2D
 * extras (composites, paints, shapes) stay inert for now -- the game proper
 * never uses them, only the Skin theming does.
 *
 * Pixel images go to the canvas through putImageData with alpha forced
 * opaque: putImageData replaces rather than blends, and the game's own
 * framebuffer is 24-bit color that must never punch holes in the page.
 * Canvas-backed images (glyph scratch) draw through drawImage instead, which
 * is both faster and blend-correct.
 */
public class CanvasGraphics extends Graphics2D {

   private final HTMLCanvasElement canvas;
   private final CanvasRenderingContext2D ctx;
   private int tx;
   private int ty;
   private Color color = Color.black;
   private Font font;

   /* Blit scratch, rebuilt only when the source size changes. */
   private ImageData imageData;
   private Int32Array blitView;
   private int[] blitScratch;
   private int blitW = -1;
   private int blitH = -1;

   public CanvasGraphics(HTMLCanvasElement canvas, CanvasRenderingContext2D ctx) {
      this.canvas = canvas;
      this.ctx = ctx;
   }

   private String css(Color c) {
      return "rgb(" + c.getRed() + "," + c.getGreen() + "," + c.getBlue() + ")";
   }

   @Override
   public Graphics create() {
      CanvasGraphics g = new CanvasGraphics(canvas, ctx);
      g.tx = tx;
      g.ty = ty;
      g.color = color;
      g.font = font;
      return g;
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
      }
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
   public void fillRect(int x, int y, int width, int height) {
      ctx.setFillStyle(css(color));
      ctx.fillRect(x + tx, y + ty, width, height);
   }

   @Override
   public void drawRect(int x, int y, int width, int height) {
      ctx.setStrokeStyle(css(color));
      ctx.setLineWidth(1);
      ctx.strokeRect(x + tx + 0.5, y + ty + 0.5, width, height);
   }

   @Override
   public void clearRect(int x, int y, int width, int height) {
      ctx.setFillStyle("rgb(0,0,0)");
      ctx.fillRect(x + tx, y + ty, width, height);
   }

   @Override
   public void drawLine(int x1, int y1, int x2, int y2) {
      ctx.setStrokeStyle(css(color));
      ctx.setLineWidth(1);
      ctx.beginPath();
      ctx.moveTo(x1 + tx + 0.5, y1 + ty + 0.5);
      ctx.lineTo(x2 + tx + 0.5, y2 + ty + 0.5);
      ctx.stroke();
   }

   @Override
   public void drawString(String str, int x, int y) {
      ctx.setFont(WebFonts.cssFont(font));
      ctx.setTextBaseline("alphabetic");
      ctx.setFillStyle(css(color));
      ctx.fillText(str, x + tx, y + ty);
   }

   @Override
   public boolean drawImage(Image img, int x, int y, ImageObserver observer) {
      if (img == null) {
         return true;
      }
      if (img instanceof WebCanvasImage) {
         ctx.drawImage(((WebCanvasImage)img).canvas(), x + tx, y + ty);
         return true;
      }
      if (img.pixels == null || img.width <= 0 || img.height <= 0) {
         return false;
      }
      blit(img.pixels, img.width, img.height, x + tx, y + ty);
      return true;
   }

   @Override
   public boolean drawImage(Image img, int x, int y, int width, int height, ImageObserver observer) {
      if (img == null) {
         return true;
      }
      if (img instanceof WebCanvasImage) {
         ctx.drawImage(((WebCanvasImage)img).canvas(), x + tx, y + ty, width, height);
         return true;
      }
      if (img.pixels == null || img.width <= 0 || img.height <= 0) {
         return false;
      }
      if (width == img.width && height == img.height) {
         blit(img.pixels, img.width, img.height, x + tx, y + ty);
         return true;
      }

      /* Nearest-neighbour, same as the shim Image.getScaledInstance. */
      int[] scaled = new int[width * height];
      for (int dy = 0; dy < height; dy++) {
         int sy = dy * img.height / height;
         for (int dx = 0; dx < width; dx++) {
            scaled[dy * width + dx] = img.pixels[sy * img.width + dx * img.width / width];
         }
      }
      blit(scaled, width, height, x + tx, y + ty);
      return true;
   }

   @Override
   public boolean drawImage(Image img, int x, int y, Color bgcolor, ImageObserver observer) {
      return drawImage(img, x, y, observer);
   }

   @Override
   public void copyArea(int x, int y, int width, int height, int dx, int dy) {
      ctx.drawImage(canvas, x + tx, y + ty, width, height, x + tx + dx, y + ty + dy, width, height);
   }

   /*
    * ARGB int[] onto the canvas. ImageData bytes are RGBA in memory order, so
    * through a little-endian Int32Array view each pixel is written as
    * 0xAABBGGRR -- ARGB with red and blue swapped, alpha forced opaque.
    * Conversion runs in a plain Java loop into a scratch array, then lands in
    * the typed array as one bulk set().
    */
   private void blit(int[] argb, int w, int h, int dx, int dy) {
      if (imageData == null || blitW != w || blitH != h) {
         ArrayBuffer buffer = new ArrayBuffer(w * h * 4);
         blitView = new Int32Array(buffer);
         imageData = new ImageData(new Uint8ClampedArray(buffer), w, h);
         blitScratch = new int[w * h];
         blitW = w;
         blitH = h;
      }

      int[] scratch = blitScratch;
      for (int i = 0; i < scratch.length; i++) {
         int p = argb[i];
         scratch[i] = 0xff000000 | (p & 0xff00) | ((p & 0xff) << 16) | ((p >> 16) & 0xff);
      }

      blitView.set(scratch);
      ctx.putImageData(imageData, dx, dy);
   }
}
