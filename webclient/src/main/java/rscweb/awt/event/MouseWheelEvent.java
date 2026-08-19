package rscweb.awt.event;

public class MouseWheelEvent extends MouseEvent {
   public static final int WHEEL_UNIT_SCROLL = 0;
   public static final int WHEEL_BLOCK_SCROLL = 1;

   private final int scrollType;
   private final int scrollAmount;
   private final int wheelRotation;

   public MouseWheelEvent(Object source, int id, long when, int modifiers, int x, int y,
         int clickCount, boolean popupTrigger, int scrollType, int scrollAmount, int wheelRotation) {
      super(source, id, when, modifiers, x, y, clickCount, popupTrigger);
      this.scrollType = scrollType;
      this.scrollAmount = scrollAmount;
      this.wheelRotation = wheelRotation;
   }

   public int getScrollType() {
      return scrollType;
   }

   public int getScrollAmount() {
      return scrollAmount;
   }

   public int getWheelRotation() {
      return wheelRotation;
   }

   public int getUnitsToScroll() {
      return scrollAmount * wheelRotation;
   }
}
