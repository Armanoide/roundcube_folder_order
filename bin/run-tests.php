<?php

declare(strict_types=1);

/** Test runner — no framework, no Roundcube. Run: php bin/run-tests.php. Targets the OrderSorter/OrderStore seams: external behaviour only. */

require __DIR__ . '/../src/OrderSorter.php';
require __DIR__ . '/../src/OrderStore.php';

use Armanoide\FolderOrder\OrderSorter;
use Armanoide\FolderOrder\OrderStore;

$pass = 0;
$fail = 0;

function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}\n";
    }
}

/** Tree shaped exactly like render_mailboxlist: keyed by segment, id = full path, folders = subtree. */
function fixture(): array
{
    return [
        'Archive' => [
            'id'      => 'Archive',
            'name'    => 'Archive',
            'virtual' => false,
            'folders' => [
                '2025' => ['id' => 'Archive/2025', 'name' => '2025', 'virtual' => false, 'folders' => []],
                '2026' => ['id' => 'Archive/2026', 'name' => '2026', 'virtual' => false, 'folders' => []],
            ],
        ],
        'Drafts'  => ['id' => 'Drafts', 'name' => 'Drafts', 'virtual' => false, 'folders' => []],
        'INBOX'   => ['id' => 'INBOX', 'name' => 'INBOX', 'virtual' => false, 'folders' => []],
        'Work'    => ['id' => 'Work', 'name' => 'Work', 'virtual' => false, 'folders' => []],
    ];
}

function ids(array $tree): array
{
    return array_map(static fn(array $n): string => $n['id'], array_values($tree));
}

echo "OrderSorter\n";

// Empty order: the core's alphabetical tree must stand untouched.
check('empty order leaves tree unchanged', OrderSorter::sort(fixture(), []) === fixture());

// Known ids float to the front in rank order; unknown ones follow alphabetically.
$sorted = OrderSorter::sort(fixture(), ['Work', 'INBOX']);
check('known ids ordered, unknown after alphabetically', ids($sorted) === ['Work', 'INBOX', 'Archive', 'Drafts']);

// Recursion: a subfolder rank reorders inside its parent, independently of the top level.
$sorted = OrderSorter::sort(fixture(), ['Archive/2026']);
check('subfolders reorder under their parent', ids($sorted['Archive']['folders']) === ['Archive/2026', 'Archive/2025']);

// A new folder never disappears: absent from order, it still renders (alphabetical among unknowns).
$tree = fixture();
$tree['New'] = ['id' => 'New', 'name' => 'New', 'virtual' => false, 'folders' => []];
$sorted = OrderSorter::sort($tree, ['INBOX']);
check('unlisted folder survives', in_array('New', ids($sorted), true));

// Keys preserved: the associative segment key must survive uasort (rendering and rename depend on it).
$sorted = OrderSorter::sort(fixture(), ['Work']);
check('associative keys preserved', array_keys($sorted) === ['Work', 'Archive', 'Drafts', 'INBOX']);

// Unknown id in the order list is simply ignored, never fatal.
check('unknown ordered id ignored', ids(OrderSorter::sort(fixture(), ['Ghost'])) === ['Archive', 'Drafts', 'INBOX', 'Work']);

echo "OrderStore\n";

$s = new OrderStore();
check('fresh store is empty', $s->isEmpty());

$s->hydrate(['INBOX', 'Work']);
check('hydrate keeps order', $s->all() === ['INBOX', 'Work']);

$s->hydrate(['INBOX', 42, null, 'Work']);
check('hydrate drops non-strings', $s->all() === ['INBOX', 'Work']);

$s->hydrate('not-an-array');
check('hydrate junk => empty', $s->isEmpty());

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
