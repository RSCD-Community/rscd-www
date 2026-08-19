package rscweb.web;

import org.teavm.jso.JSBody;
import org.teavm.jso.JSObject;
import org.teavm.jso.typedarrays.ArrayBuffer;

/*
 * The one place raw JS leaks in. hasDom() gates the whole browser back-end:
 * the same artifact runs headless under Node (smoke tests) where installing
 * canvas hooks would throw before main() ever printed a line.
 */
public final class WebEnv {

   private WebEnv() {
   }

   @JSBody(script = "return typeof document !== 'undefined' && typeof window !== 'undefined';")
   public static native boolean hasDom();

   /*
    * TeaVM erases JSO types at runtime but a Java-level cast from JSObject to
    * an overlay class is not guaranteed across teavm versions; a JS identity
    * function is.
    */
   @JSBody(params = "o", script = "return o;")
   public static native ArrayBuffer asArrayBuffer(JSObject o);
}
