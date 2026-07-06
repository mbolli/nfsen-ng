// Click-to-zoom lightbox for content screenshots. Every book chapter is a
// full page load (mdBook doesn't do client-side routing), so a plain
// DOMContentLoaded listener is enough -- no SPA-navigation re-init needed.
(function () {
    function init() {
        var dialog = document.createElement('dialog');
        dialog.id = 'lightbox-dialog';

        var img = document.createElement('img');
        img.alt = '';
        dialog.appendChild(img);
        document.body.appendChild(dialog);

        function close() {
            dialog.close();
        }

        // Clicking the backdrop (the <dialog> element itself, outside the <img>) closes it.
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) close();
        });
        img.addEventListener('click', close);

        document.querySelectorAll('.content img').forEach(function (el) {
            el.setAttribute('tabindex', '0');
            el.setAttribute('role', 'button');
            el.setAttribute('aria-label', 'Click to enlarge image');

            function open() {
                img.src = el.src;
                img.alt = el.alt;
                dialog.showModal();
            }

            el.addEventListener('click', open);
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    open();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
