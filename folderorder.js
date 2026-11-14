// Settings "Folder order": adjacent swap keeps the flat pre-order sequence; hidden JSON rides the core's save POST.
(function () {
    'use strict';

    function updateHidden() {
        var rows = document.querySelectorAll('#rcfo-list .rcfo-row');
        var ids = [];
        rows.forEach(function (r) { ids.push(r.getAttribute('data-folder')); });
        var hidden = document.getElementById('_folder_order');
        if (hidden) hidden.value = JSON.stringify(ids);
    }

    function swap(row, dir) {
        var target = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
        if (!target) return;
        row.parentNode.insertBefore(dir < 0 ? row : target, dir < 0 ? target : row);
        updateHidden();
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.rcfo-up, .rcfo-down') : null;
        if (!btn) return;
        swap(btn.closest('.rcfo-row'), btn.classList.contains('rcfo-up') ? -1 : 1);
    });

    // After a successful save the iframe re-renders with the confirmation: reload only the ancestor frame that owns the sidebar — parent may be Nextcloud, not webmail.
    if (document.querySelector('.message.success, .confirmation')) {
        var w = window.parent;
        try {
            while (w && w !== top) {
                if (w.document.querySelector('.folderlist')) break;
                w = w.parent;
            }
            if (w && w.document.querySelector('.folderlist')) {
                setTimeout(function () { w.location.reload(); }, 700);
            }
        } catch (err) { /* cross-origin ancestor: reload on next natural navigation */ }
    }
})();
