/* global markdownForAgentsBulkGenerate */
/* WordPress admin bulk-generate AJAX loop.
 * Intercepts clicks on [data-post-type] and [data-action] buttons, drives
 * sequential AJAX batch requests, and updates a live counter. A
 * [data-generate-all] button runs every post-type flow and then the
 * taxonomy flow in sequence.
 */
(function () {
    'use strict';

    var BATCH_SIZE = 10;
    var ALLOWED_ACTIONS = ['mfa_generate_batch', 'mfa_generate_taxonomy_batch'];

    /**
     * Build (or replace) an expandable list of error details beneath a button.
     *
     * The AJAX response already carries a per-item {post_id|term_id, message}
     * record for every failure; this surfaces them instead of only a count.
     *
     * @param {HTMLButtonElement}                              button
     * @param {Array<{post_id?: number, term_id?: number, message: string}>} errors
     */
    function renderErrorDetails(button, errors) {
        // Remove any list left over from a previous run of this button.
        if (button.mfaErrorDetails && button.mfaErrorDetails.parentNode) {
            button.mfaErrorDetails.parentNode.removeChild(button.mfaErrorDetails);
        }
        button.mfaErrorDetails = null;

        if (!errors.length) {
            return;
        }

        var details = document.createElement('details');
        details.className = 'mfa-error-details';

        var summary = document.createElement('summary');
        summary.textContent = 'Show ' + errors.length + ' error' + (errors.length === 1 ? '' : 's');
        details.appendChild(summary);

        var list = document.createElement('ul');
        list.style.margin = '0.5em 0 0 1.5em';
        list.style.listStyle = 'disc';

        errors.forEach(function (error) {
            var id   = error.post_id || error.term_id || '';
            var item = document.createElement('li');
            item.textContent = (id ? '#' + id + ': ' : '') + (error.message || 'Unknown error');
            list.appendChild(item);
        });

        details.appendChild(list);

        // Insert after the button's containing paragraph, falling back to the button.
        var anchor = button.parentNode || button;
        if (anchor.parentNode) {
            anchor.parentNode.insertBefore(details, anchor.nextSibling);
        }
        button.mfaErrorDetails = details;
    }

    /**
     * Send one batch request and recurse until all items are processed.
     *
     * @param {string}            action      AJAX action name.
     * @param {string|null}       postType    Post type slug, or null for taxonomy batches.
     * @param {number}            offset
     * @param {{processed: number, errors: Array}} accumulated
     * @param {HTMLButtonElement} button
     * @param {(function(boolean): void)=} onComplete Called once with success
     *     when this flow finishes or errors. Optional.
     */
    function sendBatch(action, postType, offset, accumulated, button, onComplete) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', markdownForAgentsBulkGenerate.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        function fail() {
            button.textContent = 'Error — generation stopped';
            button.disabled = false;
            if (onComplete) {
                onComplete(false);
            }
        }

        xhr.onload = function () {
            if (xhr.status !== 200) {
                fail();
                return;
            }

            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                fail();
                return;
            }

            if (!response || !response.success) {
                fail();
                return;
            }

            var data = response.data;
            accumulated.processed += parseInt(data.processed, 10);
            accumulated.errors    = accumulated.errors.concat(data.errors);

            var total = parseInt(data.total, 10);
            button.textContent = accumulated.processed + ' / ' + total;

            if (accumulated.processed < total) {
                sendBatch(action, postType, offset + BATCH_SIZE, accumulated, button, onComplete);
            } else {
                var errorSummary = accumulated.errors.length
                    ? ', ' + accumulated.errors.length + ' error(s)'
                    : '';
                button.textContent = 'Done: ' + accumulated.processed + ' processed' + errorSummary;
                button.disabled = false;
                renderErrorDetails(button, accumulated.errors);
                if (onComplete) {
                    onComplete(true);
                }
            }
        };

        xhr.onerror = fail;

        var params = 'action='  + encodeURIComponent(action)
            + '&nonce='         + encodeURIComponent(markdownForAgentsBulkGenerate.nonce)
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

        if (ALLOWED_ACTIONS.indexOf(action) === -1) {
            return;
        }

        button.disabled    = true;
        button.textContent = '0 / …';
        renderErrorDetails(button, []);

        var accumulated = { processed: 0, errors: [] };
        sendBatch(action, postType, 0, accumulated, button);
    }

    /**
     * Run every per-post-type flow and then the taxonomy flow, one after
     * another, driving each flow's own button so its counter stays live.
     *
     * @param {MouseEvent} event
     */
    function handleGenerateAllClick(event) {
        var allButton = /** @type {HTMLButtonElement} */ (event.currentTarget);
        var queue = [];

        document.querySelectorAll('button[data-post-type]').forEach(function (button) {
            queue.push({ action: 'mfa_generate_batch', postType: button.dataset.postType, button: button });
        });
        document.querySelectorAll('button[data-action="mfa_generate_taxonomy_batch"]').forEach(function (button) {
            queue.push({ action: 'mfa_generate_taxonomy_batch', postType: null, button: button });
        });

        if (!queue.length) {
            return;
        }

        var total = queue.length;
        allButton.disabled = true;
        queue.forEach(function (step) {
            step.button.disabled = true;
        });

        function runNext(index) {
            if (index >= queue.length) {
                allButton.textContent = 'Done: ' + total + ' of ' + total + ' steps';
                allButton.disabled = false;
                return;
            }

            allButton.textContent = 'Running step ' + (index + 1) + ' of ' + total + '…';

            var step = queue[index];
            step.button.textContent = '0 / …';

            sendBatch(step.action, step.postType, 0, { processed: 0, errors: [] }, step.button, function (success) {
                if (!success) {
                    allButton.textContent = 'Stopped at step ' + (index + 1) + ' of ' + total;
                    allButton.disabled = false;
                    return;
                }
                runNext(index + 1);
            });
        }

        runNext(0);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('button[data-post-type], button[data-action]');
        buttons.forEach(function (button) {
            button.addEventListener('click', handleGenerateClick);
        });

        var allButton = document.querySelector('button[data-generate-all]');
        if (allButton) {
            allButton.addEventListener('click', handleGenerateAllClick);
        }
    });
}());
