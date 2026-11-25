(function () {
    // Emitted once per media field; the bindings are document-level so they
    // only need to be registered ONCE per page.
    if (window.__radMediaBound) return;
    window.__radMediaBound = true;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function baseName(url) {
        if (!url) return '';
        var path = String(url).split(/[?#]/)[0];
        var slash = path.lastIndexOf('/');
        return slash >= 0 ? path.substring(slash + 1) : path;
    }

    function isPdf(url) {
        return /\.pdf$/i.test(String(url || '').split(/[?#]/)[0]);
    }

    // Images get an <img>. Other files get a document preview with the
    // filename underneath: the attachment's thumbnail (data-preview-url, e.g.
    // the first-page image WP generates for a PDF) when it has one, otherwise
    // — PDFs only — the PDF itself in the browser's built-in viewer. Anything
    // else (video, Office…) gets a filename tile.
    // `type` of 'unknown' falls back to sniffing the URL so a cloned row whose
    // wrapper never learned the attachment type still draws correctly.
    // Mirrors CompanionFields::mediaPreviewMarkup().
    function render(el, id, url, alt, type) {
        var preview = el.children('.rad-companion-media-preview');
        preview.attr('data-id', id || '');

        if (!url) {
            preview.html('<p class="description">No media selected.</p>');
            return;
        }

        if (!type || type === 'unknown') {
            type = /\.(jpe?g|png|gif|webp|svg|avif|bmp)$/i.test(String(url).split(/[?#]/)[0]) ? 'image' : 'application';
        }

        if (type === 'image') {
            preview.html('<img class="rad-companion-media-img" src="' + esc(url) + '" alt="' + esc(alt) + '" />');
            return;
        }

        var previewUrl = el.attr('data-preview-url') || '';
        if (previewUrl || isPdf(url)) {
            var page = previewUrl
                ? '<img src="' + esc(previewUrl) + '" alt="" />'
                : '<iframe src="' + esc(url) + '#toolbar=0&amp;navpanes=0&amp;scrollbar=0&amp;view=FitH" title="PDF preview" tabindex="-1" loading="lazy"></iframe>';
            preview.html(
                '<figure class="rad-companion-media-doc">'
                + '<span class="rad-companion-media-doc-page">' + page + '</span>'
                + '<figcaption class="rad-companion-media-file-name">' + esc(baseName(url)) + '</figcaption>'
                + '</figure>'
            );
            return;
        }

        var icon = el.attr('data-mime-icon') || '';
        var iconHtml = icon
            ? '<img class="rad-companion-media-file-icon" src="' + esc(icon) + '" alt="" aria-hidden="true" />'
            : '';
        preview.html(
            '<div class="rad-companion-media-file">'
            + iconHtml
            + '<span class="rad-companion-media-file-name">' + esc(baseName(url)) + '</span>'
            + '</div>'
        );
    }

    // Shared with repeater.js / flexible.js so cloned rows draw previews the
    // same way. Defined up front (not in init) so it exists before DOM ready.
    window.radRenderMedia = render;

    function hydrate(scope) {
        if (typeof jQuery === 'undefined' || !jQuery.fn.jquery) return;

        jQuery(scope || document).find('.rad-companion-media').each(function () {
            var el = jQuery(this);
            var input = el.children('input[type=hidden]');
            var cur = {};
            try { cur = JSON.parse(input.val() || '{}'); } catch (e) { cur = {}; }

            var preview = el.children('.rad-companion-media-preview');
            if (String(preview.attr('data-id') || '') === String(cur.id || '') && preview.children().length) return;

            if (window.radRenderMedia) {
                window.radRenderMedia(el, cur.id || '', cur.url || '', cur.alt || '', el.attr('data-attachment-type') || 'unknown');
                return;
            }

            preview.attr('data-id', cur.id || '');
            if (!cur.url) preview.html('<p class="description">No media selected.</p>');
        });
    }

    window.radRefreshMedia = hydrate;

    function init() {
        if (typeof jQuery === 'undefined' || !jQuery.fn.jquery) return;
        var jQuery$ = jQuery;

        // Map the yml `filetypes:` value onto wp.media's `library.type`.
        // 'all' returns null so the picker drops the type filter entirely.
        function libraryType(filetypes) {
            switch (filetypes) {
                case 'videos': return 'video';
                case 'documents': return 'application';
                case 'all': return null;
                case 'image':
                default: return 'image';
            }
        }

        function frame(input, el) {
            var type = libraryType(el.attr('data-filetypes'));
            var opts = {
                title: 'Choose Media',
                button: { text: 'Use this media' },
                multiple: false,
                modal: true
            };
            opts.library = type ? { type: type } : {};

            var f = wp.media(opts);
            f.on('select', function () {
                var picked = f.state().get('selection').first();
                if (!picked) return;
                var att = picked.toJSON();
                // Preview from a generated size when there is one: a full-size
                // original is slow to load in a small admin tile.
                var previewUrl = att.url;
                if (att.sizes) {
                    var size = att.sizes.medium || att.sizes.thumbnail || att.sizes.full;
                    if (size && size.url) previewUrl = size.url;
                }
                var attType = att && att.type ? String(att.type) : (el.attr('data-attachment-type') || 'unknown');
                el.attr('data-attachment-type', attType);
                // Non-image files with generated sizes (a PDF's first-page
                // image) preview from that thumbnail; clear any stale one.
                el.attr('data-preview-url', attType !== 'image' && previewUrl !== att.url ? previewUrl : '');
                // Reuse the icon wp.media already resolved for this attachment
                // so the tile matches what the library showed.
                if (att.icon) el.attr('data-mime-icon', att.icon);
                // Draw the preview BEFORE notifying listeners: the change
                // event re-syncs the enclosing repeater / flexible field.
                render(el, att.id, previewUrl, att.alt || '', attType);
                input.val(JSON.stringify({ id: att.id, url: att.url, alt: att.alt || '' }));
                input.trigger('change');
            });
            return f;
        }

        // Hydrate previews from stored values. The server already rendered the
        // mime-aware preview (img for images, filename tile for documents) on
        // initial page load; hydrate() only repairs previews that have fallen
        // out of step with their hidden input (cloned rows, AJAX-injected
        // template-preview groups, ...).
        hydrate(document);

        // Event delegation: works even if this script is stripped from scope.
        jQuery$(document).on('click', 'a.rad-companion-choose', function (e) {
            e.preventDefault();
            var el = jQuery$(this).closest('.rad-companion-media');
            // children(), not find(): a nested field's inputs must never be
            // picked up as this field's value input.
            frame(el.children('input[type=hidden]'), el).open();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
