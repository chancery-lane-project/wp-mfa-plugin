/* Agent discovery settings: live cumulative gating.
 * The three discovery toggles (OKF compatibility → bundle → ARD) are gated
 * server-side during sanitisation, and rendered disabled when their
 * prerequisite is off. This script mirrors that gating client-side so
 * enabling a level unlocks the next one immediately, in the same form
 * submit, instead of requiring an intermediate save.
 */
(function () {
    'use strict';

    var OPTION_KEY = 'markdown_for_agents_options';

    function field(name) {
        return document.querySelector('input[name="' + OPTION_KEY + '[' + name + ']"]');
    }

    function sync() {
        var okf = field('okf_compat');
        var bundle = field('bundle_enabled');
        var ard = field('ard_enabled');

        if (!okf || !bundle || !ard) {
            return;
        }

        bundle.disabled = !okf.checked;
        if (bundle.disabled) {
            bundle.checked = false;
        }

        ard.disabled = !bundle.checked;
        if (ard.disabled) {
            ard.checked = false;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        ['okf_compat', 'bundle_enabled', 'ard_enabled'].forEach(function (name) {
            var input = field(name);
            if (input) {
                input.addEventListener('change', sync);
            }
        });
        sync();
    });
})();
