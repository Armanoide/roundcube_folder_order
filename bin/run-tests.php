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

echo "OrderSorter::sort_flat\n";

/** Flat pre-order rows as the folders_list hook delivers them: id + level, special folders pinned at level 0. */
function flat_fixture(): array
{
    return [
        ['id' => 'INBOX', 'level' => 0],
        ['id' => 'Administration', 'level' => 0],
        ['id' => 'Bankings', 'level' => 0],
        ['id' => 'PRO.Clients', 'level' => 1],
        ['id' => 'PRO.Insurance', 'level' => 1],
    ];
}

function flat_ids(array $rows): array
{
    return array_column($rows, 'id');
}

check('flat: empty order leaves list unchanged', flat_ids(OrderSorter::sort_flat(flat_fixture(), [])) === flat_ids(flat_fixture()));

// Swap two top-level folders: children of the moved parent stay contiguous under it.
$flat = OrderSorter::sort_flat(flat_fixture(), ['Bankings', 'INBOX'], '.');
check('flat: ordered roots first by rank', array_slice(flat_ids($flat), 0, 2) === ['Bankings', 'INBOX']);
check('flat: children stay under their parent', flat_ids($flat) === ['Bankings', 'INBOX', 'Administration', 'PRO.Clients', 'PRO.Insurance']);

// Sibling reorder inside a parent.
$flat = OrderSorter::sort_flat(flat_fixture(), ['PRO.Insurance'], '.');
check('flat: siblings reorder under parent', array_slice(flat_ids($flat), -2) === ['PRO.Insurance', 'PRO.Clients']);

// A special folder pinned at level 0 with a dotted id must bucket at top, not under INBOX.
$special = [['id' => 'INBOX', 'level' => 0], ['id' => 'INBOX.Drafts', 'level' => 0]];
$flat = OrderSorter::sort_flat($special, ['INBOX.Drafts'], '.');
check('flat: level-0 special buckets at top despite dotted id', flat_ids($flat) === ['INBOX.Drafts', 'INBOX']);

// Orphan bucket (parent absent from the list) never swallows rows.
$flat = OrderSorter::sort_flat([['id' => 'Ghost.X', 'level' => 1]], ['Ghost.X'], '.');
check('flat: orphan rows survive', flat_ids($flat) === ['Ghost.X']);

// Regression: the core folders_list hook keys rows by 'folder_imap', not 'id'. Reading the wrong key yields empty ids and infinite recursion (live 500, 2026-11-14).
function core_flat_fixture(): array
{
    return [
        ['folder_imap' => 'INBOX', 'level' => 0],
        ['folder_imap' => 'Administration', 'level' => 0],
        ['folder_imap' => 'Bankings', 'level' => 0],
        ['folder_imap' => 'PRO.Clients', 'level' => 1],
    ];
}

$flat = OrderSorter::sort_flat(core_flat_fixture(), ['Bankings', 'INBOX'], '.', 'folder_imap');
check('flat: core folder_imap shape sorts, no recursion blowup', array_column($flat, 'folder_imap') === ['Bankings', 'INBOX', 'Administration', 'PRO.Clients']);

// The live 500 case: folders_list rows have no 'id' key. Old code recursed emit('') forever; seen-tracking must terminate and keep every row once.
$nokey = [
    ['folder_imap' => 'INBOX', 'level' => 0],
    ['folder_imap' => 'Drafts', 'level' => 0],
    ['folder_imap' => 'PRO.Clients', 'level' => 1],
];
$flat = OrderSorter::sort_flat($nokey, ['Drafts'], '.', 'folder_imap');
check('flat: no-id-key rows terminate, all preserved', count($flat) === 3 && array_column($flat, 'folder_imap') === ['Drafts', 'INBOX', 'PRO.Clients']);

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
