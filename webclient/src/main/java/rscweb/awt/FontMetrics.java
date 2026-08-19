package rscweb.awt;

/*
 * Metrics come from a pluggable measurer so the browser back-end can answer
 * from canvas measureText (or, once the baked Arial asset lands, from its
 * tables). The default approximation keeps headless smoke tests honest:
 * proportional-ish, deterministic, obviously not for layout.
 */
public class FontMetrics {

   public interface Measurer {
      int stringWidth(Font font, String str);

      int ascent(Font font);

      int descent(Font font);
   }

   private static Measurer measurer = new Measurer() {
      public int stringWidth(Font font, String str) {
         return str.length() * (font.getSize() / 2 + 1);
      }

      public int ascent(Font font) {
         return font.getSize() * 4 / 5;
      }

      public int descent(Font font) {
         return font.getSize() / 5;
      }
   };

   public static void setMeasurer(Measurer m) {
      measurer = m;
   }

   protected Font font;

   public FontMetrics(Font font) {
      this.font = font;
   }

   public Font getFont() {
      return font;
   }

   public int stringWidth(String str) {
      return measurer.stringWidth(font, str);
   }

   public int charWidth(char ch) {
      return measurer.stringWidth(font, String.valueOf(ch));
   }

   public int charsWidth(char[] data, int off, int len) {
      return stringWidth(new String(data, off, len));
   }

   public int getAscent() {
      return measurer.ascent(font);
   }

   public int getDescent() {
      return measurer.descent(font);
   }

   public int getLeading() {
      return 0;
   }

   public int getHeight() {
      return getAscent() + getDescent() + getLeading();
   }

   public int getMaxAscent() {
      return getAscent();
   }

   public int getMaxDescent() {
      return getDescent();
   }
}
