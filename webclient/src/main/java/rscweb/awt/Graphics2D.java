package rscweb.awt;

public class Graphics2D extends Graphics {
   public void setRenderingHint(RenderingHints.Key hintKey, Object hintValue) {
   }

   public void setRenderingHints(java.util.Map<?, ?> hints) {
   }

   public void setComposite(AlphaComposite comp) {
   }

   public void setPaint(Object paint) {
   }

   public void setStroke(Object s) {
   }

   public void fill(Shape s) {
      if (s instanceof Rectangle) {
         Rectangle r = (Rectangle) s;
         fillRect(r.x, r.y, r.width, r.height);
      }
   }

   public void draw(Shape s) {
      if (s instanceof Rectangle) {
         Rectangle r = (Rectangle) s;
         drawRect(r.x, r.y, r.width, r.height);
      }
   }

   public void rotate(double theta, double x, double y) {
   }

   public void scale(double sx, double sy) {
   }
}
