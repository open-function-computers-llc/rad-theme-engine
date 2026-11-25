(function () {
    // Live companion fields on the page edit screen.
    //
    // WordPress only stores the page template in `_wp_page_template`, so the
    // engine can tell which companion yml applies after a save. This script
    // removes that wait: it watches the "Template" dropdown, asks the server for
    // the group belonging to the newly selected template, and swaps it into the
    // preview container — the same shape as ACF's `acf.screen` model (watch
    // #page_template, fetch what now applies, show/enable the match and
    // hide/disable the rest).
    //
    // Groups are kept in the DOM once fetched (hidden + disabled) so an editor
    // that flips back and forth keeps what it typed and its live TinyMCE
    // instances. Disabled controls are not submitted, so only the selected
    // template's fields ever reach the save handler.
    //
    // jQuery is already on the post screen, and CompanionFields enqueues the
    // editor + media manager whenever any companion group needs them.
    if (window.__radTemplatePreviewBound) return;
    window.__radTemplatePreviewBound = true;

    var config = window.radCompanionPreview;
    if (!config || !config.templates || typeof jQuery === 'undefined' || !jQuery.fn.jquery) return;

    var $ = jQuery;
    var select = document.getElementById('page_template');
    if (!select) return;

    var containers = [];
    var current = typeof config.active === 'string' ? config.active : '';
    // template -> promise of the AJAX payload, shared by both columns.
    var inflight = {};
    // How many group fetches are still on screen-bound work; publishing while one
    // is running would post a form whose fields have not arrived yet.
    var loading = 0;
    // The browser must not warn once the post form has actually started
    // submitting: WordPress is leaving the page to save, not abandoning edits.
    var submitting = false;
    // TinyMCE can rewrite a textarea during init (plain text -> <p>text</p>).
    // Until that has settled we keep re-taking the baseline, but any real edit
    // stops us from overwriting the user's unsaved state.
    var interacted = false;
    var baselineTimer = null;
    var baselineTries = 0;

    // --- helpers -----------------------------------------------------------

    function eachContainer(fn) {
        containers.forEach(function (container) {
            fn(container.$el, container.set);
        });
    }

    function groupNode($el, template) {
        return $el.find('> .rad-companion-group[data-rad-template="' + cssEscape(template) + '"]');
    }

    function cssEscape(value) {
        return String(value).replace(/["\\]/g, '\\$&');
    }

    function label(template) {
        if (!template) return 'the default template';
        var entry = config.templates[template];
        return (entry && entry.label) ? entry.label : template;
    }

    function wantsGroup(template, set) {
        var entry = config.templates[template];
        return !!(entry && entry.sets && entry.sets[set]);
    }

    // Only the selected group's controls post. Disabling the others also stops
    // the browser failing required-field validation on fields the editor cannot
    // see (ACF does the same in Postbox.hideDisable()).
    function setEnabled($group, enabled) {
        $group.find('input, textarea, select, button').prop('disabled', !enabled);
    }

    function show($group, visible) {
        $group.prop('hidden', !visible);
        setEnabled($group, visible);
        if (visible && window.tinymce) {
            $group.find('.wp-editor-wrap').each(function () {
                var id = ($(this).attr('id') || '').replace(/^wp-/, '').replace(/-wrap$/, '');
                var ed = id && window.tinymce.get(id);
                if (ed && ed.fire) {
                    // Editors created while their group was hidden sized their
                    // iframe to nothing; nudge them the way conditions.js does.
                    try { ed.fire('ResizeEditor'); } catch (e) {}
                }
            });
        }
    }

    // Field scripts are document-level, but DOM-ready only fired once (for the
    // group rendered server-side). Newly appended AJAX groups need the same
    // first-paint pass: conditions, media tiles, repeater numbering, and the
    // flexible bulk / collapse state.
    function refreshInjected($scope) {
        if (window.radRefreshConditions) window.radRefreshConditions($scope[0]);
        if (window.radRefreshMedia) window.radRefreshMedia($scope);
        if (window.radRefreshRepeater) window.radRefreshRepeater($scope);
        if (window.radRefreshFlexible) window.radRefreshFlexible($scope);
    }

    // WordPress prints some TinyMCE / Quicktags init values unquoted because they
    // are JavaScript literals (see _WP_Editors::_parse_init). The server marks
    // those with @radLiteral so they survive the JSON trip; revive them here.
    function revive(value) {
        if (Array.isArray(value)) return value.map(revive);
        if (value && typeof value === 'object') {
            if (typeof value['@radLiteral'] === 'string') {
                var src = value['@radLiteral'];
                try { return JSON.parse(src); } catch (e) {}
                // Object / array / regexp literals that are not strict JSON (e.g.
                // a tinyMCE plugin list). The text is our own server output,
                // emitted unquoted by WordPress on a normal page load anyway.
                try { return (new Function('return (' + src + ');'))(); } catch (e) { return src; }
            }
            var out = {};
            Object.keys(value).forEach(function (key) { out[key] = revive(value[key]); });
            return out;
        }
        return value;
    }

    // A wp_editor() printed by an ordinary page request boots itself from the
    // init objects WordPress prints at admin_print_footer_scripts — a hook that
    // never fires during admin-ajax. Boot those editors here instead, mirroring
    // the bootstrap WordPress prints inline: tinymce.init(mceInit[id]) and
    // quicktags(qtInit[id]).
    function initEditors(editors) {
        if (!window.tinyMCEPreInit) return;

        Object.keys(editors || {}).forEach(function (id) {
            var cfg = editors[id] || {};

            if (cfg.tinymce && window.tinymce && !window.tinymce.get(id)) {
                var mce = revive(cfg.tinymce);
                var previousInit = mce.init_instance_callback;
                mce.selector = '#' + id;
                mce.init_instance_callback = function (editor) {
                    if (typeof previousInit === 'function') {
                        previousInit.call(this, editor);
                    }
                    bindTinyMceEditor(editor);
                    scheduleBaseline(0);
                };
                window.tinyMCEPreInit.mceInit[id] = mce;
                window.tinymce.init(mce);
            }

            if (cfg.quicktags && window.quicktags) {
                var qt = revive(cfg.quicktags);
                qt.id = id;
                window.tinyMCEPreInit.qtInit[id] = qt;
                var existing = window.QTags && window.QTags.getInstance && window.QTags.getInstance(id);
                if (!existing) {
                    window.quicktags(qt);
                    if (window.QTags && typeof window.QTags._buttonsInit === 'function') {
                        window.QTags._buttonsInit();
                    }
                }
            }
        });
    }

    // --- dirty tracking ----------------------------------------------------

    function flushEditors() {
        if (window.tinymce && window.tinymce.triggerSave) {
            try { window.tinymce.triggerSave(); } catch (e) {}
        }
    }

    function snapshot() {
        flushEditors();

        var parts = [];
        eachContainer(function ($el) {
            var $group = groupNode($el, current);
            if (!$group.length) return;
            $group.find('input, textarea, select').each(function () {
                if (!this.name) return;
                var value = (this.type === 'checkbox' || this.type === 'radio')
                    ? (this.checked ? '1' : '')
                    : this.value;
                parts.push(this.name + '=' + value);
            });
        });

        return parts.join('\n');
    }

    var saved = null;

    function currentIsDirty() {
        return current !== '' && saved !== null && snapshot() !== saved;
    }

    function isCompanionEditorId(id) {
        return String(id).indexOf('rad_wysiwyg_') === 0;
    }

    function pendingCompanionEditors() {
        if (!window.tinyMCEPreInit || !window.tinymce || !window.tinymce.get) return false;

        var pending = false;
        Object.keys(window.tinyMCEPreInit.mceInit || {}).forEach(function (id) {
            if (!isCompanionEditorId(id)) return;
            var editor = window.tinymce.get(id);
            if (!editor || !editor.initialized) pending = true;
        });

        return pending;
    }

    function markTouched() {
        interacted = true;
    }

    function bindTinyMceEditor(editor) {
        if (!editor || !editor.on) return;
        if (isCompanionEditorId(editor.id)) {
            editor.on('change', markTouched);
        }
        editor.on('init', function () {
            scheduleBaseline(0);
        });
    }

    function hookTinyMce() {
        if (!window.tinymce || window.__radCompanionTinyMceHooked) return;
        window.__radCompanionTinyMceHooked = true;

        (window.tinymce.editors || []).forEach(bindTinyMceEditor);

        var manager = window.tinymce.EditorManager || window.tinymce;
        if (manager && manager.on) {
            manager.on('AddEditor', function (event) {
                bindTinyMceEditor(event.editor || event);
            });
        }
    }

    function scheduleBaseline(delay) {
        if (submitting || interacted) return;
        if (baselineTimer) clearTimeout(baselineTimer);

        baselineTimer = setTimeout(function () {
            baselineTimer = null;
            if (submitting || interacted) return;

            if (pendingCompanionEditors() && baselineTries < 100) {
                baselineTries++;
                scheduleBaseline(50);
                return;
            }

            baselineTries = 0;
            saved = snapshot();
        }, delay || 80);
    }

    // --- fetching ----------------------------------------------------------

    function fetchGroup(template) {
        if (inflight[template]) return inflight[template];

        inflight[template] = $.post(config.ajaxUrl, {
            action: 'rad_companion_template_group',
            post_id: config.postId,
            template: template,
            nonce: config.nonce,
        }).then(function (response) {
            if (!response || !response.success || !response.data) {
                inflight[template] = null;
                return $.Deferred().reject(
                    (response && response.data && response.data.message)
                    || 'The fields for this template could not be loaded.'
                ).promise();
            }
            return response.data;
        }, function () {
            inflight[template] = null;
            return $.Deferred().reject('The fields for this template could not be loaded.').promise();
        });

        return inflight[template];
    }

    function mount($el, set, template, data) {
        var $group = $('<div class="rad-companion-group"></div>')
            .attr('data-rad-template', template)
            .attr('hidden', 'hidden')
            .append((data.html && data.html[set]) || '');

        $el.append($group);
        refreshInjected($group);

        return $group;
    }

    function setActiveTemplate(template) {
        document.body.setAttribute('data-rad-active-template', template);
        eachContainer(function ($el) {
            $el.find('> input[name="rad_companion_preview_template"]').val(template);
        });

        // A template's `hidden: content` hides the post editor with CSS, so it was
        // initialised at 0 height and needs the same nudge a `show_if:` reveal
        // gets (conditions.js) once the new template brings it back.
        setTimeout(function () {
            var content = window.tinymce && window.tinymce.get('content');
            if (content && content.fire) {
                try { content.fire('ResizeEditor'); } catch (e) {}
            }
        }, 0);
    }

    // A page on the theme's default template has no companion fields at all, and
    // neither column should show an empty "Custom Fields" box for it. WP's own
    // screen-options toggle uses the same .hidden class, so this composes with it.
    function syncBoxVisibility() {
        eachContainer(function ($el) {
            var visible = $el.find('> .rad-companion-group:not([hidden])').length > 0;
            $el.closest('.postbox').toggleClass('hidden', !visible);
        });
    }

    // --- the swap ----------------------------------------------------------

    function apply(template) {
        if (template === current) return;

        if (currentIsDirty()) {
            var keep = window.confirm(
                'You have unsaved changes in the ' + label(current) + ' fields.\n\n'
                + 'Switching to ' + label(template) + ' discards them: only the fields of '
                + 'the template you publish with are stored.'
            );
            if (!keep) {
                select.value = current || 'default';
                return;
            }
        }

        var previous = current;
        current = template;
        setActiveTemplate(template);

        var pending = [];

        eachContainer(function ($el, set) {
            $el.find('> .rad-companion-group').each(function () {
                show($(this), false);
            });

            var $match = groupNode($el, template);
            if ($match.length) {
                show($match, true);
                return;
            }
            if (!wantsGroup(template, set)) return;

            loading++;
            var $notice = $('<p class="description rad-companion-preview-loading">Loading this template\u2019s fields\u2026</p>');
            $el.append($notice);

            pending.push(fetchGroup(template).then(function (data) {
                $notice.remove();
                var $created = mount($el, set, template, data);
                // Visible before the editors boot: TinyMCE sizes its iframe from
                // what it can measure, and a hidden container measures nothing.
                show($created, true);
                initEditors(data.editors);
                syncBoxVisibility();
            }, function (message) {
                $notice.remove();
                return $.Deferred().reject(message).promise();
            }));
        });

        if (!pending.length) {
            saved = snapshot();
            syncBoxVisibility();
            return;
        }

        $.when.apply($, pending).then(function () {
            loading -= pending.length;
            saved = snapshot();
        }, function (message) {
            // Roll the dropdown back so it keeps describing what is on screen.
            loading -= pending.length;
            current = previous;
            setActiveTemplate(previous);
            eachContainer(function ($el) {
                $el.find('> .rad-companion-group').each(function () {
                    show($(this), $(this).attr('data-rad-template') === previous);
                });
            });
            select.value = previous || 'default';
            syncBoxVisibility();
            window.alert(typeof message === 'string' ? message : 'The fields for this template could not be loaded.');
        });
    }

    // --- boot --------------------------------------------------------------

    $(function () {
        containers = [];
        $('.rad-companion-preview').each(function () {
            containers.push({ $el: $(this), set: $(this).attr('data-rad-set') || 'fields' });
        });

        if (!containers.length) return;

        // The container renders the saved template server-side, so the screen is
        // usable without this script; adopt whatever is already on screen.
        var initial = typeof config.active === 'string' ? config.active : '';
        current = initial;
        // Always set, even when empty: the `hidden:` CSS keys off the attribute's
        // presence so a switch back to the default template can bring a hidden
        // editor area back.
        setActiveTemplate(initial);

        eachContainer(function ($el) {
            $el.find('> .rad-companion-group').each(function () {
                var $group = $(this);
                show($group, $group.attr('data-rad-template') === initial);
            });
        });

        syncBoxVisibility();
        saved = snapshot();

        $(document).on('input change', '.rad-companion-group', markTouched);
        hookTinyMce();
        scheduleBaseline(80);

        $(document).on('change', '#page_template', function () {
            var value = String(this.value || '');
            apply(value === 'default' ? '' : value);
        });

        // Publishing while a fetch is still running would post a form whose
        // fields have not landed yet (their values would be skipped on save).
        $(document).on('submit', '#post', function (e) {
            if (loading) {
                e.preventDefault();
                window.alert('The fields for the selected template are still loading. Try again in a moment.');
                return;
            }

            submitting = true;
            interacted = false;
            saved = snapshot();
        });

        $(window).on('beforeunload', function () {
            if (submitting || !currentIsDirty()) return undefined;
            return 'Your custom field changes have not been saved yet.';
        });
    });
})();
