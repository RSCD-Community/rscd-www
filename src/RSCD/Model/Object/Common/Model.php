<?php

namespace RSCD\Model\Object\Common;

/**
 * Base Eloquent model for all RSCD domain objects.
 *
 * Extends the Roots framework ObjectModel to provide shared utility methods
 * used across manufacturing, warehouse, and customer-facing models. Utilities
 * here deal primarily with label/document generation: converting HTML to PDF,
 * PDF to ZPL (Zebra label format), and PNG to ZPL for barcode label printing.
 *
 * All conversion methods are protected and static so subclasses can invoke
 * them without instantiating helper objects directly.
 */
class Model extends \RSCD\Model\ObjectModelBase {

    /**
     * Maximum value for a signed 32-bit integer column (safe upper bound).
     *
     * @var int
     */
    const SIGNED_INT_32 = 2147483646;

    /**
     * Maximum value for a signed 16-bit integer column.
     *
     * @var int
     */
    const SIGNED_INT_16 = 32767;

    /**
     * Maximum value for a signed 8-bit integer column.
     *
     * @var int
     */
    const SIGNED_INT_8 = 127;

}
