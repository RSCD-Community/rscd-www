package rscweb.awt;

public final class AlphaComposite {
   public static final int CLEAR = 1;
   public static final int SRC = 2;
   public static final int SRC_OVER = 3;
   public static final int DST_OVER = 4;
   public static final int SRC_IN = 5;

   public static final AlphaComposite SrcOver = new AlphaComposite(SRC_OVER, 1.0f);
   public static final AlphaComposite Src = new AlphaComposite(SRC, 1.0f);
   public static final AlphaComposite Clear = new AlphaComposite(CLEAR, 1.0f);

   private final int rule;
   private final float alpha;

   private AlphaComposite(int rule, float alpha) {
      this.rule = rule;
      this.alpha = alpha;
   }

   public static AlphaComposite getInstance(int rule) {
      return new AlphaComposite(rule, 1.0f);
   }

   public static AlphaComposite getInstance(int rule, float alpha) {
      return new AlphaComposite(rule, alpha);
   }

   public int getRule() {
      return rule;
   }

   public float getAlpha() {
      return alpha;
   }
}
