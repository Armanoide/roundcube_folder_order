// Folders page arrows: ↑/↓ swaps a row (subtree travels with it) against its orderable neighbor, autosaves the pre-order list via AJAX. No form, no Save button.
(function () {
    'use strict';

    var LIST = '#subscription-table';
    var busy = false;

    function injectStyle() {
        if (document.getElementById('rcfo-style')) return;
        var s = document.createElement('style');
        s.id = 'rcfo-style';
        // Elastic pins .custom-switch at right:0; arrows take the edge slot, checkbox shifts left. shortcut: pixel-tuned for Elastic, revisit if another skin is used.
        s.textContent = '#subscription-table li.mailbox{position:relative}'
            + '#subscription-table li.mailbox > .custom-switch{right:3.4rem}'
            + '#subscription-table li.mailbox > .rcfo-arrows{position:absolute;top:0;right:.25rem;display:flex;align-items:center;height:100%;gap:.15rem}'
            + '#subscription-table .rcfo-arrows button{border:0;background:transparent;color:inherit;cursor:pointer;font-size:.9rem;line-height:1;padding:.35rem .3rem;min-width:1.5rem;min-height:1.5rem}'
            + '#subscription-table .rcfo-arrows button[disabled]{opacity:.25;cursor:default}';
        document.head.appendChild(s);
    }

    // offsetParent null = row hidden by the search filter: swapping with an invisible neighbor would change prefs with zero feedback.
    function orderable(li) {
        if (!li || !li.classList.contains('mailbox') || li.classList.contains('root')) return false;
        // treelist search clones (clone(true,true)) mirror a real row incl. its checkbox: collectOrder would POST that folder twice and corrupt the order.
        if (li.classList.contains('searchresult__')) return false;
        if (li.offsetParent === null) return false;
        // special folders carry a DISABLED checkbox (core locks unsubscribe, folders.php:156) — reorder stays legal.
        return !!li.querySelector(':scope > .custom-switch input');
    }

    function neighbor(li, dir) {
        var ref = dir < 0 ? li.previousElementSibling : li.nextElementSibling;
        // shortcut: silent block under active search filter; needs repaint hooks to fix properly.
        return orderable(ref) ? ref : null;
    }

    function paint(li) {
        var up = neighbor(li, -1);
        var down = neighbor(li, 1);
        var old = li.querySelector(':scope > .rcfo-arrows');
        // Idempotent: never touch the DOM when state already matches — iconizer's observer watches this same tree and its repaint is remove+add, so any spurious mutation here pings back (RESULT_CODE_HUNG lesson).
        if (old) {
            var bu = old.querySelector('.rcfo-up'), bd = old.querySelector('.rcfo-down');
            if (bu && bd && bu.disabled === !up && bd.disabled === !down && !busy) return;
            old.remove();
        }

        var span = document.createElement('span');
        span.className = 'rcfo-arrows';
        span.innerHTML = '<button type="button" class="rcfo-up"' + (up ? '' : ' disabled') + ' aria-label="Move up">↑</button>'
            + '<button type="button" class="rcfo-down"' + (down ? '' : ' disabled') + ' aria-label="Move down">↓</button>';
        if (busy) span.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        // Bind on the span, not document: core treelist binds click on li and calls stopPropagation, so delegated listeners never fire (and we must not select the row when clicking an arrow).
        span.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var b = e.target.closest('button');
            if (b && !b.disabled) moveRowAndSave(b);
        });
        // keep press-drag off the row's IMAP move handler — the core draggable cancel list has no .rcfo-arrows
        span.addEventListener('mousedown', function (e) { e.stopPropagation(); });
        span.addEventListener('touchstart', function (e) { e.stopPropagation(); });
        li.appendChild(span);
    }

    function paintAll() {
        if (!document.body.classList.contains('action-folders')) return;
        injectStyle();
        document.querySelectorAll(LIST + ' li.mailbox').forEach(function (li) {
            if (orderable(li)) paint(li);
        });
    }

    // Document order of the rows = pre-order; checkbox value carries the raw IMAP name (iconizer rule: never decode ids).
    // :not(.searchresult__) — search clones mirror real rows; a second guard so a stray clone never enters the saved order.
    function collectOrder() {
        var ids = [];
        document.querySelectorAll(LIST + ' li.mailbox:not(.searchresult__) > .custom-switch input').forEach(function (cb) {
            if (cb.value) ids.push(cb.value);
        });
        return ids;
    }

    function moveRowAndSave(btn) {
        if (busy) return;
        var li = btn.closest('li.mailbox');
        var dir = btn.classList.contains('rcfo-up') ? -1 : 1;
        var ref = neighbor(li, dir);
        if (!ref) return;

        li.parentNode.insertBefore(dir < 0 ? li : ref, dir < 0 ? ref : li);
        busy = true;
        paintAll();

        // Core forces a 'plugin.' prefix on every plugin-registered action (rcube_plugin_api::register_action) — posting to the bare name silently 404s into an empty envelope.
        $.post(rcmail.url('plugin.folderorder-save'), { _order: JSON.stringify(collectOrder()), _token: rcmail.env.request_token })
            .always(function () { busy = false; paintAll(); })
            // Plain $.post never executes core commands; jQuery already parsed the JSON envelope, so the commands live in r.exec.
            .done(function (r) { if (r && r.exec && r.exec.indexOf('textmessage') > -1) location.reload(); })
            .fail(function () { location.reload(); }); // server truth wins over a desynced DOM
    }

    // No MutationObserver here on purpose: iconizer watches this same .folderlist with a remove+add repaint, and two observers ping-pong forever on the same subtree (tab HUNG). Rebuilds all come through AJAX (search/filter) which fires responseafter, or the initial parse.
    if (window.rcmail && rcmail.addEventListener) {
        rcmail.addEventListener('responseafter', paintAll);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', paintAll);
    } else {
        paintAll();
    }
})();
