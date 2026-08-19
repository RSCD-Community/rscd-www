package rscweb.web;

import org.teavm.jso.browser.Window;
import org.teavm.jso.canvas.CanvasRenderingContext2D;
import org.teavm.jso.canvas.ImageData;
import org.teavm.jso.dom.html.HTMLCanvasElement;
import org.teavm.jso.typedarrays.Int32Array;
import rscweb.awt.Graphics;
import rscweb.awt.Image;

/*
 * Component.createImage(w, h) in a browser: an offscreen canvas you can draw
 * on with real text rendering. This is what makes GameImage.drawLetter work
 * -- it draws each glyph with Graphics.drawString and reads the pixels back
 * through PixelGrabber, which calls sync() to materialise them.
 *
 * pixels stays null until sync(): the canvas is the single source of truth,
 * and reading it back mid-draw would hand out a stale copy.
 */
public class WebCanvasImage extends Image {

   private final HTMLCanvasElement element;
   private final CanvasRenderingContext2D ctx;

   public WebCanvasImage(int width, int height) {
      this.width = width;
      this.height = height;
      this.element = (HTMLCanvasElement)Window.current().getDocument().createElement("canvas");
      this.element.setWidth(width);
      this.element.setHeight(height);
      this.ctx = (CanvasRenderingContext2D)this.element.getContext("2d");
   }

   HTMLCanvasElement canvas() {
      return element;
   }

   @Override
   public Graphics getGraphics() {
      return new CanvasGraphics(element, ctx);
   }

   @Override
   public void sync() {
      ImageData data = ctx.getImageData(0, 0, width, height);
      Int32Array view = new Int32Array(data.getData().getBuffer());
      int[] px = new int[width * height];
      for (int i = 0; i < px.length; i++) {
         /* RGBA little-endian word back to ARGB. */
         int v = view.get(i);
         px[i] = (v & 0xff000000) | ((v & 0xff) << 16) | (v & 0xff00) | ((v >> 16) & 0xff);
      }
      pixels = px;
   }

   @Override
   public void flush() {
      pixels = null;
   }
}
