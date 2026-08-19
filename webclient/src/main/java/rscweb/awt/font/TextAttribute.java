package rscweb.awt.font;

public final class TextAttribute {
   public static final TextAttribute TRACKING = new TextAttribute("tracking");
   public static final TextAttribute FAMILY = new TextAttribute("family");
   public static final TextAttribute SIZE = new TextAttribute("size");
   public static final TextAttribute WEIGHT = new TextAttribute("weight");

   private final String name;

   private TextAttribute(String name) {
      this.name = name;
   }

   public String toString() {
      return name;
   }
}
