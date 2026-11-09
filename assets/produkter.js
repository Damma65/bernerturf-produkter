/* Berner Turf Produkter 0.5.1 – filter, sök, sortering, antal per rad och infotext i katalogen. Valen sparas i adressen (lev, kat, land, sok, ordning, visa, info) så att Tillbaka och delade länkar visar samma urval. Inga externa beroenden. */
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
		var view = { cols: '', info: '' };
		var KEY = 'btp-scroll:' + location.pathname + location.search.replace(/[?&](lev|kat|land|sok|ordning|visa|info)=[^&]*/g, '');

		// Valen i adressen: ?lev=Syngenta&kat=…&land=SE (land=alla = inget land) &sok=…&ordning=sup&visa=4&info=1
		var params;
		try { params = new URLSearchParams(location.search); } catch (err) { params = null; }
		if (params) {
			if (params.has('lev')) state.sup = params.get('lev');
			if (params.has('kat')) state.cat = params.get('kat');
			if (params.has('land')) state.land = params.get('land') === 'alla' ? '' : params.get('land').toUpperCase();
			if (params.has('sok')) state.q = params.get('sok').trim().toLowerCase();
			if (params.get('ordning') === 'sup') state.sort = 'sup';
			if (params.has('visa')) view.cols = params.get('visa');
			if (params.get('info') === '1') view.info = '1';
		}

		function saveUrl() {
			if (!params || !window.history || !history.replaceState) return;
			var p = new URLSearchParams(location.search);
			var set = function (k, v) { if (v) { p.set(k, v); } else { p.delete(k); } };
			set('lev', state.sup);
			set('kat', state.cat);
			var def = root.getAttribute('data-land') || '';
			set('land', state.land === def ? '' : (state.land || 'alla'));
			set('sok', search ? search.value.trim() : '');
			set('ordning', state.sort === 'sup' ? 'sup' : '');
			set('visa', view.cols);
			set('info', view.info);
			var qs = p.toString();
			try { history.replaceState(history.state, '', location.pathname + (qs ? '?' + qs : '') + location.hash); } catch (err) { /* ignoreras */ }
		}

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
			saveUrl();
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
			saveUrl();
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
				setCols(c.getAttribute('data-btp-cols'));
				saveUrl();
				return;
			}
			var t = e.target.closest('[data-btp-text]');
			if (t) {
				setInfo(t.getAttribute('aria-checked') !== 'true');
				saveUrl();
				return;
			}
			// Klick på ett produktkort: kom ihåg var i listan man var, så att Tillbaka hamnar rätt.
			if (e.target.closest('.btp-card a, a.btp-card')) {
				try { sessionStorage.setItem(KEY, String(window.scrollY || window.pageYOffset || 0)); } catch (err) { /* privat läge */ }
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

		function setCols(n) {
			var btn = root.querySelector('[data-btp-cols="' + n + '"]');
			if (!btn || !shelvesWrap) return;
			shelvesWrap.style.setProperty('--btp-cols', n);
			root.querySelectorAll('[data-btp-cols]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
			view.cols = n === '4' ? '' : n; // 4 per rad är standard och behöver inte stå i adressen.
		}
		function setInfo(on) {
			var t = root.querySelector('[data-btp-text]');
			if (t) t.setAttribute('aria-checked', on ? 'true' : 'false');
			root.querySelectorAll('.btp-card__text').forEach(function (p) { p.hidden = !on; });
			view.info = on ? '1' : '';
		}

		if (search && state.q && params) search.value = params.get('sok');
		if (view.cols) setCols(view.cols);
		if (view.info) setInfo(true);
		sort();
		render();

		// Tillbaka från en produkt: samma plats i listan.
		try {
			var y = sessionStorage.getItem(KEY);
			if (y !== null) {
				sessionStorage.removeItem(KEY);
				var nav = (performance.getEntriesByType && performance.getEntriesByType('navigation')[0]) || {};
				if (nav.type === 'back_forward' || (performance.navigation && performance.navigation.type === 2)) {
					window.scrollTo(0, parseInt(y, 10) || 0);
				}
			}
		} catch (err) { /* ignoreras */ }
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
