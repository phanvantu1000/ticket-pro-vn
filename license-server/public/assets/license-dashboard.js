(function () {
    var shell = document.querySelector('.license-shell');
    var csrf = shell ? shell.getAttribute('data-csrf') : '';
    var copyButtons = document.querySelectorAll('[data-copy]');
    var keyAccessButtons = document.querySelectorAll('[data-key-id]');
    var keyDialog = document.getElementById('key-dialog');
    var keyDialogLabel = document.getElementById('key-dialog-label');
    var revealedKey = document.getElementById('revealed-key');
    var copyRevealedKey = document.getElementById('copy-revealed-key');
    var deleteButtons = document.querySelectorAll('[data-delete-id]');
    var deleteDialog = document.getElementById('delete-dialog');
    var deleteId = document.getElementById('delete-license-id');
    var deleteLabel = document.getElementById('delete-license-label');
    var deleteConfirmation = document.getElementById('delete-confirmation');
    var deleteConfirmButton = document.getElementById('confirm-delete-button');
    var replaceButtons = document.querySelectorAll('[data-replace-id]');
    var replaceDialog = document.getElementById('replace-dialog');
    var replaceId = document.getElementById('replace-license-id');
    var replaceLabel = document.getElementById('replace-license-label');
    var replaceConfirmation = document.getElementById('replace-confirmation');
    var replaceConfirmButton = document.getElementById('confirm-replace-button');
    var revealedValue = '';

    function fallbackCopy(value) {
        var input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', 'readonly');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
    }

    function copyValue(value, button, defaultLabel) {
        if (!value) { return; }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).catch(function () { fallbackCopy(value); });
        } else {
            fallbackCopy(value);
        }
        if (button) {
            button.textContent = 'Đã sao chép';
            window.setTimeout(function () { button.textContent = defaultLabel; }, 1800);
        }
    }

    function encodeBody(data) {
        var parts = [];
        var name;
        for (name in data) {
            if (Object.prototype.hasOwnProperty.call(data, name)) {
                parts.push(encodeURIComponent(name) + '=' + encodeURIComponent(data[name]));
            }
        }
        return parts.join('&');
    }

    function accessKey(id, mode, callback) {
        var request = new XMLHttpRequest();
        request.open('POST', window.location.href, true);
        request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        request.setRequestHeader('Accept', 'application/json');
        request.onreadystatechange = function () {
            var response;
            if (request.readyState !== 4) { return; }
            try { response = JSON.parse(request.responseText || '{}'); } catch (error) { response = {}; }
            if (request.status >= 200 && request.status < 300 && response.key) {
                callback(null, response.key);
            } else {
                callback(response.error || 'Không thể truy cập key. Vui lòng thử lại.');
            }
        };
        request.send(encodeBody({ action: 'access_key', id: id, mode: mode, csrf: csrf }));
    }

    Array.prototype.forEach.call(copyButtons, function (button) {
        button.addEventListener('click', function () {
            copyValue(button.getAttribute('data-copy') || '', button, 'Sao chép key');
        });
    });

    Array.prototype.forEach.call(keyAccessButtons, function (button) {
        button.addEventListener('click', function () {
            var id = button.getAttribute('data-key-id') || '';
            var mode = button.getAttribute('data-key-mode') || 'copy';
            var originalLabel = button.textContent;
            if (!id || !csrf) { return; }
            button.disabled = true;
            button.textContent = 'Đang xử lý...';
            accessKey(id, mode, function (error, key) {
                button.disabled = false;
                button.textContent = originalLabel;
                if (error) { window.alert(error); return; }
                if (mode === 'copy') {
                    copyValue(key, button, originalLabel);
                    return;
                }
                revealedValue = key;
                if (revealedKey) { revealedKey.textContent = key; }
                if (keyDialogLabel) { keyDialogLabel.textContent = 'Key #' + id; }
                if (keyDialog && typeof keyDialog.showModal === 'function') { keyDialog.showModal(); }
            });
        });
    });

    if (copyRevealedKey) {
        copyRevealedKey.addEventListener('click', function () { copyValue(revealedValue, copyRevealedKey, 'Sao chép key'); });
    }

    function closeDialog(dialog) {
        if (dialog && typeof dialog.close === 'function') { dialog.close(); }
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-key-close]'), function (button) {
        button.addEventListener('click', function () { closeDialog(keyDialog); });
    });
    if (keyDialog) {
        keyDialog.addEventListener('close', function () { revealedValue = ''; if (revealedKey) { revealedKey.textContent = ''; } });
        keyDialog.addEventListener('click', function (event) { if (event.target === keyDialog) { closeDialog(keyDialog); } });
    }

    function resetDeleteDialog() {
        if (deleteConfirmation) { deleteConfirmation.value = ''; }
        if (deleteConfirmButton) { deleteConfirmButton.disabled = true; }
        if (deleteId) { deleteId.value = ''; }
        if (deleteLabel) { deleteLabel.textContent = ''; }
    }

    Array.prototype.forEach.call(deleteButtons, function (button) {
        button.addEventListener('click', function () {
            if (!deleteDialog || !deleteId || !deleteLabel) { return; }
            resetDeleteDialog();
            deleteId.value = button.getAttribute('data-delete-id') || '';
            deleteLabel.textContent = button.getAttribute('data-delete-label') || '';
            if (typeof deleteDialog.showModal === 'function') {
                deleteDialog.showModal();
                if (deleteConfirmation) { window.setTimeout(function () { deleteConfirmation.focus(); }, 0); }
            }
        });
    });

    if (deleteConfirmation && deleteConfirmButton) {
        deleteConfirmation.addEventListener('input', function () { deleteConfirmButton.disabled = deleteConfirmation.value !== 'DELETE'; });
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-delete-close]'), function (button) {
        button.addEventListener('click', function () { closeDialog(deleteDialog); });
    });
    if (deleteDialog) {
        deleteDialog.addEventListener('close', resetDeleteDialog);
        deleteDialog.addEventListener('click', function (event) { if (event.target === deleteDialog) { closeDialog(deleteDialog); } });
    }

    function resetReplaceDialog() {
        if (replaceConfirmation) { replaceConfirmation.value = ''; }
        if (replaceConfirmButton) { replaceConfirmButton.disabled = true; }
        if (replaceId) { replaceId.value = ''; }
        if (replaceLabel) { replaceLabel.textContent = ''; }
    }

    Array.prototype.forEach.call(replaceButtons, function (button) {
        button.addEventListener('click', function () {
            if (!replaceDialog || !replaceId || !replaceLabel) { return; }
            resetReplaceDialog();
            replaceId.value = button.getAttribute('data-replace-id') || '';
            replaceLabel.textContent = button.getAttribute('data-replace-label') || '';
            if (typeof replaceDialog.showModal === 'function') {
                replaceDialog.showModal();
                if (replaceConfirmation) { window.setTimeout(function () { replaceConfirmation.focus(); }, 0); }
            }
        });
    });

    if (replaceConfirmation && replaceConfirmButton) {
        replaceConfirmation.addEventListener('input', function () { replaceConfirmButton.disabled = replaceConfirmation.value !== 'REPLACE'; });
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-replace-close]'), function (button) {
        button.addEventListener('click', function () { closeDialog(replaceDialog); });
    });
    if (replaceDialog) {
        replaceDialog.addEventListener('close', resetReplaceDialog);
        replaceDialog.addEventListener('click', function (event) { if (event.target === replaceDialog) { closeDialog(replaceDialog); } });
    }
}());
