(function () {
    // Conditional field display (`show_if:` in the companion yml).
    //
    // A field rendered with data-rad-show-field="<name>" is shown only while
    // the sibling field <name> (a select, radio group or checkbox in the same
    // scope) holds one of the JSON-encoded data-rad-show-values. The scope is
    // the nearest repeater row, flexible block or field box, so a condition in
    // a repeater row only reacts to the controller in that same row.
    //
    // Emitted once with the shared admin styles; guarded so a second copy is
    // inert. Plain DOM (no jQuery) so it works on any admin screen.
    if (window.__radConditionsBound) return;
    window.__radConditionsBound = true;

    var SCOPE = '.rad-companion-repeater-row, .rad-flexible-block-body, .rad-companion-fields, form';

    function scopeOf(el) {
        return el.parentElement ? el.parentElement.closest(SCOPE) : null;
    }

    // The controller is the field wrapper named `name` whose own scope is the
    // same as the dependent field's (so a nested row's field never matches).
    function findController(field) {
        var scope = scopeOf(field);
        if (!scope) return null;
        var name = field.getAttribute('data-rad-show-field');
        var candidates = scope.querySelectorAll('.rad-companion-field[data-rad-field]');
        for (var i = 0; i < candidates.length; i++) {
            var c = candidates[i];
            if (c !== field && c.getAttribute('data-rad-field') === name && scopeOf(c) === scope) {
                return c;
            }
        }
        return null;
    }

    function controllerValue(wrap) {
        var control = wrap.querySelector('.rad-companion-field-control');
        if (!control) return '';
        var radios = control.querySelectorAll('input[type=radio]');
        if (radios.length) {
            for (var i = 0; i < radios.length; i++) {
                if (radios[i].checked) return radios[i].value;
            }
            return '';
        }
        var box = control.querySelector('input[type=checkbox]');
        if (box) return box.checked ? '1' : '';
        var input = control.querySelector('select, input, textarea');
        return input ? String(input.value) : '';
    }

    function setVisible(field, visible) {
        field.classList.toggle('rad-companion-field--hidden', !visible);
        field.hidden = !visible;
        // A hidden `required` input would block the post from saving with an
        // error the editor can't see, so park the attribute while hidden.
        field.querySelectorAll('[required], [data-rad-required]').forEach(function (el) {
            if (!visible && el.hasAttribute('required')) {
                el.removeAttribute('required');
                el.setAttribute('data-rad-required', '1');
            } else if (visible && el.hasAttribute('data-rad-required')) {
                el.setAttribute('required', '');
                el.removeAttribute('data-rad-required');
            }
        });
        // TinyMCE sizes its iframe on init; nudge it once it becomes visible.
        if (visible && window.tinymce) {
            field.querySelectorAll('.wp-editor-wrap').forEach(function (wrap) {
                var id = (wrap.id || '').replace(/^wp-/, '').replace(/-wrap$/, '');
                var ed = id && window.tinymce.get(id);
                if (ed && ed.fire) { try { ed.fire('ResizeEditor'); } catch (e) {} }
            });
        }
    }

    function evaluate(field) {
        // Fields inside <template> content are inert until cloned.
        if (!field.isConnected) return;
        var values = [];
        try { values = JSON.parse(field.getAttribute('data-rad-show-values') || '[]'); } catch (e) { values = []; }
        var controller = findController(field);
        // No controller found (typo in the yml): fail open so nothing is lost.
        if (!controller) { setVisible(field, true); return; }
        // A hidden controller hides its dependants too (chained conditions).
        var visible = !controller.hidden && values.indexOf(controllerValue(controller)) !== -1;
        setVisible(field, visible);
    }

    function refresh(root) {
        var fields = (root || document).querySelectorAll('.rad-companion-field[data-rad-show-field]');
        // Two passes so chained conditions settle regardless of DOM order.
        for (var pass = 0; pass < 2; pass++) {
            for (var i = 0; i < fields.length; i++) evaluate(fields[i]);
        }
    }

    window.radRefreshConditions = refresh;

    document.addEventListener('change', function (e) {
        if (e.target && e.target.closest && e.target.closest('.rad-companion-field')) {
            var scope = e.target.closest(SCOPE) || document;
            refresh(scope);
        }
    });

    // New repeater rows / flexible blocks are cloned from <template>s; watch
    // for them so their conditions apply as soon as they land.
    function observe() {
        refresh(document);
        if (!window.MutationObserver) return;
        var pending = false;
        new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length) {
                    if (!pending) {
                        pending = true;
                        setTimeout(function () { pending = false; refresh(document); }, 0);
                    }
                    return;
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observe);
    } else {
        observe();
    }
})();
