package rscweb.web;

import org.teavm.jso.browser.Window;
import org.teavm.jso.canvas.CanvasRenderingContext2D;
import org.teavm.jso.canvas.TextMetrics;
import org.teavm.jso.dom.html.HTMLCanvasElement;
import rscweb.awt.Font;
import rscweb.awt.FontMetrics;

/*
 * Font measurement through canvas measureText, against the same family stack
 * the glyphs are drawn with. Arial first: that is what AWT "Helvetica" really
 * mapped to on the Windows machines this client's layouts were tuned on, and
 * Liberation Sans is its metric twin on Linux (agreed with rscd1-dev for the
 * baked-font work -- same family, same reasoning).
 */
public class WebFonts implements FontMetrics.Measurer {

   public static final String FAMILY = "Arial, 'Liberation Sans', 'Helvetica Neue', Helvetica, sans-serif";

   private static CanvasRenderingContext2D measureCtx;

   private static CanvasRenderingContext2D ctx() {
      if (measureCtx == null) {
         HTMLCanvasElement c = (HTMLCanvasElement)Window.current().getDocument().createElement("canvas");
         c.setWidth(1);
         c.setHeight(1);
         measureCtx = (CanvasRenderingContext2D)c.getContext("2d");
      }
      return measureCtx;
   }

   public static String cssFont(Font font) {
      int size = font == null ? 12 : font.getSize();
      int style = font == null ? 0 : font.getStyle();
      StringBuilder sb = new StringBuilder();
      if ((style & Font.ITALIC) != 0) {
         sb.append("italic ");
      }
      if ((style & Font.BOLD) != 0) {
         sb.append("bold ");
      }
      return sb.append(size).append("px ").append(FAMILY).toString();
   }

   @Override
   public int stringWidth(Font font, String str) {
      CanvasRenderingContext2D c = ctx();
      c.setFont(cssFont(font));
      return (int)Math.ceil(c.measureText(str).getWidth());
   }

   @Override
   public int ascent(Font font) {
      CanvasRenderingContext2D c = ctx();
      c.setFont(cssFont(font));
      TextMetrics m = c.measureText("Mg");
      double a = m.getFontBoundingBoxAscent();
      if (a > 0 && !Double.isNaN(a)) {
         return (int)Math.ceil(a);
      }
      /* Arial's real ratios, for browsers without fontBoundingBox. */
      int size = font == null ? 12 : font.getSize();
      return Math.round(size * 0.905F);
   }

   @Override
   public int descent(Font font) {
      CanvasRenderingContext2D c = ctx();
      c.setFont(cssFont(font));
      TextMetrics m = c.measureText("Mg");
      double d = m.getFontBoundingBoxDescent();
      if (d > 0 && !Double.isNaN(d)) {
         return (int)Math.ceil(d);
      }
      int size = font == null ? 12 : font.getSize();
      return Math.round(size * 0.212F);
   }
}
