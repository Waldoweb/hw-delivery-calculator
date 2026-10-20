/*
 * HW Delivery Calculator - settings page "Look up coordinates" button.
 * Uses Places (New) text search with the key typed in the API key field.
 */
(function () {
	'use strict';

	var btn = document.getElementById('hwdc-lookup');
	if (!btn) {
		return;
	}
	var t = window.hwdcAdmin || {};
	var status = document.getElementById('hwdc-lookup-status');
	var field = function (name) {
		return document.getElementById('hwdc-' + name);
	};
	var loadedKey = null;
	var authFailed = false;

	function say(text) {
		status.textContent = text;
	}

	function load(key) {
		if (window.google && google.maps && google.maps.importLibrary) {
			return Promise.resolve();
		}
		return new Promise(function (resolve, reject) {
			window.gm_authFailure = function () {
				authFailed = true;
				say(t.keyError);
			};
			window.__hwdcAdminReady = resolve;
			var s = document.createElement('script');
			s.src = 'https://maps.googleapis.com/maps/api/js?' + new URLSearchParams({
				key: key,
				v: 'weekly',
				loading: 'async',
				callback: '__hwdcAdminReady'
			}).toString();
			s.async = true;
			s.onerror = function () {
				reject(new Error('script did not load'));
			};
			document.head.appendChild(s);
			loadedKey = key;
		});
	}

	btn.addEventListener('click', function () {
		var key = field('api_key').value.trim();
		var address = field('kennel_address').value.trim();
		if (!key || !address) {
			say(t.needKey);
			return;
		}
		if (loadedKey && loadedKey !== key) {
			say(t.reload);
			return;
		}
		btn.disabled = true;
		say(t.looking);
		load(key).then(function () {
			return google.maps.importLibrary('places');
		}).then(function (places) {
			return places.Place.searchByText({
				textQuery: address,
				fields: ['location', 'formattedAddress', 'addressComponents'],
				maxResultCount: 1,
				region: 'us',
				language: 'en'
			});
		}).then(function (res) {
			var place = res && res.places && res.places[0];
			if (!place || !place.location) {
				say(t.notFound);
				return;
			}
			var lat = place.location.lat().toFixed(6);
			var lng = place.location.lng().toFixed(6);
			field('kennel_lat').value = lat;
			field('kennel_lng').value = lng;
			if (!field('kennel_label').value) {
				var town = '';
				var state = '';
				(place.addressComponents || []).forEach(function (c) {
					if (c.types.indexOf('locality') !== -1) {
						town = c.longText;
					}
					if (c.types.indexOf('administrative_area_level_1') !== -1) {
						state = c.shortText;
					}
				});
				if (town) {
					field('kennel_label').value = state ? town + ', ' + state : town;
				}
			}
			var pin = document.getElementById('hwdc-pin');
			if (pin) {
				pin.href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(lat + ',' + lng);
			}
			say((t.found || '%s').replace('%s', place.formattedAddress + ' (' + lat + ', ' + lng + ')'));
		}).catch(function (err) {
			say(authFailed ? t.keyError : (t.failed || '%s').replace('%s', err && err.message ? err.message : String(err)));
		}).then(function () {
			btn.disabled = false;
		});
	});
})();

/*
 * Styles section: a color picker beside each color field, and the preview
 * restyles as you type. Builds the same CSS as HW_Delivery_Calculator::style_css().
 */
(function () {
	'use strict';

	var fields = (window.hwdcAdmin || {}).styles || {};
	var safe = /^[A-Za-z0-9#%(),.\s'"\/+*_-]+$/;
	var hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;
	var tag = document.getElementById('hw-delivery-calculator-styles');

	function value(key) {
		var input = document.getElementById('hwdc-' + key);
		var v = input ? input.value.trim().replace(/\s+/g, ' ') : '';
		return /^--[\w-]+$/.test(v) ? 'var(' + v + ')' : v;
	}

	function render() {
		var vars = '';
		var rules = {};
		Object.keys(fields).forEach(function (key) {
			var f = fields[key];
			var v = value(key);
			if (!v || !safe.test(v)) {
				return;
			}
			vars += f['var'] + ':' + v + ';';
			if (f.target) {
				rules[f.target] = (rules[f.target] || '') + f.prop + ':var(' + f['var'] + ');';
			}
		});
		var css = vars ? '.hwdc{' + vars + '}' : '';
		Object.keys(rules).forEach(function (selector) {
			css += selector + '{' + rules[selector] + '}';
		});
		if (!tag) {
			tag = document.createElement('style');
			tag.id = 'hw-delivery-calculator-styles';
			document.head.appendChild(tag);
		}
		tag.textContent = css;
	}

	Object.keys(fields).forEach(function (key) {
		var input = document.getElementById('hwdc-' + key);
		if (input) {
			input.addEventListener('input', render);
		}
	});

	Array.prototype.forEach.call(document.querySelectorAll('.hwdc-color'), function (picker) {
		var input = document.getElementById(picker.getAttribute('data-for'));
		if (!input) {
			return;
		}
		// The picker only knows #rrggbb; it follows the field when the field holds a hex
		// color, otherwise it shows the default.
		function sync() {
			var m = hex.exec(input.value.trim()) || hex.exec(input.placeholder);
			picker.value = m ? (m[1].length === 3 ? '#' + m[1].replace(/./g, '$&$&') : m[0]).toLowerCase() : '#000000';
		}
		sync();
		input.addEventListener('input', sync);
		picker.addEventListener('input', function () {
			input.value = picker.value;
			render();
		});
	});
})();
