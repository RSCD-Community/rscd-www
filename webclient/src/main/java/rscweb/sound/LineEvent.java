package rscweb.sound;

public class LineEvent {
   public static final class Type {
      public static final Type OPEN = new Type();
      public static final Type START = new Type();
      public static final Type STOP = new Type();
      public static final Type CLOSE = new Type();
   }

   private final Type type;

   public LineEvent(Type type) {
      this.type = type;
   }

   public Type getType() {
      return type;
   }
}
