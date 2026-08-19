package rscweb.web;

import org.rscdaemon.client.GameWindow;
import org.teavm.jso.browser.Window;
import org.teavm.jso.core.JSArrayReader;
import org.teavm.jso.dom.events.EventListener;
import org.teavm.jso.dom.events.KeyboardEvent;
import org.teavm.jso.dom.events.MouseEvent;
import org.teavm.jso.dom.events.Touch;
import org.teavm.jso.dom.html.HTMLCanvasElement;
import org.teavm.jso.dom.html.TextRectangle;
import rscweb.awt.Component;
import rscweb.awt.Event;

/*
 * DOM events back into the AWT 1.0 model. Everything funnels through
 * Component.postEvent on the captured GameFrame -- whose handleEvent is the
 * same forwarding switch the desktop client uses -- so the game sees the
 * identical ids, key codes and modifier bits either way. The wheel is the
 * one event the 1.0 model never had; it goes straight to
 * GameWindow.mouseWheel, exactly as GameFrame.processEvent does on desktop.
 *
 * Coordinates are canvas offsets, uncorrected: the web Frame reports insets
 * of (0,0,1,0), so GameFrame.handleEvent subtracts zero from both axes.
 */
public final class DomEvents {

   /* The GameFrame, captured by the graphics backend on its first paint. */
   static Component target;
   /* The GameWindow itself, captured off that same first paint. */
   static GameWindow wheelTarget;

   private DomEvents() {
   }

