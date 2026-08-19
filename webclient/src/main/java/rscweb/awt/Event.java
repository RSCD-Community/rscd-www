package rscweb.awt;

/*
 * The AWT 1.0 event, which the client still speaks natively: GameWindow
 * overrides keyDown/keyUp/mouseDown/mouseDrag/mouseUp/mouseMove and reads
 * these constants. The browser layer synthesizes instances of this from DOM
 * events, exactly the way GameFrame synthesizes them from AWT 1.1 events on
 * the desktop.
 */
public class Event {
   public static final int SHIFT_MASK = 1 << 0;
   public static final int CTRL_MASK = 1 << 1;
   public static final int META_MASK = 1 << 2;
   public static final int ALT_MASK = 1 << 3;

   public static final int HOME = 1000;
   public static final int END = 1001;
   public static final int PGUP = 1002;
   public static final int PGDN = 1003;
   public static final int UP = 1004;
   public static final int DOWN = 1005;
   public static final int LEFT = 1006;
   public static final int RIGHT = 1007;

   public static final int F1 = 1008;
   public static final int F2 = 1009;
   public static final int F3 = 1010;
   public static final int F4 = 1011;
   public static final int F5 = 1012;
   public static final int F6 = 1013;
   public static final int F7 = 1014;
   public static final int F8 = 1015;
   public static final int F9 = 1016;
   public static final int F10 = 1017;
   public static final int F11 = 1018;
   public static final int F12 = 1019;

   public static final int PRINT_SCREEN = 1020;
   public static final int SCROLL_LOCK = 1021;
   public static final int CAPS_LOCK = 1022;
   public static final int NUM_LOCK = 1023;
   public static final int PAUSE = 1024;
   public static final int INSERT = 1025;

   public static final int WINDOW_DESTROY = 201;
   public static final int KEY_PRESS = 401;
   public static final int KEY_RELEASE = 402;
   public static final int KEY_ACTION = 403;
   public static final int KEY_ACTION_RELEASE = 404;
   public static final int MOUSE_DOWN = 501;
   public static final int MOUSE_UP = 502;
   public static final int MOUSE_MOVE = 503;
   public static final int MOUSE_ENTER = 504;
   public static final int MOUSE_EXIT = 505;
   public static final int MOUSE_DRAG = 506;

   public Object target;
   public long when;
   public int id;
   public int x;
   public int y;
   public int key;
   public int modifiers;
   public int clickCount;
   public Object arg;

   public Event(Object target, int id, Object arg) {
      this.target = target;
      this.id = id;
      this.arg = arg;
   }

   public Event(Object target, long when, int id, int x, int y, int key, int modifiers) {
      this.target = target;
      this.when = when;
      this.id = id;
      this.x = x;
      this.y = y;
      this.key = key;
      this.modifiers = modifiers;
   }

   public boolean shiftDown() {
      return (modifiers & SHIFT_MASK) != 0;
   }

   public boolean controlDown() {
      return (modifiers & CTRL_MASK) != 0;
   }

   public boolean metaDown() {
      return (modifiers & META_MASK) != 0;
   }
}
