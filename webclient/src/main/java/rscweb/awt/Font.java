package rscweb.awt;

import java.util.Map;

public class Font {
   public static final int PLAIN = 0;
   public static final int BOLD = 1;
   public static final int ITALIC = 2;

   protected String name;
   protected int style;
   protected int size;

   public Font(String name, int style, int size) {
      this.name = name;
      this.style = style;
      this.size = size;
   }

   public String getName() {
      return name;
   }

   public String getFontName() {
      return name;
   }

   public String getFamily() {
      return name;
   }

   public int getStyle() {
      return style;
   }

   public int getSize() {
      return size;
   }

   public boolean isBold() {
      return (style & BOLD) != 0;
   }

   public boolean isItalic() {
      return (style & ITALIC) != 0;
   }

   public Font deriveFont(int style) {
      return new Font(name, style, size);
   }

   public Font deriveFont(float size) {
      return new Font(name, style, (int) (size + 0.5f));
   }

   public Font deriveFont(int style, float size) {
      return new Font(name, style, (int) (size + 0.5f));
   }

   /*
    * The client only ever derives with TextAttribute.TRACKING (Skin's label
    * letter-spacing). Tracking has no effect on the shimmed metrics, so the
    * attributes are accepted and dropped.
    */
   public Font deriveFont(Map<? extends rscweb.awt.font.TextAttribute, ?> attributes) {
      return new Font(name, style, size);
   }

   public boolean equals(Object o) {
      if (!(o instanceof Font)) {
         return false;
      }
      Font f = (Font) o;
      return f.style == style && f.size == size && f.name.equals(name);
   }

   public int hashCode() {
      return name.hashCode() ^ (style << 16) ^ size;
   }
}
