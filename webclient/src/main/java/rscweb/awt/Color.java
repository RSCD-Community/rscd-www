package rscweb.awt;

/*
 * Browser-side stand-in for java.awt.Color. Pure data: an ARGB int and the
 * accessors the client uses. Everything renders through the software pipeline
 * anyway, so a colour never needs to be more than its packed value.
 */
public class Color {
   public static final Color white = new Color(255, 255, 255);
   public static final Color WHITE = white;
   public static final Color lightGray = new Color(192, 192, 192);
   public static final Color LIGHT_GRAY = lightGray;
   public static final Color gray = new Color(128, 128, 128);
   public static final Color GRAY = gray;
   public static final Color darkGray = new Color(64, 64, 64);
   public static final Color DARK_GRAY = darkGray;
   public static final Color black = new Color(0, 0, 0);
   public static final Color BLACK = black;
   public static final Color red = new Color(255, 0, 0);
   public static final Color RED = red;
   public static final Color pink = new Color(255, 175, 175);
   public static final Color PINK = pink;
   public static final Color orange = new Color(255, 200, 0);
   public static final Color ORANGE = orange;
   public static final Color yellow = new Color(255, 255, 0);
   public static final Color YELLOW = yellow;
   public static final Color green = new Color(0, 255, 0);
   public static final Color GREEN = green;
   public static final Color magenta = new Color(255, 0, 255);
   public static final Color MAGENTA = magenta;
   public static final Color cyan = new Color(0, 255, 255);
   public static final Color CYAN = cyan;
   public static final Color blue = new Color(0, 0, 255);
   public static final Color BLUE = blue;

   private final int value;

   public Color(int r, int g, int b) {
      this(r, g, b, 255);
   }

   public Color(int r, int g, int b, int a) {
      this.value = ((a & 0xff) << 24) | ((r & 0xff) << 16) | ((g & 0xff) << 8) | (b & 0xff);
   }

   public Color(int rgb) {
      this.value = 0xff000000 | rgb;
   }

   public Color(int rgba, boolean hasalpha) {
      this.value = hasalpha ? rgba : (0xff000000 | rgba);
   }

   public Color(float r, float g, float b) {
      this((int) (r * 255 + 0.5f), (int) (g * 255 + 0.5f), (int) (b * 255 + 0.5f));
   }

   public int getRed() {
      return (value >> 16) & 0xff;
   }

   public int getGreen() {
      return (value >> 8) & 0xff;
   }

   public int getBlue() {
      return value & 0xff;
   }

   public int getAlpha() {
      return (value >> 24) & 0xff;
   }

   public int getRGB() {
      return value;
   }

   public Color darker() {
      return new Color((int) (getRed() * 0.7), (int) (getGreen() * 0.7), (int) (getBlue() * 0.7), getAlpha());
   }

   public Color brighter() {
      int r = getRed(), g = getGreen(), b = getBlue();
      int i = 3;
      if (r == 0 && g == 0 && b == 0) {
         return new Color(i, i, i, getAlpha());
      }
      r = Math.min(255, Math.max(r, i) * 10 / 7);
      g = Math.min(255, Math.max(g, i) * 10 / 7);
      b = Math.min(255, Math.max(b, i) * 10 / 7);
      return new Color(r, g, b, getAlpha());
   }

   public boolean equals(Object o) {
      return o instanceof Color && ((Color) o).value == value;
   }

   public int hashCode() {
      return value;
   }

   public static Color decode(String nm) {
      return new Color(Integer.decode(nm).intValue());
   }
}
