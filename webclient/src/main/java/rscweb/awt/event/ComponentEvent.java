package rscweb.awt.event;

import rscweb.awt.AWTEvent;
import rscweb.awt.Component;

public class ComponentEvent extends AWTEvent {
   public static final int COMPONENT_MOVED = 100;
   public static final int COMPONENT_RESIZED = 101;
   public static final int COMPONENT_SHOWN = 102;
   public static final int COMPONENT_HIDDEN = 103;

   public ComponentEvent(Component source, int id) {
      super(source, id);
   }

   public Component getComponent() {
      return (Component) getSource();
   }
}
