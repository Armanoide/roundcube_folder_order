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

        if (rcmail::get_instance()->task === 'settings') {
            // Autosave endpoint for the ↑/↓ arrows injected into the folders page (folderorder.js).
            $this->register_action('folderorder-save', [$this, 'save_ajax']);

            // Inline, not include_script (iconizer lesson): registered scripts vanished from re-rendered pages. The JS self-guards on body.action-folders.
            $out = rcmail::get_instance()->output;
            if ($out && $out->type === 'html' && method_exists($out, 'add_script')) {
                $out->add_script((string) @file_get_contents(__DIR__ . '/folderorder.js'));
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
            // Core rows key the raw name under 'folder_imap' — reading 'id' here caused the live 500 (empty ids, infinite recursion).
            $args['list'] = OrderSorter::sort_flat($args['list'], $this->store->all(), $rcmail->get_storage()->get_hierarchy_delimiter(), 'folder_imap');
        }

        return $args;
    }

    /** Persist the posted pre-order list. Broken payload keeps the previous order — a tampered or truncated POST must never wipe the user's layout. */
    public function save_ajax(): void
    {
        $rcmail = rcmail::get_instance();

        if (!$rcmail->check_request_token(rcube_utils::INPUT_POST)) {
            raise_error(['code' => 403, 'message' => 'invalidrequesttoken']);
            return;
        }

        $order = OrderStore::sanitize_json(rcube_utils::get_input_string('_order', rcube_utils::INPUT_POST));

        if ($order === null) {
            $rcmail->output->command('textmessage', ['message' => 'Invalid order payload — nothing saved.', 'target' => 'messagestack']);
            $rcmail->output->send();
            return;
        }

        $rcmail->user->save_prefs([OrderStore::PREFS_KEY => $order]);
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
