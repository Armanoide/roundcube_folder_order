# Roundcube Folder Order

Manual folder ordering in the Roundcube Elastic sidebar — drag-free ↑/↓ arrows in Settings → Folders, sorted server-side, stored per user.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

## Features

- **Reorder arrows** in Settings → *Folders* — one click moves a row (its subtree travels with it), autosaved instantly
- **Server-side sort** — the sidebar HTML arrives already ordered; no client repaint, no flicker
- **Per-user persistence** — the order lives in your Roundcube user preferences, synced across devices
- **Follows your folders** — renames keep the position (inline and edit-form), deletes clean up prefs (subtree included)
- **Never wipes** — a broken or truncated POST keeps your previous order

## Requirements

- Roundcube **1.6+** with the **Elastic** skin
- PHP **8.1+**

## Installation

1. Put the plugin folder into your Roundcube `plugins` directory:

```bash
git clone https://github.com/Armanoide/roundcube_folder_order.git \
    /var/www/html/plugins/roundcube_folder_order
```

2. Enable it in `config/config.inc.php`:

```php
$config['plugins'] = [
    // ... other plugins ...
    'roundcube_folder_order', // <-- add this line
];
```

### Docker

```yaml
services:
  roundcubemail:
    image: roundcube/roundcubemail:latest
    volumes:
      - ./plugins/roundcube_folder_order:/var/www/html/plugins/roundcube_folder_order
```

## Configuration

None. Install, enable, reorder.

## Usage

**Reorder folders** — Settings → *Folders* → click the ↑/↓ arrows on any row. The order saves immediately — no form, no Save button. Folders missing from your saved order (new ones) appear after the ordered ones, alphabetically — nothing ever disappears.

## How it works

- `render_mailboxlist` hook — re-sorts the sidebar tree by the saved order
- `folders_list` hook — same sort, flat variant for the settings page (rows keyed by `folder_imap`)
- `folder_rename` / `folder_delete` hooks — migrate or drop the saved positions, subtree included
- `plugin.folderorder-save` action — autosave endpoint for the arrows; CSRF is handled by core
- The order lives in the user preference `folder_order`: a flat pre-order list of raw IMAP folder names

## Known limitations

- The arrow layout is pixel-tuned for the **Elastic** skin; other skins show the reorder functionality without the styling.
- Your custom order also applies to folder dropdowns (move/copy dialogs, filter editor) — they share the sidebar sort. Consistency was chosen over alphabetical there.

## License

[MIT](LICENSE) © 2026 Armanoide
