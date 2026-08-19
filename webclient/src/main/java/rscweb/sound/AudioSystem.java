package rscweb.sound;

import java.io.IOException;

import rscweb.io.File;

/*
 * Script-API surface only (Methods.playSoundFile reads a .wav beside the
 * jar). The web client has no jar, no beside-the-jar files, and no scripts;
 * the client's own catch already treats an unplayable sound as a shrug.
 */
public final class AudioSystem {
   private AudioSystem() {
   }

   public static AudioInputStream getAudioInputStream(File file) throws IOException {
      throw new IOException("no sampled audio in the browser");
   }

   public static Clip getClip() throws IOException {
      throw new IOException("no sampled audio in the browser");
   }
}
