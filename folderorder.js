// Folders page arrows: ↑/↓ swaps a row (subtree travels with it) against its orderable neighbor, autosaves the pre-order list via AJAX. No form, no Save button.
(function () {
    'use strict';

    var LIST = '#subscription-table';
    var busy = false;

    function css() {
        if (document.getElementById('rcfo-style')) return;
        var s = document.createElement('style');
        s.id = 'rcfo-style';
        // Elastic pins .custom-switch at right:0; arrows take the edge slot, checkbox shifts left. shortcut: pixel-tuned for Elastic, revisit if another skin is used.
        s.textContent = '#subscription-table li.mailbox{position:relative}'
            + '#subscription-table li.mailbox > .custom-switch{right:3.4rem}'
            + '#subscription-table li.mailbox > .rcfo-arrows{position:absolute;top:0;right:.25rem;display:flex;align-items:center;height:100%;gap:.15rem}'
            + '#subscription-table .rcfo-arrows button{border:0;background:transparent;color:inherit;cursor:pointer;font-size:.9rem;line-height:1;padding:.35rem .3rem}'
            + '#subscription-table .rcfo-arrows button[disabled]{opacity:.25;cursor:default}';
        document.head.appendChild(s);
    }

    function orderable(li) {
        return li && li.classList.contains('mailbox') && !li.classList.contains('root')
            && !!li.querySelector(':scope > .custom-switch input');
    }

    function neighbor(li, dir) {
        var ref = dir < 0 ? li.previousElementSibling : li.nextElementSibling;
        return orderable(ref) ? ref : null;
    }

    function setBusy(on) {
        busy = on;
        document.querySelectorAll(LIST + ' .rcfo-arrows button').forEach(function (b) { b.disabled = on; });
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
        li.appendChild(span);
    }

    function paintAll() {
        if (!document.body.classList.contains('action-folders')) return;
        css();
        document.querySelectorAll(LIST + ' li.mailbox').forEach(function (li) {
            if (orderable(li)) paint(li); else { var o = li.querySelector(':scope > .rcfo-arrows'); if (o) o.remove(); }
        });
    }

    // Document order of the rows = pre-order; checkbox value carries the raw IMAP name (iconizer rule: never decode ids).
    function collectOrder() {
        var ids = [];
        document.querySelectorAll(LIST + ' li.mailbox > .custom-switch input').forEach(function (cb) {
            if (cb.value) ids.push(cb.value);
        });
        return ids;
    }

    function onArrow(btn) {
        if (busy) return;
        var li = btn.closest('li.mailbox');
        var dir = btn.classList.contains('rcfo-up') ? -1 : 1;
        var ref = neighbor(li, dir);
        if (!li || !ref) return;

        li.parentNode.insertBefore(dir < 0 ? li : ref, dir < 0 ? ref : li);
        paintAll();

        setBusy(true);
        $.post(rcmail.url('folderorder-save'), { _order: JSON.stringify(collectOrder()), _token: rcmail.env.request_token })
            .always(function () { setBusy(false); })
            .fail(function () { location.reload(); }); // server truth wins over a desynced DOM
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.rcfo-up, .rcfo-down') : null;
        if (btn && !btn.disabled) onArrow(btn);
    });

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
