(function () {
    if (typeof jQuery === 'undefined' || !jQuery.fn.jquery) return;
    var $ = jQuery;

    // dashedSlug mirror of PHP CompanionFields::dashedSlug()
    function dashed(s) {
        return s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    // Rewrite a wysiwyg editor's DOM ids + re-target its config to a new editor id.
    function rekeyEditor($wrap, oldEditorId, newEditorId) {
        // 1. Clone the TinyMCE + Quicktags settings registered for the template's
        //    editor id (they exist because wp_editor() ran server-side for the
        //    template), pointing at the new id.
        if (window.tinyMCEPreInit) {
            var mce = window.tinyMCEPreInit.mceInit[oldEditorId];
            var qt = window.tinyMCEPreInit.qtInit[oldEditorId];
            if (mce) {
                var newMce = Object.assign({}, mce);
                newMce.selector = '#' + newEditorId;
                window.tinyMCEPreInit.mceInit[newEditorId] = newMce;
            }
            if (qt) {
                var newQt = Object.assign({}, qt);
                newQt.id = newEditorId;
                window.tinyMCEPreInit.qtInit[newEditorId] = newQt;
            }
        }

        // 2. Rewrite every id / data-wp-editor-id from the old to the new editor
        //    id. The wrap's OWN id (wp-<id>-wrap) is NOT a descendant, so it must
        //    be rewritten explicitly in addition to $wrap.find('[id]'). If the
        //    wrap id stays stale, switchEditors() — which looks the wrap up via
        //    '#wp-<id>-wrap' — finds nothing and the Visual/Code tabs stop
        //    switching.
        if ($wrap.attr('id') && $wrap.attr('id').indexOf(oldEditorId) !== -1) {
            $wrap.attr('id', $wrap.attr('id').split(oldEditorId).join(newEditorId));
        }
        $wrap.find('[id]').each(function () {
            var id = this.id;
            if (id && id.indexOf(oldEditorId) !== -1) {
                this.id = id.split(oldEditorId).join(newEditorId);
            }
        });
        // The Visual/Code tab buttons carry data-wp-editor-id; switchEditors()
        // reads it to know which editor to toggle. Rekey it alongside the ids.
        $wrap.find('[data-wp-editor-id]').each(function () {
            this.setAttribute('data-wp-editor-id', newEditorId);
        });

        // 3. Init the new editor instances. Deferred to the next task so the
        //    appended DOM is committed + laid out before TinyMCE/Quicktags run
        //    (initialising them synchronously inside the click handler can leave
        //    the toolbars unrendered).
        setTimeout(function () {
            if (window.tinymce && window.tinyMCEPreInit && window.tinyMCEPreInit.mceInit[newEditorId]) {
                window.tinymce.init(window.tinyMCEPreInit.mceInit[newEditorId]);
            }
            if (window.quicktags && window.tinyMCEPreInit && window.tinyMCEPreInit.qtInit[newEditorId]) {
                window.quicktags(window.tinyMCEPreInit.qtInit[newEditorId]);
                // Quicktags normally renders its toolbar buttons via a deferred
                // DOM-ready callback, which does not reliably fire for editors
                // added to the DOM after page load. Force the button render now by
                // re-running the (public) _buttonsInit() with no arg — that
                // re-populates the toolbar for every QTags instance, including the
                // one we just created. Idempotent for the page-load editors.
                if (window.QTags && typeof window.QTags._buttonsInit === 'function') {
                    window.QTags._buttonsInit();
                }
            }
        }, 0);
    }

    // Re-key a cloned row/block: oldKey -> newKey across names/ids, and re-init
    // any wysiwyg editors it holds.
    window.radRekeyRow = function ($row, oldKey, newKey) {
        // 1. Rewrite input/textarea names + ids (rad_<key>[<sub>]).
        $row.find('[name], [id]').each(function () {
            var el = this; // jQuery's .each() calls with `this` = the DOM element;
                           // capture it so the inner forEach keeps the reference.
            ['name', 'id'].forEach(function (attr) {
                var v = el.getAttribute(attr);
                if (v && v.indexOf('rad_' + oldKey + '[') !== -1) {
                    el.setAttribute(attr, v.split('rad_' + oldKey + '[').join('rad_' + newKey + '['));
                }
            });
        });

        // 2. Re-key + re-init each wysiwyg editor in the row.
        var oldKeyDashed = dashed(oldKey);
        var newKeyDashed = dashed(newKey);
        $row.find('.wp-editor-wrap').each(function () {
            var $wrap = $(this);
            var oldEditorId = $wrap.attr('id').replace(/^wp-/, '').replace(/-wrap$/, '');
            if (!oldEditorId) return;
            var newEditorId = oldEditorId.split(oldKeyDashed).join(newKeyDashed);
            rekeyEditor($wrap, oldEditorId, newEditorId);
        });
    };

    // Shared by the flexible + repeater drop handlers. TinyMCE does not survive
    // its container being moved in the DOM: relocating the wrap leaves the
    // editor's iframe selection pointing at detached nodes, so the next
    // selection/focus restore throws ('setBaseAndExtent' on null) and the editor
    // desyncs (empty Visual tab, broken Code tab). After a drag-and-drop reorder
    // we therefore destroy each moved editor instance (flushing content to the
    // textarea first) and re-create it at the new position with a fresh iframe —
    // exactly what a page reload does. Safe when TinyMCE is absent.
    window.radReinitWysiwyg = function ($block) {
        if (!window.tinymce) return;
        $block.find('.wp-editor-wrap').each(function () {
            var editorId = $(this).attr('id').replace(/^wp-/, '').replace(/-wrap$/, '');
            if (!editorId) return;
            var existing = window.tinymce.get(editorId);
            if (existing) {
                try { existing.save(); } catch (e) {}
                try { existing.remove(); } catch (e) {}
            }
            var mceCfg = window.tinyMCEPreInit && window.tinyMCEPreInit.mceInit[editorId];
            if (mceCfg) {
                mceCfg.selector = '#' + editorId;
                window.tinymce.init(mceCfg);
            }
            var qtCfg = window.tinyMCEPreInit && window.tinyMCEPreInit.qtInit[editorId];
            var existingQt = window.QTags && window.QTags.getInstance(editorId);
            if (existingQt) { try { existingQt.remove(); } catch (e) {} }
            if (qtCfg && window.quicktags) {
                qtCfg.id = editorId;
                window.quicktags(qtCfg);
                if (window.QTags && typeof window.QTags._buttonsInit === 'function') {
                    window.QTags._buttonsInit();
                }
            }
        });
    };
})();
