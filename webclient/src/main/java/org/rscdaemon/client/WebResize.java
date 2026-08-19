package org.rscdaemon.client;

/*
 * The one seam the browser needs into the client's resize support.
 *
 * The desktop client resizes for real -- mudclient.applyPendingResize grows
 * the framebuffer, re-points the camera and rebuilds the sized menus, and
 * explicitly scales nothing. That code compiles into this module unchanged,
 * so the browser gets the same behaviour for free; what it does not get is
 * anything that *calls* it.
 *
 * On desktop the call comes from GameFrame.processEvent on
 * ComponentEvent.COMPONENT_RESIZED. This module never goes through
 * processEvent at all: DomEvents posts old-model java.awt.Event objects
 * straight at Component.handleEvent, which is the path GameWindow's
 * keyDown/mouseDown were written against and the path the 1.0 model gives us.
 * The old model has no resize event, so nothing on the web side can report
 * one the way the frame does.
 *
 * GameWindow.frameResized is package-private, which is correct -- it is the
 * frame's business, not the world's. This class lives in the same package for
 * that reason alone, so the browser layer has exactly one narrow, named way
 * in rather than the client being loosened to suit it. Nothing under rscweb.*
 * can reach frameResized without coming through here.
 *
 * It is a new file in this module, not an edit to the game sources:
 * prepare-sources.sh copies those into target/generated-sources/game and the
 * repo client stays untouched, which is the rule this module is built on.
 */
public final class WebResize {

   private WebResize() {
   }

   /**
    * Report a new frame interior to the game, exactly as GameFrame does.
    *
    * Store-only, like the desktop path: the game thread picks the size up
    * between ticks in applyPendingResize, so nothing is reallocated while a
    * draw is mid-flight against the old framebuffer.
    *
    * The game is taken from the frame rather than passed in, because the
    * browser layer has no dependable moment at which it holds one. Its only
    * handle came from Component.createImage, and the boot screens never ask
    * the GameWindow for a back buffer -- measured, not assumed: a boot-time
    * resize silently did nothing at all, because the window it was reported
    * to was still null every time. GameFrame has held aGameWindow since its
    * constructor ran, which is before anything can paint.
    *
    * Takes Object so that nothing in the client package needs to know the
    * browser shim's types exist.
    *
    * @param  frame  the GameFrame, as the browser layer's opaque handle
    * @param  width  frame interior width in pixels
    * @param  height frame interior height in pixels, chat tabs included
    * @return whether the size actually reached a running game
    */
   public static boolean report(Object frame, int width, int height) {
      if (frame instanceof GameFrame && width > 0 && height > 0) {
         GameWindow window = ((GameFrame)frame).aGameWindow;
         if (window != null) {
            window.frameResized(width, height);
            return true;
         }
      }
      return false;
   }

   /**
    * The same narrow handle, for callers that just need the GameWindow
    * itself rather than a one-shot report.
    *
    * Both createImage overrides -- GameWindow's and mudclient's -- forward to
    * {@code gameFrame.createImage(i, j)} rather than rasterising through
    * themselves, so every Component.Backend#offscreenImage call this client
    * ever makes arrives with the GameFrame as {@code c}, never the GameWindow.
    * A backend that checks {@code c instanceof GameWindow} to find one is
    * checking a branch that can never be true -- measured 2026-08-08 as the
    * cause of the browser's mouse wheel doing nothing: DomEvents.wheelTarget
    * was never set, because that was exactly the check it used.
    *
    * @param  frame the GameFrame, as the browser layer's opaque handle
    * @return       the owning GameWindow, or null if frame is not a GameFrame
    *                or its window has not been constructed yet
    */
   public static GameWindow windowFor(Object frame) {
      return frame instanceof GameFrame ? ((GameFrame)frame).aGameWindow : null;
   }
}
