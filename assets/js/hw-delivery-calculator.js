/*
 * HW Delivery Calculator - front end.
 *
 * Small on purpose: Google's Maps script loads only when a visitor first touches
 * the address field. The address goes from the browser straight to Google
 * (Places autocomplete, then Routes for the driving distance); nothing is sent
 * to the site.
 */
(function () {
	'use strict';

	var METERS_PER_MILE = 1609.344;
	var mapsPromise = null;
	var authFailed = false;
	var instances = [];

	// Loads the Maps JS API once per page and resolves with the two libraries.
	function loadMaps(key) {
		if (mapsPromise) {
			return mapsPromise;
		}
		mapsPromise = new Promise(function (resolve, reject) {
			if (window.google && google.maps && google.maps.importLibrary) {
				resolve();
				return;
			}
			var timer = setTimeout(function () {
				reject(new Error('timeout'));
			}, 20000);
			window.__hwdcMapsReady = function () {
				clearTimeout(timer);
				resolve();
			};
			// Google calls this when the key is wrong or this domain is not allowed.
			var previous = window.gm_authFailure;
			window.gm_authFailure = function () {
				authFailed = true;
				instances.forEach(function (c) {
					if (c.loading || c.libs) {
						c.fail('loadError');
					}
				});
				if (typeof previous === 'function') {
					previous();
				}
			};
			var script = document.createElement('script');
			script.src = 'https://maps.googleapis.com/maps/api/js?' + new URLSearchParams({
				key: key,
				v: 'weekly',
				loading: 'async',
				libraries: 'places,routes',
				callback: '__hwdcMapsReady'
			}).toString();
			script.async = true;
			script.onerror = function () {
				clearTimeout(timer);
				script.remove();
				reject(new Error('load'));
			};
			document.head.appendChild(script);
		}).then(function () {
			return Promise.all([google.maps.importLibrary('places'), google.maps.importLibrary('routes')]);
		}).then(function (libs) {
			return { places: libs[0], routes: libs[1] };
		});
		mapsPromise.catch(function () {
			mapsPromise = null; // let the next focus try again
		});
		return mapsPromise;
	}

	// Key, referrer, API-not-enabled, billing and quota problems: retrying will not help.
	function isSetupError(err) {
		var text = String(err && (err.message || err.code) || err || '');
		return authFailed || /api[ _]?key|permission|denied|not authori[sz]ed|referer|referrer|blocked|not activated|billing|quota|RESOURCE_EXHAUSTED/i.test(text);
	}

	function report(err) {
		if (window.console && err && !err.hwdc) {
			console.warn('HW Delivery Calculator:', err);
		}
	}

	function hasType(components, type) {
		return (components || []).some(function (c) {
			return c.types && c.types.indexOf(type) !== -1;
		});
	}

	function Calculator(root) {
		this.root = root;
		this.cfg = JSON.parse(root.getAttribute('data-hwdc'));
		this.t = this.cfg.i18n || {};
		this.input = root.querySelector('.hwdc__input');
		this.clearBtn = root.querySelector('.hwdc__clear');
		this.popup = root.querySelector('.hwdc__popup');
		this.list = root.querySelector('.hwdc__list');
		this.sr = root.querySelector('.hwdc__sr');
		this.message = root.querySelector('.hwdc__message');
		this.result = root.querySelector('.hwdc__result');
		this.over = root.querySelector('.hwdc__over');
		this.libs = null;
		this.loading = null;
		this.token = null;
		this.suggestions = [];
		this.active = -1;
		this.searchId = 0;
		this.calcId = 0;
		this.debounce = null;
		this.bind();
		instances.push(this);
		if (document.activeElement === this.input) {
			this.warm();
		}
	}

	Calculator.prototype.bind = function () {
		var self = this;
		var warm = function () {
			self.warm();
		};
		// pointerdown/touchstart fire before focus, so Google starts loading a little sooner.
		this.input.addEventListener('pointerdown', warm);
		this.input.addEventListener('touchstart', warm, { passive: true });
		this.input.addEventListener('focus', warm);
		this.input.addEventListener('input', function () {
			self.onInput();
		});
		this.input.addEventListener('keydown', function (e) {
			self.onKey(e);
		});
		this.input.addEventListener('blur', function () {
			setTimeout(function () {
				if (document.activeElement !== self.input) {
					self.close();
				}
			}, 150);
		});
		// Keep focus in the field while an option is tapped or clicked.
		this.popup.addEventListener('mousedown', function (e) {
			e.preventDefault();
		});
		this.list.addEventListener('click', function (e) {
			var li = e.target.closest('[role="option"]');
			if (li) {
				self.select(parseInt(li.getAttribute('data-i'), 10));
			}
		});
		this.clearBtn.addEventListener('click', function () {
			self.reset();
			self.input.focus();
		});
		this.root.querySelector('.hwdc__form').addEventListener('submit', function (e) {
			e.preventDefault();
			self.submit();
		});
	};

	Calculator.prototype.warm = function () {
		var self = this;
		if (this.loading || this.libs) {
			return this.loading;
		}
		if (authFailed) {
			this.fail('loadError');
			return null;
		}
		this.setBusy(true);
		this.loading = loadMaps(this.cfg.key).then(function (libs) {
			self.libs = libs;
			self.setBusy(false);
			if (self.message.getAttribute('data-type') === 'loading') {
				self.show(null);
			}
			if (self.input.value.trim().length >= 3) {
				self.search();
			}
		}, function (err) {
			report(err);
			self.loading = null;
			self.setBusy(false);
			self.fail('loadError');
		});
		return this.loading;
	};

	Calculator.prototype.onInput = function () {
		var self = this;
		var q = this.input.value.trim();
		this.clearBtn.hidden = this.input.value === '';
		this.calcId++; // an edit cancels a distance lookup in flight
		this.show(null);
		clearTimeout(this.debounce);
		if (q.length < 3) {
			this.searchId++;
			this.close();
			return;
		}
		if (!this.libs) {
			this.warm(); // searches as soon as Google is ready
			if (this.loading) {
				this.show('loading', 'loading');
			}
			return;
		}
		this.debounce = setTimeout(function () {
			self.search();
		}, 220);
	};

	Calculator.prototype.search = function () {
		var self = this;
		var q = this.input.value.trim();
		var places = this.libs.places;
		var id = ++this.searchId;
		if (q.length < 3) {
			return;
		}
		if (!this.token) {
			this.token = new places.AutocompleteSessionToken();
		}
		var request = {
			input: q,
			sessionToken: this.token,
			includedRegionCodes: ['us'],
			region: 'us',
			language: 'en',
			// Rank nearby addresses first; addresses anywhere in the US still match.
			locationBias: { center: this.cfg.origin, radius: 50000 }
		};
		if (this.cfg.types && this.cfg.types.length) {
			request.includedPrimaryTypes = this.cfg.types;
		}
		places.AutocompleteSuggestion.fetchAutocompleteSuggestions(request).then(function (res) {
			if (id !== self.searchId) {
				return;
			}
			var list = (res && res.suggestions || []).filter(function (s) {
				return s.placePrediction;
			});
			self.render(list);
			if (!list.length) {
				self.show('noMatches', 'warn');
			}
		}).catch(function (err) {
			report(err);
			if (id !== self.searchId) {
				return;
			}
			self.close();
			self.show(isSetupError(err) ? 'loadError' : 'searchError', 'error');
		});
	};

	Calculator.prototype.render = function (list) {
		var self = this;
		this.suggestions = list;
		this.active = -1;
		this.list.textContent = '';
		this.input.removeAttribute('aria-activedescendant');
		if (!list.length) {
			this.close();
			return;
		}
		list.forEach(function (s, i) {
			var p = s.placePrediction;
			var li = document.createElement('li');
			li.id = self.list.id + '-' + i;
			li.className = 'hwdc__option';
			li.setAttribute('role', 'option');
			li.setAttribute('aria-selected', 'false');
			li.setAttribute('data-i', i);
			var main = document.createElement('span');
			main.className = 'hwdc__main';
			main.textContent = p.mainText ? p.mainText.toString() : p.text.toString();
			li.appendChild(main);
			if (p.secondaryText) {
				var sub = document.createElement('span');
				sub.className = 'hwdc__sub';
				sub.textContent = p.secondaryText.toString().replace(/, USA$/, '');
				li.appendChild(document.createTextNode(' '));
				li.appendChild(sub);
			}
			self.list.appendChild(li);
		});
		this.popup.hidden = false;
		this.input.setAttribute('aria-expanded', 'true');
		this.sr.textContent = list.length === 1 ? this.t.countOne : (this.t.countMany || '').replace('%d', list.length);
		this.keepPopupVisible();
	};

	// On phones the keyboard can cover the list; scroll the field up if so.
	Calculator.prototype.keepPopupVisible = function () {
		var vv = window.visualViewport;
		var bottom = this.popup.getBoundingClientRect().bottom;
		var visible = vv ? vv.height : window.innerHeight;
		if (bottom > visible) {
			this.input.scrollIntoView({ block: 'start', behavior: 'smooth' });
		}
	};

	Calculator.prototype.close = function () {
		this.popup.hidden = true;
		this.input.setAttribute('aria-expanded', 'false');
		this.input.removeAttribute('aria-activedescendant');
		this.active = -1;
	};

	Calculator.prototype.isOpen = function () {
		return !this.popup.hidden && this.suggestions.length > 0;
	};

	Calculator.prototype.move = function (step) {
		var n = this.suggestions.length;
		if (!n) {
			return;
		}
		if (!this.isOpen()) {
			this.popup.hidden = false;
			this.input.setAttribute('aria-expanded', 'true');
		}
		this.active = this.active < 0 ? (step > 0 ? 0 : n - 1) : (this.active + step + n) % n;
		var options = this.list.children;
		for (var i = 0; i < options.length; i++) {
			options[i].setAttribute('aria-selected', i === this.active ? 'true' : 'false');
		}
		var current = options[this.active];
		this.input.setAttribute('aria-activedescendant', current.id);
		current.scrollIntoView({ block: 'nearest' });
	};

	Calculator.prototype.onKey = function (e) {
		switch (e.key) {
			case 'ArrowDown':
				e.preventDefault();
				this.move(1);
				break;
			case 'ArrowUp':
				e.preventDefault();
				this.move(-1);
				break;
			case 'Enter':
				if (this.isOpen() && this.active >= 0) {
					e.preventDefault();
					this.select(this.active);
				}
				break;
			case 'Escape':
				if (this.isOpen()) {
					e.preventDefault();
					this.close();
				} else if (this.input.value) {
					e.preventDefault();
					this.reset();
				}
				break;
			case 'Tab':
				this.close();
				break;
		}
	};

	// Enter / "Go" on the phone keyboard without picking: use the top suggestion.
	Calculator.prototype.submit = function () {
		if (this.suggestions.length && this.input.value.trim().length >= 3 && !this.popup.hidden) {
			this.select(this.active >= 0 ? this.active : 0);
		} else if (this.input.value.trim().length >= 3 && this.libs) {
			this.show('pickOne', 'warn');
		}
	};

	Calculator.prototype.select = function (i) {
		var s = this.suggestions[i];
		if (!s) {
			return;
		}
		this.input.value = s.placePrediction.text.toString().replace(/, USA$/, '');
		this.clearBtn.hidden = false;
		this.searchId++;
		this.suggestions = [];
		this.close();
		this.calculate(s.placePrediction);
	};

	Calculator.prototype.calculate = function (prediction) {
		var self = this;
		var id = ++this.calcId;
		var stale = function () {
			return id !== self.calcId;
		};
		var place = prediction.toPlace();
		this.setBusy(true);
		this.show('calculating', 'loading');

		place.fetchFields({ fields: ['addressComponents', 'location'] }).then(function () {
			self.token = null; // fetchFields ends the billing session
			var parts = place.addressComponents;
			if (!hasType(parts, 'street_number') || !hasType(parts, 'route') || !place.location) {
				throw { hwdc: 'fullStreet' };
			}
			if (!self.libs.routes.Route) {
				throw { hwdc: 'routeError' };
			}
			return self.libs.routes.Route.computeRoutes({
				origin: self.cfg.origin,
				destination: place.location,
				travelMode: 'DRIVING',
				fields: ['distanceMeters']
			}).catch(function (err) {
				report(err);
				// The Routes service answers "no route" as an empty list or a NOT_FOUND style error.
				var text = String(err && (err.code || err.message) || '');
				throw { hwdc: isSetupError(err) ? 'loadError' : (/NOT_FOUND|ZERO_RESULTS|no route/i.test(text) ? 'noRoute' : 'routeError') };
			});
		}).then(function (res) {
			if (stale()) {
				return;
			}
			var route = res && res.routes && res.routes[0];
			if (!route || typeof route.distanceMeters !== 'number') {
				throw { hwdc: 'noRoute' };
			}
			self.estimate(route.distanceMeters);
		}).catch(function (err) {
			report(err);
			if (stale()) {
				return;
			}
			var code = err && err.hwdc ? err.hwdc : (isSetupError(err) ? 'loadError' : 'routeError');
			self.show(code, code === 'fullStreet' ? 'warn' : 'error');
		}).then(function () {
			if (!stale()) {
				self.setBusy(false);
			}
		});
	};

	Calculator.prototype.money = function (amount, decimals) {
		try {
			return new Intl.NumberFormat(this.cfg.locale || 'en-US', {
				style: 'currency',
				currency: this.cfg.currency || 'USD',
				minimumFractionDigits: decimals,
				maximumFractionDigits: decimals
			}).format(amount);
		} catch (e) {
			return '$' + amount.toFixed(decimals);
		}
	};

	Calculator.prototype.estimate = function (meters) {
		var miles = Math.round(meters / METERS_PER_MILE);
		var rate = Number(this.cfg.rate) || 0;
		var max = parseInt(this.cfg.max, 10) || 0;
		// Price from the rounded miles so the numbers shown add up.
		var values = {
			miles: miles.toLocaleString(this.cfg.locale || 'en-US'),
			price: this.money(Math.round(miles * rate), 0),
			rate: this.money(rate, Math.round(rate) === rate ? 0 : 2)
		};
		var box = max > 0 && miles > max ? this.over : this.result;
		box.querySelectorAll('.hwdc-v').forEach(function (el) {
			el.textContent = values[el.getAttribute('data-v')] || '';
		});
		this.show(null);
		box.hidden = false;
		this.root.classList.add('has-result');
	};

	// type: loading | warn | error. Passing null clears the message and any result.
	Calculator.prototype.show = function (key, type) {
		this.result.hidden = true;
		this.over.hidden = true;
		this.root.classList.remove('has-result');
		if (!key) {
			this.message.hidden = true;
			this.message.textContent = '';
			this.message.removeAttribute('data-type');
			return;
		}
		this.message.textContent = this.t[key] || key;
		this.message.setAttribute('data-type', type || 'info');
		this.message.hidden = false;
	};

	Calculator.prototype.fail = function (key) {
		this.close();
		this.setBusy(false);
		this.show(key, 'error');
	};

	Calculator.prototype.setBusy = function (busy) {
		this.root.classList.toggle('is-busy', busy);
		this.input.setAttribute('aria-busy', busy ? 'true' : 'false');
	};

	Calculator.prototype.reset = function () {
		clearTimeout(this.debounce);
		this.searchId++;
		this.calcId++;
		this.input.value = '';
		this.clearBtn.hidden = true;
		this.suggestions = [];
		this.list.textContent = '';
		this.close();
		this.setBusy(false);
		this.show(null);
	};

	function init(scope) {
		(scope || document).querySelectorAll('.hwdc[data-hwdc]:not([data-hwdc-ready])').forEach(function (el) {
			el.setAttribute('data-hwdc-ready', '');
			try {
				new Calculator(el);
			} catch (e) {
				if (window.console) {
					console.error('HW Delivery Calculator:', e);
				}
			}
		});
	}

	// For builders that insert the shortcode after load (popups, AJAX): hwDeliveryCalculator.init(element).
	window.hwDeliveryCalculator = { init: init };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			init();
		});
	} else {
		init();
	}
})();
