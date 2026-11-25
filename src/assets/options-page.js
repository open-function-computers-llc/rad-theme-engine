/* Collapse state for options page postboxes.
 *
 * Clicking a box header (or its handle button) folds the box, mirroring the
 * post editor. Which boxes are folded is remembered per options page in
 * localStorage — the editor keeps the same state in user meta, which would
 * mean a save round trip on every click for a purely visual preference.
 */
(function () {
    var ROOT = '.rad-options-page';
    var KEY = 'rad-options-closed-boxes';

    function page() {
        var root = document.querySelector(ROOT);
        return root ? (root.getAttribute('data-rad-options-page') || '') : '';
    }

    function read(page) {
        try {
            var raw = window.localStorage.getItem(KEY + ':' + page);
            var parsed = raw ? JSON.parse(raw) : null;
            return Array.isArray(parsed) ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    function write(page, ids) {
        try {
            window.localStorage.setItem(KEY + ':' + page, JSON.stringify(ids));
        } catch (e) {
            /* Private mode / storage disabled: collapsing still works, it just
               doesn't survive a reload. */
        }
    }

    function setClosed(box, closed) {
        box.classList.toggle('closed', closed);

        var handle = box.querySelector('.handlediv');
        if (handle) {
            handle.setAttribute('aria-expanded', closed ? 'false' : 'true');
        }
    }

    function closedIds(root) {
        var ids = [];
        root.querySelectorAll('.postbox.closed[data-rad-box-id]').forEach(function (box) {
            ids.push(box.getAttribute('data-rad-box-id'));
        });
        return ids;
    }

    function restore(root) {
        var saved = read(root.getAttribute('data-rad-options-page') || '');
        if (saved === null) {
            return;
        }

        root.querySelectorAll('.postbox[data-rad-box-id]').forEach(function (box) {
            setClosed(box, saved.indexOf(box.getAttribute('data-rad-box-id')) !== -1);
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) {
            return;
        }

        var header = target.closest('.postbox-header[data-rad-toggleable]');
        if (!header) {
            return;
        }

        // Links and inputs inside a header keep their own behaviour; only the
        // title text and the toggle button fold the box.
        if (target.closest('a,input,select,textarea,button') && !target.closest('.handlediv')) {
            return;
        }

        var box = header.closest('.postbox');
        if (!box) {
            return;
        }

        setClosed(box, !box.classList.contains('closed'));

        var root = document.querySelector(ROOT);
        if (root) {
            write(page(), closedIds(root));
        }
    });

    function init() {
        var root = document.querySelector(ROOT);
        if (root) {
            restore(root);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
