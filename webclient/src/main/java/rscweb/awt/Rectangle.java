package rscweb.awt;

public class Rectangle implements Shape {
   public int x;
   public int y;
   public int width;
   public int height;

   public Rectangle() {
   }

   public Rectangle(int x, int y, int width, int height) {
      this.x = x;
      this.y = y;
      this.width = width;
      this.height = height;
   }

   public Rectangle(int width, int height) {
      this(0, 0, width, height);
   }

   public void setBounds(int nx, int ny, int nw, int nh) {
      this.x = nx;
      this.y = ny;
      this.width = nw;
      this.height = nh;
   }

   public boolean contains(int px, int py) {
      return px >= x && py >= y && px < x + width && py < y + height;
   }
}
