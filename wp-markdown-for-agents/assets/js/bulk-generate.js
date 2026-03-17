/* global mfaBulkGenerate */
/* WordPress admin bulk-generate AJAX loop.
 * Intercepts clicks on [data-post-type] buttons, drives sequential AJAX
 * batch requests (10 posts per request), and updates a live counter.
 */
(function () {
    'use strict';

    var BATCH_SIZE = 10;

    /**
     * Send one batch request and recurse until all posts are processed.
     *
     * @param {string} postType
     * @param {number} offset
     * @param {{processed: number, errors: Array}} accumulated
     * @param {HTMLButtonElement} button
     */
    function sendBatch(postType, offset, accumulated, button) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', mfaBulkGenerate.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            if (xhr.status !== 200) {
                button.textContent = 'Error \u2014 generation stopped';
                button.disabled = false;
                return;
            }

            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                button.textContent = 'Error \u2014 generation stopped';
                button.disabled = false;
                return;
            }

            if (!response || !response.success) {
                button.textContent = 'Error \u2014 generation stopped';
                button.disabled = false;
                return;
            }

            var data = response.data;
            accumulated.processed += data.processed;
            accumulated.errors    = accumulated.errors.concat(data.errors);

            button.textContent = accumulated.processed + ' / ' + data.total;

            if (offset + BATCH_SIZE < data.total) {
                sendBatch(postType, offset + BATCH_SIZE, accumulated, button);
            } else {
                var errorSummary = accumulated.errors.length
                    ? ', ' + accumulated.errors.length + ' error(s)'
                    : '';
                button.textContent = 'Done: ' + accumulated.processed + ' processed' + errorSummary;
                button.disabled = false;
            }
        };

        xhr.onerror = function () {
            button.textContent = 'Error \u2014 generation stopped';
            button.disabled = false;
        };

        var params = 'action=mfa_generate_batch'
            + '&nonce='     + encodeURIComponent(mfaBulkGenerate.nonce)
            + '&post_type=' + encodeURIComponent(postType)
            + '&offset='    + encodeURIComponent(offset)
            + '&limit='     + encodeURIComponent(BATCH_SIZE);

        xhr.send(params);
    }

    /**
     * @param {MouseEvent} event
     */
    function handleGenerateClick(event) {
        var button   = /** @type {HTMLButtonElement} */ (event.currentTarget);
        var postType = button.dataset.postType;

        button.disabled    = true;
        button.textContent = '0 / \u2026';

        var accumulated = { processed: 0, errors: [] };
        sendBatch(postType, 0, accumulated, button);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('button[data-post-type]');
        buttons.forEach(function (button) {
            button.addEventListener('click', handleGenerateClick);
        });
    });
}());
