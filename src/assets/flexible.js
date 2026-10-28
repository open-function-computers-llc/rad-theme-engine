(function () {
    if (typeof jQuery === 'undefined' || !jQuery.fn.jquery) return;
    var $ = jQuery;

    // This script is emitted once per flexible field, and a page can contain
    // several. The handlers below are delegated on <document> and self-scope
    // via closest('.rad-flexible'), so they only need to be registered ONCE per
    // page. Guard the registration so a second flexible field does not re-bind
    // the same handlers (which would make each add/remove/drag fire twice).
    if (window.__radFlexibleBound) return;
    window.__radFlexibleBound = true;

    // Push the current TinyMCE editor content back into its backing textarea so
    // the values read from the DOM are current. No-op when TinyMCE isn't loaded.
    function flushWysiwyg() {
        if (window.tinymce) {
            tinymce.triggerSave();
        }
    }

    // Keep a single block's collapse button (label/aria) in sync with its
    // is-collapsed class.
    function setBlockCollapsed($block, collapsed) {
        $block.toggleClass('is-collapsed', collapsed);
        $block.find('.rad-flexible-collapse').each(function () {
            $(this)
                .attr('aria-expanded', collapsed ? 'false' : 'true')
                .attr('title', collapsed ? 'Expand' : 'Collapse');
            $(this).find('.rad-flexible-collapse-label').text(collapsed ? 'Expand' : 'Collapse');
        });
    }

    // --- collapse-state persistence (localStorage) ---
    //
    // Each block's collapsed state is stored per post + field + layout +
    // position (key: rad-flex-collapse:<post-id>:<field>:<layout>:<index>) so
    // the editor's layout survives reloads. localStorage is best-effort: if it
    // is unavailable (private mode, disabled), collapse simply starts fresh.
    var COLLAPSE_PREFIX = 'rad-flex-collapse:';

    function postId($flexible) {
        var id = jQuery('#post_ID').val() || jQuery('input[name=post_ID]').val();
        if (!id) {
            id = $flexible.attr('data-post-id') || '0';
        }
        return id;
    }

    function collapseKey($flexible, index) {
        var name = $flexible.attr('id').replace('rad-flexible-', '');
        var layout = $flexible.find('.rad-flexible-blocks > .rad-flexible-block')
            .eq(index).find('input.rad-flexible-layout').val() || '';
        return COLLAPSE_PREFIX + postId($flexible) + ':' + name + ':' + layout + ':' + index;
    }

    function lsGet(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }

    function lsSet(key) {
        try { localStorage.setItem(key, '1'); } catch (e) {}
    }

    function lsRemove(key) {
        try { localStorage.removeItem(key); } catch (e) {}
    }

    // Write every block's current collapsed state, keyed by its current
    // position (so a drag-reorder re-keys the whole group), and purge keys for
    // positions that no longer exist so a later-appended block never inherits
    // a removed block's state.
    function persistState($flexible) {
        var $blocks = $flexible.find('.rad-flexible-blocks > .rad-flexible-block');
        $blocks.each(function (i) {
            var key = collapseKey($flexible, i);
            if ($(this).hasClass('is-collapsed')) { lsSet(key); } else { lsRemove(key); }
        });
        var i = $blocks.length;
        while (lsGet(collapseKey($flexible, i)) !== null) {
            lsRemove(collapseKey($flexible, i));
            i++;
        }
    }

    // Re-apply stored collapse state on load.
    function applyState($flexible) {
        $flexible.find('.rad-flexible-blocks > .rad-flexible-block').each(function (i) {
            if (lsGet(collapseKey($flexible, i)) !== null) {
                setBlockCollapsed($(this), true);
            }
        });
    }

    // Update the "collapse all / expand all" controls for a group: they say
    // "Collapse all" while any block is expanded and "Expand all" when every
    // block is collapsed (or there are none).
    function updateBulkState($flexible) {
        var $blocks = $flexible.find('.rad-flexible-blocks > .rad-flexible-block');
        var $bulk = $flexible.find('.rad-flexible-bulk');
        // With no blocks the control is meaningless (it would read
        // "Expand all" over an empty area), so hide it entirely.
        if (!$blocks.length) {
            $bulk.hide();
            return;
        }
        $bulk.show();
        var anyExpanded = $blocks.filter(function () { return !$(this).hasClass('is-collapsed'); }).length > 0;
        $bulk.toggleClass('is-all-collapsed', !anyExpanded);
        $bulk.find('.rad-flexible-collapse-all-label').text(anyExpanded ? 'Collapse all' : 'Expand all');
    }

    function readBlock($block) {
        flushWysiwyg();
        var o = {};
        o._layout = $block.find('input.rad-flexible-layout').val() || '';
        $block.find('input[type=hidden],input[type=text],input[type=email],input[type=number],input[type=url],input[type=password],input[type=date],input[type=datetime-local],input[type=time],input[type=week],input[type=month],input[type=checkbox],textarea,select').each(function () {
            // Input names render as rad_<blockKey>[<sub>], e.g. rad_block-0[title]
            var m = this.name.match(/^rad_(?:blank[-\w]+|block-\d+)\[([\w\-]+)\]$/);
            if (!m) return;
            // Checkboxes report their state via `.checked`, not `.value`.
            o[m[1]] = (this.type === 'checkbox') ? (this.checked ? this.value : '') : this.value;
        });
        return o;
    }

    function sync($flexible) {
        flushWysiwyg();
        var input = $flexible.find('> input[type=hidden]');
        var a = [];
        $flexible.find('.rad-flexible-blocks > .rad-flexible-block').each(function () {
            a.push(readBlock($(this)));
        });
        input.val(JSON.stringify(a));
    }

    function hydrate($flexible) {
        $flexible.find('.rad-companion-media').each(function () {
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

    // Clone a blank template of `layout` and append it to the field's block
    // list. Shared by the picker modal's card clicks.
    function addBlock($wrap, layout) {
        if (!layout) return;
        var name = $wrap.attr('id').replace('rad-flexible-', '');
        var safe = layout.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        var template = document.getElementById('rad-flexible-template-' + name + '-' + safe);
        if (!template) return;
        var html = template.innerHTML;
        var $blocks = $wrap.find('.rad-flexible-blocks');
        $blocks.append($(html));
        // Re-key the freshly appended block (it inherited the template's
        // `blank-<layout>` key) to a unique `block-N` so input names stay unique
        // and any nested wysiwyg editor gets re-targeted + initialised.
        var $newBlock = $blocks.children().last();
        var newKey = 'block-' + $blocks.children().length;
        if (window.radRekeyRow) {
            window.radRekeyRow($newBlock, 'blank-' + safe, newKey);
        }
        hydrate($wrap);
        sync($wrap);
        updateBulkState($wrap);
        if ($newBlock[0].scrollIntoView) {
            $newBlock[0].scrollIntoView({ block: 'nearest' });
        }
    }

    // Show/hide cards matching the search term; surface the empty state when
    // nothing matches.
    function filterPickerCards($modal, term) {
        term = (term || '').toLowerCase().trim();
        var visible = 0;
        $modal.find('.rad-flexible-card').each(function () {
            var match = !term || ($(this).attr('data-search') || '').indexOf(term) !== -1;
            $(this).toggle(match);
            if (match) visible++;
        });
        $modal.find('.rad-flexible-modal-empty').prop('hidden', visible > 0);
    }

    function openPickerModal($wrap) {
        // Look the modal up by id (not as a child of the wrapper) because the
        // first open moves it to <body>, after which it is no longer a child.
        var name = $wrap.attr('id').replace('rad-flexible-', '');
        var $modal = $('#rad-flexible-modal-' + name);
        if (!$modal.length) return;
        // Move to <body> on first open so fixed positioning escapes any
        // transformed/overflowed ancestors; document-level delegation keeps the
        // handlers working after the move.
        if (!$modal.data('radMoved')) {
            $modal.appendTo(document.body);
            $modal.data('radMoved', true);
        }
        $modal.data('radWrap', $wrap);
        $modal.data('radReturnFocus', document.activeElement);
        $modal.removeAttr('hidden');
        document.body.classList.add('rad-flexible-modal-open');
        var $search = $modal.find('.rad-flexible-modal-search');
        $search.val('');
        filterPickerCards($modal, '');
        $search.trigger('focus');
    }

    function closePickerModal($modal) {
        $modal.attr('hidden', 'hidden');
        document.body.classList.remove('rad-flexible-modal-open');
        var target = $modal.data('radReturnFocus');
        if (target && document.body.contains(target)) {
            target.focus();
        }
    }

    $(document).on('click', '.rad-flexible-add-open', function (e) {
        e.preventDefault();
        openPickerModal($(this).closest('.rad-flexible'));
    });

    $(document).on('click', '.rad-flexible-modal-close, .rad-flexible-modal-backdrop', function () {
        closePickerModal($(this).closest('.rad-flexible-modal'));
    });

    $(document).on('click', '.rad-flexible-card', function () {
        var $modal = $(this).closest('.rad-flexible-modal');
        var $wrap = $modal.data('radWrap');
        if (!$wrap || !$wrap.closest('body').length) return;
        addBlock($wrap, $(this).attr('data-layout'));
        closePickerModal($modal);
    });

    $(document).on('input', '.rad-flexible-modal-search', function () {
        filterPickerCards($(this).closest('.rad-flexible-modal'), $(this).val());
    });

    $(document).on('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var $open = $('.rad-flexible-modal:not([hidden])');
        if ($open.length) {
            e.preventDefault();
            closePickerModal($open.first());
        }
    });

    $(document).on('click', '.rad-flexible-remove', function (e) {
        e.preventDefault();
        var $block = $(this).closest('.rad-flexible-block');
        var $flexible = $block.closest('.rad-flexible');
        var title = $block.find('.rad-flexible-block-title').first().text() || 'this section';
        if (!window.confirm('Remove "' + title + '"? This cannot be undone.')) return;
        $block.remove();
        sync($flexible);
        updateBulkState($flexible);
        persistState($flexible);
    });

    // Toggle a block's collapsed state (hides the body so only the title bar
    // shows). Hidden inputs stay in the DOM, so sync() still reads their values.
    $(document).on('click', '.rad-flexible-collapse', function (e) {
        e.preventDefault();
        var $block = $(this).closest('.rad-flexible-block');
        setBlockCollapsed($block, !$block.hasClass('is-collapsed'));
        updateBulkState($block.closest('.rad-flexible'));
        persistState($block.closest('.rad-flexible'));
    });

    // ACF lets the whole block header toggle collapsing; clicks on the drag
    // handle or the header's action buttons are excluded, as is a click that
    // ends a text selection inside the title (which shouldn't collapse).
    $(document).on('click', '.rad-flexible-block-head', function (e) {
        if ($(e.target).closest('.rad-flexible-block-handle, .rad-flexible-collapse, .rad-flexible-remove').length) return;
        if (window.getSelection && String(window.getSelection())) return;
        var $block = $(this).closest('.rad-flexible-block');
        setBlockCollapsed($block, !$block.hasClass('is-collapsed'));
        updateBulkState($block.closest('.rad-flexible'));
        persistState($block.closest('.rad-flexible'));
    });

    // Collapse (or expand) every block in the group at once. Collapses when any
    // block is still expanded, expands when all are already collapsed.
    $(document).on('click', '.rad-flexible-collapse-all', function (e) {
        e.preventDefault();
        var $flexible = $(this).closest('.rad-flexible');
        var $blocks = $flexible.find('.rad-flexible-blocks > .rad-flexible-block');
        var anyExpanded = $blocks.filter(function () { return !$(this).hasClass('is-collapsed'); }).length > 0;
        $blocks.each(function () { setBlockCollapsed($(this), anyExpanded); });
        updateBulkState($flexible);
        persistState($flexible);
    });

    $(document).ready(function () {
        $('.rad-flexible').each(function () {
            hydrate($(this));
            applyState($(this));
            updateBulkState($(this));
        });
    });

    $(document).on('change input', '.rad-flexible-blocks input, .rad-flexible-blocks textarea, .rad-flexible-blocks select', function () {
        sync($(this).closest('.rad-flexible'));
    });

    // Native HTML5 drag-and-drop reordering, scoped so a flexible block only
    // interacts with other flexible blocks in the SAME flexible field. It never
    // accepts a drop from a repeater row nested inside it, and it is never
    // dropped into a repeater list. The active drag's type is tracked in a
    // closure variable because dataTransfer.getData() is unreadable during
    // dragover (a browser security restriction).
    //
    // Only the block's ⠿ handle is draggable (draggable="true" is set on the
    // handle, not the whole block), so a reorder can only be started by
    // dragging the handle. Dragging anywhere else — text, a field, the WYSIWYG
    // area, etc. — is a normal text-selection/scroll gesture and never kicks
    // off a block move.
    var flexDragType = null;

    // Build a lightweight off-screen "drag card" that represents the block
    // being moved (title + an ellipsis body). It is used as the custom drag
    // image so, while only the ⠿ handle initiates the drag, the drag ghost
    // shows the whole section — not just the tiny handle icon. Kept minimal
    // (no field inputs / WYSIWYG) to avoid cloning editor state.
    function makeFlexDragCard(title) {
        var card = document.createElement('div');
        card.className = 'rad-flexible-drag-card';
        card.innerHTML =
            '<div class="rad-flexible-drag-card-title"></div>' +
            '<div class="rad-flexible-drag-card-body">…</div>';
        card.querySelector('.rad-flexible-drag-card-title').textContent = title;
        // Fixed off-screen so the browser can rasterise it as the drag ghost
        // without the user seeing it sit in the page.
        card.style.position = 'fixed';
        card.style.left = '-9999px';
        card.style.top = '0';
        card.style.width = '320px';
        document.body.appendChild(card);
        return card;
    }

    // Decide where a drop lands relative to a hovered block: before it when the
    // cursor is in the block's upper half, after it in the lower half. This is
    // what the drop indicator visualises, so it drives both the indicator
    // position and the actual insert on drop.
    function getDropPlacement(block, clientY) {
        var rect = block.getBoundingClientRect();
        return clientY < rect.top + rect.height / 2 ? 'before' : 'after';
    }

    // Show a single reusable "drop here" line at the boundary of a hovered
    // block (before it, or after it). The indicator lives inside the
    // .rad-flexible-blocks container (which is position:relative) so offsetTop
    // is measured against it. It has pointer-events:none, so it never hijacks
    // the dragover events flowing to the blocks beneath it.
    function showDropIndicator($blocks, $block, placement) {
        var $ind = $blocks.children('.rad-flexible-drop-indicator');
        if (!$ind.length) {
            $ind = $('<div class="rad-flexible-drop-indicator"></div>');
            $blocks.append($ind);
        }
        var block = $block[0];
        var top = placement === 'after'
            ? block.offsetTop + block.offsetHeight
            : block.offsetTop;
        $ind.css({ top: top + 'px', display: 'block' });
    }

    function hideDropIndicator($blocks) {
        $blocks.children('.rad-flexible-drop-indicator').css('display', 'none');
    }

    $(document).on('dragstart', '.rad-flexible-block', function (e) {
        // Only proceed if the drag began on the block's own handle; otherwise
        // (e.g. a nested draggable that bubbles up) let the inner element own it.
        var handle = $(e.target).closest('.rad-flexible-block-handle', this)[0];
        if (!handle) {
            e.preventDefault();
            return;
        }
        this.classList.add('dragging');
        flexDragType = 'rad-flexible';
        e.originalEvent.dataTransfer.setData('text/plain', 'rad-flexible');
        flexDragClientY = e.clientY;
        flexScrollState = 'none';
        // While a block is being dragged, make every iframe (e.g. the TinyMCE
        // editor iframes) transparent to the drag. Otherwise, when the cursor
        // passes over another block's editor iframe, dragover events fire inside
        // that iframe's document and report clientY relative to the IFRAME (not
        // the window), corrupting the auto-scroll + drop-indicator math. With
        // pointer-events:none the drag stays in the parent document and the
        // coordinates are always window-relative.
        document.body.classList.add('rad-flexible-dragging');
        startFlexAutoScroll();
        // Show the whole section (title + ellipsis) as the drag ghost instead of
        // just the ⠿ handle. Cleaned up in the block's dragend handler.
        var title = this.querySelector('.rad-flexible-block-title');
        var card = makeFlexDragCard(title ? title.textContent.trim() : 'Section');
        this.__radDragCard = card;
        try {
            e.originalEvent.dataTransfer.setDragImage(card, 16, 16);
        } catch (err) {}
    });
    $(document).on('dragend', '.rad-flexible-block', function () {
        this.classList.remove('dragging');
        flexDragType = null;
        stopFlexAutoScroll();
        document.body.classList.remove('rad-flexible-dragging');
        // Clear any drop indicator still showing (e.g. the drag was cancelled
        // outside a valid target, so no drop/dragleave cleaned it up).
        $(this).closest('.rad-flexible').find('> .rad-flexible-blocks').each(function () {
            hideDropIndicator($(this));
        });
        // Remove the custom drag card created in dragstart.
        if (this.__radDragCard && this.__radDragCard.parentNode) {
            this.__radDragCard.parentNode.removeChild(this.__radDragCard);
        }
        delete this.__radDragCard;
    });

    // The scroll container the page content lives in. Walk up from a starting
    // element to the nearest ancestor that ACTUALLY scrolls (overflowY auto/
    // scroll AND scrollHeight > clientHeight). In the WP admin that is often
    // NOT #wpbody-content (which is overflow:visible); the document itself is
    // the scroller. Returns null when no inner element scrolls, meaning "use
    // the window/document". Needed because native HTML5 drag-and-drop does not
    // scroll the page while a drag is in flight, so on tall pages you cannot
    // reach a distant drop target without manual scrolling.
    function getScrollParent(el) {
        var node = el;
        while (node && node !== document.documentElement) {
            var overflow = getComputedStyle(node).overflowY;
            if ((overflow === 'auto' || overflow === 'scroll') && node.scrollHeight > node.clientHeight) {
                return node;
            }
            node = node.parentElement;
        }
        return null;
    }

    // The WP admin bar sits at the very top of the viewport, so the cursor
    // cannot reach clientY 0 while dragging — it can only get just under the
    // bar. Pad the top trigger by the bar height plus a small amount so the
    // cursor (held just under the bar) still triggers scroll-up. Kept modest
    // (not a wide band) so the top zone doesn't overlap the middle of the
    // screen and fight the cursor's normal movement (which felt jittery).
    function getTopMargin() {
        var bar = parseInt(getComputedStyle(document.documentElement)
            .getPropertyValue('--wp-admin--admin-bar--height'), 10);
        if (isNaN(bar) || bar <= 0) bar = 32;
        return bar + 40;
    }

    // Auto-scroll the page while dragging so a distant drop target on a tall
    // page is reachable. Native HTML5 drag-and-drop neither scrolls with the
    // wheel (the drag session swallows wheel events) nor scrolls the page by
    // itself. While a flexible drag is active we track the cursor's Y (via the
    // document-level dragover, which fires everywhere during a native drag) and
    // run a requestAnimationFrame loop that scrolls the container when the
    // cursor is in an edge "enter zone". A hysteresis state machine prevents the
    // scroll from flapping up/down: entering an edge zone starts scrolling that
    // way, and only reaching the central "safe band" stops it — so moving the
    // cursor up to cancel a down-scroll settles in the middle and stops rather
    // than reversing into an up-scroll.
    var flexAutoScrollRAF = null;
    var flexDragClientY = 0;
    var flexScrollState = 'none'; // hysteresis state: 'none' | 'up' | 'down'
    var EDGE_MARGIN = 64;
    // Hysteresis: an edge zone you must enter to START scrolling, and a wide
    // middle "safe band" you must reach to STOP. Starting requires the cursor
    // in the edge zone; stopping requires it in the middle. Because the stop
    // band is far from the opposite edge's start zone, moving the cursor up to
    // cancel a down-scroll lands in the middle and stops — it does not flip
    // into an up-scroll. (Prevents the up/down/up/down flapping.)
    var ENTER_ZONE = 64;            // px from an edge to begin scrolling
    var SAFE_BAND_FRACTION = 0.35;  // central 35% of the viewport = stop band
    // Per-frame scroll distance at max proximity. Kept modest so auto-scroll
    // feels smooth and doesn't jerk the page; the speed ramp still eases in
    // near the edge. 12px/frame at 60fps ≈ 720px/sec, plenty to traverse a
    // tall page without feeling twitchy.
    var MAX_SCROLL_STEP = 12;

    function autoScrollFrame() {
        if (flexDragType !== 'rad-flexible') {
            flexAutoScrollRAF = null;
            return;
        }
        var dragging = document.querySelector('.rad-flexible-block.dragging');
        if (!dragging) return;
        var scroller = getScrollParent(dragging); // null => the window scrolls
        var y = flexDragClientY;
        // The "window" case (scroller === null) is the WP admin default: the
        // document scrolls. There the cursor is measured against the viewport
        // (clientY 0..innerHeight), and the top enter zone is padded by the
        // admin bar height so the cursor (which can't reach under the bar) can
        // still trigger scroll-up. For a real inner scroller, use its rect.
        var useWindow = scroller === null;
        var top = useWindow ? 0 : scroller.getBoundingClientRect().top;
        var bottom = useWindow ? window.innerHeight : scroller.getBoundingClientRect().bottom;
        // Asymmetric enter zones: the top one is padded by the admin bar
        // (the cursor can't reach clientY 0 under the bar); the bottom one
        // is the plain edge margin.
        var topEnter = useWindow ? getTopMargin() : ENTER_ZONE;
        var bottomEnter = EDGE_MARGIN;
        var scrollBy = function (dy) {
            if (useWindow) { window.scrollBy(0, dy); } else { scroller.scrollBy(0, dy); }
        };
        // Hysteresis state machine. Zones (in viewport-relative terms):
        //   top enter zone:    [top, top + topEnter]
        //   bottom enter zone: [bottom - bottomEnter, bottom]
        //   safe (stop) band:  the central SAFE_BAND_FRACTION of the viewport.
        // Entering an enter zone starts scrolling that direction. Reaching the
        // safe band stops it. Because the safe band is centered and far from
        // the opposite enter zone, a cursor moved up to cancel a down-scroll
        // settles in the band and stops — it does not reverse to up.
        var height = bottom - top;
        var safeTop = top + height * SAFE_BAND_FRACTION / 2;
        var safeBottom = bottom - height * SAFE_BAND_FRACTION / 2;
        var inTopEnter = (y - top < topEnter);
        var inBottomEnter = (bottom - y < bottomEnter);
        var inSafeBand = (y > safeTop && y < safeBottom);
        // Transition the state.
        if (flexScrollState === 'none') {
            if (inTopEnter) flexScrollState = 'up';
            else if (inBottomEnter) flexScrollState = 'down';
        } else if (flexScrollState === 'up') {
            if (inSafeBand) flexScrollState = 'none';
        } else if (flexScrollState === 'down') {
            if (inSafeBand) flexScrollState = 'none';
        }
        // Scroll according to the current state, with a speed ramp that eases
        // in near the active edge and reaches MAX right at it.
        if (flexScrollState === 'up') {
            // Ramp: closer to the top edge => faster (0..1). The ramp spans the
            // top enter zone; deeper into the top (y < top) clamps to 1.
            var tUp = 1 - (y - top) / Math.max(1, topEnter);
            scrollBy(-Math.round(MAX_SCROLL_STEP * Math.max(0, Math.min(1, tUp))));
        } else if (flexScrollState === 'down') {
            var tDn = 1 - (bottom - y) / Math.max(1, bottomEnter);
            scrollBy(Math.round(MAX_SCROLL_STEP * Math.max(0, Math.min(1, tDn))));
        }
        flexAutoScrollRAF = window.requestAnimationFrame(autoScrollFrame);
    }

    function startFlexAutoScroll() {
        if (flexAutoScrollRAF) return;
        flexAutoScrollRAF = window.requestAnimationFrame(autoScrollFrame);
    }

    function stopFlexAutoScroll() {
        if (flexAutoScrollRAF) {
            window.cancelAnimationFrame(flexAutoScrollRAF);
            flexAutoScrollRAF = null;
        }
    }

    // Keep the cursor's vertical position fresh for the auto-scroll loop. In
    // this environment mousemove does not fire during a native drag, so the
    // primary position source is the document-level dragover below (bound on
    // <document>, not just .rad-flexible-block, so it fires even when the
    // cursor is over the gaps between blocks, the page header, or the admin bar
    // area — if it were bound only to blocks, flexDragClientY would freeze as
    // soon as the cursor left a block and auto-scroll would stop responding to
    // direction changes). mousemove is kept as a harmless secondary source for
    // browsers where it does fire during a drag.
    $(document).on('mousemove', function (e) {
        if (flexDragType === 'rad-flexible') {
            flexDragClientY = e.clientY;
        }
    });
    $(document).on('dragover', function (e) {
        if (flexDragType !== 'rad-flexible') return;
        var ev = e.originalEvent;
        // Defense-in-depth (in addition to body.rad-flexible-dragging setting
        // pointer-events:none on iframes during a drag): if the event somehow
        // originated inside a nested document (e.g. a same-origin TinyMCE
        // iframe), its clientY is relative to THAT document, not the window,
        // and would corrupt the auto-scroll/drop math. Ignore it so the last
        // valid window-relative position is retained.
        var targetDoc = (ev.target && ev.target.ownerDocument) || null;
        if (targetDoc && targetDoc !== document) {
            return;
        }
        // clientY (viewport coords) matches the window-viewport math used by
        // the auto-scroll loop.
        flexDragClientY = ev.clientY;
    });
    $(document).on('dragover', '.rad-flexible-block', function (e) {
        // (Cursor position for auto-scroll is tracked by the document-level
        // dragover handler above, which fires everywhere during the drag.)
        // Only allow the drop (which requires preventDefault) while a flexible
        // block is the active drag, not a nested repeater row.
        if (flexDragType !== 'rad-flexible') return;
        var $flexible = $(this).closest('.rad-flexible');
        var $blocks = $flexible.find('> .rad-flexible-blocks');
        var $drag = $blocks.children('.rad-flexible-block.dragging');
        // The active drag lives in a different flexible field (or none in this
        // one): a drop here would be a no-op. Without preventDefault the browser
        // shows a "no-drop" cursor, which is the honest signal for this case.
        if (!$drag.length) return;
        // Hovering the block being dragged itself is not a useful drop target;
        // drop the indicator rather than hinting at a no-op move onto itself.
        if ($drag[0] === this) {
            this.classList.remove('drag-over');
            hideDropIndicator($blocks);
            return;
        }
        e.preventDefault();
        e.originalEvent.dataTransfer.dropEffect = 'move';
        this.classList.add('drag-over');
        showDropIndicator($blocks, $(this), getDropPlacement(this, e.originalEvent.clientY));
    });
    $(document).on('dragleave', '.rad-flexible-block', function () { this.classList.remove('drag-over'); });
    $(document).on('drop', '.rad-flexible-block', function (e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        if (flexDragType !== 'rad-flexible') return;
        var $flexible = $(this).closest('.rad-flexible');
        var $blocks = $flexible.find('> .rad-flexible-blocks');
        var $drag = $blocks.children('.rad-flexible-block.dragging');
        if ($drag.length && $drag[0] !== this) {
            // Land exactly where the drop indicator was pointing: before this
            // block when the cursor was in its upper half, after when in the
            // lower half.
            var placement = getDropPlacement(this, e.originalEvent.clientY);
            if (placement === 'after') {
                $(this).after($drag);
            } else {
                $(this).before($drag);
            }
            hideDropIndicator($blocks);
            // The DOM move corrupts any TinyMCE editors inside the moved block
            // (stale iframe selection -> 'setBaseAndExtent' error -> desynced
            // editor). Re-init them from their stored config so they come back
            // fully functional at the new position, as on a page reload.
            if (window.radReinitWysiwyg) window.radReinitWysiwyg($drag);
            sync($flexible);
            // Collapse state is keyed by position, so re-key the whole group
            // after a reorder instead of letting stale keys mis-tag blocks.
            persistState($flexible);
        }
    });

    $(document).on('submit', 'form', function () {
        $(this).find('.rad-flexible').each(function () { sync($(this)); });
    });
})();
