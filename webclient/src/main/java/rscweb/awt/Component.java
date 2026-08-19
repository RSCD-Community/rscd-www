package rscweb.awt;

import rscweb.awt.image.ImageObserver;
import rscweb.awt.image.ImageProducer;

/*
 * The root of the shimmed widget tree. The client's window classes only ever
 * use a component as (a) a rectangle with a size, (b) a source of Graphics and
 * Images, and (c) a receiver of AWT 1.0 events -- GameWindow overrides
 * keyDown/mouseDown and friends directly. So that is all this is. postEvent
 * carries the 1.0 routing table so a synthesized browser event lands in the
 * same overrides a real AWT event would have.
 */
public class Component implements ImageObserver {
   int x;
   int y;
   int width;
   int height;
   boolean visible = true;
   Color background = Color.black;
   Color foreground = Color.white;
   Font font;
   Container parent;

   public int getX() {
      return x;
   }

   public int getY() {
      return y;
   }

   public int getWidth() {
      return width;
   }

   public int getHeight() {
      return height;
   }

   public Dimension getSize() {
      return new Dimension(width, height);
   }

   public Dimension size() {
      return getSize();
   }

   public Rectangle getBounds() {
      return new Rectangle(x, y, width, height);
   }

   public Rectangle bounds() {
      return getBounds();
   }

   public void setSize(int width, int height) {
      resize(width, height);
   }

   public void setSize(Dimension d) {
      resize(d.width, d.height);
   }

   public void resize(int width, int height) {
      this.width = width;
      this.height = height;
   }

   public void setBounds(int x, int y, int width, int height) {
      this.x = x;
      this.y = y;
      this.width = width;
      this.height = height;
   }

   public void setLocation(int x, int y) {
      this.x = x;
      this.y = y;
   }

   public Dimension getPreferredSize() {
      return getSize();
   }

   public Dimension preferredSize() {
      return getPreferredSize();
   }

   public Dimension getMinimumSize() {
      return getSize();
   }

   public Dimension minimumSize() {
      return getMinimumSize();
   }

   public void setVisible(boolean visible) {
      this.visible = visible;
   }

   public void show() {
      setVisible(true);
   }

   public void hide() {
      setVisible(false);
   }

   public boolean isVisible() {
      return visible;
   }

   public boolean isShowing() {
      return visible;
   }

   public Container getParent() {
      return parent;
   }

   public void setBackground(Color c) {
      background = c;
   }

   public Color getBackground() {
      return background;
   }

   public void setForeground(Color c) {
      foreground = c;
   }

   public Color getForeground() {
      return foreground;
   }

   public void setFont(Font f) {
      font = f;
   }

   public Font getFont() {
      return font;
   }

   public FontMetrics getFontMetrics(Font f) {
      return Toolkit.getDefaultToolkit().getFontMetrics(f);
   }

   public Toolkit getToolkit() {
      return Toolkit.getDefaultToolkit();
   }

   /*
    * The seam between the shim world and a real screen. On desktop AWT the
    * peer supplies these; here, whatever launched the client does. Without a
    * backend installed, getGraphics() is null -- the same thing a Component
    * with no peer reports -- and createImage falls back to a plain in-memory
    * image whose Graphics silently draws nothing.
    */
   public interface Backend {
      Graphics graphicsFor(Component c);

      Image offscreenImage(Component c, int width, int height);
   }

   private static Backend backend;

   public static void setBackend(Backend b) {
      backend = b;
   }

   public Graphics getGraphics() {
      return backend == null ? null : backend.graphicsFor(this);
   }

   public Image createImage(int width, int height) {
      return backend == null ? new Image(width, height) : backend.offscreenImage(this, width, height);
   }

   public Image createImage(ImageProducer producer) {
      return Toolkit.getDefaultToolkit().createImage(producer);
   }

   public boolean prepareImage(Image image, ImageObserver observer) {
      return true;
   }

   public void requestFocus() {
   }

   public boolean requestFocusInWindow() {
      return true;
   }

   protected final void enableEvents(long eventsToEnable) {
   }

   protected void processEvent(AWTEvent e) {
   }

   public void setMinimumSize(Dimension minimumSize) {
   }

   public void setCursor(Object cursor) {
   }

   public void validate() {
   }

   public void invalidate() {
   }

   public void repaint() {
      Graphics g = getGraphics();
      if (g != null) {
         update(g);
      }
   }

   public void repaint(long tm) {
      repaint();
   }

   public void repaint(int x, int y, int width, int height) {
      repaint();
   }

   public void paint(Graphics g) {
   }

   public void update(Graphics g) {
      paint(g);
   }

   public void paintAll(Graphics g) {
      paint(g);
   }

   public boolean imageUpdate(Image img, int infoflags, int x, int y, int width, int height) {
      return (infoflags & (ALLBITS | ABORT)) == 0;
   }

   /*
    * AWT 1.0 event delivery, verbatim from the old Component.postEvent
    * routing: the id picks the specific handler, and any handler returning
    * false falls through to handleEvent's caller (the parent, on real AWT --
    * here there is nowhere further to go, which the client never relies on).
    */
   public boolean postEvent(Event e) {
      return handleEvent(e);
   }

   public void deliverEvent(Event e) {
      postEvent(e);
   }

   public boolean handleEvent(Event evt) {
      switch (evt.id) {
         case Event.MOUSE_ENTER:
            return mouseEnter(evt, evt.x, evt.y);
         case Event.MOUSE_EXIT:
            return mouseExit(evt, evt.x, evt.y);
         case Event.MOUSE_MOVE:
            return mouseMove(evt, evt.x, evt.y);
         case Event.MOUSE_DOWN:
            return mouseDown(evt, evt.x, evt.y);
         case Event.MOUSE_DRAG:
            return mouseDrag(evt, evt.x, evt.y);
         case Event.MOUSE_UP:
            return mouseUp(evt, evt.x, evt.y);
         case Event.KEY_PRESS:
         case Event.KEY_ACTION:
            return keyDown(evt, evt.key);
         case Event.KEY_RELEASE:
         case Event.KEY_ACTION_RELEASE:
            return keyUp(evt, evt.key);
         case Event.WINDOW_DESTROY:
            return false;
      }
      return false;
   }

   public boolean mouseDown(Event evt, int x, int y) {
      return false;
   }

   public boolean mouseDrag(Event evt, int x, int y) {
      return false;
   }

   public boolean mouseUp(Event evt, int x, int y) {
      return false;
   }

   public boolean mouseMove(Event evt, int x, int y) {
      return false;
   }

   public boolean mouseEnter(Event evt, int x, int y) {
      return false;
   }

   public boolean mouseExit(Event evt, int x, int y) {
      return false;
   }

   public boolean keyDown(Event evt, int key) {
      return false;
   }

   public boolean keyUp(Event evt, int key) {
      return false;
   }

   public boolean action(Event evt, Object what) {
      return false;
   }

   public boolean gotFocus(Event evt, Object what) {
      return false;
   }

   public boolean lostFocus(Event evt, Object what) {
      return false;
   }
}