   public static void install(HTMLCanvasElement canvas) {
      canvas.listenMouseDown(e -> {
         e.preventDefault();
         post(Event.MOUSE_DOWN, e);
      });
      canvas.listenMouseUp(e -> post(Event.MOUSE_UP, e));
      canvas.listenMouseMove(e -> post(e.getButtons() != 0 ? Event.MOUSE_DRAG : Event.MOUSE_MOVE, e));
      canvas.addEventListener("contextmenu", org.teavm.jso.dom.events.Event::preventDefault);
      canvas.listenWheel(e -> {
         e.preventDefault();
         if (wheelTarget != null) {
            int rotation = e.getDeltaY() > 0 ? 1 : e.getDeltaY() < 0 ? -1 : 0;
            if (rotation != 0) {
               wheelTarget.mouseWheel(rotation, e.getOffsetX(), e.getOffsetY());
            }
         }
      });

      /*
       * Touch. Phones and tablets never fire the mouse events above -- mobile
       * browsers do not reliably synthesize mousedown/mouseup/mousemove from
       * taps on a bare <canvas>, so without this the game is unclickable on
       * touch-only hardware. preventDefault on all four stops the page itself
       * from scrolling and zooming under the player's finger.
       *
       * One finger has to stand in for a whole mouse -- two buttons, a wheel,
       * and the arrow keys that turn and raise the camera -- so what it means
       * is decided by what it does, not by where it lands:
       *
       *   a tap        left click, sent as press and release at touchend
       *   held still   right click, the context menu, sent at 450ms
       *   dragged      turns the camera out in the world; anywhere else it is
       *                a held press being dragged, as the mouse would be, plus
       *                the vertical swipe that stands in for the wheel
       *   two fingers  pinch to zoom
       *
       * Nothing is sent at touchstart, which is the point the whole shape turns
       * on. The game acts on the press, so a press sent as the finger lands has
       * already been acted upon by the time the gesture reveals itself -- the
       * character walks off before the camera can turn, the item is used before
       * the long press can open its menu. Waiting costs a tap nothing and makes
       * everything else possible.
       *
       * The swipe-as-wheel is coordinate-gated on the game side
       * (handleMouseWheel only acts inside an actually-scrollable widget under
       * x,y -- see mudclient), so it does something only on the
       * quest/friends/spellbook lists, the chat scrollback and the world map,
       * which is exactly where the mouse wheel already worked.
       */
      canvas.onTouchStart(e -> {
         e.preventDefault();
         JSArrayReader<Touch> touches = e.getTargetTouches();
         if (touches.getLength() > 1) {
            /* A second finger: whatever one was doing is now a pinch. */
            cancelLongPress();
            release();
            pending = false;
            camera = false;
            beginPinch(touches);
            return;
         }
         Touch t = pickTouch(touches);
         if (t == null) {
            return;
         }
         reset();
         touchY = t.getClientY();
         touchStartY = touchY;
         startX = canvasX(canvas, t);
         startY = canvasY(canvas, t);
         lastX = startX;
         lastY = startY;

         /*
          * The chat line answers a tap itself, by raising the keyboard, so the
          * game must not be told about that tap as well -- otherwise the same
          * finger also walks the character to whatever the chat strip is
          * sitting over, yellow X and all, at the moment the keyboard appears.
          */
         swallowTouch = wheelTarget != null && wheelTarget.chatEntryTapped(startX, startY);
         if (swallowTouch) {
            return;
         }

         /*
          * The press itself is held back until the finger has either moved or
          * lifted, because until then there is no telling a tap from the start
          * of something else -- and the game acts on the press, not the
          * release. Sent early, every attempt to turn the camera would first
          * walk the character to wherever the finger landed, and every long
          * press would have already left-clicked before it became a long press.
          *
          * Nothing is lost by waiting: a tap arrives as press-and-release
          * together at touchend, and a drag gets its press the moment the
          * finger passes the slop, which is soon enough for anything dragged by
          * holding -- the world map, a scrollbar.
          */
         pending = true;
         world = wheelTarget != null && wheelTarget.cameraDragArea(startX, startY);
         armLongPress();
      });
      canvas.onTouchMove(e -> {
         e.preventDefault();
         JSArrayReader<Touch> touches = e.getTargetTouches();

         if (touches.getLength() > 1 || pinching) {
            if (touches.getLength() > 1) {
               if (!pinching) {
                  cancelLongPress();
                  release();
                  pending = false;
                  camera = false;
                  beginPinch(touches);
               }
               pinch(touches);
            }
            return;
         }

         Touch t = pickTouch(touches);
         if (t == null || handled) {
            return;
         }
         int x = canvasX(canvas, t);
         int y = canvasY(canvas, t);

         /* Past the slop this is a drag, and the finger has committed to
            whichever of the two things a drag can mean here. */
         if (pending && moved(x, y)) {
            pending = false;
            cancelLongPress();
            if (world) {
               camera = true;
               lastX = x;
            } else {
               postAt(Event.MOUSE_DOWN, startX, startY, 0);
               posted = true;
            }
         }

         if (camera) {
            rotateRest += x - lastX;
            lastX = x;
            /* Left along the screen turns the world left, which is to say the
               scene follows the finger, the way dragging a map does. */
            while (rotateRest >= TURN_STEP) {
               rotateRest -= TURN_STEP;
               wheelTarget.nudgeCamera(1);
            }
            while (rotateRest <= -TURN_STEP) {
               rotateRest += TURN_STEP;
               wheelTarget.nudgeCamera(-1);
            }
            return;
         }

         lastX = x;
         lastY = y;
         if (!posted && !swallowTouch) {
            return;
         }

         double delta = touchY - t.getClientY();
         if (Math.abs(delta) >= SWIPE_STEP) {
            touchY = t.getClientY();
            /* Not while something is being panned by the same finger -- the
               world map would zoom a notch every fifteen pixels of the drag
               that is already moving it. See GameWindow.dragPans. */
            if (wheelTarget != null && !wheelTarget.dragPans()) {
               /*
                * A finger dragged up scrolls down, which is the opposite of
                * what a wheel notch does and is why this is not simply the
                * sign of the movement. The finger is on the list, not on the
                * wheel: it drags the content itself, so pulling the page up
                * brings what was below into view. Every touch platform works
                * this way, and getting it backwards reads immediately as
                * "up goes down" rather than as a matter of taste.
                */
               wheelTarget.mouseWheel(delta > 0 ? 1 : -1, x, y);
            }
         }
         if (posted) {
            postAt(Event.MOUSE_DRAG, x, y, 0);
         }
      });
      canvas.onTouchEnd(e -> {
         e.preventDefault();
         Touch t = pickTouch(e.getChangedTouches());
         boolean tapped = pending && !handled;

         cancelLongPress();
         if (tapped) {
            /* The press that was held back, delivered now that it has turned
               out to be a tap after all -- press and release together, at where
               the finger first landed rather than where it left. */
            postAt(Event.MOUSE_DOWN, startX, startY, 0);
            postAt(Event.MOUSE_UP, startX, startY, 0);
         } else {
            release();
         }

         /*
          * Inside the real gesture, which is the only place a browser will
          * raise the on-screen keyboard from -- see MobileKeyboard. Only for a
          * tap that stayed put: a swipe over the chat line is someone scrolling
          * their message history, and should not end in a keyboard.
          */
         if (t != null && !camera && !pinching && !handled
            && Math.abs(t.getClientY() - touchStartY) < SWIPE_STEP) {
            MobileKeyboard.onCanvasTap(canvasX(canvas, t), canvasY(canvas, t));
         }
         if (e.getTargetTouches().getLength() == 0) {
            reset();
         }
      });
      canvas.onTouchCancel(e -> {
         e.preventDefault();
         cancelLongPress();
         release();
         reset();
      });

      /* Keys on the window: the canvas never needs focus this way. */
      Window w = Window.current();
      w.addEventListener("keydown", (EventListener<KeyboardEvent>)e -> key(e, true));
      w.addEventListener("keyup", (EventListener<KeyboardEvent>)e -> key(e, false));
   }

