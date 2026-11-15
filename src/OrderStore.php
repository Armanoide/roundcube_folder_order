<?php

declare(strict_types=1);

namespace Armanoide\FolderOrder;

/** Ordered list of full folder ids, raw IMAP names never re-encoded (iconizer rule). Working copy; prefs are the source of truth. */
final class OrderStore
{
    /** User preference key holding the ordered list of full folder ids. */
    public const PREFS_KEY = 'folder_order';

    /** @var list<string> */
    private array $order = [];

    /** Accept only strings; a hand-edited prefs JSON may carry junk, junk must not kill the sidebar. */
    public function hydrate(mixed $prefs): void
    {
        if (!is_array($prefs)) {
            $this->order = [];
            return;
        }

        $this->order = array_values(array_filter($prefs, 'is_string'));
    }

    /** Decode an autosave POST payload; null on broken JSON so the caller keeps the previous order, never wipes. */
    public static function sanitize_json(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || array_keys($decoded) !== range(0, count($decoded) - 1)) {
            return null; // object or scalar: not a pre-order list
        }

        $clean = array_values(array_filter($decoded, static fn($v): bool => is_string($v) && $v !== ''));

        // dedup server-side too: a search-clone POST (or an old client) can carry the same folder twice, duplicate ranks corrupt the sort
        return $clean === [] ? null : array_values(array_unique($clean));
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->order;
    }

    /** Rewrite a renamed folder and its whole subtree in place — positions travel with it. */
    public function rename(string $oldFolder, string $newFolder, string $delimiter = '/'): void
    {
        $prefix = $oldFolder . $delimiter;
        foreach ($this->order as $i => $folder) {
            if ($folder === $oldFolder || str_starts_with($folder, $prefix)) {
                $this->order[$i] = $newFolder . substr($folder, strlen($oldFolder));
            }
        }
    }

    /** Drop a deleted folder and its whole subtree from the list. */
    public function forgetTree(string $folder, string $delimiter = '/'): void
    {
        $prefix = $folder . $delimiter;
        $this->order = array_values(array_filter(
            $this->order,
            static fn(string $f): bool => $f !== $folder && !str_starts_with($f, $prefix)
        ));
    }

    public function isEmpty(): bool
    {
        return $this->order === [];
    }
}
