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
