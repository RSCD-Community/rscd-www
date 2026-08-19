package rscweb.awt;

public abstract class AWTEvent {
   public static final long COMPONENT_EVENT_MASK = 0x01;
   public static final long FOCUS_EVENT_MASK = 0x04;
   public static final long KEY_EVENT_MASK = 0x08;
   public static final long MOUSE_EVENT_MASK = 0x10;
   public static final long MOUSE_MOTION_EVENT_MASK = 0x20;
   public static final long WINDOW_EVENT_MASK = 0x40;
   public static final long MOUSE_WHEEL_EVENT_MASK = 0x20000;

   private final Object source;
   private final int id;

   protected AWTEvent(Object source, int id) {
      this.source = source;
      this.id = id;
   }

   public Object getSource() {
      return source;
   }

   public int getID() {
      return id;
   }
}