   /* How far a finger has to move, in CSS pixels, before it counts as one
      wheel notch. Matches the popup menus' own 15px row height, so a swipe
      scrolls a list about one line per notch -- see the offer/stake menu. */
   private static final int SWIPE_STEP = 15;

   /* The vertical position (client, not canvas-relative) the current swipe's
      next wheel notch is measured from. Reset at touchstart. */
   private static double touchY;

   /* Where the finger first went down, kept so touchend can tell a tap from a
      swipe -- the two mean different things over the chat line. */
   private static double touchStartY;

   /* Whether this gesture began on the chat line, and so belongs to the
      keyboard rather than to the game. Decided at touchstart and held for the
      whole gesture, so a finger that starts there and wanders off does not
      hand the game half a click. */
   private static boolean swallowTouch;

   /* How far the finger may wander, in canvas pixels, and still have been a
      tap. Generous next to a mouse click, because a finger on glass is never
      quite still and a long press is held rather than pinned. */
   private static final int TAP_SLOP = 12;

   /* Canvas pixels of horizontal drag per step of camera turn, and of change
      in the distance between two fingers per step of zoom. Sized against what
      a step is worth on the game side: a turn is an eighth of the way round,
      so a drag across the width of a phone is about a full circle, and the
      zoom's whole range is a pinch of some four hundred pixels. */
   private static final int TURN_STEP = 40;
   private static final int ZOOM_STEP = 18;

   /* How long a finger has to stay put to mean the right mouse button. */
   private static final int LONG_PRESS_MS = 450;

   /* Where the finger went down, in canvas pixels, and where the last turn
      step was measured from. */
   private static int startX;
   private static int startY;
   private static int lastX;
   private static int lastY;

   /* Whether the press is still being held back, and whether it landed
      somewhere a drag should turn the camera. */
   private static boolean pending;
   private static boolean world;

   /* Whether a press has actually been handed to the game and so still owes it
      a release. */
   private static boolean posted;

   /* What this gesture turned into, once it did. */
   private static boolean camera;
   private static boolean pinching;

   /* Whether the gesture has already been answered in full -- a long press
      sends its own press and release, and the lift that follows must not send
      another. */
   private static boolean handled;

   /* Drag and pinch distance not yet worth a step, carried to the next move so
      a slow gesture still accumulates instead of rounding away. */
   private static int rotateRest;
   private static double pinchDist;

   /* The pending long-press timer, or 0. */
   private static int longPress;

   private static Touch pickTouch(JSArrayReader<Touch> touches) {
      return touches.getLength() == 0 ? null : touches.get(0);
   }

   private static boolean moved(int x, int y) {
      return Math.abs(x - startX) >= TAP_SLOP || Math.abs(y - startY) >= TAP_SLOP;
   }

   private static void postAt(int id, int x, int y, int mods) {
      if (target != null) {
         target.postEvent(new Event(target, 0L, id, x, y, 0, mods));
      }
   }

   /* The release owed for a press already sent. Silent when none is. */
   private static void release() {
      if (posted) {
         posted = false;
         postAt(Event.MOUSE_UP, lastX, lastY, 0);
      }
   }

   private static void reset() {
      pending = false;
      world = false;
      posted = false;
      camera = false;
      pinching = false;
      handled = false;
      swallowTouch = false;
      rotateRest = 0;
   }

