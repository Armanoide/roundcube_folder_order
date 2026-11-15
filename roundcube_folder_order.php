<?php
// No namespace: Roundcube requires the global class name "roundcube_folder_order" — implementation lives in src/ (Armanoide\FolderOrder).

require_once __DIR__ . '/src/OrderSorter.php';
require_once __DIR__ . '/src/OrderStore.php';

use Armanoide\FolderOrder\OrderSorter;
use Armanoide\FolderOrder\OrderStore;

// Sorts the folder tree server-side in render_mailboxlist — the sidebar HTML arrives already ordered, no client paint needed.
class roundcube_folder_order extends rcube_plugin
{
    public $task = '.*';

    private OrderStore $store;

    /** @var string user id the store is bound to */
    private string $bound_user = '';

    public function init(): void
    {
        $this->add_hook('render_mailboxlist', [$this, 'sort_tree']);
        // The settings folders page builds its own flat list — same order, flat variant.
        $this->add_hook('folders_list', [$this, 'sort_flat_list']);
        // Renames keep their position, deletes stop littering prefs (renames can fire from the mail sidebar too).
        $this->add_hook('folder_rename', [$this, 'on_folder_rename']);
        // The edit-folder form renames through folder_update, never folder_rename — same migration, record shape.
        $this->add_hook('folder_update', [$this, 'on_folder_update']);
        $this->add_hook('folder_delete', [$this, 'on_folder_delete']);

        if (rcmail::get_instance()->task === 'settings') {
            // Autosave endpoint for the ↑/↓ arrows injected into the folders page (folderorder.js).
            $this->register_action('folderorder-save', [$this, 'save_ajax']);

            // Inline, not include_script (iconizer lesson): registered scripts vanished from re-rendered pages. The JS self-guards on body.action-folders.
            $out = rcmail::get_instance()->output;
            if ($out && $out->type === 'html') {
                $js = file_get_contents(__DIR__ . '/folderorder.js');
                if ($js === false) {
                    error_log('roundcube_folder_order: folderorder.js unreadable — arrows will not render');
                } else {
                    $out->add_script($js);
                }
            }
        }
    }

    public function sort_tree($args)
    {
        $this->bind_store();

        if ($this->store->isEmpty()) {
            return $args; // no order set: the core's alphabetical sort stands, zero behavior change
        }

        if (isset($args['list']) && is_array($args['list'])) {
            $args['list'] = OrderSorter::sort($args['list'], $this->store->all());
        }

        return $args;
    }

    public function sort_flat_list($args)
    {
        $this->bind_store();

        if ($this->store->isEmpty()) {
            return $args;
        }

        $rcmail = rcmail::get_instance();
        if (isset($args['list']) && is_array($args['list'])) {
            // regression: core folders_list keys rows by 'folder_imap' — reading 'id' yields empty ids and infinite recursion.
            $args['list'] = OrderSorter::sort_flat($args['list'], $this->store->all(), $rcmail->get_storage()->get_hierarchy_delimiter(), 'folder_imap');
        }

        return $args;
    }

    /** Migrate the stored position of a renamed folder (and its subfolders) to the new name.
     *  Core fires this hook BEFORE the storage rename runs: if the server fails, the prefs entry is orphaned and the folder falls back to alphabetical — cosmetic, self-corrects on next save. */
    public function on_folder_rename($args)
    {
        $this->bind_store();

        $old = (string) ($args['oldname'] ?? '');
        $new = (string) ($args['newname'] ?? '');

        if ($old !== '' && $new !== '' && $old !== $new) {
            $before = $this->store->all();
            $this->store->rename($old, $new, rcmail::get_instance()->get_storage()->get_hierarchy_delimiter());
            $this->persistIfChanged($before);
        }

        return $args;
    }

    /** Edit-folder form path (folder_save): core fires folder_update with the new name under record['name'], old under record['oldname']. */
    public function on_folder_update($args)
    {
        $record = (array) ($args['record'] ?? []);
        // Return $args untouched: folder_save reads back $plugin['record']; we only piggyback the migration.
        $this->on_folder_rename([
            'oldname' => (string) ($record['oldname'] ?? ''),
            'newname' => (string) ($record['name'] ?? ''),
        ]);

        return $args;
    }

    /** Forget the stored position of a deleted folder (and its subfolders). */
    public function on_folder_delete($args)
    {
        $this->bind_store();

        $name = (string) ($args['name'] ?? '');
        if ($name !== '') {
            $before = $this->store->all();
            $this->store->forgetTree($name, rcmail::get_instance()->get_storage()->get_hierarchy_delimiter());
            $this->persistIfChanged($before);
        }

        return $args;
    }

    /** Save prefs only when a rename/delete actually touched the order — no needless writes. */
    private function persistIfChanged(array $before): void
    {
        if ($this->store->all() !== $before) {
            rcmail::get_instance()->user->save_prefs([OrderStore::PREFS_KEY => $this->store->all()]);
        }
    }

    /** Persist the posted pre-order list. Broken payload keeps the previous order — a tampered or truncated POST must never wipe the user's layout. */
    public function save_ajax(): void
    {
        $rcmail = rcmail::get_instance();

        // CSRF handled by core: request_security_check() runs before exec_action.

        $order = OrderStore::sanitize_json(rcube_utils::get_input_string('_order', rcube_utils::INPUT_POST));

        if ($order === null) {
            $rcmail->output->command('textmessage', ['message' => 'Invalid order payload — nothing saved.', 'target' => 'messagestack']);
            $rcmail->output->send();
            return;
        }

        if (!$rcmail->user->save_prefs([OrderStore::PREFS_KEY => $order])) {
            $rcmail->output->command('textmessage', ['message' => 'Saving failed — the order was not stored.', 'target' => 'messagestack']);
        }
        $rcmail->output->send();
    }

    /** Bind the store to the authenticated user (id exists post-login). */
    private function bind_store(): void
    {
        $rcmail = rcmail::get_instance();
        // A null rcmail/user pre-session would fatal on ->ID and silently kill the hook.
        $user = (string) ($rcmail && $rcmail->user ? $rcmail->user->ID : 'guest');
        if (isset($this->store) && $user === $this->bound_user) {
            return;
        }

        $this->bound_user = $user;
        $this->store      = new OrderStore();

        $prefs = ($rcmail && $rcmail->user) ? (array) $rcmail->user->get_prefs() : [];
        $this->store->hydrate($prefs[OrderStore::PREFS_KEY] ?? []);
    }
}
