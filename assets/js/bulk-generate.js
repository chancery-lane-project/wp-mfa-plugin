/* global markdownForAgentsBulkGenerate */
/* WordPress admin bulk-generation control.
 *
 * The browser no longer drives batches. Clicking a button POSTs a scope to
 * start a server-side WP-Cron job, then this polls a read-only status endpoint
 * until the job reports done or failed. The job runs independently of this page
 * as long as WP-Cron is working on the site; where it is not, the admin_init
 * nudge means progress only advances while a wp-admin page is open. Reloading
 * reconnects to whatever is running.
 */
(function () {
    'use strict';

    var POLL_INTERVAL   = 5000;
    var MAX_POLL_ERRORS = 3;

    // 3 minutes: long enough that one slow tick (the server-side budget caps
    // a single tick at 30s) or a quiet cron minute never trips this, short
    // enough that someone watching this page finds out quickly that nothing
    // is advancing rather than waiting out the 1-hour watchdog interval,
    // which only helps when cron runs at all — the case this warns about.
    var STALL_WARNING_SECONDS = 180;

    var pollTimer  = null;
    var pollErrors = 0;

    function container() {
        return document.getElementById('mfa-job-progress');
    }

    function post(action, extra, onSuccess, onError) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', markdownForAgentsBulkGenerate.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            var response = null;

            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                response = null;
            }

            if (response && response.success) {
                onSuccess(response.data || {}, xhr.status);
                return;
            }

            onError(response && response.data ? response.data : {}, xhr.status);
        };

        xhr.onerror = function () {
            onError({}, 0);
        };

        var params = 'action=' + encodeURIComponent(action)
            + '&nonce=' + encodeURIComponent(markdownForAgentsBulkGenerate.nonce);

        Object.keys(extra || {}).forEach(function (key) {
            params += '&' + key + '=' + encodeURIComponent(extra[key]);
        });

        xhr.send(params);
    }

    function setButtonsDisabled(disabled) {
        document.querySelectorAll('button[data-mfa-scope]').forEach(function (button) {
            button.disabled = disabled;
        });
    }

    function stageLabel(stage) {
        if ('post_type' === stage.type) {
            return 'Post type: ' + stage.slug;
        }
        if ('taxonomy' === stage.type) {
            return 'Taxonomy archives';
        }
        if ('bundle' === stage.type) {
            return 'Export bundle';
        }
        return stage.type;
    }

    function stageLine(stage, index, currentIndex) {
        var total  = (null === stage.total || undefined === stage.total) ? '…' : stage.total;
        var parts  = [stage.processed + ' / ' + total + ' processed'];
        var marker = '';

        if (parseInt(stage.skipped, 10) > 0) {
            parts.push(stage.skipped + ' skipped');
        }
        if (parseInt(stage.error_count, 10) > 0) {
            parts.push(stage.error_count + ' error(s)');
        }
        if ('unavailable' === stage.state) {
            parts.push('unavailable — skipped');
        }
        if (index === currentIndex) {
            marker = ' ← running';
        }

        return stageLabel(stage) + ': ' + parts.join(', ') + marker;
    }

    function render(job, notice) {
        var target = container();

        if (!target) {
            return;
        }

        target.textContent = '';

        if (notice) {
            var warning = document.createElement('p');
            warning.className   = 'notice notice-warning';
            warning.textContent = notice;
            target.appendChild(warning);
        }

        if (!job || 'idle' === job.status || !job.stages || !job.stages.length) {
            return;
        }

        var stages = job.stages;

        // Only a running job has a current stage. When the job is done,
        // stage_index has advanced past the last stage, and clamping it to the
        // final index would mark that stage "running" on a finished run.
        var current = ('running' === job.status)
            ? Math.min(parseInt(job.stage_index, 10) || 0, stages.length - 1)
            : -1;

        var heading = document.createElement('p');

        if ('running' === job.status) {
            heading.innerHTML = '<strong>Generating…</strong> stage ' + (current + 1) + ' of ' + stages.length
                + ' — this continues in the background as long as WP-Cron is running on this site.';
        } else if ('done' === job.status) {
            heading.innerHTML = '<strong>Generation complete.</strong>';
        } else {
            heading.innerHTML = '<strong>Generation failed.</strong> ' + (job.message || '');
        }

        target.appendChild(heading);

        if ('running' === job.status && parseInt(job.seconds_since_tick, 10) > STALL_WARNING_SECONDS) {
            var minutes = Math.round(parseInt(job.seconds_since_tick, 10) / 60);
            var stall   = document.createElement('p');
            stall.className   = 'notice notice-warning';
            stall.textContent = 'No progress for ' + minutes + ' minute' + (1 === minutes ? '' : 's')
                + ' — WP-Cron may not be running on this site. See the plugin readme.';
            target.appendChild(stall);
        }

        var list = document.createElement('ul');
        list.style.margin    = '0.5em 0 0 1.5em';
        list.style.listStyle = 'disc';

        stages.forEach(function (stage, index) {
            var item = document.createElement('li');
            item.textContent = stageLine(stage, index, current);
            list.appendChild(item);
        });

        target.appendChild(list);

        var errorCount = parseInt(job.error_count, 10) || 0;

        if (errorCount && job.errors && job.errors.length) {
            var details = document.createElement('details');
            details.className = 'mfa-error-details';

            var summary = document.createElement('summary');
            summary.textContent = 'Show ' + errorCount + ' error' + (1 === errorCount ? '' : 's');
            details.appendChild(summary);

            var errorList = document.createElement('ul');
            errorList.style.margin    = '0.5em 0 0 1.5em';
            errorList.style.listStyle = 'disc';

            if (errorCount > job.errors.length) {
                var capped = document.createElement('li');
                capped.textContent = '+' + (errorCount - job.errors.length) + ' earlier error(s) not shown';
                errorList.appendChild(capped);
            }

            job.errors.forEach(function (error) {
                var id   = error.post_id || error.term_id || '';
                var item = document.createElement('li');
                item.textContent = (id ? '#' + id + ': ' : '') + (error.message || 'Unknown error');
                errorList.appendChild(item);
            });

            details.appendChild(errorList);
            target.appendChild(details);
        }
    }

    function stopPolling() {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function schedulePoll() {
        stopPolling();
        pollTimer = window.setTimeout(poll, POLL_INTERVAL);
    }

    function handleJob(job) {
        render(job);

        if (job && 'running' === job.status) {
            setButtonsDisabled(true);
            schedulePoll();
            return;
        }

        setButtonsDisabled(false);
        stopPolling();
    }

    function poll() {
        post(
            'mfa_job_status',
            {},
            function (job) {
                pollErrors = 0;
                handleJob(job);
            },
            function () {
                pollErrors += 1;

                if (pollErrors >= MAX_POLL_ERRORS) {
                    // The job itself is very likely still running — a long run
                    // can outlive this page's nonce. Do not report it as failed.
                    stopPolling();
                    setButtonsDisabled(false);
                    render(null, 'Lost contact with the server. Reload this page to see current progress; any running job continues.');
                    return;
                }

                schedulePoll();
            }
        );
    }

    function start(scope) {
        setButtonsDisabled(true);
        render(null, '');

        post(
            'mfa_start_generation_job',
            { scope: scope },
            function (job) {
                pollErrors = 0;
                handleJob(job);
            },
            function (data, status) {
                if (409 === status && data && data.job) {
                    // A job is already running: show that one instead of an error.
                    handleJob(data.job);
                    return;
                }

                setButtonsDisabled(false);
                render(null, (data && data.message) ? data.message : 'Could not start generation.');
            }
        );
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('button[data-mfa-scope]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                start(event.currentTarget.dataset.mfaScope);
            });
        });

        // Reconnect to a job started before this page load.
        poll();
    });
}());
