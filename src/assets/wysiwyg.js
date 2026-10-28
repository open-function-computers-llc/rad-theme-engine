(function () {
    if (window.__radWysiwygBound) return;
    window.__radWysiwygBound = true;
    document.addEventListener('submit', function () {
        if (window.tinymce) {
            tinymce.triggerSave();
        }
    }, true);
})();
