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
    public static function sanitize_json(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || array_keys($decoded) !== range(0, count($decoded) - 1)) {
            return null; // object or scalar: not a pre-order list
        }

        $clean = array_values(array_filter($decoded, static fn($v): bool => is_string($v) && $v !== ''));

        return $clean === [] ? null : $clean;
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->order;
    }

    public function isEmpty(): bool
    {
        return $this->order === [];
    }
}
