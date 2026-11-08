/**
 * Admin JavaScript for Woo SplitPay & Delbetalning Gateway Settings
 *
 * @package WC_Bankgiro_Split
 */
(function ($) {
	'use strict';

	$(function () {
		// 1. Tab Switching
		$(document).on('click', '.wc-bg-admin-tab-btn', function (e) {
			e.preventDefault();
			var target = $(this).data('target');
			$('.wc-bg-admin-tab-btn').removeClass('is-active');
			$(this).addClass('is-active');

			if (target === 'all') {
				$('.wc-bg-admin-section').removeClass('is-hidden');
			} else {
				$('.wc-bg-admin-section').addClass('is-hidden');
				$('.wc-bg-admin-section[data-section="' + target + '"]').removeClass('is-hidden');
			}
		});

		// 2. WP Media Library Uploader for Split & Delbetalning Logos
		$(document).on('click', '.wc-bg-upload-logo-btn', function (e) {
			e.preventDefault();
			var $wrap = $(this).closest('.wc-bg-logo-uploader');
			var $input = $wrap.find('.wc-bg-logo-url-input');
			var $preview = $wrap.find('.wc-bg-logo-preview-box');

			if (typeof wp === 'undefined' || !wp.media) {
				alert('WordPress mediabibliotek är inte tillgängligt på denna sida. Klistra in logotypens bild-URL direkt i textfältet.');
				return;
			}

			var frame = wp.media({
				title: 'Välj eller ladda upp logotyp',
				button: { text: 'Använd denna logotyp' },
				library: { type: 'image' },
				multiple: false
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				if (attachment && attachment.url) {
					$input.val(attachment.url).trigger('change');
				}
			});

			frame.open();
		});

		$(document).on('click', '.wc-bg-remove-logo-btn', function (e) {
			e.preventDefault();
			var $wrap = $(this).closest('.wc-bg-logo-uploader');
			$wrap.find('.wc-bg-logo-url-input').val('').trigger('change');
		});

		$(document).on('input change', '.wc-bg-logo-url-input', function () {
			var url = $.trim($(this).val());
			var $preview = $(this).closest('.wc-bg-logo-uploader').find('.wc-bg-logo-preview-box');
			if (url) {
				$preview.html('<img src="' + $('<div>').text(url).html() + '" alt="Logo" />');
			} else {
				$preview.html('<span class="wc-bg-logo-preview-empty">Ingen egen logotyp vald (standardikon visas i kassan)</span>');
			}
		});

		// 3. Helper functions to escape HTML and sync Repeater JSON
		function escapeHtml(str) {
			return $('<div>').text(str || '').html();
		}

		function syncSplitRepeater() {
			var rows = [];
			$('#wc-bg-split-repeater-tbody tr.wc-bg-repeater-row').each(function () {
				var key = $.trim($(this).find('.wc-bg-split-col-key').val());
				var label = $.trim($(this).find('.wc-bg-split-col-label').val());
				var pct = parseFloat($(this).find('.wc-bg-split-col-pct').val());
				if (isNaN(pct) || pct <= 0 || pct > 100) {
					pct = 50;
				}
				if (label !== '') {
					if (!key) {
						key = 'plan_' + Math.round(pct) + '_' + (rows.length + 1);
					}
					rows.push({
						option_key: key,
						label: label,
						percentage: pct
					});
				}
			});
			$('#wc_bg_split_options_json').val(JSON.stringify(rows));
		}

		function syncDelbetalningRepeater() {
			var rows = [];
			$('#wc-bg-delbetalning-repeater-tbody tr.wc-bg-repeater-row').each(function () {
				var campaign_name = $.trim($(this).find('.wc-bg-camp-col-name').val());
				var interest_rate = $.trim($(this).find('.wc-bg-camp-col-rate').val());
				var setup_fee = $.trim($(this).find('.wc-bg-camp-col-setup').val());
				var monthly_fee = $.trim($(this).find('.wc-bg-camp-col-monthly').val());
				var max_amount = $.trim($(this).find('.wc-bg-camp-col-max').val());

				if (campaign_name !== '') {
					rows.push({
						campaign_name: campaign_name,
						interest_rate: interest_rate,
						setup_fee: setup_fee,
						monthly_fee: monthly_fee,
						max_amount: max_amount
					});
				}
			});
			$('#wc_bg_delbetalning_campaigns_json').val(JSON.stringify(rows));
		}

		function buildSplitRowHtml(item) {
			var key = item && item.option_key ? item.option_key : '';
			var label = item && item.label ? item.label : '';
			var pct = item && typeof item.percentage !== 'undefined' ? item.percentage : 50;
			return (
				'<tr class="wc-bg-repeater-row">' +
					'<td><input type="text" class="wc-bg-split-col-key" value="' + escapeHtml(key) + '" placeholder="t.ex. split eller full" /></td>' +
					'<td><input type="text" class="wc-bg-split-col-label" value="' + escapeHtml(label) + '" placeholder="Visningstext i dropdown..." /></td>' +
					'<td><input type="number" class="wc-bg-split-col-pct" value="' + escapeHtml(pct) + '" min="1" max="100" step="1" /></td>' +
					'<td>' +
						'<div class="wc-bg-repeater-actions">' +
							'<button type="button" class="wc-bg-row-btn wc-bg-move-up" title="Flytta upp">↑</button>' +
							'<button type="button" class="wc-bg-row-btn wc-bg-move-down" title="Flytta ner">↓</button>' +
							'<button type="button" class="wc-bg-row-btn is-delete wc-bg-remove-row" title="Ta bort rad">✕</button>' +
						'</div>' +
					'</td>' +
				'</tr>'
			);
		}

		function buildDelbetalningRowHtml(item) {
			var name = item && item.campaign_name ? item.campaign_name : '';
			var rate = item && item.interest_rate ? item.interest_rate : '0 %';
			var setup = item && item.setup_fee ? item.setup_fee : '295 kr';
			var monthly = item && item.monthly_fee ? item.monthly_fee : '45 kr';
			var max = item && item.max_amount ? item.max_amount : '100 000 kr';
			return (
				'<tr class="wc-bg-repeater-row">' +
					'<td><input type="text" class="wc-bg-camp-col-name" value="' + escapeHtml(name) + '" placeholder="t.ex. 12 mån räntefritt" /></td>' +
					'<td><input type="text" class="wc-bg-camp-col-rate" value="' + escapeHtml(rate) + '" placeholder="0 %" /></td>' +
					'<td><input type="text" class="wc-bg-camp-col-setup" value="' + escapeHtml(setup) + '" placeholder="295 kr" /></td>' +
					'<td><input type="text" class="wc-bg-camp-col-monthly" value="' + escapeHtml(monthly) + '" placeholder="45 kr" /></td>' +
					'<td><input type="text" class="wc-bg-camp-col-max" value="' + escapeHtml(max) + '" placeholder="100 000 kr" /></td>' +
					'<td>' +
						'<div class="wc-bg-repeater-actions">' +
							'<button type="button" class="wc-bg-row-btn wc-bg-move-up" title="Flytta upp">↑</button>' +
							'<button type="button" class="wc-bg-row-btn wc-bg-move-down" title="Flytta ner">↓</button>' +
							'<button type="button" class="wc-bg-row-btn is-delete wc-bg-remove-row" title="Ta bort rad">✕</button>' +
						'</div>' +
					'</td>' +
				'</tr>'
			);
		}

		// Split Repeater Handlers
		$(document).on('click', '#wc-bg-add-split-row', function (e) {
			e.preventDefault();
			var count = $('#wc-bg-split-repeater-tbody tr.wc-bg-repeater-row').length + 1;
			$('#wc-bg-split-repeater-tbody').append(
				buildSplitRowHtml({
					option_key: 'split_custom_' + count,
					label: '',
					percentage: 50
				})
			);
			syncSplitRepeater();
		});

		$(document).on('click', '#wc-bg-reset-split-rows', function (e) {
			e.preventDefault();
			if (!confirm('Är du säker på att du vill återställa Split-alternativen till standard?')) {
				return;
			}
			var defaults = $(this).data('defaults');
			if (Array.isArray(defaults)) {
				var $tbody = $('#wc-bg-split-repeater-tbody').empty();
				defaults.forEach(function (item) {
					$tbody.append(buildSplitRowHtml(item));
				});
				syncSplitRepeater();
			}
		});

		// Delbetalning Repeater Handlers
		$(document).on('click', '#wc-bg-add-delbetalning-row', function (e) {
			e.preventDefault();
			$('#wc-bg-delbetalning-repeater-tbody').append(
				buildDelbetalningRowHtml({
					campaign_name: '',
					interest_rate: '0 %',
					setup_fee: '295 kr',
					monthly_fee: '45 kr',
					max_amount: '100 000 kr'
				})
			);
			syncDelbetalningRepeater();
		});

		$(document).on('click', '#wc-bg-reset-delbetalning-rows', function (e) {
			e.preventDefault();
			if (!confirm('Är du säker på att du vill återställa Delbetalnings-kampanjerna till standard?')) {
				return;
			}
			var defaults = $(this).data('defaults');
			if (Array.isArray(defaults)) {
				var $tbody = $('#wc-bg-delbetalning-repeater-tbody').empty();
				defaults.forEach(function (item) {
					$tbody.append(buildDelbetalningRowHtml(item));
				});
				syncDelbetalningRepeater();
			}
		});

		// Reorder & Delete Handlers
		$(document).on('click', '.wc-bg-move-up', function (e) {
			e.preventDefault();
			var $row = $(this).closest('tr.wc-bg-repeater-row');
			var $prev = $row.prev('tr.wc-bg-repeater-row');
			if ($prev.length) {
				$row.insertBefore($prev);
				syncSplitRepeater();
				syncDelbetalningRepeater();
			}
		});

		$(document).on('click', '.wc-bg-move-down', function (e) {
			e.preventDefault();
			var $row = $(this).closest('tr.wc-bg-repeater-row');
			var $next = $row.next('tr.wc-bg-repeater-row');
			if ($next.length) {
				$row.insertAfter($next);
				syncSplitRepeater();
				syncDelbetalningRepeater();
			}
		});

		$(document).on('click', '.wc-bg-remove-row', function (e) {
			e.preventDefault();
			var $tbody = $(this).closest('tbody');
			if ($tbody.find('tr.wc-bg-repeater-row').length <= 1) {
				alert('Det måste finnas minst ett alternativ i listan.');
				return;
			}
			$(this).closest('tr.wc-bg-repeater-row').remove();
			syncSplitRepeater();
			syncDelbetalningRepeater();
		});

		// Realtime input binding & form submit binding
		$(document).on('input change', '#wc-bg-split-repeater-tbody input', syncSplitRepeater);
		$(document).on('input change', '#wc-bg-delbetalning-repeater-tbody input', syncDelbetalningRepeater);

		$('form#mainform, form#woocommerce_settings').on('submit', function () {
			syncSplitRepeater();
			syncDelbetalningRepeater();
		});

		// Initial sync
		syncSplitRepeater();
		syncDelbetalningRepeater();

		// 4. Click-to-copy buttons in User Guide ("Bruksanvisning")
		$(document).on('click', '.wc-bg-copy-btn', function (e) {
			e.preventDefault();
			var textToCopy = $(this).attr('data-copy') || '';
			if (!textToCopy) {
				return;
			}
			var $btn = $(this);
			var originalText = $btn.text();

			function showCopied() {
				$btn.text('Kopierat! ✓').addClass('is-copied');
				setTimeout(function () {
					$btn.text(originalText).removeClass('is-copied');
				}, 2000);
			}

			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(textToCopy).then(showCopied).catch(function () {
					fallbackCopy(textToCopy);
				});
			} else {
				fallbackCopy(textToCopy);
			}

			function fallbackCopy(str) {
				var $temp = $('<textarea>');
				$('body').append($temp);
				$temp.val(str).select();
				try {
					document.execCommand('copy');
					showCopied();
				} catch (err) {
					// Fallback failed
				}
				$temp.remove();
			}
		});
	});
})(jQuery);
