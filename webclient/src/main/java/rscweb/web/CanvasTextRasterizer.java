package rscweb.web;

import java.util.HashMap;

import org.teavm.jso.browser.Window;
import org.teavm.jso.canvas.CanvasRenderingContext2D;
import org.teavm.jso.canvas.ImageData;
import org.teavm.jso.dom.html.HTMLCanvasElement;
import org.teavm.jso.typedarrays.Uint8ClampedArray;
import rscweb.awt.Font;
import rscweb.awt.RasterGraphics;

/*
 * Per-string alpha masks for RasterGraphics.drawString: white text on a
 * transparent offscreen canvas, alpha channel lifted out as the mask. Cached
 * by font+text because the Worlds/login screens redraw the same strings at
 * 30fps; the cache clears wholesale when it grows past its cap (screens hold
 * a few dozen distinct strings, so a wholesale clear is a non-event).
 */
public class CanvasTextRasterizer implements RasterGraphics.TextRasterizer {

   private static final int CACHE_CAP = 600;

   private final WebFonts measurer = new WebFonts();
   private final HashMap<String, RasterGraphics.Mask> cache = new HashMap<>();
   private HTMLCanvasElement canvas;
   private CanvasRenderingContext2D ctx;

   @Override
   public RasterGraphics.Mask rasterize(String text, Font font) {
      String key = WebFonts.cssFont(font) + "|" + text;
      RasterGraphics.Mask mask = cache.get(key);
      if (mask != null) {
         return mask;
      }
      int width = Math.max(1, measurer.stringWidth(font, text) + 2);
      int ascent = Math.max(1, measurer.ascent(font));
      int height = Math.max(1, ascent + measurer.descent(font) + 2);
      ensureCanvas(width, height);
      ctx.clearRect(0, 0, canvas.getWidth(), canvas.getHeight());
      ctx.setFont(WebFonts.cssFont(font));
      ctx.setTextBaseline("alphabetic");
      ctx.setFillStyle("#ffffff");
      ctx.fillText(text, 0, ascent);
      ImageData d = ctx.getImageData(0, 0, width, height);
      Uint8ClampedArray data = d.getData();
      mask = new RasterGraphics.Mask();
      mask.width = width;
      mask.height = height;
      mask.ascent = ascent;
      mask.alpha = new int[width * height];
      for (int i = 0; i < mask.alpha.length; i++) {
         mask.alpha[i] = data.get(i * 4 + 3);
      }
      if (cache.size() >= CACHE_CAP) {
         cache.clear();
      }
      cache.put(key, mask);
      return mask;
   }

   private void ensureCanvas(int width, int height) {
      if (canvas == null) {
         canvas = (HTMLCanvasElement)Window.current().getDocument().createElement("canvas");
         canvas.setWidth(Math.max(64, width));
         canvas.setHeight(Math.max(32, height));
         ctx = (CanvasRenderingContext2D)canvas.getContext("2d");
      } else if (canvas.getWidth() < width || canvas.getHeight() < height) {
         canvas.setWidth(Math.max(canvas.getWidth(), width));
         canvas.setHeight(Math.max(canvas.getHeight(), height));
         ctx = (CanvasRenderingContext2D)canvas.getContext("2d");
      }
   }
}
