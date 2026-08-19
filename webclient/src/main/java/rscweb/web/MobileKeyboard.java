package rscweb.web;

import org.teavm.jso.JSBody;
import org.teavm.jso.browser.Window;
import org.teavm.jso.dom.events.EventListener;
import org.teavm.jso.dom.html.HTMLDocument;
import org.teavm.jso.dom.html.HTMLInputElement;

/*
 * iOS and Android only raise the on-screen keyboard for a real, focusable
 * text element that the player just tapped -- a <canvas> can never trigger
 * it, no matter how faithfully DomEvents forwards keydown to the game. The
 * client itself has no such element: chat, the login fields, bank/shop "how
 * many?" and every script prompt are pixels plus an internal text buffer.
 *
 * So this owns one hidden, genuinely focusable <input>, invisible but never
 * display:none (a display:none element cannot be focused, which would defeat
 * the point). It is focused when the game wants typing and left alone
 * otherwise.
 *
 * Two things were wrong with the first version of this, and both are worth
 * writing down because both looked like the keyboard being possessed.
 *
 * It focused or blurred on *every* animation frame rather than on the change.
 * awaitingTextInput() is true for the whole time the login screen has a field
 * being edited, so focus() ran sixty times a second for as long as that screen
 * was up: dismissing the keyboard re-raised it on the next frame, and a tap
 * anywhere at all appeared to summon it. The reverse cost just as much -- the
 * Chat button focuses this element itself, from its own click, and the very
 * next frame blurred it straight back because chat typing is not a state
 * awaitingTextInput() reports. Both directions are edge-triggered now, and the
 * poll only ever undoes a focus it performed itself.
 *
 * And keys were read from window keydown, which is fine for a physical
 * keyboard and useless for a soft one: Android reports every ordinary
 * character as key "Unidentified" with keyCode 229, deliberately, because the
 * text is not settled until composition ends. Nothing typed on a phone
 * survived DomEvents.charCode. The text is taken from the input element's own
 * beforeinput/input events instead and replayed a character at a time through
 * DomEvents.typeKey, which is the same pair of events a real key sends. The
 * window listeners stay exactly as they were for anyone with real keys.
 */
final class MobileKeyboard {

   static final String PROXY_ID = "rscd-kb-proxy";

   private static HTMLInputElement proxy;

   /* Whether the game wanted typing as of the last frame, so focus and blur
      can fire on the change rather than continuously. */
   private static boolean wanted;

   /* Whether the focus currently on the proxy is one this class asked for. A
      focus the player caused -- the Chat button -- is not ours to take back,
      and the poll must leave it alone until they dismiss it themselves. */
   private static boolean ours;

   private MobileKeyboard() {
   }

   static void install() {
      HTMLDocument doc = Window.current().getDocument();
      HTMLInputElement input = (HTMLInputElement)doc.createElement("input");
      input.setId(PROXY_ID);
      input.setAttribute("type", "text");
      input.setAttribute("autocomplete", "off");
      input.setAttribute("autocorrect", "off");
      input.setAttribute("autocapitalize", "off");
      input.setAttribute("spellcheck", "false");
      input.setAttribute("tabindex", "-1");
      input.setAttribute("aria-hidden", "true");
      /*
       * Off the visible page rather than display:none or visibility:hidden --
       * both of those make an element unfocusable, which is the one thing
       * this element exists to be. Zero-sized and out of flow instead, with
       * pointer-events off so an odd layout can never let it eat a tap the
       * canvas beneath it was meant to get.
       */
      applyHiddenStyle(input);
      doc.getBody().appendChild(input);
      proxy = input;

      /*
       * beforeinput names what the edit is about to do, which is the only
       * place a soft keyboard says plainly "this was a backspace" or "this was
       * a newline" rather than leaving it to be inferred. Cancelling it keeps
       * the element permanently empty, so the player can hold backspace past
       * the start of what they typed and the game still gets every keypress --
       * an input with nothing left to delete stops reporting deletions.
       */
      input.addEventListener("beforeinput", (EventListener<org.teavm.jso.dom.events.Event>)ev -> {
         String how = inputType(ev);
         if (how.startsWith("delete")) {
            DomEvents.typeKey(8);
         } else if (how.equals("insertLineBreak") || how.equals("insertParagraph")) {
            DomEvents.typeKey(10);
         } else if (how.startsWith("insert")) {
            send(inputData(ev));
         } else {
            return;
         }
         ev.preventDefault();
         input.setValue("");
      });

      /*
       * The fallback for anything that does not implement beforeinput, where
       * the text lands in the element for real and is read back out. Silent
       * when the listener above has already handled and cancelled the edit,
       * because then there is nothing in the element to find.
       */
      input.addEventListener("input", (EventListener<org.teavm.jso.dom.events.Event>)ev -> {
         String typed = input.getValue();
         if (typed != null && !typed.isEmpty()) {
            send(typed);
            input.setValue("");
         }
      });

      poll();
   }

