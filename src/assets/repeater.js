(function () {
    if (typeof jQuery === 'undefined' || !jQuery.fn.jquery) return;
    var $ = jQuery;

    // This script is emitted once per repeater field, and a page can contain
    // several (including inside flexible layouts). The event handlers below are
    // delegated on <document> and self-scope via closest('.rad-companion-
    // repeater'), so they only need to be registered ONCE per page. Guard the
    // registration so additional executions (one per extra repeater) do not
    // re-bind the same handlers — that was causing each add/remove click to fire
    // twice (e.g. adding 2 rows, or a second confirm dialog).
    if (window.__radRepeaterBound) return;
    window.__radRepeaterBound = true;

    // Push the current TinyMCE editor content back into its backing textarea so
    // the values read from the DOM are current. No-op when TinyMCE isn't loaded.
    function flushWysiwyg() {
        if (window.tinymce) {
            tinymce.triggerSave();
        }
    }

    function readRow($row) {
        flushWysiwyg();
        var o = {};
        $row.find('input[type=hidden],input[type=text],input[type=email],input[type=number],input[type=url],input[type=password],input[type=date],input[type=datetime-local],input[type=time],input[type=week],input[type=month],input[type=checkbox],textarea,select').each(function () {
            // Input names render as rad_<rowKey>[<sub>], e.g. rad_row-0[title]
            var m = this.name.match(/^rad_(?:blank|row-\d+)\[([\w\-]+)\]$/);
            if (!m) return;
            // Checkboxes report their state via `.checked`, not `.value`.
            o[m[1]] = (this.type === 'checkbox') ? (this.checked ? this.value : '') : this.value;
        });
        return o;
    }

    function sync($repeater) {
        flushWysiwyg();
        var input = $repeater.find('> input[type=hidden]');
        var a = [];
        $repeater.find('.rad-companion-repeater-rows > .rad-companion-repeater-row').each(function () {
            a.push(readRow($(this)));
        });
        input.val(JSON.stringify(a));
    }

    // The media picker updates a row's media input via .val(), which does NOT
    // fire change/input. Re-sync the repeater shortly after so the hidden JSON
    // picks up the freshly chosen media (deferred so the value is committed).
    function onMediaChange(e) {
        var $repeater = $(e.target).closest('.rad-companion-repeater');
        if (!$repeater.length) return;
        setTimeout(function () { sync($repeater); }, 0);
    }

    function hydrate($repeater) {
        // Re-run the media preview hydration for any media sub-fields in rows.
        $repeater.find('.rad-companion-media').each(function () {
            var el = $(this);
            var input = el.find('input[type=hidden]');
            var cur = {};
            try { cur = JSON.parse(input.val() || '{}'); } catch (e) { cur = {}; }
            var preview = el.find('.rad-companion-media-preview');
            preview.removeAttr('data-id').attr('data-id', cur.id || '');
            preview.html(cur.url
                ? '<img class="rad-companion-media-img" src="' + cur.url + '" alt="' + (cur.alt || '') + '" />'
                : '<p class="description">No media selected.</p>');
        });
    }

    // Keep the "Row N" header numbers matching the visible order after any
    // add/remove/reorder. Scoped to this repeater's own rows (direct children)
    // so nested repeaters are unaffected.
    function renumberRows($repeater) {
        $repeater.find('.rad-companion-repeater-rows > .rad-companion-repeater-row').each(function (i) {
            $(this).find('.rad-companion-repeater-row-number').first().text(i + 1);
        });
    }

    $(document).on('click', 'a.rad-companion-repeater-add', function (e) {
        e.preventDefault();
        var $repeater = $(this).closest('.rad-companion-repeater');
        var template = document.getElementById($repeater.attr('id').replace('rad-companion-repeater-', 'rad-companion-row-template-'));
        var html = template ? template.innerHTML : '';
        var $rows = $repeater.find('.rad-companion-repeater-rows');
        $rows.append($(html));
        // Re-key the freshly appended row (it inherited the template's `blank`
        // key) to a unique `row-N` so input names stay unique and any nested
        // wysiwyg editor gets re-targeted + initialised.
        var $newRow = $rows.children().last();
        var newKey = 'row-' + $rows.children().length;
        if (window.radRekeyRow) {
            window.radRekeyRow($newRow, 'blank', newKey);
        }
        hydrate($repeater);
        sync($repeater);
        renumberRows($repeater);
    });

    $(document).on('click', 'a.rad-companion-repeater-remove', function (e) {
        e.preventDefault();
        var $row = $(this).closest('.rad-companion-repeater-row');
        var $repeater = $row.closest('.rad-companion-repeater');
        var index = $repeater.find('.rad-companion-repeater-row').index($row) + 1;
        var noun = ($repeater.attr('data-item-label') || 'row').toLowerCase();
        if (!window.confirm('Remove ' + noun + ' ' + index + '? This cannot be undone.')) return;
        $row.remove();
        sync($repeater);
        renumberRows($repeater);
    });

    // Hydrate all repeaters on page load so media previews show correctly.
    $(document).ready(function () {
        $('.rad-companion-repeater').each(function () {
            hydrate($(this));
            renumberRows($(this));
        });
    });

    // Keep the hidden JSON in sync with edits to any sub-field.
    $(document).on('change input', '.rad-companion-repeater-rows input, .rad-companion-repeater-rows textarea, .rad-companion-repeater-rows select', function () {
        sync($(this).closest('.rad-companion-repeater'));
    });

    // Native HTML5 drag-and-drop reordering, scoped so a repeater row only
    // interacts with other rows in the SAME repeater. It is never dropped into
    // the flexible list, and flexible blocks are never dropped into it. The
    // active drag's type is tracked in a closure variable because
    // dataTransfer.getData() is unreadable during dragover (a browser security
    // restriction).
    var repeaterDragType = null;

    $(document).on('dragstart', '.rad-companion-repeater-row', function (e) {
        // A row can live inside a flexible block; stop the event from bubbling
        // so the enclosing block's dragstart (which would make the whole block
        // draggable too) does not also fire.
        e.stopPropagation();
        this.classList.add('dragging');
        repeaterDragType = 'rad-repeater';
        e.originalEvent.dataTransfer.setData('text/plain', 'rad-repeater');
    });
    $(document).on('dragend', '.rad-companion-repeater-row', function (e) {
        e.stopPropagation();
        this.classList.remove('dragging');
        hideRepeaterDropLine($(this).closest('.rad-companion-repeater-rows'));
        repeaterDragType = null;
    });

    // Half-position-aware placement, mirroring the flexible blocks'
    // getDropPlacement: a drop in a row's upper half lands before it, in the
    // lower half after it. Before this, drop only ever did insertBefore, so a
    // downward drag always landed one row short of where it was aimed.
    function repeaterDropPlacement(row, clientY) {
        var rect = row.getBoundingClientRect();
        return clientY < rect.top + rect.height / 2 ? 'before' : 'after';
    }

    // The blue insertion line reuses the flexible drop-indicator styles. It is
    // appended into (and removed from) the rows container, which is
    // position:relative so offsetTop measures against it; it is removed rather
    // than hidden so the container can still be :empty for the empty state.
    function showRepeaterDropLine($rows, $row, placement) {
        var $ind = $rows.children('.rad-flexible-drop-indicator');
        if (!$ind.length) {
            $ind = $('<div class="rad-flexible-drop-indicator"></div>');
            $rows.append($ind);
        }
        var el = $row[0];
        var top = placement === 'after' ? el.offsetTop + el.offsetHeight : el.offsetTop;
        $ind.css('top', top + 'px');
    }

    function hideRepeaterDropLine($rows) {
        $rows.children('.rad-flexible-drop-indicator').remove();
    }

    $(document).on('dragover', '.rad-companion-repeater-row', function (e) {
        // Only allow the drop while a repeater row is the active drag.
        if (repeaterDragType !== 'rad-repeater') return;
        var $rows = $(this).closest('.rad-companion-repeater-rows');
        var $drag = $rows.children('.rad-companion-repeater-row.dragging');
        // Hovering the dragged row itself is not a useful target.
        if (!$drag.length || $drag[0] === this) {
            hideRepeaterDropLine($rows);
            return;
        }
        e.preventDefault();
        e.originalEvent.dataTransfer.dropEffect = 'move';
        showRepeaterDropLine($rows, $(this), repeaterDropPlacement(this, e.originalEvent.clientY));
    });
    $(document).on('dragleave', '.rad-companion-repeater-row', function () {
        hideRepeaterDropLine($(this).closest('.rad-companion-repeater-rows'));
    });
    $(document).on('drop', '.rad-companion-repeater-row', function (e) {
        e.preventDefault();
        if (repeaterDragType !== 'rad-repeater') return;
        var $repeater = $(this).closest('.rad-companion-repeater');
        var $rows = $repeater.children('.rad-companion-repeater-table').children('.rad-companion-repeater-rows');
        var $drag = $rows.children('.rad-companion-repeater-row.dragging');
        hideRepeaterDropLine($rows);
        if ($drag.length && $drag[0] !== this) {
            if (repeaterDropPlacement(this, e.originalEvent.clientY) === 'after') {
                $(this).after($drag);
            } else {
                $(this).before($drag);
            }
            // Re-init any WYSIWYG editors in the moved row; see the flexible drop
            // handler for why (a DOM move corrupts the editors' iframe selection).
            if (window.radReinitWysiwyg) window.radReinitWysiwyg($drag);
            sync($repeater);
            renumberRows($repeater);
        }
    });

    // Final safety net: re-sync every repeater right before the form submits.
    $(document).on('submit', 'form', function () {
        $(this).find('.rad-companion-repeater').each(function () { sync($(this)); });
    });
})();
