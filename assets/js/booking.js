(function () {
	'use strict';

	var cfg = window.XDLBooking || {};
	var t = cfg.i18n || {};

	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function ymd(y, m, d) {
		return y + '-' + pad(m) + '-' + pad(d);
	}

	function parseYmd(s) {
		var p = s.split('-');
		return new Date(+p[0], +p[1] - 1, +p[2]);
	}

	function getJSON(path) {
		return fetch(cfg.api + path, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			return r.json();
		});
	}

	function BookingWidget(root) {
		this.root = root;
		this.q = function (sel) {
			return root.querySelector(sel);
		};
		this.grid = this.q('[data-xdlb-grid]');
		this.monthLabel = this.q('[data-xdlb-month]');
		this.prevBtn = this.q('[data-xdlb-prev]');
		this.nextBtn = this.q('[data-xdlb-next]');
		this.slotList = this.q('[data-xdlb-slot-list]');
		this.slotsDate = this.q('[data-xdlb-slots-date]');
		this.form = this.q('[data-xdlb-form]');
		this.alertBox = this.q('[data-xdlb-alert]');
		this.submitBtn = this.q('[data-xdlb-submit]');

		var now = new Date();
		this.year = now.getFullYear();
		this.month = now.getMonth() + 1;
		this.min = null;
		this.max = null;
		this.selectedDate = null;
		this.selectedTime = null;
		this.cache = {};

		this.fmtMonth = new Intl.DateTimeFormat(cfg.locale, { month: 'long', year: 'numeric' });
		this.fmtDay = new Intl.DateTimeFormat(cfg.locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
		this.fmtWeekday = new Intl.DateTimeFormat(cfg.locale, { weekday: 'short' });

		this.initPhone();
		this.bind();
		this.loadMonth();
	}

	BookingWidget.prototype.initPhone = function () {
		var input = this.q('[data-xdlb-phone]');
		if (!input || !window.intlTelInput) {
			return;
		}
		this.iti = window.intlTelInput(input, {
			initialCountry: cfg.defaultCountry || 'vn',
			countryOrder: [cfg.defaultCountry || 'vn'],
			separateDialCode: true,
			strictMode: true,
		});
	};

	BookingWidget.prototype.bind = function () {
		var self = this;

		this.prevBtn.addEventListener('click', function () {
			self.shiftMonth(-1);
		});
		this.nextBtn.addEventListener('click', function () {
			self.shiftMonth(1);
		});

		this.grid.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-date]');
			if (btn && !btn.disabled) {
				self.selectDate(btn.getAttribute('data-date'));
			}
		});

		this.slotList.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-time]');
			if (btn) {
				self.selectTime(btn.getAttribute('data-time'));
			}
		});

		var toggle = this.q('[data-xdlb-guests-toggle]');
		var guests = this.q('[data-xdlb-guests]');
		if (toggle && guests) {
			toggle.addEventListener('click', function () {
				guests.hidden = false;
				toggle.hidden = true;
				toggle.setAttribute('aria-expanded', 'true');
				guests.querySelector('textarea').focus();
			});
		}

		this.form.addEventListener('submit', function (e) {
			e.preventDefault();
			self.submit();
		});
	};

	BookingWidget.prototype.monthKey = function () {
		return this.year + '-' + pad(this.month);
	};

	BookingWidget.prototype.shiftMonth = function (delta) {
		this.month += delta;
		if (this.month < 1) {
			this.month = 12;
			this.year--;
		} else if (this.month > 12) {
			this.month = 1;
			this.year++;
		}
		this.loadMonth();
	};

	BookingWidget.prototype.loadMonth = function () {
		var self = this;
		var key = this.monthKey();
		this.monthLabel.textContent = this.fmtMonth.format(new Date(this.year, this.month - 1, 1));
		this.grid.setAttribute('aria-busy', 'true');

		var p = this.cache[key] ? Promise.resolve(this.cache[key]) : getJSON('month?month=' + key);
		p.then(function (data) {
			self.cache[key] = data;
			self.min = data.min;
			self.max = data.max;
			if (key === self.monthKey()) {
				self.renderMonth(data.days);
			}
		}).catch(function () {
			self.grid.innerHTML = '<p class="xdlb-muted">' + t.loadError + '</p>';
		}).finally(function () {
			self.grid.removeAttribute('aria-busy');
		});
	};

	BookingWidget.prototype.renderMonth = function (days) {
		var html = '';
		var weekStart = parseInt(cfg.weekStart, 10) || 0;
		// 2023-01-01 was a Sunday: use it to label weekday headers.
		for (var i = 0; i < 7; i++) {
			html += '<span class="xdlb-wd">' + this.fmtWeekday.format(new Date(2023, 0, 1 + ((weekStart + i) % 7))) + '</span>';
		}

		var first = new Date(this.year, this.month - 1, 1).getDay();
		var lead = (first - weekStart + 7) % 7;
		for (var j = 0; j < lead; j++) {
			html += '<span class="xdlb-day xdlb-day--empty"></span>';
		}

		var total = new Date(this.year, this.month, 0).getDate();
		for (var d = 1; d <= total; d++) {
			var date = ymd(this.year, this.month, d);
			var open = !!days[date];
			var cls = 'xdlb-day' + (open ? ' is-open' : '') + (date === this.selectedDate ? ' is-selected' : '');
			html += '<button type="button" class="' + cls + '" data-date="' + date + '"' + (open ? '' : ' disabled') +
				' aria-pressed="' + (date === this.selectedDate) + '" aria-label="' + this.fmtDay.format(parseYmd(date)) + '">' + d + '</button>';
		}
		this.grid.innerHTML = html;

		var key = this.monthKey();
		this.prevBtn.disabled = !!this.min && key <= this.min.slice(0, 7);
		this.nextBtn.disabled = !!this.max && key >= this.max.slice(0, 7);
	};

	BookingWidget.prototype.selectDate = function (date) {
		var self = this;
		this.selectedDate = date;
		this.selectedTime = null;
		this.form.hidden = true;

		Array.prototype.forEach.call(this.grid.querySelectorAll('[data-date]'), function (b) {
			var on = b.getAttribute('data-date') === date;
			b.classList.toggle('is-selected', on);
			b.setAttribute('aria-pressed', on);
		});

		this.slotsDate.textContent = this.fmtDay.format(parseYmd(date));
		this.slotList.innerHTML = '<p class="xdlb-muted">' + t.loading + '</p>';

		getJSON('slots?date=' + date).then(function (data) {
			if (self.selectedDate !== date) {
				return;
			}
			if (!data.slots.length) {
				self.slotList.innerHTML = '<p class="xdlb-muted">' + t.noSlots + '</p>';
				return;
			}
			self.slotList.innerHTML = data.slots.map(function (time) {
				return '<button type="button" class="xdlb-slot" data-time="' + time + '" aria-pressed="false">' + time + '</button>';
			}).join('');
		}).catch(function () {
			self.slotList.innerHTML = '<p class="xdlb-muted">' + t.loadError + '</p>';
		});
	};

	BookingWidget.prototype.selectTime = function (time) {
		this.selectedTime = time;
		Array.prototype.forEach.call(this.slotList.querySelectorAll('[data-time]'), function (b) {
			var on = b.getAttribute('data-time') === time;
			b.classList.toggle('is-selected', on);
			b.setAttribute('aria-pressed', on);
		});

		this.form.elements.date.value = this.selectedDate;
		this.form.elements.time.value = time;
		this.q('[data-xdlb-picked]').textContent = this.fmtDay.format(parseYmd(this.selectedDate)) + ' · ' + time;

		var wasHidden = this.form.hidden;
		this.form.hidden = false;
		if (wasHidden) {
			this.form.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	};

	BookingWidget.prototype.clearErrors = function () {
		Array.prototype.forEach.call(this.root.querySelectorAll('[data-xdlb-error]'), function (el) {
			el.textContent = '';
		});
		Array.prototype.forEach.call(this.form.querySelectorAll('.has-error'), function (el) {
			el.classList.remove('has-error');
		});
		this.alertBox.hidden = true;
	};

	BookingWidget.prototype.showErrors = function (fields) {
		var firstField = null;
		Object.keys(fields).forEach(function (name) {
			var el = this.root.querySelector('[data-xdlb-error="' + name + '"]');
			if (el) {
				el.textContent = fields[name];
				el.closest('.xdlb-field').classList.add('has-error');
				firstField = firstField || el.closest('.xdlb-field');
			}
		}, this);
		if (fields.guest_emails) {
			this.q('[data-xdlb-guests]').hidden = false;
			this.q('[data-xdlb-guests-toggle]').hidden = true;
		}
		if (firstField) {
			var input = firstField.querySelector('input, textarea');
			if (input) {
				input.focus();
			}
		}
	};

	BookingWidget.prototype.showAlert = function (msg) {
		this.alertBox.textContent = msg;
		this.alertBox.hidden = false;
	};

	BookingWidget.prototype.submit = function () {
		var self = this;
		this.clearErrors();

		if (!this.selectedDate || !this.selectedTime) {
			this.showAlert(t.pickSlot);
			return;
		}

		var fd = new FormData(this.form);
		if (this.iti) {
			if (!this.iti.isValidNumber()) {
				this.showErrors({ phone: t.invalidPhone });
				return;
			}
			fd.set('phone', this.iti.getNumber());
		}

		var label = this.submitBtn.textContent;
		this.submitBtn.disabled = true;
		this.submitBtn.textContent = t.submitting;

		fetch(cfg.api + 'bookings', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) {
				return r.json().then(function (body) {
					return { ok: r.ok, status: r.status, body: body };
				});
			})
			.then(function (res) {
				if (res.ok) {
					self.success(res.body);
					return;
				}
				var data = res.body.data || {};
				if (data.fields) {
					self.showErrors(data.fields);
				}
				self.showAlert(res.body.message || t.submitError);
				if (res.status === 409) {
					delete self.cache[self.monthKey()];
					self.form.hidden = true;
					self.selectDate(self.selectedDate);
					self.loadMonth();
					self.slotList.scrollIntoView({ behavior: 'smooth', block: 'center' });
					self.slotsDate.textContent = res.body.message;
				}
			})
			.catch(function () {
				self.showAlert(t.submitError);
			})
			.finally(function () {
				self.submitBtn.disabled = false;
				self.submitBtn.textContent = label;
			});
	};

	BookingWidget.prototype.success = function (body) {
		this.q('[data-xdlb-schedule]').hidden = true;
		this.form.hidden = true;
		var box = this.q('[data-xdlb-success]');
		this.q('[data-xdlb-success-when]').textContent = body.summary || '';
		this.q('[data-xdlb-success-msg]').textContent = body.message || '';
		box.hidden = false;
		box.scrollIntoView({ behavior: 'smooth', block: 'center' });
	};

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('.xdlb:not([data-xdlb-ready])'), function (el) {
			el.setAttribute('data-xdlb-ready', '1');
			new BookingWidget(el);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
