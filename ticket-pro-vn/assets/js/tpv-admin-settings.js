(function () {
    function syncAttachmentOptions() {
        var enabled = document.getElementById('tpv-attachments-enabled');
        var options = document.getElementById('tpv-attachment-options');
        var controls;
        var i;

        if (!enabled || !options) {
            return;
        }

        controls = options.querySelectorAll('input');
        options.classList.toggle('is-disabled', !enabled.checked);
        options.setAttribute('aria-disabled', enabled.checked ? 'false' : 'true');
        for (i = 0; i < controls.length; i++) {
            controls[i].disabled = !enabled.checked;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var enabled = document.getElementById('tpv-attachments-enabled');
        var flash = document.querySelector('.tpv-admin-flash');
        var openPurge = document.querySelector('[data-tpv-open-purge-modal]');
        var purgeModal = document.querySelector('[data-tpv-purge-modal]');
        var purgeInput = document.querySelector('[data-tpv-purge-confirmation]');
        var purgeSubmit = document.querySelector('[data-tpv-confirm-purge]');
        var closePurge;
        var closePurgeButtons;
        var index;
        var close;

        function closePurgeModal() {
            if (!purgeModal) {
                return;
            }
            purgeModal.hidden = true;
            if (purgeInput) {
                purgeInput.value = '';
            }
            if (purgeSubmit) {
                purgeSubmit.disabled = true;
            }
            if (openPurge) {
                openPurge.focus();
            }
        }

        function openPurgeModal() {
            if (!purgeModal) {
                return;
            }
            purgeModal.hidden = false;
            if (purgeInput) {
                purgeInput.focus();
            }
        }

        if (flash) {
            close = flash.querySelector('.tpv-admin-flash-close');
            if (close) {
                close.addEventListener('click', function () {
                    flash.parentNode.removeChild(flash);
                });
            }
            if (!flash.classList.contains('is-error')) {
                window.setTimeout(function () {
                    if (flash.parentNode) {
                        flash.classList.add('is-leaving');
                        window.setTimeout(function () {
                            if (flash.parentNode) {
                                flash.parentNode.removeChild(flash);
                            }
                        }, 240);
                    }
                }, 5000);
            }
        }

        if (openPurge && purgeModal) {
            openPurge.addEventListener('click', openPurgeModal);
            closePurgeButtons = purgeModal.querySelectorAll('[data-tpv-close-purge-modal]');
            for (index = 0; index < closePurgeButtons.length; index++) {
                closePurgeButtons[index].addEventListener('click', closePurgeModal);
            }
            if (purgeInput && purgeSubmit) {
                purgeInput.addEventListener('input', function () {
                    purgeSubmit.disabled = purgeInput.value !== 'DELETE';
                });
            }
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !purgeModal.hidden) {
                    closePurgeModal();
                }
            });
        }

        if (!enabled) {
            return;
        }
        enabled.addEventListener('change', syncAttachmentOptions);
        syncAttachmentOptions();
    });
}());
