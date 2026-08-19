<?php

namespace RSCD\Util;

/**
 * Comparator functions for use with usort() / uasort() across the RSCD domain.
 *
 * All methods follow the PHP comparator contract: return a negative integer,
 * zero, or a positive integer to indicate less-than, equal, or greater-than.
 *
 * Methods are static and stateless so they can be passed directly as callable
 * strings: usort($items, [Sort::class, 'lineItemByArtworkArtboardHeightDesc']).
 */
class Sort {

    /**
     * Compare two order line-items by their first artwork's artboard height, descending.
     *
     * Business purpose: When imposing heat-transfer sheets, taller artwork must
     * be placed first so that sheet nesting works from largest to smallest. This
     * keeps waste minimal on the cut table.
     *
     * Tie-breaking: When two artworks share the same artboard_height, the one
     * with greater total area (width × height) sorts first. This secondary sort
     * catches artworks of equal height but different width.
     *
     * Edge cases:
     * - If $x has no artwork, $x sorts after $y (returns 1) — artwork-less
     *   items sink to the bottom so imposition works largest-to-smallest.
     * - If $y has no artwork (but $x does), $y sorts after $x (returns -1).
     *
     * @param  object  $x  Line-item object with an `artwork` array property.
     * @param  object  $y  Line-item object with an `artwork` array property.
     * @return int         Negative if $x sorts before $y, positive if after, 0 if equal.
     */
    public static function lineItemByArtworkArtboardHeightDesc($x, $y) {
        // Items without artwork sink to the bottom so imposition order is unaffected.
        if(empty($x->artwork[0]->id)) {
            return 1;
        }
        if(empty($y->artwork[0]->id)) {
            return -1;
        }

        $a = $x->artwork[0];
        $b = $y->artwork[0];

        // Primary sort: artboard_height descending.
        if($a->artboard_height === $b->artboard_height) {
            // Secondary sort: total area descending.
            return ($b->artboard_width * $b->artboard_height) - ($a->artboard_width * $a->artboard_height);
        }
        return $b->artboard_height - $a->artboard_height;
    }

    /**
     * Compare two print-rotation entries in ascending order.
     *
     * Supports two naming conventions for the index fields, checking for the
     * more generic `index`/`subindex` pair first, then falling back to
     * `rotation_index`/`subrotation_index`.
     *
     * Sort logic:
     * 1. The numeric index is compared as integers (e.g., 1 < 2 < 10).
     * 2. The string subindex is compared with strnatcmp() so that "A" < "B"
     *    and "A2" < "A10" (natural order, not lexicographic).
     *
     * If neither naming convention is present, entries are considered equal
     * and their relative order is left to the sort algorithm (unstable).
     *
     * @param  object  $x  Rotation entry with index+subindex or rotation_index+subrotation_index.
     * @param  object  $y  Rotation entry with the same set of fields as $x.
     * @return int         Negative if $x sorts before $y, positive if after, 0 if equal.
     */
    public static function printRotationAsc($x, $y) {
        // Try the shorter `index` / `subindex` field names first.
        if(isset($x->index) && isset($x->subindex) && isset($y->index) && isset($y->subindex)) {
            if((int)$x->index > (int)$y->index) {
                return 1;
            }
            if((int)$x->index < (int)$y->index) {
                return -1;
            }
            // Same numeric index — compare subindex with natural string ordering.
            return strnatcmp($x->subindex, $y->subindex);
        }
        // Fall back to `rotation_index` / `subrotation_index` naming convention.
        if(isset($x->rotation_index) && isset($x->subrotation_index) && isset($y->rotation_index) && isset($y->subrotation_index)) {
            if((int)$x->rotation_index > (int)$y->rotation_index) {
                return 1;
            }
            if((int)$x->rotation_index < (int)$y->rotation_index) {
                return -1;
            }
            // Same rotation_index — compare subrotation_index with natural ordering.
            return strnatcmp($x->subrotation_index, $y->subrotation_index);
        }
        // No recognized fields present; treat as equal.
        return 0;
    }

}
