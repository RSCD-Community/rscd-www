package rscweb.awt.event;

import rscweb.awt.AWTEvent;

public abstract class InputEvent extends AWTEvent {
   public static final int SHIFT_MASK = 1 << 0;
   public static final int CTRL_MASK = 1 << 1;
   public static final int META_MASK = 1 << 2;
   public static final int ALT_MASK = 1 << 3;
   public static final int BUTTON1_MASK = 1 << 4;
   public static final int BUTTON2_MASK = 1 << 3;
   public static final int BUTTON3_MASK = 1 << 2;
   public static final int SHIFT_DOWN_MASK = 1 << 6;
   public static final int CTRL_DOWN_MASK = 1 << 7;
   public static final int META_DOWN_MASK = 1 << 8;
   public static final int ALT_DOWN_MASK = 1 << 9;
   public static final int BUTTON1_DOWN_MASK = 1 << 10;
   public static final int BUTTON2_DOWN_MASK = 1 << 11;
   public static final int BUTTON3_DOWN_MASK = 1 << 12;

   private final long when;
   private final int modifiers;

   protected InputEvent(Object source, int id, long when, int modifiers) {
      super(source, id);
      this.when = when;
      this.modifiers = modifiers;
   }

   public long getWhen() {
      return when;
   }

   public int getModifiers() {
      return modifiers;
   }

   public int getModifiersEx() {
      return modifiers;
   }

   public boolean isShiftDown() {
      return (modifiers & (SHIFT_MASK | SHIFT_DOWN_MASK)) != 0;
   }

   public boolean isControlDown() {
      return (modifiers & (CTRL_MASK | CTRL_DOWN_MASK)) != 0;
   }

   public boolean isAltDown() {
      return (modifiers & (ALT_MASK | ALT_DOWN_MASK)) != 0;
   }

   public boolean isMetaDown() {
      return (modifiers & (META_MASK | META_DOWN_MASK)) != 0;
   }

   public void consume() {
   }
}
