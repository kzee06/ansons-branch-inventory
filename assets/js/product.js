(function () {
	'use strict';

	function escapeHtml(text) {
		var div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	function renderBranchItem(branch) {
		var html = '<li class="bpi-branch-list__item">';
		html += '<span class="bpi-branch-list__name">' + escapeHtml(branch.label) + '</span>';
		html += '<span class="bpi-status ' + escapeHtml(branch.status_class) + '">' + escapeHtml(branch.status_label) + '</span>';
		html += '</li>';
		return html;
	}

	function renderBranchList(branches, modifierClass, options) {
		options = options || {};
		var className = 'bpi-branch-list';

		if (modifierClass) {
			className += ' ' + modifierClass;
		}

		var html = '<ul class="' + className + '"' + (options.hidden ? ' hidden' : '') + '>';

		branches.forEach(function (branch) {
			html += renderBranchItem(branch);
		});

		html += '</ul>';
		return html;
	}

	function buildBranchList(branches) {
		if (!branches.length) {
			return '<p class="bpi-product-availability__empty">' + escapeHtml(bpiProduct.labels.noData) + '</p>';
		}

		var available = branches.filter(function (branch) {
			return branch.status === 'in_stock';
		});
		var unavailable = branches.filter(function (branch) {
			return branch.status !== 'in_stock';
		});
		var html = '';

		if (available.length) {
			html += renderBranchList(available, 'bpi-branch-list--available');
		} else {
			html += '<p class="bpi-product-availability__summary">' + escapeHtml(bpiProduct.labels.noneAvailable) + '</p>';
		}

		if (unavailable.length) {
			html += '<button type="button" class="bpi-product-availability__toggle-unavailable" aria-expanded="false" data-count="' + unavailable.length + '">';
			html += escapeHtml(bpiProduct.labels.showUnavailable.replace('%d', String(unavailable.length)));
			html += '</button>';
			html += renderBranchList(unavailable, 'bpi-branch-list--unavailable', { hidden: true });
		}

		html += '<p class="bpi-product-availability__note">' + escapeHtml(bpiProduct.labels.note) + '</p>';

		return html;
	}

	function setHint(details, text) {
		var hint = details.querySelector('.bpi-product-availability__toggle-hint');

		if (hint) {
			hint.textContent = text;
		}
	}

	function bindPanelInteractions(details) {
		var panel = details.querySelector('.bpi-product-availability__panel');

		if (!panel || panel.getAttribute('data-bound') === '1') {
			return;
		}

		panel.setAttribute('data-bound', '1');

		panel.addEventListener('click', function (event) {
			var button = event.target.closest('.bpi-product-availability__toggle-unavailable');

			if (!button) {
				return;
			}

			event.preventDefault();

			var list = panel.querySelector('.bpi-branch-list--unavailable');
			var expanded = button.getAttribute('aria-expanded') === 'true';
			var count = button.getAttribute('data-count') || '0';

			button.setAttribute('aria-expanded', expanded ? 'false' : 'true');

			if (list) {
				list.hidden = expanded;
			}

			button.textContent = expanded
				? bpiProduct.labels.showUnavailable.replace('%d', count)
				: bpiProduct.labels.hideUnavailable;
		});
	}

	function applyAvailability(details, data) {
		setHint(details, data.summary_hint || bpiProduct.labels.defaultHint);

		if (details.open) {
			var panel = details.querySelector('.bpi-product-availability__panel');

			if (panel) {
				panel.innerHTML = buildBranchList(data.branches || []);
			}
		}

		details.setAttribute('data-loaded', '1');
	}

	function fetchAvailability(details) {
		var panel = details.querySelector('.bpi-product-availability__panel');
		var productId = details.getAttribute('data-product-id');

		if (!productId) {
			return;
		}

		if (details._bpiData) {
			applyAvailability(details, details._bpiData);
			return;
		}

		if (details.open && panel) {
			panel.innerHTML = '<p class="bpi-product-availability__loading">' + escapeHtml(bpiProduct.labels.loading) + '</p>';
		}

		var sep = bpiProduct.ajaxUrl.indexOf('?') >= 0 ? '&' : '?';
		var url = bpiProduct.ajaxUrl + sep + 'product_id=' + encodeURIComponent(productId);

		fetch(url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'Accept': 'application/json'
			}
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (response) {
				if (!response || !response.success || !response.data) {
					throw new Error('invalid_response');
				}

				details._bpiData = response.data;
				applyAvailability(details, response.data);
			})
			.catch(function () {
				if (details.open && panel) {
					panel.innerHTML = '<p class="bpi-product-availability__empty">' + escapeHtml(bpiProduct.labels.error) + '</p>';
				}

				setHint(details, bpiProduct.labels.defaultHint);
			});
	}

	function bindDetails(details) {
		bindPanelInteractions(details);

		details.addEventListener('toggle', function () {
			if (details.open) {
				fetchAvailability(details);
			}
		});

		fetchAvailability(details);
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.bpi-product-availability[data-product-id]').forEach(bindDetails);
	});
})();
