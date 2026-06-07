(function ($) {
	'use strict';

	function getBranchField() {
		return $('#orddd_locations_0, select.orddd_locations_field, #orddd_locations').first();
	}

	function isPickupContext() {
		if ($('input[name="orddd_order_type"]:checked').val() === 'pickup') {
			return true;
		}

		var shipping = $('input[name^="shipping_method"]:checked').val() || '';
		return shipping.indexOf('local_pickup') !== -1;
	}

	function escapeHtml(text) {
		return $('<div>').text(text).html();
	}

	function getStatusClass(item) {
		if (item.status_class) {
			return item.status_class;
		}

		if (item.status === 'in_stock') {
			return 'bpi-status--in-stock';
		}

		if (item.status === 'out_of_stock') {
			return 'bpi-status--out-of-stock';
		}

		return 'bpi-status--unknown';
	}

	function buildFullyAvailableHint(branches) {
		if (!branches || !branches.length) {
			return bpiCheckout.labels.noFullyAvailable || '';
		}

		return bpiCheckout.labels.fullyAvailableHint.replace('%s', branches.join(', '));
	}

	function renderAvailability(data) {
		var $panel = $('#bpi-checkout-availability');

		if (!$panel.length) {
			return;
		}

		$panel.removeClass('is-loading');

		if (!isPickupContext()) {
			$panel.empty();
			return;
		}

		var html = '<div class="bpi-availability-card">';

		if (!data || data.message === 'select_branch') {
			html += '<h4 class="bpi-availability-card__title">' + escapeHtml(bpiCheckout.labels.title) + '</h4>';
			html += '<p class="bpi-availability-card__message">' + escapeHtml(bpiCheckout.labels.selectBranch) + '</p>';
			html += '</div>';
			$panel.html(html);
			return;
		}

		html += '<h4 class="bpi-availability-card__title">' + escapeHtml(bpiCheckout.labels.title) + '</h4>';

		if (!data.items || !data.items.length) {
			html += '<p class="bpi-availability-card__message">' + escapeHtml(bpiCheckout.labels.selectBranch) + '</p>';
			html += '</div>';
			$panel.html(html);
			return;
		}

		html += '<ul class="bpi-branch-list">';

		data.items.forEach(function (item) {
			html += '<li class="bpi-branch-list__item">';
			html += '<span class="bpi-branch-list__name">' + escapeHtml(item.name) + '</span>';
			html += '<span class="bpi-status ' + escapeHtml(getStatusClass(item)) + '">' + escapeHtml(item.status_label) + '</span>';
			html += '</li>';
		});

		html += '</ul>';

		if (data.all_available) {
			html += '<p class="bpi-availability-card__note bpi-availability-card__note--positive">' + escapeHtml(bpiCheckout.labels.allAvailable) + '</p>';
		} else {
			html += '<p class="bpi-availability-card__note bpi-availability-card__note--negative">' + escapeHtml(bpiCheckout.labels.unavailable) + '</p>';
			html += '<p class="bpi-availability-card__note bpi-availability-card__note--hint">' + escapeHtml(buildFullyAvailableHint(data.fully_available_branches)) + '</p>';
		}

		html += '</div>';
		$panel.html(html);
	}

	function fetchAvailability(branchId) {
		var $panel = $('#bpi-checkout-availability');

		if (!$panel.length || !branchId || branchId === 'select_location') {
			renderAvailability({ message: 'select_branch' });
			return;
		}

		$panel.addClass('is-loading').html(
			'<div class="bpi-availability-card">' +
			'<h4 class="bpi-availability-card__title">' + escapeHtml(bpiCheckout.labels.title) + '</h4>' +
			'<p class="bpi-availability-card__message">' + escapeHtml(bpiCheckout.labels.loading) + '</p>' +
			'</div>'
		);

		$.get(bpiCheckout.ajaxUrl, {
			branch_id: branchId,
			security: bpiCheckout.nonce
		}).done(function (response) {
			if (response && response.success) {
				renderAvailability(response.data);
			}
		}).fail(function () {
			$panel.removeClass('is-loading').html(
				'<div class="bpi-availability-card">' +
				'<h4 class="bpi-availability-card__title">' + escapeHtml(bpiCheckout.labels.title) + '</h4>' +
				'<p class="bpi-availability-card__message">' + escapeHtml(bpiCheckout.labels.selectBranch) + '</p>' +
				'</div>'
			);
		});
	}

	function bindEvents() {
		$(document.body).on('change', '#orddd_locations_0, select.orddd_locations_field, #orddd_locations', function () {
			fetchAvailability($(this).val());
		});

		$(document.body).on('change', 'input[name="orddd_order_type"], input[name^="shipping_method"]', function () {
			var $field = getBranchField();
			if (isPickupContext() && $field.length) {
				fetchAvailability($field.val());
			} else {
				renderAvailability(null);
			}
		});

		$(document.body).on('updated_checkout', function () {
			var $field = getBranchField();
			if (isPickupContext() && $field.length) {
				fetchAvailability($field.val());
			}
		});
	}

	$(function () {
		bindEvents();

		var $field = getBranchField();
		if (isPickupContext() && $field.length && $field.val()) {
			fetchAvailability($field.val());
		}
	});
})(jQuery);
