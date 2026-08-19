package rscweb.sound;

import java.io.IOException;

public interface Clip {
   void addLineListener(LineListener listener);

   void open(AudioInputStream stream) throws IOException;

   void start();

   void stop();

   void close();
}
