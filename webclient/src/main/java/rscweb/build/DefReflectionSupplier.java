package rscweb.build;

import java.util.ArrayList;
import java.util.Collection;
import java.util.Collections;

import org.teavm.classlib.ReflectionContext;
import org.teavm.classlib.ReflectionSupplier;
import org.teavm.model.ClassReader;
import org.teavm.model.FieldReader;
import org.teavm.model.MethodDescriptor;
import org.teavm.model.ValueType;

/*
 * Compile-time service, not app code: JavaScriptTarget.contributeDependencies
 * ServiceLoader-loads org.teavm.classlib.ReflectionSupplier from the build
 * classpath. (The newer ReflectionPolicy SPI is loaded too but its answers
 * never reach the JS backend's dependency listener -- found empirically; this
 * older SPI is the one that works.)
 *
 * XmlObjects binds the def XML with narrow reflection -- Class.forName on the
 * alias table, getDeclaredConstructor().newInstance(), getField().set() on
 * public fields. TeaVM strips all of that by default; this supplier retains it
 * for exactly the entityhandling package (the def classes plus EntityDef,
 * whose public name/description fields the subclasses inherit).
 *
 * This supplier is necessary but not sufficient: the compiler only emits the
 * metadata when concrete class constants reach the reflection call receivers
 * through dependency analysis (a dynamic forName string never does -- its
 * result node is seeded once, before these answers land). rscweb.lang.Classes
 * provides those constants; prepare-sources.sh rewrites XmlObjects onto it.
 */
public class DefReflectionSupplier implements ReflectionSupplier {

   private static final String PKG = "org.rscdaemon.client.entityhandling.";

   @Override
   public Collection<String> getAccessibleFields(ReflectionContext context, String className) {
      if (!className.startsWith(PKG)) {
         return Collections.emptyList();
      }
      ClassReader cls = context.getClassSource().get(className);
      if (cls == null) {
         return Collections.emptyList();
      }
      Collection<String> fields = new ArrayList<>();
      for (FieldReader field : cls.getFields()) {
         fields.add(field.getName());
      }
      return fields;
   }

   @Override
   public Collection<MethodDescriptor> getAccessibleMethods(ReflectionContext context, String className) {
      if (!className.startsWith(PKG)) {
         return Collections.emptyList();
      }
      return Collections.singletonList(new MethodDescriptor("<init>", ValueType.VOID));
   }

   @Override
   public boolean isClassFoundByName(ReflectionContext context, String className) {
      return className.startsWith(PKG);
   }
}
