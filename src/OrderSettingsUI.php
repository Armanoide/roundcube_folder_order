<?php

declare(strict_types=1);

namespace Armanoide\FolderOrder;

/** Settings section "Folder order": flat pre-order list of subscribed folders, ↑/↓ swaps adjacent rows, one hidden JSON input rides the core's save-prefs POST. */
final class OrderSettingsUI
{
    public function __construct(private string $jsSource)
    {
    }

    public function sectionsList($p)
    {
        $p['list']['folderorder'] = ['id' => 'folderorder', 'section' => 'Folder order'];

        return $p;
    }

    public function list($p)
    {
        if (($p['section'] ?? '') !== 'folderorder') {
            return $p;
        }

        if (empty($p['current'])) {
            $p['blocks']['main']['content'] = true;

            return $p;
        }

        $rcmail    = \rcmail::get_instance();
        $storage   = $rcmail->get_storage();
        $delimiter = $storage->get_hierarchy_delimiter();

        $rows = self::flat_rows($storage->list_folders_subscribed('', '*', null, true), $delimiter);
        $rows = OrderSorter::sort_flat($rows, self::current_order(), $delimiter);

        $html = '<div id="rcfo-list">' . self::render_rows($rows, $delimiter) . '</div>'
            . '<input type="hidden" name="_folder_order" id="_folder_order" value="'
            . htmlspecialchars(json_encode(array_column($rows, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG), ENT_QUOTES) . '">';

        // Inline, not include_script: the core re-renders this form after a failed save without registered scripts (iconizer lesson).
        $html .= '<script>' . $this->jsSource . '</script>';

        $p['blocks']['main'] = [
            'id'      => 'folderorder',
            'name'    => 'Folder order',
            'content' => $html,
        ];

        return $p;
    }

    /** @return array{prefs:array} */
    public function save($p)
    {
        if (($p['section'] ?? '') !== 'folderorder') {
            return $p;
        }

        $raw   = (string) \rcube_utils::get_input_string('_folder_order', \rcube_utils::INPUT_POST);
        $order = json_decode($raw, true);

        if (!is_array($order)) {
            $order = []; // broken payload from a tampered form: keep the previous order, never wipe
        }

        $order = array_values(array_filter($order, static fn($v): bool => is_string($v) && $v !== ''));

        $p['prefs']['folder_order'] = $order; // the core shows the confirmation itself

        return $p;
    }

    /** @param list<string> $folders raw IMAP names @return list<array{id:string,name:string,level:int}> */
    public static function flat_rows(array $folders, string $delimiter): array
    {
        $rows = [];
        foreach ($folders as $id) {
            $rows[] = [
                'id'    => (string) $id,
                'name'  => \rcube_charset::convert((string) $id, 'UTF7-IMAP'),
                'level' => substr_count((string) $id, $delimiter),
            ];
        }

        return $rows;
    }

    private static function current_order(): array
    {
        $rcmail = \rcmail::get_instance();

        return array_values(array_filter((array) ($rcmail->user->get_prefs()[OrderStore::PREFS_KEY] ?? []), 'is_string'));
    }

    /** @param list<array> $rows */
    private static function render_rows(array $rows, string $delimiter): string
    {
        $out = '';
        foreach ($rows as $row) {
            $id    = htmlspecialchars((string) $row['id'], ENT_QUOTES);
            $name  = htmlspecialchars((string) $row['name'], ENT_QUOTES);
            $level = (int) $row['level'];

            $out .= '<div class="rcfo-row" data-folder="' . $id . '" style="padding-left:' . ($level * 18) . 'px">'
                . '<span class="rcfo-name">' . $name . '</span>'
                . '<button type="button" class="btn rcfo-up" aria-label="Move up">↑</button>'
                . '<button type="button" class="btn rcfo-down" aria-label="Move down">↓</button>'
                . '</div>';
        }

        return $out;
    }
}
