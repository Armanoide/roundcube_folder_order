<?php

declare(strict_types=1);

namespace Armanoide\FolderOrder;

/** Re-sort the render_mailboxlist tree by user order. Pure seam: no Roundcube, no prefs, no storage — the whole plugin's brain. */
final class OrderSorter
{
    /** @param array<string,array> $tree hierarchical tree keyed by raw folder segment, each node carrying id + folders @param list<string> $order full folder ids in user order */
    public static function sort(array $tree, array $order): array
    {
        if (empty($tree) || $order === []) {
            return $tree;
        }

        $rank = array_flip($order);

        foreach ($tree as $key => $folder) {
            if (!empty($folder['folders']) && is_array($folder['folders'])) {
                $tree[$key]['folders'] = self::sort($folder['folders'], $order);
            }
        }

        uasort($tree, static function (array $a, array $b) use ($rank): int {
            return self::compare((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''), $rank);
        });

        return $tree;
    }

    /** Ordered ids first by rank, unknown ones after them alphabetically — a new folder must never disappear. */
    private static function compare(string $a, string $b, array $rank): int
    {
        $ra = $rank[$a] ?? null;
        $rb = $rank[$b] ?? null;

        if ($ra !== null && $rb !== null) {
            return $ra <=> $rb;
        }
        if ($ra !== null) {
            return -1;
        }
        if ($rb !== null) {
            return 1;
        }

        return strcmp($a, $b);
    }
}
