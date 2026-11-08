/**
 * Frontend Checkout JavaScript for Woo SplitPay & Delbetalning Dual-Mode Gateway
 *
 * @package WC_Bankgiro_Split
 */
(function ($) {
	'use strict';

	function escapeHtml(str) {
		return $('<div>').text(str || '').html();
	}

	function formatThousands(num) {
		return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
	}

	function updateDelbetalningSummary($container, selectedIdx) {
		var $delbPanel = $container.find('.wc-bg-mode-panel[data-mode="delbetalning"]');
		if (!$delbPanel.length) {
			return;
		}

		var $summaryBox = $delbPanel.find('#wc-bg-delbetalning-summary-box');
		if (!$summaryBox.length) {
			return;
		}

		var $table = $delbPanel.find('.wc-bg-campaign-table');
		var $rows = $table.find('tbody tr');
		if (!$rows.length) {
			return;
		}

		var idx = (typeof selectedIdx !== 'undefined' && selectedIdx !== '') ? parseInt(selectedIdx, 10) : 0;
		if (isNaN(idx) || idx < 0 || idx >= $rows.length) {
			idx = 0;
		}

		var $selectedRow = $table.find('tbody tr[data-campaign-index="' + idx + '"]');
		if (!$selectedRow.length) {
			$selectedRow = $rows.first();
		}

		var campaignName = $selectedRow.attr('data-campaign-name') || $.trim($selectedRow.find('.wc-bg-camp-name-cell').text()) || $.trim($selectedRow.find('td').eq(1).text());
		var interestRate = $selectedRow.attr('data-interest-rate') || $.trim($selectedRow.find('td').eq(2).text());
		var setupFee     = $selectedRow.attr('data-setup-fee')     || $.trim($selectedRow.find('td').eq(3).text());
		var monthlyFee   = $selectedRow.attr('data-monthly-fee')   || $.trim($selectedRow.find('td').eq(4).text());

		var cartTotalAttr = $delbPanel.attr('data-cart-total');
		var cartTotal     = parseFloat(cartTotalAttr) || 0;

		var match  = campaignName.match(/^\s*(\d+)/);
		var months = match ? parseInt(match[1], 10) : 0;

		var summaryHtml = '';

		if (months > 0 && cartTotal > 0 && isFinite(cartTotal)) {
			var monthlyCost = Math.round(cartTotal / months);
			if (!isNaN(monthlyCost) && isFinite(monthlyCost) && monthlyCost > 0) {
				var formattedMonthly = formatThousands(monthlyCost) + ' kr/mån';
				var formattedTotal   = formatThousands(Math.round(cartTotal)) + ' kr';

				summaryHtml =
					'<div class="wc-bg-summary-line-plan">' +
						'Vald plan: <strong>' + escapeHtml(campaignName) + '</strong> — ' +
						'Uppskattad månadskostnad: <span class="wc-bg-summary-monthly-amount">ca ' + escapeHtml(formattedMonthly) + '</span> ' +
						'<span class="wc-bg-summary-calc">(' + escapeHtml(formattedTotal) + ' / ' + months + ' mån)</span>' +
					'</div>' +
					'<div class="wc-bg-summary-details">' +
						'Ränta: ' + escapeHtml(interestRate) + ' | Uppl.avgift: ' + escapeHtml(setupFee) + ' | Aviavgift: ' + escapeHtml(monthlyFee) +
					'</div>';
			} else {
				summaryHtml =
					'<div class="wc-bg-summary-line-plan">' +
						'Vald plan: <strong>' + escapeHtml(campaignName) + '</strong>' +
					'</div>' +
					'<div class="wc-bg-summary-details">' +
						'Ränta: ' + escapeHtml(interestRate) + ' | Uppl.avgift: ' + escapeHtml(setupFee) + ' | Aviavgift: ' + escapeHtml(monthlyFee) +
					'</div>';
			}
		} else {
			summaryHtml =
				'<div class="wc-bg-summary-line-plan">' +
					'Vald plan: <strong>' + escapeHtml(campaignName) + '</strong>' +
				'</div>' +
				'<div class="wc-bg-summary-details">' +
					'Ränta: ' + escapeHtml(interestRate) + ' | Uppl.avgift: ' + escapeHtml(setupFee) + ' | Aviavgift: ' + escapeHtml(monthlyFee) +
				'</div>';
		}

		$summaryBox.html(summaryHtml);
	}

	function syncCheckoutModeUI($context) {
		var $wrap = ($context && $context.length) ? $context : $('.wc-bg-dual-checkout-wrap');
		if (!$wrap.length) {
			return;
		}

		$wrap.each(function () {
			var $container = $(this);
			var selectedMode = $container.find('input.wc-bg-mode-radio:checked').val() || 'split';

			// Update visual selected state on cards
			$container.find('.wc-bg-mode-card').removeClass('is-selected');
			$container.find('.wc-bg-mode-card[data-mode="' + selectedMode + '"]').addClass('is-selected');

			// Toggle panel visibility and toggle HTML5 required attribute
			$container.find('.wc-bg-mode-panel').each(function () {
				var $panel = $(this);
				var panelMode = $panel.attr('data-mode');
				if (panelMode === selectedMode) {
					$panel.removeClass('is-hidden');
					$panel.find('select.wc-bg-select').prop('required', true);
				} else {
					$panel.addClass('is-hidden');
					$panel.find('select.wc-bg-select').prop('required', false);
				}
			});

			// Highlight selected row in Delbetalning campaign table
			var $hiddenInput = $container.find('#bg_delbetalning_campaign');
			var selectedCampaignIdx = $hiddenInput.length ? $hiddenInput.val() : '0';
			if (typeof selectedCampaignIdx === 'undefined' || selectedCampaignIdx === '') {
				selectedCampaignIdx = '0';
				if ($hiddenInput.length) {
					$hiddenInput.val('0');
				}
			}

			$container.find('.wc-bg-campaign-table tbody tr').removeClass('is-selected-row');
			$container.find('.wc-bg-campaign-table tbody tr[data-campaign-index="' + selectedCampaignIdx + '"]').addClass('is-selected-row');

			// Update instant dynamic summary box
			updateDelbetalningSummary($container, selectedCampaignIdx);
		});
	}

	$(function () {
		syncCheckoutModeUI();

		// Handle WooCommerce AJAX checkout updates
		$(document.body).on('updated_checkout', function () {
			syncCheckoutModeUI();
		});

		// Clicking a mode card
		$(document).on('click', '.wc-bg-mode-card', function (e) {
			var $card = $(this);
			var $radio = $card.find('input.wc-bg-mode-radio');
			if (!$radio.prop('checked')) {
				$radio.prop('checked', true).trigger('change');
			}
		});

		// Radio input change
		$(document).on('change', 'input.wc-bg-mode-radio', function () {
			var $wrap = $(this).closest('.wc-bg-dual-checkout-wrap');
			syncCheckoutModeUI($wrap);
		});

		// Clicking a row in Delbetalning table
		$(document).on('click', '.wc-bg-campaign-table tbody tr[data-campaign-index]', function () {
			var idx = $(this).attr('data-campaign-index');
			var $wrap = $(this).closest('.wc-bg-dual-checkout-wrap');
			var $hidden = $wrap.find('#bg_delbetalning_campaign');

			if ($hidden.length) {
				$hidden.val(idx);
			}

			$wrap.find('.wc-bg-campaign-table tbody tr').removeClass('is-selected-row');
			$(this).addClass('is-selected-row');

			updateDelbetalningSummary($wrap, idx);
		});
	});
})(jQuery);