   private static void send(String text) {
      if (text == null) {
         return;
      }
      for (int i = 0; i < text.length(); i++) {
         DomEvents.typeKey(text.charAt(i));
      }
   }

   /*
    * Called from the canvas's own touchend, which is a live user gesture --
    * the only moment iOS will honour focus() at all. The frame-driven focus
    * below is enough for Android, which allows it briefly after any tap, but
    * Safari ignores a focus that no gesture is currently running, so without
    * this a prompt could open with no way to answer it.
    *
    * Two reasons to raise the keyboard, and nothing else does: the game is
    * already asking for typing, or the player tapped the chat line -- the '*'
    * cursor and part-typed message at the bottom left, which is where chat
    * goes anyway and so is the one thing on screen that already means "type
    * here". An ordinary tap to walk somewhere raises nothing.
    *
    * Chat is a toggle, because chat is the case with no end state to watch:
    * awaitingTextInput() never reports it (the game keeps chat focused the
    * whole time it is up, so it cannot mean anything), which is exactly why
    * the poll below leaves it alone once it is open. Tapping the line again is
    * the way back out.
    */
   static void onCanvasTap(int x, int y) {
      if (proxy == null || DomEvents.wheelTarget == null) {
         return;
      }
      if (wants()) {
         wanted = true;
         ours = true;
         proxy.focus();
      } else if (DomEvents.wheelTarget.chatEntryTapped(x, y)) {
         if (isFocused(proxy)) {
            proxy.blur();
         } else {
            ours = false;
            proxy.focus();
         }
      } else if (isFocused(proxy)) {
         /*
          * A tap on the world puts the keyboard away, which on any other page
          * the browser would have done by itself -- tapping elsewhere moves
          * focus, and moving focus closes the keyboard. Not here: the canvas
          * calls preventDefault on every touch (it has to, or the page scrolls
          * and zooms under the player), and that is exactly the default being
          * prevented. So focus stayed on the proxy no matter where the next
          * tap landed, the keyboard stayed up with it, and each new tap looked
          * like it had just summoned one.
          */
         ours = false;
         proxy.blur();
      }
   }

   /* Whether the proxy currently holds focus -- DomEvents asks, so its window
      key listener can stand down for keys the proxy will deliver itself. */
   static boolean hasFocus() {
      return proxy != null && isFocused(proxy);
   }

   private static boolean wants() {
      return DomEvents.wheelTarget != null && DomEvents.wheelTarget.awaitingTextInput();
   }

   private static void poll() {
      Window.requestAnimationFrame(t -> {
         boolean now = wants();
         if (now != wanted) {
            wanted = now;
            if (now) {
               ours = true;
               proxy.focus();
            } else if (ours) {
               ours = false;
               proxy.blur();
            }
         }
         poll();
      });
   }

   /* font-size 16px matters even though the element is invisible: iOS Safari
      zooms the whole page in on focusing any text input smaller than that,
      which would visibly happen the instant a prompt opened. */
   @JSBody(params = "e", script =
      "e.style.position='fixed';" +
      "e.style.top='0';" +
      "e.style.left='0';" +
      "e.style.width='1px';" +
      "e.style.height='1px';" +
      "e.style.padding='0';" +
      "e.style.border='0';" +
      "e.style.opacity='0';" +
      "e.style.pointerEvents='none';" +
      "e.style.fontSize='16px';")
   private static native void applyHiddenStyle(HTMLInputElement e);

   @JSBody(params = "e", script = "return document.activeElement === e;")
   private static native boolean isFocused(HTMLInputElement e);

   @JSBody(params = "e", script = "return e.inputType || '';")
   private static native String inputType(org.teavm.jso.dom.events.Event e);

   @JSBody(params = "e", script = "return e.data == null ? '' : e.data;")
   private static native String inputData(org.teavm.jso.dom.events.Event e);
}
