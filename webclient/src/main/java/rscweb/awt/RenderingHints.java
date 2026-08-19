package rscweb.awt;

public class RenderingHints {
   public static class Key {
      Key() {
      }
   }

   private static Object value() {
      return new Object();
   }

   public static final Key KEY_ANTIALIASING = new Key();
   public static final Object VALUE_ANTIALIAS_ON = value();
   public static final Object VALUE_ANTIALIAS_OFF = value();
   public static final Key KEY_TEXT_ANTIALIASING = new Key();
   public static final Object VALUE_TEXT_ANTIALIAS_ON = value();
   public static final Object VALUE_TEXT_ANTIALIAS_OFF = value();
   public static final Key KEY_INTERPOLATION = new Key();
   public static final Object VALUE_INTERPOLATION_NEAREST_NEIGHBOR = value();
   public static final Object VALUE_INTERPOLATION_BILINEAR = value();
   public static final Object VALUE_INTERPOLATION_BICUBIC = value();
   public static final Key KEY_STROKE_CONTROL = new Key();
   public static final Object VALUE_STROKE_PURE = value();
   public static final Object VALUE_STROKE_NORMALIZE = value();
   public static final Key KEY_RENDERING = new Key();
   public static final Object VALUE_RENDER_QUALITY = value();
   public static final Object VALUE_RENDER_SPEED = value();
}
