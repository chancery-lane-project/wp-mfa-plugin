/* global mfaBulkGenerate */
/* WordPress admin bulk-generate AJAX loop.
 * Intercepts clicks on [data-post-type] and [data-action] buttons, drives
 * sequential AJAX batch requests, and updates a live counter.
 */
(function () {
    'use strict';

    var BATCH_SIZE = 10;

    /**
     * Send one batch request and recurse until all items are processed.
     *
     * @param {string}            action      AJAX action name.
     * @param {string|null}       postType    Post type slug, or null for taxonomy batches.
     * @param {number}            offset
     * @param {{processed: number, errors: Array}} accumulated
     * @param {HTMLButtonElement} button
     */
    function sendBatch(action, postType, offset, accumulated, button) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', mfaBulkGenerate.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            if (xhr.status !== 200) {
                button.textContent = 'Error — generation stopped';
                button.disabled = false;
                return;
            }

            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                button.textContent = 'Error — generation stopped';
                button.disabled = false;
                return;
            }

            if (!response || !response.success) {
                button.textContent = 'Error — generation stopped';
                button.disabled = false;
                return;
            }

            var data = response.data;
            accumulated.processed += data.processed;
            accumulated.errors    = accumulated.errors.concat(data.errors);

            button.textContent = accumulated.processed + ' / ' + data.total;

            if (accumulated.processed < data.total) {
                sendBatch(action, postType, offset + BATCH_SIZE, accumulated, button);
            } else {
                var errorSummary = accumulated.errors.length
                    ? ', ' + accumulated.errors.length + ' error(s)'
                    : '';
                button.textContent = 'Done: ' + accumulated.processed + ' processed' + errorSummary;
                button.disabled = false;
            }
        };

        xhr.onerror = function () {
            button.textContent = 'Error — generation stopped';
            button.disabled = false;
        };

        var params = 'action='  + encodeURIComponent(action)
            + '&nonce='         + encodeURIComponent(mfaBulkGenerate.nonce)
            + '&offset='        + encodeURIComponent(offset)
            + '&limit='         + encodeURIComponent(BATCH_SIZE);

        if (postType) {
            params += '&post_type=' + encodeURIComponent(postType);
        }

        xhr.send(params);
    }

    /**
     * @param {MouseEvent} event
     */
    function handleGenerateClick(event) {
        var button   = /** @type {HTMLButtonElement} */ (event.currentTarget);
        var postType = button.dataset.postType || null;
        var action   = button.dataset.action || 'mfa_generate_batch';

        button.disabled    = true;
        button.textContent = '0 / …';

        var accumulated = { processed: 0, errors: [] };
        sendBatch(action, postType, 0, accumulated, button);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('button[data-post-type], button[data-action]');
        buttons.forEach(function (button) {
            button.addEventListener('click', handleGenerateClick);
        });
    });
}());
