package rscweb.swing;

public final class JOptionPane {
   public static final int ERROR_MESSAGE = 0;
   public static final int INFORMATION_MESSAGE = 1;
   public static final int WARNING_MESSAGE = 2;

   private JOptionPane() {
   }

   public static void showMessageDialog(Object parent, Object message, String title, int type) {
      System.err.println(title + ": " + message);
   }
}
