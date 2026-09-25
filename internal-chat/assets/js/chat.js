/**
 * Admin chat – lebegő, Messenger stílusú chat ablak.
 */
(function () {
	'use strict';

	var cfg = window.IWC_CONFIG;
	if (!cfg || !window.fetch) {
		return;
	}
	var t = cfg.i18n;
	var nonce = cfg.nonce;
	var LS = 'iwc_' + cfg.userId + '_';

	var VIEW_OPEN = 'open';
	var VIEW_MIN = 'min';
	var VIEW_CLOSED = 'closed';

	var state = {
		rooms: [],
		roomId: parseInt(lsGet('room', '0'), 10) || 0,
		loadedRoom: 0,
		// A legnagyobb, a szervertől (lekérdezés / stream) kapott üzenet azonosító.
		cursor: 0,
		// A legnagyobb megjelenített üzenet azonosító (a saját, épp elküldött üzenetet is beleértve).
		lastId: 0,
		lastRead: 0,
		hasMore: false,
		ids: {},
		view: lsGet('view', VIEW_OPEN),
		busy: false,
		again: false,
		loadingOlder: false,
		stopped: false,
		timer: null
	};

	// Valós idejű kapcsolat (Server-Sent Events). Ha nem működik, lekérdezéses módra váltunk.
	var stream = {
		enabled: !!(cfg.realtime && window.EventSource),
		source: null,
		room: 0,
		failures: 0,
		received: false,
		retryTimer: null
	};

	var ui = {};

	/* ---------- segédfüggvények ---------- */

	function lsGet(key, def) {
		try {
			var v = window.localStorage.getItem(LS + key);
			return v === null ? def : v;
		} catch (e) {
			return def;
		}
	}

	function lsSet(key, value) {
		try {
			window.localStorage.setItem(LS + key, String(value));
		} catch (e) {}
	}

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) {
			node.className = cls;
		}
		if (text !== undefined && text !== null) {
			node.textContent = text;
		}
		return node;
	}

	function url(path, params) {
		var u = cfg.restUrl + path;
		if (params) {
			var q = Object.keys(params).map(function (k) {
				return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
			}).join('&');
			u += (u.indexOf('?') === -1 ? '?' : '&') + q;
		}
		return u;
	}

	function api(method, path, params, body) {
		return fetch(url(path, params), {
			method: method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': nonce,
				'Content-Type': 'application/json'
			},
			body: body ? JSON.stringify(body) : undefined
		}).then(function (res) {
			return res.json().catch(function () {
				return {};
			}).then(function (data) {
				if (!res.ok) {
					var err = new Error(data && data.message ? data.message : 'HTTP ' + res.status);
					err.status = res.status;
					err.code = data && data.code;
					throw err;
				}
				return data;
			});
		});
	}

	function currentRoom() {
		for (var i = 0; i < state.rooms.length; i++) {
			if (state.rooms[i].id === state.roomId) {
				return state.rooms[i];
			}
		}
		return null;
	}

	function roomAvatar(room, cls) {
		if (room && room.logo) {
			var img = el('img', cls);
			img.src = room.logo;
			img.alt = '';
			return img;
		}
		var name = room ? room.name : '';
		return el('span', cls + ' iwc-logo-letter', name ? name.charAt(0).toUpperCase() : '#');
	}

	/* ---------- felépítés ---------- */

	function build() {
		var root = el('div', 'iwc');
		root.id = 'iwc-root';
		root.hidden = true;

		// Bezárt állapotban látszó kerek gomb.
		var launcher = el('button', 'iwc-launcher');
		launcher.type = 'button';
		launcher.title = t.open;
		launcher.setAttribute('aria-label', t.open);
		launcher.innerHTML = '<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path fill="currentColor" d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.19 5.44 3.14 7.17.16.14.26.35.27.57l.05 1.78a.8.8 0 0 0 1.12.71l1.98-.87c.17-.08.36-.09.53-.04.91.25 1.87.38 2.91.38 5.64 0 10-4.13 10-9.7S17.64 2 12 2z"/></svg>';
		ui.launcherBadge = el('span', 'iwc-badge');
		ui.launcherBadge.hidden = true;
		launcher.appendChild(ui.launcherBadge);
		launcher.addEventListener('click', function () {
			setView(VIEW_OPEN);
		});

		var win = el('div', 'iwc-window');
		win.setAttribute('role', 'dialog');

		// Fejléc: logó + szoba neve (kattintásra szobaváltó), kis méret, bezárás.
		var header = el('div', 'iwc-header');
		ui.title = el('button', 'iwc-title');
		ui.title.type = 'button';
		ui.logoSlot = el('span', 'iwc-logo-slot');
		ui.name = el('span', 'iwc-name');
		ui.caret = el('span', 'iwc-caret');
		ui.caret.innerHTML = '<svg viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg>';
		ui.otherBadge = el('span', 'iwc-badge iwc-badge-inline');
		ui.otherBadge.hidden = true;
		ui.title.appendChild(ui.logoSlot);
		ui.title.appendChild(ui.name);
		ui.title.appendChild(ui.caret);
		ui.title.appendChild(ui.otherBadge);
		ui.title.addEventListener('click', function (e) {
			e.stopPropagation();
			if (state.view !== VIEW_OPEN) {
				setView(VIEW_OPEN);
				return;
			}
			// Ha nincs másik szoba, nem történik semmi.
			if (state.rooms.length < 2) {
				return;
			}
			toggleMenu();
		});

		var actions = el('div', 'iwc-actions');
		var minBtn = el('button', 'iwc-icon-btn iwc-min');
		minBtn.type = 'button';
		minBtn.title = t.minimize;
		minBtn.setAttribute('aria-label', t.minimize);
		minBtn.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><rect x="5" y="11" width="14" height="2.4" rx="1.2" fill="currentColor"/></svg>';
		minBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			setView(state.view === VIEW_MIN ? VIEW_OPEN : VIEW_MIN);
		});
		var closeBtn = el('button', 'iwc-icon-btn iwc-close');
		closeBtn.type = 'button';
		closeBtn.title = t.close;
		closeBtn.setAttribute('aria-label', t.close);
		closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>';
		closeBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			setView(VIEW_CLOSED);
		});
		actions.appendChild(minBtn);
		actions.appendChild(closeBtn);

		header.appendChild(ui.title);
		header.appendChild(actions);
		header.addEventListener('click', function () {
			if (state.view === VIEW_MIN) {
				setView(VIEW_OPEN);
			}
		});

		ui.menu = el('ul', 'iwc-menu');
		ui.menu.hidden = true;
		ui.menu.setAttribute('role', 'menu');

		ui.list = el('div', 'iwc-messages');
		ui.list.setAttribute('aria-live', 'polite');
		ui.list.addEventListener('scroll', function () {
			if (ui.list.scrollTop < 40) {
				loadOlder();
			}
		});
		ui.notice = el('div', 'iwc-notice');
		ui.notice.hidden = true;

		// Szövegbeviteli sáv.
		var composer = el('form', 'iwc-composer');
		ui.input = el('textarea', 'iwc-input');
		ui.input.rows = 1;
		ui.input.placeholder = t.placeholder;
		ui.input.setAttribute('aria-label', t.placeholder);
		ui.send = el('button', 'iwc-send');
		ui.send.type = 'submit';
		ui.send.title = t.send;
		ui.send.setAttribute('aria-label', t.send);
		ui.send.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M3.4 20.4l17.45-7.48a1 1 0 0 0 0-1.84L3.4 3.6a.99.99 0 0 0-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"/></svg>';
		composer.appendChild(ui.input);
		composer.appendChild(ui.send);

		// Rákattintás / fókusz a beviteli mezőre: az új üzenetek visszaváltanak normál betűre.
		ui.input.addEventListener('focus', markRead);
		ui.input.addEventListener('click', markRead);
		ui.input.addEventListener('input', autosize);
		ui.input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
				e.preventDefault();
				sendMessage();
			}
		});
		composer.addEventListener('submit', function (e) {
			e.preventDefault();
			sendMessage();
		});

		win.appendChild(header);
		win.appendChild(ui.menu);
		win.appendChild(ui.list);
		win.appendChild(ui.notice);
		win.appendChild(composer);

		root.appendChild(win);
		root.appendChild(launcher);
		document.body.appendChild(root);

		document.addEventListener('click', function (e) {
			if (!ui.menu.hidden && !ui.menu.contains(e.target)) {
				ui.menu.hidden = true;
			}
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				ui.menu.hidden = true;
			}
		});

		ui.root = root;
		applyView();
	}

	function autosize() {
		ui.input.style.height = 'auto';
		ui.input.style.height = Math.min(ui.input.scrollHeight, 120) + 'px';
	}

	/* ---------- ablak állapot ---------- */

	function setView(view) {
		state.view = view;
		lsSet('view', view);
		applyView();
		if (view === VIEW_OPEN) {
			scrollToBottom();
		}
	}

	function applyView() {
		ui.root.classList.toggle('iwc-is-open', state.view === VIEW_OPEN);
		ui.root.classList.toggle('iwc-is-min', state.view === VIEW_MIN);
		ui.root.classList.toggle('iwc-is-closed', state.view === VIEW_CLOSED);
		if (state.view !== VIEW_OPEN) {
			ui.menu.hidden = true;
		}
	}

	/* ---------- fejléc, szobaváltó, jelvények ---------- */

	function renderHeader() {
		var room = currentRoom();
		ui.root.hidden = state.rooms.length === 0;
		ui.logoSlot.textContent = '';
		ui.logoSlot.appendChild(roomAvatar(room, 'iwc-logo'));
		ui.name.textContent = room ? room.name : '';
		ui.title.classList.toggle('iwc-has-rooms', state.rooms.length > 1);
		ui.title.title = state.rooms.length > 1 ? t.switchRoom : '';

		var total = 0;
		var others = 0;
		state.rooms.forEach(function (r) {
			total += r.unread;
			if (r.id !== state.roomId) {
				others += r.unread;
			}
		});
		setBadge(ui.launcherBadge, total);
		setBadge(ui.otherBadge, others);

		if (!ui.menu.hidden) {
			renderMenu();
		}
	}

	function setBadge(node, count) {
		node.hidden = count < 1;
		node.textContent = count > 99 ? '99+' : String(count);
	}

	function toggleMenu() {
		if (ui.menu.hidden) {
			renderMenu();
			ui.menu.hidden = false;
		} else {
			ui.menu.hidden = true;
		}
	}

	function renderMenu() {
		ui.menu.textContent = '';
		state.rooms.forEach(function (room) {
			var li = el('li');
			var btn = el('button', 'iwc-menu-item' + (room.id === state.roomId ? ' iwc-active' : ''));
			btn.type = 'button';
			btn.setAttribute('role', 'menuitem');
			btn.appendChild(roomAvatar(room, 'iwc-logo'));
			btn.appendChild(el('span', 'iwc-menu-name', room.name));
			if (room.unread > 0) {
				btn.appendChild(el('span', 'iwc-badge iwc-badge-inline', room.unread > 99 ? '99+' : String(room.unread)));
			}
			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				ui.menu.hidden = true;
				switchRoom(room.id);
			});
			li.appendChild(btn);
			ui.menu.appendChild(li);
		});
	}

	function switchRoom(id) {
		if (id === state.roomId) {
			return;
		}
		state.roomId = id;
		state.loadedRoom = 0;
		lsSet('room', id);
		resetList();
		ui.list.appendChild(el('div', 'iwc-empty', t.loading));
		renderHeader();
		refresh();
	}

	/* ---------- üzenetek ---------- */

	function resetList() {
		ui.list.textContent = '';
		state.ids = {};
		state.lastId = 0;
		state.cursor = 0;
		state.lastRead = 0;
		state.hasMore = false;
	}

	function renderMessage(m) {
		var row = el('div', 'iwc-msg' + (m.mine ? ' iwc-mine' : ''));
		row.dataset.id = m.id;
		if (!m.mine && m.id > state.lastRead) {
			row.classList.add('iwc-unread');
		}
		if (!m.mine) {
			var avatar = el('img', 'iwc-avatar');
			avatar.src = m.avatar;
			avatar.alt = '';
			avatar.loading = 'lazy';
			row.appendChild(avatar);
		}
		var body = el('div', 'iwc-msg-body');
		// Felül a felhasználónév, alatta halvány szürke időbélyeg, majd az üzenet szövege.
		body.appendChild(el('div', 'iwc-msg-name', m.name));
		var time = el('time', 'iwc-msg-time', m.time);
		time.dateTime = m.iso;
		body.appendChild(time);
		body.appendChild(el('div', 'iwc-bubble', m.text));
		row.appendChild(body);
		return row;
	}

	function nearBottom() {
		return ui.list.scrollHeight - ui.list.scrollTop - ui.list.clientHeight < 80;
	}

	function scrollToBottom() {
		ui.list.scrollTop = ui.list.scrollHeight;
	}

	function removePlaceholder() {
		var ph = ui.list.querySelector('.iwc-empty');
		if (ph) {
			ph.parentNode.removeChild(ph);
		}
	}

	/**
	 * Üzenetek hozzáadása azonosító szerinti sorrendben (duplikáció nélkül).
	 *
	 * @param {boolean} fromServerFeed A lekérdezésből / streamből jött-e (ez lépteti a kurzort).
	 */
	function appendMessages(messages, forceScroll, fromServerFeed) {
		var stick = forceScroll || nearBottom();
		var added = false;
		messages.forEach(function (m) {
			if (fromServerFeed) {
				state.cursor = Math.max(state.cursor, m.id);
			}
			if (state.ids[m.id]) {
				return;
			}
			state.ids[m.id] = true;
			removePlaceholder();
			var node = renderMessage(m);
			var next = null;
			if (m.id < state.lastId) {
				var rows = ui.list.querySelectorAll('.iwc-msg');
				for (var i = 0; i < rows.length; i++) {
					if (parseInt(rows[i].dataset.id, 10) > m.id) {
						next = rows[i];
						break;
					}
				}
			}
			ui.list.insertBefore(node, next);
			state.lastId = Math.max(state.lastId, m.id);
			added = true;
		});
		if (added && stick) {
			scrollToBottom();
		}
	}

	/** Ha máshol (pl. másik böngészőfülön) olvasottnak jelölte, itt is normál betűre vált. */
	function syncRead() {
		var bold = ui.list.querySelectorAll('.iwc-unread');
		for (var i = 0; i < bold.length; i++) {
			if (parseInt(bold[i].dataset.id, 10) <= state.lastRead) {
				bold[i].classList.remove('iwc-unread');
			}
		}
	}

	function prependMessages(messages) {
		var before = ui.list.scrollHeight;
		var first = ui.list.firstChild;
		messages.forEach(function (m) {
			if (state.ids[m.id]) {
				return;
			}
			state.ids[m.id] = true;
			ui.list.insertBefore(renderMessage(m), first);
		});
		ui.list.scrollTop += ui.list.scrollHeight - before;
	}

	function showEmptyIfNeeded() {
		if (!ui.list.querySelector('.iwc-msg') && !ui.list.querySelector('.iwc-empty')) {
			ui.list.appendChild(el('div', 'iwc-empty', t.empty));
		}
	}

	function firstId() {
		var first = ui.list.querySelector('.iwc-msg');
		return first ? parseInt(first.dataset.id, 10) : 0;
	}

	function loadOlder() {
		if (!state.hasMore || state.loadingOlder || !state.roomId) {
			return;
		}
		var roomId = state.roomId;
		state.loadingOlder = true;
		api('GET', 'rooms/' + roomId + '/messages', { before: firstId(), limit: 30 })
			.then(function (data) {
				if (roomId !== state.roomId) {
					return;
				}
				state.hasMore = !!data.has_more;
				prependMessages(data.messages || []);
			})
			.catch(function () {})
			.then(function () {
				state.loadingOlder = false;
			});
	}

	/* ---------- olvasottság ---------- */

	function markRead() {
		var room = currentRoom();
		var bold = ui.list.querySelectorAll('.iwc-unread');
		if (!room || (!bold.length && !room.unread)) {
			return;
		}
		for (var i = 0; i < bold.length; i++) {
			bold[i].classList.remove('iwc-unread');
		}
		state.lastRead = Math.max(state.lastRead, state.lastId);
		room.unread = 0;
		room.latest_unread_id = 0;
		renderHeader();
		if (state.lastId) {
			api('POST', 'rooms/' + room.id + '/read', null, { last_id: state.lastId }).catch(function () {});
		}
	}

	/* ---------- küldés ---------- */

	function sendMessage() {
		var text = ui.input.value.trim();
		if (!text || !state.roomId || ui.send.disabled) {
			return;
		}
		var roomId = state.roomId;
		ui.send.disabled = true;
		api('POST', 'rooms/' + roomId + '/messages', null, { message: text })
			.then(function (message) {
				ui.input.value = '';
				autosize();
				if (roomId === state.roomId) {
					// Azonnal megjelenik; a stream / lekérdezés később a helyére teszi a köztes üzeneteket.
					appendMessages([message], true, false);
				}
				markRead();
				if (!streaming()) {
					poll();
				}
			})
			.catch(function (err) {
				handleError(err, t.sendError);
			})
			.then(function () {
				ui.send.disabled = false;
				ui.input.focus();
			});
	}

	/* ---------- szerverállapot feldolgozása ---------- */

	/**
	 * A /poll válasz és a stream "state" eseménye ugyanaz a szerkezet.
	 *
	 * @param {number} requestedRoom A kérés indításakor kiválasztott szoba.
	 */
	function applyState(data, requestedRoom) {
		if (data.nonce) {
			nonce = data.nonce;
		}
		state.rooms = data.rooms || [];

		if (requestedRoom === state.roomId) {
			if (data.room !== state.roomId) {
				// A kért szoba már nem elérhető (archiválták / jogosultság változott) – a szerver választott másikat.
				state.roomId = data.room;
				lsSet('room', data.room);
			}
			if (data.full) {
				resetList();
				state.loadedRoom = state.roomId;
				state.lastRead = data.last_read || 0;
				state.hasMore = !!data.has_more;
				appendMessages(data.messages || [], true, true);
				showEmptyIfNeeded();
			} else {
				state.lastRead = Math.max(state.lastRead, data.last_read || 0);
				appendMessages(data.messages || [], false, true);
				syncRead();
			}
		}

		renderHeader();
		checkNewMessages();
	}

	function needsFullLoad() {
		return !state.roomId || state.loadedRoom !== state.roomId;
	}

	/** Azonnali frissítés: látható fülön valós idejű kapcsolat, egyébként lekérdezés. */
	function refresh() {
		if (stream.enabled && !document.hidden) {
			openStream();
		} else {
			poll();
		}
	}

	/* ---------- valós idejű kapcsolat (Server-Sent Events) ---------- */

	function streaming() {
		return !!stream.source;
	}

	function closeStream() {
		clearTimeout(stream.retryTimer);
		if (stream.source) {
			stream.source.close();
			stream.source = null;
		}
	}

	function openStream() {
		closeStream();
		clearTimeout(state.timer);
		if (state.stopped) {
			return;
		}
		var room = state.roomId;
		var source = new EventSource(url('stream', {
			context: cfg.context,
			room: room || 0,
			after: state.cursor,
			full: needsFullLoad() ? 1 : 0,
			_wpnonce: nonce
		}));
		stream.source = source;
		stream.room = room;

		source.addEventListener('state', function (e) {
			if (stream.source !== source) {
				return;
			}
			stream.failures = 0;
			stream.received = true;
			var data;
			try {
				data = JSON.parse(e.data);
			} catch (err) {
				return;
			}
			applyState(data, stream.room);
			if (stream.room !== state.roomId) {
				if (state.loadedRoom === state.roomId) {
					// A szerver váltott szobát (a kért nem elérhető): a stream már az újat követi.
					stream.room = state.roomId;
				} else {
					// A felhasználó közben szobát váltott.
					openStream();
				}
			}
		});

		// A szerver a kapcsolat élettartama végén lezár: azonnal újranyitjuk.
		source.addEventListener('bye', function () {
			if (stream.source === source) {
				openStream();
			}
		});

		source.addEventListener('error', function () {
			if (stream.source !== source) {
				return;
			}
			closeStream();
			stream.failures++;
			if (stream.failures >= 3 && !stream.received) {
				// A tárhely nem támogatja a streamet: végleg lekérdezéses módra váltunk.
				stream.enabled = false;
				poll();
				return;
			}
			if (stream.failures >= 3) {
				// Valószínűleg lejárt a munkamenet: a lekérdezés kideríti és jelzi.
				poll();
				stream.failures = 0;
				return;
			}
			stream.retryTimer = setTimeout(refresh, Math.min(1000 * Math.pow(2, stream.failures), 15000));
		});
	}

	/* ---------- lekérdezés (polling, tartalék mód) ---------- */

	function schedule(delay) {
		clearTimeout(state.timer);
		if (state.stopped || streaming()) {
			return;
		}
		if (delay === undefined) {
			delay = document.hidden ? Math.max(cfg.poll * 4, 15000) : cfg.poll;
		}
		state.timer = setTimeout(function () {
			if (stream.enabled && !document.hidden) {
				openStream();
			} else {
				poll();
			}
		}, delay);
	}

	function poll() {
		if (state.stopped) {
			return;
		}
		if (state.busy) {
			state.again = true;
			return;
		}
		clearTimeout(state.timer);
		state.busy = true;
		state.again = false;

		var roomAtRequest = state.roomId;
		var full = needsFullLoad();

		api('GET', 'poll', {
			context: cfg.context,
			room: roomAtRequest || 0,
			after: full ? 0 : state.cursor,
			full: full ? 1 : 0
		}).then(function (data) {
			applyState(data, roomAtRequest);
		}).catch(function (err) {
			handleError(err);
		}).then(function () {
			state.busy = false;
			schedule(state.again || roomAtRequest !== state.roomId ? 0 : undefined);
		});
	}

	/**
	 * Új (még fel nem dobott) olvasatlan üzenet esetén az ablak felugrik.
	 * A már felugrott üzenet azonosítóját eltároljuk, így oldalváltáskor nem ugrik fel újra.
	 */
	function checkNewMessages() {
		var newest = 0;
		var target = 0;
		state.rooms.forEach(function (r) {
			if (r.unread > 0 && r.latest_unread_id > newest) {
				newest = r.latest_unread_id;
				target = r.id;
			}
		});
		var popped = parseInt(lsGet('popped', '0'), 10) || 0;
		if (newest <= popped) {
			return;
		}
		lsSet('popped', newest);

		var room = currentRoom();
		var wasOpen = state.view === VIEW_OPEN;
		if (!wasOpen && target && target !== state.roomId && (!room || room.unread === 0)) {
			switchRoom(target);
		}
		if (!wasOpen) {
			setView(VIEW_OPEN);
		} else if (target === state.roomId) {
			scrollToBottom();
		}
	}

	function handleError(err, fallback) {
		if (err && (err.status === 401 || err.code === 'rest_cookie_invalid_nonce')) {
			state.stopped = true;
			closeStream();
			ui.notice.textContent = t.expired;
			ui.notice.hidden = false;
			return;
		}
		if (fallback) {
			ui.notice.textContent = err && err.message ? fallback + ' (' + err.message + ')' : fallback;
			ui.notice.hidden = false;
			setTimeout(function () {
				if (!state.stopped) {
					ui.notice.hidden = true;
				}
			}, 5000);
		}
	}

	// Háttérben lévő fülön nem tartunk nyitva kapcsolatot (kímélni a szervert), csak ritkán kérdezünk.
	document.addEventListener('visibilitychange', function () {
		if (document.hidden) {
			if (streaming()) {
				closeStream();
				schedule();
			}
		} else {
			refresh();
		}
	});

	window.addEventListener('pagehide', closeStream);

	function start() {
		build();
		refresh();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