   /*
    * Long press for the right mouse button.
    *
    * A phone has one button, and RSC's whole interface has two: left does the
    * default action, right opens the menu of everything else -- which is the
    * only way to reach most of what an object, a player or an inventory item
    * can do. Holding still is the gesture every touch platform already uses for
    * "the other button", so it is the one used here.
    *
    * META_MASK is what the old event model called the right button, and is
    * exactly what GameWindow.mouseDown reads to decide between 1 and 2. The
    * whole click is delivered from the timer, and the gesture marked answered,
    * so lifting the finger afterwards adds nothing.
    */
   private static void armLongPress() {
      cancelLongPress();
      longPress = Window.setTimeout(() -> {
         longPress = 0;
         if (!pending || pinching || handled) {
            return;
         }
         pending = false;
         handled = true;
         postAt(Event.MOUSE_DOWN, startX, startY, Event.META_MASK);
         postAt(Event.MOUSE_UP, startX, startY, Event.META_MASK);
      }, LONG_PRESS_MS);
   }

   private static void cancelLongPress() {
      if (longPress != 0) {
         Window.clearTimeout(longPress);
         longPress = 0;
      }
   }

   /*
    * Pinch to zoom. The distance between the two fingers is the whole gesture:
    * every ZOOM_STEP pixels it grows is one step closer, every ZOOM_STEP it
    * shrinks is one step back. Where the fingers are does not matter, so the
    * player can pinch anywhere on the canvas -- including over the panels,
    * where there is nothing else a second finger could have meant.
    */
   private static void beginPinch(JSArrayReader<Touch> touches) {
      pinching = true;
      handled = true;
      pinchDist = spread(touches);
   }

   private static void pinch(JSArrayReader<Touch> touches) {
      if (wheelTarget == null) {
         return;
      }
      double now = spread(touches);
      double delta = now - pinchDist;
      while (delta >= ZOOM_STEP) {
         delta -= ZOOM_STEP;
         pinchDist += ZOOM_STEP;
         wheelTarget.nudgeCameraZoom(1);
      }
      while (delta <= -ZOOM_STEP) {
         delta += ZOOM_STEP;
         pinchDist -= ZOOM_STEP;
         wheelTarget.nudgeCameraZoom(-1);
      }
   }

   private static double spread(JSArrayReader<Touch> touches) {
      if (touches.getLength() < 2) {
         return pinchDist;
      }
      Touch a = touches.get(0);
      Touch b = touches.get(1);
      double dx = (double) a.getClientX() - b.getClientX();
      double dy = (double) a.getClientY() - b.getClientY();
      return Math.sqrt(dx * dx + dy * dy);
   }

   /*
    * A touch says where on the screen it landed; the game wants to know which
    * of its own pixels that is. The two differ whenever the canvas is drawn at
    * a size other than its own -- which on this site it is, on every phone held
    * upright: rscd.css zooms .stone-frame down (0.46 to 0.94 below 612px) so
    * the 2003 layout fits a narrow screen, and the canvas inside it renders
    * scaled along with everything else. Landscape is wider than any of those
    * breakpoints, so nothing zooms, the two spaces coincide, and taps landed
    * correctly by luck -- which is exactly why this only ever looked broken in
    * portrait.
    *
    * getBoundingClientRect reports the size actually rendered, so dividing the
    * canvas's own width by it recovers the factor no matter what caused it, and
    * comes out at 1 when nothing did. The mouse needs none of this: offsetX and
    * offsetY are already in the target's own coordinates, which is why the
    * mouse path above never showed the bug.
    */
   private static int canvasX(HTMLCanvasElement canvas, Touch t) {
      TextRectangle rect = canvas.getBoundingClientRect();
      double shown = rect.getWidth();
      double scale = shown > 0 ? canvas.getWidth() / shown : 1.0;
      return (int)(((double) t.getClientX() - rect.getLeft()) * scale);
   }

   private static int canvasY(HTMLCanvasElement canvas, Touch t) {
      TextRectangle rect = canvas.getBoundingClientRect();
      double shown = rect.getHeight();
      double scale = shown > 0 ? canvas.getHeight() / shown : 1.0;
      return (int)(((double) t.getClientY() - rect.getTop()) * scale);
   }

   /*
    * One typed character, as the pair of events a real key would have sent.
    * MobileKeyboard calls this: a soft keyboard's keydown carries no usable
    * key on Android, so the text has to be read back off the proxy input
    * instead and replayed through here.
    */
   static void typeKey(int code) {
      if (target == null || code < 0) {
         return;
      }
      target.postEvent(new Event(target, 0L, Event.KEY_PRESS, 0, 0, code, 0));
      target.postEvent(new Event(target, 0L, Event.KEY_RELEASE, 0, 0, code, 0));
   }

