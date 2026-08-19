package rscweb.awt;

public class GradientPaint {
   public final float x1;
   public final float y1;
   public final Color color1;
   public final float x2;
   public final float y2;
   public final Color color2;
   public final boolean cyclic;

   public GradientPaint(float x1, float y1, Color color1, float x2, float y2, Color color2) {
      this(x1, y1, color1, x2, y2, color2, false);
   }

   public GradientPaint(float x1, float y1, Color color1, float x2, float y2, Color color2, boolean cyclic) {
      this.x1 = x1;
      this.y1 = y1;
      this.color1 = color1;
      this.x2 = x2;
      this.y2 = y2;
      this.color2 = color2;
      this.cyclic = cyclic;
   }
}
