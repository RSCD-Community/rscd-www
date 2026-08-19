package rscweb.awt.event;

public class MouseEvent extends InputEvent {
   public static final int MOUSE_CLICKED = 500;
   public static final int MOUSE_PRESSED = 501;
   public static final int MOUSE_RELEASED = 502;
   public static final int MOUSE_MOVED = 503;
   public static final int MOUSE_ENTERED = 504;
   public static final int MOUSE_EXITED = 505;
   public static final int MOUSE_DRAGGED = 506;
   public static final int MOUSE_WHEEL = 507;

   public static final int NOBUTTON = 0;
   public static final int BUTTON1 = 1;
   public static final int BUTTON2 = 2;
   public static final int BUTTON3 = 3;

   private final int x;
   private final int y;
   private final int clickCount;
   private final int button;

   public MouseEvent(Object source, int id, long when, int modifiers, int x, int y,
         int clickCount, boolean popupTrigger) {
      this(source, id, when, modifiers, x, y, clickCount, popupTrigger, NOBUTTON);
   }

   public MouseEvent(Object source, int id, long when, int modifiers, int x, int y,
         int clickCount, boolean popupTrigger, int button) {
      super(source, id, when, modifiers);
      this.x = x;
      this.y = y;
      this.clickCount = clickCount;
      this.button = button;
   }

   public int getX() {
      return x;
   }

   public int getY() {
      return y;
   }

   public int getClickCount() {
      return clickCount;
   }

   public int getButton() {
      return button;
   }
}
