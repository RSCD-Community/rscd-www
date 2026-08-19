package rscweb;

/*
 * Stands in for System.exit, which TeaVM has no equivalent for -- a browser
 * page cannot end the process. Throwing kills the calling green thread (the
 * game loop, in every caller), which is as exited as a page gets; the error
 * surfaces on the console instead of vanishing.
 */
public final class Exit {
   private Exit() {
   }

   public static void exit(int status) {
      throw new IllegalStateException("client requested exit(" + status + ")");
   }
}
