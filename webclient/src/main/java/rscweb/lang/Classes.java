package rscweb.lang;

import java.util.HashMap;
import java.util.Map;

/*
 * Class.forName replacement for XmlObjects under TeaVM (prepare-sources
 * rewrites that one call site to Classes.forName).
 *
 * Why not the real forName: TeaVM's ReflectionDependencyListener seeds a
 * forName call's result node with classesFoundByName ONCE, at methodReached
 * time — classes our ReflectionSupplier marks later never flow downstream, so
 * getField/getDeclaredConstructor receivers see no concrete types and the
 * field/ctor metadata emitters (which hang off those nodes) never fire.
 *
 * Class literals fix both halves at once: each NPCDef.class here is a class
 * constant the dependency analyzer propagates through the map into
 * XmlObjects' getField()/getDeclaredConstructor() receivers, which (a) makes
 * DefReflectionSupplier's field/ctor answers actually get consulted and
 * emitted, and (b) makes the runtime lookup a plain map hit — no name-map
 * dependence at all.
 *
 * The key set is exactly PersistenceManager's alias table plus EntityDef
 * (superclass; harmless to include, and future-proofs subclass binding).
 */
public final class Classes {

   private static final Map<String, Class<?>> MAP = new HashMap<>();

   static {
      MAP.put("org.rscdaemon.client.entityhandling.defs.NPCDef",
            org.rscdaemon.client.entityhandling.defs.NPCDef.class);
      MAP.put("org.rscdaemon.client.entityhandling.defs.ItemDef",
            org.rscdaemon.client.entityhandling.defs.ItemDef.class);
      MAP.put("org.rscdaemon.client.entityhandling.defs.SpellDef",
            org.rscdaemon.client.entityhandling.defs.SpellDef.class);
      MAP.put("org.rscdaemon.client.entityhandling.defs.PrayerDef",
            org.rscdaemon.client.entityhandling.defs.PrayerDef.class);
      MAP.put("org.rscdaemon.client.entityhandling.defs.TileDef",
            org.rscdaemon.client.entityhandling.defs.TileDef.class);
      MAP.put("org.rscdaemon.client.entityhandling.defs.DoorDef",
            org.rscdaemon.client.entityhandling.defs.DoorDef.class);
      MAP.put("org.rscdaemon.client.entityhandling.defs.ElevationDef",
            org.rscdaemon.client.entityhandling.defs.ElevationDef.class);
      MAP.put("org.rscdaemon.client.entityhandling.defs.GameObjectDef",
            org.rscdaemon.client.entityhandling.defs.GameObjectDef.class);
      MAP.put("org.rscdaemon.client.entityhandling.defs.EntityDef",
            org.rscdaemon.client.entityhandling.defs.EntityDef.class);
      MAP.put("org.rscdaemon.client.entityhandling.defs.extras.TextureDef",
            org.rscdaemon.client.entityhandling.defs.extras.TextureDef.class);
      MAP.put("org.rscdaemon.client.entityhandling.defs.extras.AnimationDef",
            org.rscdaemon.client.entityhandling.defs.extras.AnimationDef.class);
      MAP.put("org.rscdaemon.client.entityhandling.defs.extras.ItemDropDef",
            org.rscdaemon.client.entityhandling.defs.extras.ItemDropDef.class);
   }

   private Classes() {
   }

   public static Class<?> forName(String name) throws ClassNotFoundException {
      Class<?> cls = MAP.get(name);
      if (cls == null) {
         throw new ClassNotFoundException(name);
      }
      return cls;
   }

   /*
    * Class.getField replacement (prepare-sources rewrites XmlObjects' one call
    * site). TeaVM 0.15's TClass.findField has a broken visited-set: it adds
    * the FIELD NAME instead of the class, so the superclass recursion sees
    * "already visited" on its first step and inherited public fields (EntityDef
    * .name/.description) can never resolve. getDeclaredField doesn't recurse
    * and is unaffected; walk the chain ourselves.
    */
   public static java.lang.reflect.Field publicField(Class<?> type, String name)
         throws NoSuchFieldException {
      for (Class<?> c = type; c != null; c = c.getSuperclass()) {
         try {
            java.lang.reflect.Field field = c.getDeclaredField(name);
            if (java.lang.reflect.Modifier.isPublic(field.getModifiers())) {
               return field;
            }
         } catch (NoSuchFieldException e) {
            // keep walking
         }
      }
      throw new NoSuchFieldException(name);
   }
}
