package rscweb.web;

import org.teavm.jso.JSBody;
import org.teavm.jso.JSObject;
import org.teavm.jso.typedarrays.Int32Array;
import org.teavm.jso.typedarrays.Int8Array;

/*
 * PNG/GIF/JPEG decoding via the browser's own decoder: bytes -> Blob -> object
 * URL -> HTMLImageElement -> offscreen canvas -> ImageData.
 *
 * The handshake is a plain JS state object polled from Java, NOT a @JSFunctor
 * callback: TeaVM 0.15 does not marshal a Java lambda into a callable where a
 * JSBody script invokes it ("c is not a function" inside onload, found the
 * hard way). Poll-sleep is the same green-thread seam pattern as the socket
 * and XHR back-ends -- Thread.sleep suspends the fiber, the event loop runs
 * onload, onload writes plain JS fields, the next poll reads them.
 */
public final class WebImages {

   private WebImages() {
   }

   @JSBody(
      params = {"data"},
      script = ""
         + "var state = {done: false, pixels: null, w: 0, h: 0};"
         + "var blob = new Blob([data]);"
         + "var url = URL.createObjectURL(blob);"
         + "var img = new Image();"
         + "img.onload = function() {"
         + "  var c = document.createElement('canvas');"
         + "  c.width = img.width; c.height = img.height;"
         + "  var x = c.getContext('2d');"
         + "  x.drawImage(img, 0, 0);"
         + "  var d = x.getImageData(0, 0, c.width, c.height).data;"
         + "  var n = c.width * c.height;"
         + "  var out = new Int32Array(n);"
         + "  for (var i = 0; i < n; i++) {"
         + "    out[i] = ((d[4*i+3] << 24) | (d[4*i] << 16) | (d[4*i+1] << 8) | d[4*i+2]) | 0;"
         + "  }"
         + "  URL.revokeObjectURL(url);"
         + "  state.pixels = out; state.w = c.width; state.h = c.height; state.done = true;"
         + "};"
         + "img.onerror = function() {"
         + "  URL.revokeObjectURL(url);"
         + "  state.pixels = new Int32Array(1); state.w = 1; state.h = 1; state.done = true;"
         + "};"
         + "img.src = url;"
         + "return state;")
   private static native JSObject decodeStart(Int8Array data);

   @JSBody(params = {"state"}, script = "return state.done;")
   private static native boolean isDone(JSObject state);

   @JSBody(params = {"state"}, script = "return state.w;")
   private static native int stateWidth(JSObject state);

   @JSBody(params = {"state"}, script = "return state.h;")
   private static native int stateHeight(JSObject state);

   @JSBody(params = {"state"}, script = "return state.pixels;")
   private static native Int32Array statePixels(JSObject state);

   public static void decode(byte[] data, int offset, int length, rscweb.awt.Image target) {
      byte[] slice;
      if (offset == 0 && length == data.length) {
         slice = data;
      } else {
         slice = new byte[length];
         System.arraycopy(data, offset, slice, 0, length);
      }

      JSObject state = decodeStart(Int8Array.copyFromJavaArray(slice));
      while (!isDone(state)) {
         try {
            Thread.sleep(10);
         } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            return;
         }
      }

      int w = stateWidth(state);
      int h = stateHeight(state);
      Int32Array pixels = statePixels(state);
      int[] px = new int[w * h];
      for (int i = 0; i < px.length; i++) {
         px[i] = pixels.get(i);
      }

      /* pixels last: it is the "decoded" flag MediaTracker polls. */
      target.width = w;
      target.height = h;
      target.pixels = px;
   }
}
