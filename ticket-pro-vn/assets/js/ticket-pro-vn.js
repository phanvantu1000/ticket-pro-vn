(function () {
    'use strict';

    function updateFilePicker(input) {
        var picker = input.parentNode;
        var name = picker ? picker.querySelector('.tpv-file-picker-name') : null;
        var count = input.files ? input.files.length : 0;
        var label;

        if (!name) {
            return;
        }

        if (!count) {
            label = name.getAttribute('data-empty');
        } else if (count === 1) {
            label = name.getAttribute('data-one');
        } else {
            label = name.getAttribute('data-many').replace('%d', count);
        }
        name.textContent = label;
    }

    function bindFilePickers() {
        var inputs = document.querySelectorAll('.tpv-file-picker-input');
        var index;

        for (index = 0; index < inputs.length; index++) {
            inputs[index].addEventListener('change', function () {
                updateFilePicker(this);
            });
        }
    }

    function bindImagePreviews() {
        var preview = document.querySelectorAll('[data-tpv-image-preview]');
        var modal;
        var image;
        var close;
        var index;

        if (!preview.length) {
            return;
        }

        modal = document.createElement('div');
        modal.className = 'tpv-image-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', 'Image preview');
        modal.innerHTML = '<button type="button" class="tpv-image-modal-close" aria-label="Close image preview">&times;</button><img alt="" />';
        image = modal.querySelector('img');
        close = modal.querySelector('.tpv-image-modal-close');
        document.body.appendChild(modal);

        function hideModal() {
            modal.className = 'tpv-image-modal';
            image.removeAttribute('src');
        }

        for (index = 0; index < preview.length; index++) {
            preview[index].addEventListener('click', function (event) {
                event.preventDefault();
                image.src = this.href;
                image.alt = this.querySelector('img').alt;
                modal.className = 'tpv-image-modal is-open';
                close.focus();
            });
        }

        close.addEventListener('click', hideModal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                hideModal();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal.className.indexOf('is-open') !== -1) {
                hideModal();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bindFilePickers();
            bindImagePreviews();
        });
    } else {
        bindFilePickers();
        bindImagePreviews();
    }
}());
