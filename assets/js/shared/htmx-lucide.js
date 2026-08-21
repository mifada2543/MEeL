lucide.createIcons();
document.body.addEventListener('htmx:afterOnLoad', function(e) {
    lucide.createIcons({}, e.detail?.target || document.body);
});
