(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.mfa-preview-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var postId  = btn.dataset.postId;
				var nonce   = btn.dataset.nonce;
				var ajaxurl = btn.dataset.ajaxurl;
				var box     = btn.closest('#markdown_for_agents_status');
				var details = box ? box.querySelector('.mfa-preview-output') : null;
				var pre     = details ? details.querySelector('.mfa-preview-content') : null;

				if (!details || !pre) {
					return;
				}

				btn.disabled = true;
				pre.textContent = 'Loading\u2026';
				details.removeAttribute('hidden');
				details.open = true;

				var body = new URLSearchParams({
					action:  'mfa_preview_post',
					post_id: postId,
					nonce:   nonce,
				});

				fetch(ajaxurl, { method: 'POST', body: body })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						if (data.success) {
							pre.textContent = data.data.markdown;
						} else {
							pre.textContent = 'Error: ' + ((data.data && data.data.message) ? data.data.message : 'Unknown error.');
						}
					})
					.catch(function (err) {
						pre.textContent = 'Request failed: ' + err.message;
					})
					.finally(function () {
						btn.disabled = false;
					});
			});
		});
	});
}());
