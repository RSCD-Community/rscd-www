package rscweb.awt;

public class Frame extends Window {
   /*
    * Window states, for the desktop's benefit only. A tab is whatever the
    * person browsing has made it and cannot change that from in here, so the
    * three methods below accept the request and do nothing -- which is also
    * what a desktop window manager is free to do with it.
    */
   public static final int NORMAL = 0;
   public static final int ICONIFIED = 1;

   private String title = "";
   private boolean resizable = true;

   public Frame() {
   }

   public Frame(String title) {
      this.title = title;
   }

   public void setTitle(String title) {
      this.title = title;
   }

   public String getTitle() {
      return title;
   }

   public void setResizable(boolean resizable) {
      this.resizable = resizable;
   }

   public boolean isResizable() {
      return resizable;
   }

   public void setIconImage(Image image) {
   }

   public void setState(int state) {
   }

   public int getState() {
      return NORMAL;
   }

   public void toFront() {
   }

   /*
    * A canvas has no title bar, but all-zero insets mean "the window manager
    * has not reparented us yet" to GameFrame, which then waits a second for
    * decorations and substitutes a guessed 24-pixel title bar -- drawing the
    * whole game 24 pixels down a canvas that has no bar to clear. One token
    * pixel of bottom inset reads as "decorated, and the decorations are
    * nothing", which is the truth here.
    */
   @Override
   public Insets getInsets() {
      return new Insets(0, 0, 1, 0);
   }
}
