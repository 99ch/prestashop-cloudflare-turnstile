(function () {
    'use strict';

    var config = window.pixelTurnstileConfig;
    if (!config || !config.sitekey) {
        return;
    }

    function findTargetForm() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            var form = forms[i];
            if (form.dataset.pxdTurnstileMounted) {
                continue;
            }
            if (!form.querySelector('button[type=submit], input[type=submit], [type=submit]')) {
                continue;
            }
            if (form.closest('#search_widget, #_desktop_search_wrapper, #_mobile_search, header, .header')) {
                continue;
            }
            var visibleInputs = form.querySelectorAll('input:not([type=hidden]):not([type=search]), textarea, select');
            if (visibleInputs.length === 0) {
                continue;
            }
            return form;
        }
        return null;
    }

    function mount() {
        var form = findTargetForm();
        if (!form) {
            return;
        }
        form.dataset.pxdTurnstileMounted = '1';

        var wrapper = document.createElement('div');
        wrapper.className = 'pxd-cloudflare-turnstile';

        var container = document.createElement('div');
        wrapper.appendChild(container);

        var submit = form.querySelector('button[type=submit], input[type=submit], [type=submit]');
        if (submit && submit.parentNode) {
            submit.parentNode.insertBefore(wrapper, submit);
        } else {
            form.appendChild(wrapper);
        }

        turnstile.render(container, {
            sitekey: config.sitekey,
            theme: config.theme || 'auto',
            appearance: config.appearance || 'always',
            action: (config.action || 'default').substring(0, 32)
        });
    }

    function whenReady() {
        if (typeof turnstile !== 'undefined' && typeof turnstile.render === 'function') {
            mount();
            return;
        }
        setTimeout(whenReady, 100);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', whenReady);
    } else {
        whenReady();
    }
})();
