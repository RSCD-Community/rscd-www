package rscweb.awt.datatransfer;

/*
 * Just enough of java.awt.datatransfer for CalculatorPanel's paste(). The
 * only flavor anything asks for is stringFlavor, and only to pass it to
 * Clipboard.getData.
 */
public class DataFlavor {
   public static final DataFlavor stringFlavor = new DataFlavor();
}
