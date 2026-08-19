package rscweb.awt.event;

public class KeyEvent extends InputEvent {
   public static final int KEY_PRESSED = 401;
   public static final int KEY_RELEASED = 402;
   public static final int KEY_TYPED = 400;

   public static final char CHAR_UNDEFINED = 0xFFFF;

   public static final int VK_ENTER = '\n';
   public static final int VK_BACK_SPACE = '\b';
   public static final int VK_TAB = '\t';
   public static final int VK_SHIFT = 0x10;
   public static final int VK_CONTROL = 0x11;
   public static final int VK_ALT = 0x12;
   public static final int VK_ESCAPE = 0x1B;
   public static final int VK_SPACE = 0x20;
   public static final int VK_PAGE_UP = 0x21;
   public static final int VK_PAGE_DOWN = 0x22;
   public static final int VK_END = 0x23;
   public static final int VK_HOME = 0x24;
   public static final int VK_LEFT = 0x25;
   public static final int VK_UP = 0x26;
   public static final int VK_RIGHT = 0x27;
   public static final int VK_DOWN = 0x28;
   public static final int VK_DELETE = 0x7F;
   public static final int VK_F1 = 0x70;
   public static final int VK_F2 = 0x71;
   public static final int VK_F3 = 0x72;
   public static final int VK_F4 = 0x73;
   public static final int VK_F5 = 0x74;
   public static final int VK_F6 = 0x75;
   public static final int VK_F7 = 0x76;
   public static final int VK_F8 = 0x77;
   public static final int VK_F9 = 0x78;
   public static final int VK_F10 = 0x79;
   public static final int VK_F11 = 0x7A;
   public static final int VK_F12 = 0x7B;
   public static final int VK_INSERT = 0x9B;
   public static final int VK_CAPS_LOCK = 0x14;
   public static final int VK_UNDEFINED = 0;
   public static final int VK_NUM_LOCK = 0x90;
   public static final int VK_SCROLL_LOCK = 0x91;
   public static final int VK_PRINTSCREEN = 0x9A;
   public static final int VK_PAUSE = 0x13;

   private final int keyCode;
   private final char keyChar;

   public KeyEvent(Object source, int id, long when, int modifiers, int keyCode, char keyChar) {
      super(source, id, when, modifiers);
      this.keyCode = keyCode;
      this.keyChar = keyChar;
   }

   public int getKeyCode() {
      return keyCode;
   }

   public char getKeyChar() {
      return keyChar;
   }

   public boolean isActionKey() {
      switch (keyCode) {
         case VK_HOME:
         case VK_END:
         case VK_PAGE_UP:
         case VK_PAGE_DOWN:
         case VK_UP:
         case VK_DOWN:
         case VK_LEFT:
         case VK_RIGHT:
         case VK_F1:
         case VK_F2:
         case VK_F3:
         case VK_F4:
         case VK_F5:
         case VK_F6:
         case VK_F7:
         case VK_F8:
         case VK_F9:
         case VK_F10:
         case VK_F11:
         case VK_F12:
         case VK_INSERT:
         case VK_CAPS_LOCK:
         case VK_NUM_LOCK:
         case VK_SCROLL_LOCK:
         case VK_PRINTSCREEN:
         case VK_PAUSE:
            return true;
      }
      return false;
   }
}
