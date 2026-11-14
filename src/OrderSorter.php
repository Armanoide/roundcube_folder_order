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

    /** Flat pre-order list (folders_list hook of the settings page): siblings bucketed by parent, DFS emit keeps children contiguous under their parent. Rows key the id under 'folder_imap' there, 'id' in my own settings list. */
    public static function sort_flat(array $list, array $order, string $delim = '/', string $id_key = 'id'): array
    {
        if (empty($list) || $order === []) {
            return $list;
        }

        $rank = array_flip($order);

        $buckets = [];
        foreach ($list as $folder) {
            $id     = (string) ($folder[$id_key] ?? '');
            // level 0 = top level even when the id carries delimiters — special folders are pinned flat by the core.
            $parent = (int) ($folder['level'] ?? 0) === 0 ? '' : self::parent_of($id, $delim);
            $buckets[$parent][] = $folder;
        }

        foreach ($buckets as &$siblings) {
            usort($siblings, static function (array $a, array $b) use ($rank, $id_key): int {
                return self::compare((string) ($a[$id_key] ?? ''), (string) ($b[$id_key] ?? ''), $rank);
            });
        }
        unset($siblings);

        $out = [];
        $seen = [];
        $emit = static function (string $parent) use (&$emit, &$buckets, &$out, &$seen, $id_key): void {
            foreach ($buckets[$parent] ?? [] as $folder) {
                $id = (string) ($folder[$id_key] ?? '');
                // seen: a folder emits once, so no data shape can loop forever (the empty-id live 500 of 2026-11-14).
                if ($id !== '' && isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $out[] = $folder;
                if ($id !== '' && $id !== $parent) {
                    $emit($id);
                }
            }
        };
        $emit('');

        // A bucket whose parent never rendered keeps its rows at the end — never swallow a folder.
        foreach ($buckets as $parent => $siblings) {
            if ($parent !== '' && !self::is_ancestor_chain($parent, $list, $id_key)) {
                foreach ($siblings as $folder) {
                    $id = (string) ($folder[$id_key] ?? '');
                    if ($id !== '' && isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $out[] = $folder;
                }
            }
        }

        return $out;
    }

    private static function parent_of(string $id, string $delim): string
    {
        return str_contains($id, $delim) ? substr($id, 0, strrpos($id, $delim)) : '';
    }

    /** @param list<array> $list */
    private static function is_ancestor_chain(string $parent, array $list, string $id_key = 'id'): bool
    {
        foreach ($list as $folder) {
            if ((string) ($folder[$id_key] ?? '') === $parent) {
                return true;
            }
        }

        return false;
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
