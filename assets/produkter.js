/* Berner Turf Produkter 0.5.0 – filter, sök, sortering, antal per rad och infotext i katalogen. Inga externa beroenden. */
(function () {
	'use strict';

	function initCatalog(root) {
		var T = { names: {}, from: 'från', 'in': 'i', approved_in: 'godkända i' };
		try { T = Object.assign(T, JSON.parse(root.getAttribute('data-strings') || '{}')); } catch (err) { /* standardtexter */ }
		var NAMES = T.names || {};
		var shelvesWrap = root.querySelector('[data-btp-shelves]');
		var shelves = Array.prototype.slice.call(root.querySelectorAll('[data-btp-shelf]'));
		var cards = Array.prototype.slice.call(root.querySelectorAll('.btp-card'));
		var search = root.querySelector('[data-btp-search]');
		var total = root.querySelector('[data-btp-total]');
		var summary = root.querySelector('[data-btp-summary]');
		var empty = root.querySelector('[data-btp-empty]');
		var state = { q: '', sup: '', cat: '', land: root.getAttribute('data-land') || '', sort: 'name' };

		function matches(card, skip) {
			var d = card.dataset;
			if (state.q && d.search.indexOf(state.q) === -1) return false;
			if (skip !== 'sup' && state.sup && d.sup !== state.sup) return false;
			if (skip !== 'cat' && state.cat && d.cat !== state.cat) return false;
			if (skip !== 'land' && state.land && (' ' + d.land + ' ').indexOf(' ' + state.land + ' ') === -1) return false;
			return true;
		}

		function render() {
			var shown = 0;
			cards.forEach(function (card) {
				var ok = matches(card);
				card.hidden = !ok;
				if (ok) shown++;
			});
			shelves.forEach(function (shelf) {
				shelf.hidden = !shelf.querySelector('.btp-card:not([hidden])');
			});

			root.querySelectorAll('[data-btp-filter]').forEach(function (btn) {
				var type = btn.getAttribute('data-btp-filter');
				var value = btn.getAttribute('data-value');
				btn.setAttribute('aria-pressed', state[type] === value ? 'true' : 'false');
				var counter = btn.querySelector('[data-btp-count]');
				if (counter && !btn.disabled) {
					counter.textContent = cards.filter(function (c) {
						return matches(c, type) && c.dataset[type] === value;
					}).length;
				}
			});

			var parts = [];
			if (state.sup) parts.push((T.from ? T.from + ' ' : '') + state.sup);
			if (state.cat) parts.push((T['in'] ? T['in'] + ' ' : '') + state.cat.toLowerCase());
			if (state.land) parts.push(T.approved_in + ' ' + (NAMES[state.land] || state.land));
			total.textContent = shown;
			summary.textContent = parts.join(' · ');
			empty.hidden = shown !== 0;
		}

		function sort() {
			var key = state.sort === 'sup' ? function (c) { return c.dataset.sup + ' ' + c.dataset.name; } : function (c) { return c.dataset.name; };
			root.querySelectorAll('[data-btp-grid]').forEach(function (grid) {
				var list = Array.prototype.slice.call(grid.querySelectorAll('.btp-card'));
				list.sort(function (a, b) { return key(a).localeCompare(key(b), 'sv'); });
				list.forEach(function (c) { grid.appendChild(c); });
			});
			root.querySelectorAll('[data-btp-sort]').forEach(function (b) {
				b.setAttribute('aria-pressed', b.getAttribute('data-btp-sort') === state.sort ? 'true' : 'false');
			});
		}

		root.addEventListener('click', function (e) {
			var f = e.target.closest('[data-btp-filter]');
			if (f && !f.disabled) {
				var type = f.getAttribute('data-btp-filter');
				var value = f.getAttribute('data-value');
				state[type] = state[type] === value ? '' : value;
				render();
				return;
			}
			var s = e.target.closest('[data-btp-sort]');
			if (s) {
				state.sort = s.getAttribute('data-btp-sort');
				sort();
				return;
			}
			var c = e.target.closest('[data-btp-cols]');
			if (c) {
				shelvesWrap.style.setProperty('--btp-cols', c.getAttribute('data-btp-cols'));
				root.querySelectorAll('[data-btp-cols]').forEach(function (b) { b.setAttribute('aria-pressed', b === c ? 'true' : 'false'); });
				return;
			}
			var t = e.target.closest('[data-btp-text]');
			if (t) {
				var on = t.getAttribute('aria-checked') !== 'true';
				t.setAttribute('aria-checked', on ? 'true' : 'false');
				root.querySelectorAll('.btp-card__text').forEach(function (p) { p.hidden = !on; });
				return;
			}
			if (e.target.closest('[data-btp-reset]')) {
				state.q = ''; state.sup = ''; state.cat = ''; state.land = '';
				if (search) search.value = '';
				render();
			}
		});

		if (search) {
			search.addEventListener('input', function () {
				state.q = search.value.trim().toLowerCase();
				render();
			});
		}

		sort();
		render();
	}

	function init() {
		document.querySelectorAll('[data-btp-catalog]').forEach(initCatalog);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
