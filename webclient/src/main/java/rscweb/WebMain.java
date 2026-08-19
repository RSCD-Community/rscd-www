package rscweb;

import org.rscdaemon.client.mudclient;

/*
 * Browser entry point. mudclient.main is the boot sequence -- config,
 * protocol version, client, window -- and it runs here unmodified. The
 * browser back-ends (canvas, websocket, fetch, image decode) are installed
 * first, so every shim seam answers before the client asks. Headless (Node
 * smoke tests) there is no DOM and nothing installs; the client boots to
 * the render seam and stops there, which is the test.
 */
public final class WebMain {

   private WebMain() {
   }

   public static void main(String[] args) throws Exception {
      if (rscweb.web.WebEnv.hasDom()) {
         rscweb.web.WebLaunch.install();
      }
      mudclient.main(new String[0]);
   }
}
