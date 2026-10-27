(function () {
    // Emitted once per media field; the bindings are document-level so they
    // only need to be registered ONCE per page.
    if (window.__radMediaBound) return;
    window.__radMediaBound = true;

    function init() {
        if (typeof jQuery === 'undefined' || !jQuery.fn.jquery) return;
        var jQuery$ = jQuery;

        function render(el, id, url, alt) {
            var preview = el.find('.rad-companion-media-preview');
            preview.removeAttr('data-id').attr('data-id', id);
            if (url) {
                preview.html('<img class="rad-companion-media-img" src="' + url + '" alt="' + (alt || '') + '" />');
            } else {
                preview.html('<p class="description">No media selected.</p>');
            }
        }

        function frame(input, el) {
            var f = wp.media({
                title: 'Choose Media',
                button: { text: 'Use this media' },
                library: { type: 'image' },
                multiple: false,
                modal: true
            });
            f.on('select', function () {
                var att = f.state().get('selection').first().toJSON();
                input.val(JSON.stringify({ id: att.id, url: att.url, alt: att.alt || '' }));
                // Notify any enclosing repeater to re-sync its hidden JSON.
                input.trigger('change');
                render(el, att.id, att.url, att.alt || '');
            });
            return f;
        }

        // Hydrate previews from stored values.
        jQuery$('[id^=rad-companion-media-]').each(function () {
            var el = jQuery$(this);
            var input = el.find('input[type=hidden]');
            var cur = {};
            try { cur = JSON.parse(input.val() || '{}'); } catch (e) { cur = {}; }
            render(el, cur.id || '', cur.url || '', cur.alt || '');
        });

        // Event delegation: works even if this script is stripped from scope.
        jQuery$(document).on('click', 'a.rad-companion-choose', function (e) {
            e.preventDefault();
            var el = jQuery$(this).closest('.rad-companion-media');
            frame(el.find('input[type=hidden]'), el).open();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
