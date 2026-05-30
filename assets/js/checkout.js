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

	function renderAvailability(data) {
		var $panel = $('#bpi-checkout-availability');

		if (!$panel.length) {
			return;
		}

		$panel.removeClass('is-loading is-warning is-success');

		if (!isPickupContext()) {
			$panel.empty();
			return;
		}

		if (!data || data.message === 'select_branch') {
			$panel
				.addClass('is-warning')
				.html('<p class="bpi-message">' + bpiCheckout.labels.selectBranch + '</p>');
			return;
		}

		var html = '<h4>' + bpiCheckout.labels.title + '</h4>';

		if (!data.items || !data.items.length) {
			$panel.html('<p class="bpi-message">' + bpiCheckout.labels.selectBranch + '</p>');
			return;
		}

		html += '<ul>';

		data.items.forEach(function (item) {
			var statusClass = 'bpi-status--unknown';

			if (item.status === 'in_stock') {
				statusClass = 'bpi-status--in-stock';
			} else if (item.status === 'out_of_stock') {
				statusClass = 'bpi-status--out-of-stock';
			}

			html += '<li><span>' + item.name + '</span><span class="bpi-status ' + statusClass + '">' + item.status_label + '</span></li>';
		});

		html += '</ul>';

		if (data.all_available) {
			$panel.addClass('is-success');
			html += '<p class="bpi-message">' + bpiCheckout.labels.allAvailable + '</p>';
		} else {
			$panel.addClass('is-warning');
			html += '<p class="bpi-message">' + bpiCheckout.labels.unavailable + '</p>';
		}

		$panel.html(html);
	}

	function fetchAvailability(branchId) {
		var $panel = $('#bpi-checkout-availability');

		if (!$panel.length || !branchId || branchId === 'select_location') {
			renderAvailability({ message: 'select_branch' });
			return;
		}

		$panel.addClass('is-loading').html('<p class="bpi-message">' + bpiCheckout.labels.loading + '</p>');

		$.get(bpiCheckout.ajaxUrl, {
			branch_id: branchId,
			security: bpiCheckout.nonce
		}).done(function (response) {
			if (response && response.success) {
				renderAvailability(response.data);
			}
		}).fail(function () {
			$panel.removeClass('is-loading').html('<p class="bpi-message">' + bpiCheckout.labels.selectBranch + '</p>');
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
