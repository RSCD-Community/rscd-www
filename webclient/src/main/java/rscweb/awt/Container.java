package rscweb.awt;

import java.util.ArrayList;
import java.util.List;

public class Container extends Component {
   private final List<Component> children = new ArrayList<Component>();

   public Component add(Component c) {
      children.add(c);
      c.parent = this;
      return c;
   }

   public Component add(String name, Component c) {
      return add(c);
   }

   public void remove(Component c) {
      children.remove(c);
      c.parent = null;
   }

   public void removeAll() {
      for (Component c : children) {
         c.parent = null;
      }
      children.clear();
   }

   public int getComponentCount() {
      return children.size();
   }

   public Component getComponent(int n) {
      return children.get(n);
   }

   public Insets getInsets() {
      return new Insets(0, 0, 0, 0);
   }

   public Insets insets() {
      return getInsets();
   }

   public void setLayout(Object mgr) {
   }
}
