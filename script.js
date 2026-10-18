(function () {
	'use strict';

	var cfg = window.BonusPlus;
	if (!cfg) return;

	var modal, amount, reason, otherWrap, other, alertBox, submit, lastFocus;

	function $(id) { return document.getElementById(id); }

	// put "Give bonus" and "Bonus history" right beside the "Bonus points" input.
	// Done with JS (not a form note) so themes like Polaris cannot restyle them into an icon.
	function makeBtn(id, cls, text) {
		var b = document.createElement('button');
		b.type = 'button';
		b.id = id;
		b.className = cls;
		b.textContent = text;
		return b;
	}

	function placeButtons() {
		var input = document.querySelector('input[name="bonus"]');
		if (!input || !input.parentNode) return; // member view: PHP already rendered the row

		var row = document.createElement('div');
		row.className = 'bp-inline bp-inline-row';
		input.parentNode.insertBefore(row, input);

		var value = document.createElement('span');
		value.className = 'bp-points-value';
		value.textContent = input.value !== '' ? input.value : '0';
		row.appendChild(value);

		input.style.display = 'none';
		input.setAttribute('aria-hidden', 'true');
		row.appendChild(input);

		if (cfg.canGive && $('bonusplus-popup')) {
			row.appendChild(makeBtn('bp-give-open', 'bp-inline-btn bp-inline-primary', cfg.i18n.giveBonus));
		}
		if ($('bonusplus-history')) {
			row.appendChild(makeBtn('bp-history-open', 'bp-inline-btn', cfg.i18n.historyButton));
		}
	}
	function init() {
		placeButtons();
		initHistory();

		modal = $('bonusplus-popup');
		if (!modal) return; // viewer cannot give bonus points; history may still work

		amount    = $('bp-amount');
		reason    = $('bp-reason');
		otherWrap = $('bp-other-wrap');
		other     = $('bp-other');
		alertBox  = $('bp-alert');
		submit    = $('bp-submit');

		// open from the "Give bonus" button on the profile form
		document.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('#bp-give-open, button[name="givebonusplus"]') : null;
			if (btn) {
				e.preventDefault();
				openModal();
			}
		});

		// close: X, Cancel, backdrop
		modal.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('[data-bp-close]')) closeModal();
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !modal.hidden) closeModal();
			if (e.key === 'Enter' && !modal.hidden && e.target !== other && e.target.tagName !== 'BUTTON') {
				e.preventDefault();
				send();
			}
		});

		reason.addEventListener('change', toggleOther);
		amount.addEventListener('input', updatePreview);
		modal.addEventListener('change', function (e) {
			if (e.target.name === 'bp-dir') updatePreview();
		});
		submit.addEventListener('click', send);
	}

	function openModal() {
		lastFocus = document.activeElement;
		showError('');
		modal.hidden = false;
		document.documentElement.classList.add('bp-lock');
		toggleOther();
		amount.focus();
	}

	function closeModal() {
		modal.hidden = true;
		document.documentElement.classList.remove('bp-lock');
		if (lastFocus && lastFocus.focus) lastFocus.focus();
	}

	function showError(msg) {
		alertBox.textContent = msg;
		alertBox.hidden = !msg;
		alertBox.className = 'bp-alert';
	}

	function selectedEffect() {
		var r = document.querySelector('input[name="bp-dir"]:checked');
		return r && r.value === 'deduct' ? -1 : 1;
	}

	function updatePreview() {
		var eff = selectedEffect();
		var limit = eff < 0 ? Math.abs(cfg.min) : cfg.max;
		$('bp-range').textContent = '1 \u2026 ' + limit;
		amount.max = limit;
		var n = parseInt(amount.value, 10);
		$('bp-preview').textContent = n > 0 ? (eff < 0 ? '\u2212' : '+') + n : '';
	}

	// the chosen reason sets the default direction; the admin may flip it afterwards
	function syncDirection() {
		var opt = reason.options[reason.selectedIndex];
		var eff = opt && opt.getAttribute('data-effect') === '-1' ? 'deduct' : 'add';
		var radio = document.querySelector('input[name="bp-dir"][value="' + eff + '"]');
		if (radio) radio.checked = true;
		updatePreview();
	}

	function toggleOther() {
		var isOther = reason.value === 'other';
		otherWrap.hidden = !isOther;
		if (isOther) other.focus();
		syncDirection();
	}

	function validate() {
		var raw = amount.value.trim();
		var n = parseInt(raw, 10);

		if (!/^\d+$/.test(raw) || n <= 0) return cfg.i18n.amountInvalid;

		var eff = selectedEffect();
		if (eff * n < cfg.min || eff * n > cfg.max) {
			var limit = eff < 0 ? Math.abs(cfg.min) : cfg.max;
			return cfg.i18n.amountRange.replace('%min%', 1).replace('%max%', limit);
		}
		if (!reason.value) return cfg.i18n.reasonRequired;
		if (reason.value === 'other' && !other.value.trim()) return cfg.i18n.otherRequired;
		return '';
	}

	function setBusy(busy) {
		submit.disabled = busy;
		submit.textContent = busy ? cfg.i18n.sending : cfg.i18n.send;
	}

	function send() {
		var err = validate();
		if (err) { showError(err); return; }

		showError('');
		setBusy(true);

		var payload = {
			code: cfg.code,
			receiverid: cfg.receiverid,
			amount: parseInt(amount.value, 10),
			direction: selectedEffect() < 0 ? 'deduct' : 'add',
			reasonid: reason.value,
			newreason: reason.value === 'other' ? other.value.trim() : ''
		};

		var body = new URLSearchParams();
		body.append('ajaxdata', JSON.stringify(payload));

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) {
					alertBox.textContent = cfg.i18n.success;
					alertBox.className = 'bp-alert bp-alert-ok';
					alertBox.hidden = false;
					setTimeout(function () { window.location.reload(); }, 700);
				} else {
					setBusy(false);
					showError((res && res.error) || cfg.i18n.networkError);
				}
			})
			.catch(function () {
				setBusy(false);
				showError(cfg.i18n.networkError);
			});
	}

	// ---------- bonus history modal ----------
	function initHistory() {
		var hm = $('bonusplus-history');
		if (!hm) return;

		var body = $('bph-body'), moreBtn = $('bph-more'), alertEl = $('bph-alert');
		var emptyEl = $('bph-empty'), tableWrap = $('bph-table-wrap');
		var breakdown = $('bph-breakdown'), reasonsBody = $('bph-reasons');
		var loaded = 0, total = 0, busy = false, lastFocus = null;

		function signed(n) { n = +n; return (n > 0 ? '+' : '') + n; }

		// dates are stored in GMT; show them in the viewer's own time zone
		function fmtDate(iso) {
			if (!iso) return '-';
			var d = new Date(iso);
			if (isNaN(d.getTime())) return iso;
			return d.toLocaleString(undefined, {
				year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
			});
		}

		function cell(text, cls) {
			var td = document.createElement('td');
			td.textContent = text;
			if (cls) td.className = cls;
			return td;
		}

		function amountClass(n) { return 'bp-num ' + (n > 0 ? 'bp-pos' : (n < 0 ? 'bp-neg' : '')); }

		function showAlert(msg) {
			alertEl.textContent = msg;
			alertEl.className = 'bp-alert';
			alertEl.hidden = !msg;
		}

		function setStats(s) {
			$('bph-net').textContent = signed(s.net);
			$('bph-net').className = amountClass(s.net).replace('bp-num ', '');
			$('bph-added').textContent = signed(s.added);
			$('bph-deducted').textContent = String(s.deducted);
			$('bph-count').textContent = s.count;
			total = s.count;
		}

		function addRows(rows) {
			rows.forEach(function (r) {
				var tr = document.createElement('tr');
				tr.appendChild(cell(fmtDate(r.date)));
				tr.appendChild(cell(signed(r.amount), amountClass(r.amount)));
				tr.appendChild(cell(r.reason || '-'));

				if (cfg.showGiver) {
					var td = document.createElement('td');
					if (r.giver && r.url) {
						var a = document.createElement('a');
						a.href = r.url;
						a.textContent = r.giver;
						td.appendChild(a);
					} else {
						td.textContent = r.giver || '-';
					}
					tr.appendChild(td);
				}
				body.appendChild(tr);
			});
			loaded += rows.length;
		}

		function setReasons(list) {
			reasonsBody.innerHTML = '';
			list.forEach(function (r) {
				var tr = document.createElement('tr');
				tr.appendChild(cell(r.reason || '-'));
				tr.appendChild(cell(r.count, 'bp-num'));
				tr.appendChild(cell(signed(r.total), amountClass(r.total)));
				reasonsBody.appendChild(tr);
			});
			breakdown.hidden = list.length === 0;
		}

		function updateView() {
			var none = total === 0;
			emptyEl.hidden = !none;
			tableWrap.hidden = none;
			moreBtn.hidden = loaded >= total;
		}

		function load(first) {
			if (busy) return;
			busy = true;
			showAlert('');
			moreBtn.disabled = true;
			moreBtn.textContent = cfg.i18n.historyLoading;

			var payload = { code: cfg.code, action: 'history', receiverid: cfg.receiverid, offset: first ? 0 : loaded };
			var form = new URLSearchParams();
			form.append('ajaxdata', JSON.stringify(payload));

			fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: form.toString()
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res || !res.success) {
						showAlert((res && res.error) || cfg.i18n.historyError);
						return;
					}
					if (res.stats) setStats(res.stats);
					if (res.reasons) setReasons(res.reasons);
					addRows(res.rows || []);
					updateView();
				})
				.catch(function () { showAlert(cfg.i18n.historyError); })
				.then(function () {
					busy = false;
					moreBtn.disabled = false;
					moreBtn.textContent = cfg.i18n.loadMore;
				});
		}

		function open() {
			lastFocus = document.activeElement;
			body.innerHTML = '';
			loaded = 0;
			total = 0;
			emptyEl.hidden = true;
			tableWrap.hidden = false;
			moreBtn.hidden = true;
			hm.hidden = false;
			document.documentElement.classList.add('bp-lock');
			load(true);
		}

		function close() {
			hm.hidden = true;
			document.documentElement.classList.remove('bp-lock');
			if (lastFocus && lastFocus.focus) lastFocus.focus();
		}

		document.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('#bp-history-open')) {
				e.preventDefault();
				open();
			}
		});
		hm.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('[data-bp-close]')) close();
		});
		moreBtn.addEventListener('click', function () { load(false); });
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !hm.hidden) close();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();