   private static void post(int id, MouseEvent e) {
      if (target == null) {
         return;
      }
      int mods = 0;
      /* Old-model button encoding: right is META, middle is ALT. */
      if (id == Event.MOUSE_DOWN || id == Event.MOUSE_UP) {
         if (e.getButton() == 2) {
            mods |= Event.META_MASK;
         } else if (e.getButton() == 1) {
            mods |= Event.ALT_MASK;
         }
      } else if ((e.getButtons() & 2) != 0) {
         mods |= Event.META_MASK;
      } else if ((e.getButtons() & 4) != 0) {
         mods |= Event.ALT_MASK;
      }
      if (e.getShiftKey()) {
         mods |= Event.SHIFT_MASK;
      }
      if (e.getCtrlKey()) {
         mods |= Event.CTRL_MASK;
      }
      target.postEvent(new Event(target, 0L, id, e.getOffsetX(), e.getOffsetY(), 0, mods));
   }

   /* DOM key -> the old event's id and key code, or -1 to ignore. */
   private static int actionCode(String key) {
      switch (key) {
         case "ArrowUp": return Event.UP;
         case "ArrowDown": return Event.DOWN;
         case "ArrowLeft": return Event.LEFT;
         case "ArrowRight": return Event.RIGHT;
         case "Home": return Event.HOME;
         case "End": return Event.END;
         case "PageUp": return Event.PGUP;
         case "PageDown": return Event.PGDN;
         case "Insert": return Event.INSERT;
         case "F1": return Event.F1;
         case "F2": return Event.F2;
         case "F3": return Event.F3;
         case "F4": return Event.F4;
         case "F5": return Event.F5;
         case "F6": return Event.F6;
         case "F7": return Event.F7;
         case "F8": return Event.F8;
         case "F9": return Event.F9;
         case "F10": return Event.F10;
         case "F11": return Event.F11;
         case "F12": return Event.F12;
         default: return -1;
      }
   }

   private static int charCode(String key) {
      switch (key) {
         case "Enter": return 10;
         case "Backspace": return 8;
         case "Tab": return 9;
         case "Escape": return 27;
         case "Delete": return 127;
         default: return key.length() == 1 ? key.charAt(0) : -1;
      }
   }

   private static void key(KeyboardEvent e, boolean down) {
      if (target == null) {
         return;
      }
      String k = e.getKey();
      /*
       * While the keyboard proxy is focused -- any state awaitingTextInput()
       * reports, on every platform, not just phones -- a physical keystroke
       * reaches the game twice: once through this listener and once through
       * the proxy's beforeinput replay. So for exactly the keys the proxy
       * will deliver (typed characters and the edits beforeinput names),
       * this path stands down. Keys that never raise beforeinput -- arrows,
       * F-keys, Escape, Tab -- must keep coming through here, or a focused
       * prompt would eat them.
       */
      if (MobileKeyboard.hasFocus()
         && (k.length() == 1 || k.equals("Enter") || k.equals("Backspace") || k.equals("Delete"))) {
         return;
      }
      int id;
      int code = actionCode(k);
      if (code >= 0) {
         id = down ? Event.KEY_ACTION : Event.KEY_ACTION_RELEASE;
      } else {
         code = charCode(k);
         if (code < 0) {
            return;
         }
         id = down ? Event.KEY_PRESS : Event.KEY_RELEASE;
      }

      /* The browser must not act on keys the game consumes. F-keys are left
         alone: refresh and devtools belong to the player. */
      switch (k) {
         case "Backspace":
         case "Tab":
         case "ArrowUp":
         case "ArrowDown":
         case "ArrowLeft":
         case "ArrowRight":
         case "PageUp":
         case "PageDown":
         case "Home":
         case "End":
         case " ":
         case "'":
         case "/":
            e.preventDefault();
            break;
         default:
            break;
      }

      int mods = 0;
      if (e.isShiftKey()) {
         mods |= Event.SHIFT_MASK;
      }
      if (e.isCtrlKey()) {
         mods |= Event.CTRL_MASK;
      }
      if (e.isAltKey()) {
         mods |= Event.ALT_MASK;
      }
      target.postEvent(new Event(target, 0L, id, 0, 0, code, mods));
   }
}
