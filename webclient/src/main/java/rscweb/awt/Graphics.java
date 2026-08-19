package rscweb.awt;

import rscweb.awt.image.ImageObserver;

/*
 * Concrete-with-overridables rather than the JDK's abstract class: the default
 * body of every operation is a no-op, and the browser back-end (rscweb.web)
 * overrides exactly the subset the client actually draws with. That keeps this
 * file a compile surface and keeps the real behaviour in one place.
 */
public class Graphics {
   protected Color color = Color.black;
   protected Font font;

   public Graphics create() {
      return this;
   }

   public void dispose() {
   }

   public void translate(int x, int y) {
   }

   public Color getColor() {
      return color;
   }

   public void setColor(Color c) {
      if (c != null) {
         color = c;
      }
   }

   public Font getFont() {
      return font;
   }

   public void setFont(Font f) {
      font = f;
   }

   public FontMetrics getFontMetrics() {
      return getFontMetrics(font);
   }

   public FontMetrics getFontMetrics(Font f) {
      return Toolkit.getDefaultToolkit().getFontMetrics(f);
   }

   public void clipRect(int x, int y, int width, int height) {
   }

   public void setClip(int x, int y, int width, int height) {
   }

   public Rectangle getClipBounds() {
      return null;
   }

   public Shape getClip() {
      return getClipBounds();
   }

   public void setClip(Shape clip) {
      if (clip instanceof Rectangle) {
         Rectangle r = (Rectangle) clip;
         setClip(r.x, r.y, r.width, r.height);
      }
   }

   public void drawLine(int x1, int y1, int x2, int y2) {
   }

   public void fillRect(int x, int y, int width, int height) {
   }

   public void drawRect(int x, int y, int width, int height) {
   }

   public void clearRect(int x, int y, int width, int height) {
   }

   public void drawRoundRect(int x, int y, int width, int height, int arcWidth, int arcHeight) {
   }

   public void fillRoundRect(int x, int y, int width, int height, int arcWidth, int arcHeight) {
   }

   public void drawOval(int x, int y, int width, int height) {
   }

   public void fillOval(int x, int y, int width, int height) {
   }

   public void drawArc(int x, int y, int width, int height, int startAngle, int arcAngle) {
   }

   public void fillArc(int x, int y, int width, int height, int startAngle, int arcAngle) {
   }

   public void drawPolygon(int[] xPoints, int[] yPoints, int nPoints) {
   }

   public void fillPolygon(int[] xPoints, int[] yPoints, int nPoints) {
   }

   public void drawString(String str, int x, int y) {
   }

   public boolean drawImage(Image img, int x, int y, ImageObserver observer) {
      return true;
   }

   public boolean drawImage(Image img, int x, int y, int width, int height, ImageObserver observer) {
      return true;
   }

   public boolean drawImage(Image img, int x, int y, Color bgcolor, ImageObserver observer) {
      return drawImage(img, x, y, observer);
   }

   public boolean drawImage(Image img, int dx1, int dy1, int dx2, int dy2,
         int sx1, int sy1, int sx2, int sy2, ImageObserver observer) {
      return true;
   }

   public void copyArea(int x, int y, int width, int height, int dx, int dy) {
   }
}
