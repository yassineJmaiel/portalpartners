/* The portal's small script. Every page works from the server; this adds the conveniences:
   menus, the search palette, the document viewer, the ICD-10 picker, the live line. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var body = document.body;
  var data = body.dataset || {};
  var csrf = ($('meta[name="csrf-token"]') || {}).content;
  var reduced = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var esc = function (v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; };
  var getJson = function (url) { return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }); };

  /* ---------------------------------------- what each piece of a page does; run again on content that was swapped in */
  function enhance(root) {
  $$('form[data-autosubmit] select, form[data-autosubmit] input[type=checkbox]', root).forEach(function (el) { el.addEventListener('change', function () { el.form.requestSubmit(); }); });
  $$('[data-submit-on-change]', root).forEach(function (el) {
    el.addEventListener('change', function () {
      var f = el.form;
      var nav = document.createElement('input');
      nav.type = 'hidden'; nav.name = 'nav'; nav.value = 'stay';
      if (softable(f)) { softSubmit(f, { name: 'nav', value: 'stay' }); return; }
      if (f.querySelector('[name=step]')) f.appendChild(nav);
      f.submit();
    });
  });
  $$('[data-file-names]', root).forEach(function (input) {
    input.addEventListener('change', function () {
      var list = input.closest('form').querySelector('[data-file-list]');
      if (!list) return;
      list.innerHTML = Array.prototype.map.call(input.files, function (f) { return '<span class="file">' + esc(f.name) + '</span>'; }).join('');
    });
  });
  $$('[data-select-on-focus]', root).forEach(function (el) { el.addEventListener('focus', function () { el.select(); }); });
  $$('tr[data-href]', root).forEach(function (tr) {
    tr.addEventListener('click', function (e) { if (!e.target.closest('a,button,form,input,select')) location.href = tr.dataset.href; });
  });
  $$('[data-toggle]', root).forEach(function (b) { b.addEventListener('click', function () { var t = document.getElementById(b.dataset.toggle); if (t) t.hidden = !t.hidden; }); });
  $$('[data-dialog]', root).forEach(function (b) { b.addEventListener('click', function () { var d = document.getElementById(b.dataset.dialog); if (d) { d.hidden = false; var f = $('input:not([type=hidden]),textarea,select,button', d); if (f) f.focus(); } }); });
  $$('[data-dialog-close]', root).forEach(function (b) { b.addEventListener('click', function () { b.closest('.layer').hidden = true; }); });
  $$('.layer', root).forEach(function (l) { l.addEventListener('mousedown', function (e) { if (e.target === l) l.hidden = true; }); });
  var docLayer = $('#doc-layer');
  if (docLayer) {
    $$('[data-doc]', root).forEach(function (b) {
      b.addEventListener('click', function () {
        $('#doc-title').textContent = b.dataset.docTitle || '';
        $('#doc-frame').src = b.dataset.doc;
        $('#doc-open').href = b.dataset.doc;
        docLayer.hidden = false;
      });
    });
  }
  /* ---------------------------------------- the ICD-10 picker */
  $$('[data-icd-picker]', root).forEach(function (combo) {
    var input = $('input[name]:not([type=hidden])', combo);
    var list = $('.combo-list', combo);
    var title = $('[data-icd-title]', combo);
    var certainty = $('[data-icd-certainty]', combo);
    var allowUnknown = combo.dataset.allowUnknown === '1';
    var timer;
    var pick = function (code, text, how) {
      input.value = code;
      if (title) title.value = text || code;
      if (certainty) certainty.value = how || 'Confirmed';
      list.hidden = true;
    };
    var show = function (items) {
      var html = allowUnknown ? '<button type="button" class="combo-it unknown" data-code="R69" data-title="Illness, unspecified" data-how="Unknown Illness"><b>R69</b><span>' + esc(data.tUnknown) + '</span></button>' : '';
      items.forEach(function (i) {
        html += '<button type="button" class="combo-it" data-code="' + esc(i.code) + '" data-title="' + esc(i.title) + '"><b>' + esc(i.code) + '</b><span>' + esc(i.title) + '</span></button>';
      });
      if (!items.length) html += '<div class="combo-empty">' + esc(data.tNomatch) + '</div>';
      list.innerHTML = html;
      list.hidden = false;
    };
    var load = function () { getJson('/icd10?q=' + encodeURIComponent(input.value)).then(function (r) { show(r.items || []); }).catch(function () { list.hidden = true; }); };
    input.addEventListener('focus', load);
    input.addEventListener('input', function () { if (certainty) certainty.value = 'Confirmed'; if (title) title.value = ''; clearTimeout(timer); timer = setTimeout(load, 180); });
    list.addEventListener('mousedown', function (e) {
      var b = e.target.closest('[data-code]');
      if (b) { e.preventDefault(); pick(b.dataset.code, b.dataset.title, b.dataset.how); }
    });
    input.addEventListener('blur', function () { setTimeout(function () { list.hidden = true; }, 120); });
  });
  /* ---------------------------------------- @mentions in a team note */
  $$('textarea[data-mentions]', root).forEach(function (box) {
    var people = JSON.parse(box.dataset.mentions || '[]');
    var pick = document.createElement('ul');
    pick.className = 'mention-pick';
    pick.hidden = true;
    box.parentNode.appendChild(pick);
    var query = null;
    box.addEventListener('input', function () {
      var upto = box.value.slice(0, box.selectionStart);
      var m = upto.match(/(^|\s)@([^\s@]{0,30})$/);
      query = m ? { text: m[2], at: upto.length - m[2].length - 1 } : null;
      var found = query ? people.filter(function (u) { return u.name.toLowerCase().indexOf(query.text.toLowerCase()) >= 0; }).slice(0, 6) : [];
      pick.hidden = !found.length;
      pick.innerHTML = found.map(function (u) { return '<li><button type="button" data-name="' + esc(u.name) + '"><b>' + esc(u.name) + '</b><i>' + esc(u.role) + '</i></button></li>'; }).join('');
    });
    pick.addEventListener('mousedown', function (e) {
      var b = e.target.closest('[data-name]');
      if (!b) return;
      e.preventDefault();
      box.value = box.value.slice(0, query.at) + '@' + b.dataset.name + ' ' + box.value.slice(query.at + 1 + query.text.length).replace(/^\s/, '');
      pick.hidden = true;
      box.focus();
    });
  });
  /* ---------------------------------------- phone cards: each cell knows its column */
  $$('table.tbl', root).forEach(function (table) {
    var heads = $$('thead tr:last-child th', table).map(function (th) { return th.textContent.trim(); });
    $$('tbody tr', table).forEach(function (tr) {
      Array.prototype.forEach.call(tr.children, function (td, i) { if (heads[i] && !td.hasAttribute('data-l')) td.setAttribute('data-auto-l', heads[i]); });
    });
  });
  $$('textarea[data-submit-on-enter]', root).forEach(function (box) {
    box.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && box.value.trim()) { e.preventDefault(); box.form.requestSubmit(); } });
  });
  /* ---------------------------------------- saved views live in this browser, per person */
  var views = $('#saved-views', root);
  if (views) {
    var key = 'vpp.claimViews.' + (data.uid || '');
    var read = function () { try { return JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { return []; } };
    var write = function (list) { try { localStorage.setItem(key, JSON.stringify(list)); } catch (e) { /* storage blocked */ } };
    var draw = function () {
      var list = read();
      var html = list.length ? '<span class="muted" style="font-size:12.5px">' + esc(views.dataset.tSaved) + ':</span>' + list.map(function (v, i) {
        return '<span class="file"><a class="lnk" href="/claims?' + esc(v.q) + '">' + esc(v.name) + '</a><button type="button" data-drop="' + i + '" aria-label="' + esc(views.dataset.tDelete) + '">×</button></span>';
      }).join('') : '';
      if (views.dataset.filtered) html += '<input id="view-name" placeholder="' + esc(views.dataset.tName) + '" style="width:160px"><button type="button" class="btn btn-s btn-sm" id="view-save">' + esc(views.dataset.tSave) + '</button>';
      views.innerHTML = html;
    };
    views.addEventListener('click', function (e) {
      var drop = e.target.closest('[data-drop]');
      if (drop) { var l = read(); l.splice(Number(drop.dataset.drop), 1); write(l); draw(); }
      if (e.target.id === 'view-save') {
        var name = $('#view-name').value.trim();
        if (name) { write(read().filter(function (x) { return x.name !== name; }).concat([{ name: name, q: location.search.slice(1) }])); draw(); }
      }
    });
    draw();
  }
  /* ---------------------------------------- scan the member card (where the browser can read codes) */
  $$('[data-scan-card]', root).forEach(function (b) {
    if (!('BarcodeDetector' in window) || !navigator.mediaDevices) { b.title = b.dataset.tNoscan; b.disabled = true; return; }
    b.addEventListener('click', function () {
      var layerEl = document.createElement('div');
      layerEl.className = 'layer';
      layerEl.innerHTML = '<div class="modal modal-md"><div class="modal-b"><video muted playsinline style="width:100%;border-radius:14px;background:#000"></video></div></div>';
      document.body.appendChild(layerEl);
      var video = $('video', layerEl);
      var stream;
      var stop = function () { if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); layerEl.remove(); };
      layerEl.addEventListener('mousedown', function (e) { if (e.target === layerEl) stop(); });
      var detector = new window.BarcodeDetector({ formats: ['qr_code', 'code_128', 'code_39', 'pdf417', 'data_matrix'] });
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
        stream = s; video.srcObject = s; return video.play();
      }).then(function tick() {
        return detector.detect(video).then(function (codes) {
          if (codes.length) {
            var value = codes[0].rawValue;
            var input = b.parentNode.querySelector('input[name=q]');
            input.value = value;
            var hidden = document.getElementById('scanned-card');
            if (hidden) hidden.value = value;
            stop();
            return null;
          }
          return new Promise(function (r) { setTimeout(r, 200); }).then(tick);
        });
      }).catch(function () { stop(); alert(b.dataset.tNocam); });
    });
  });
  var followed = $('[data-batch-status]', root);
  if (followed) {
    var follow = function () {
      getJson(followed.dataset.batchStatus).then(function (r) { if (r.status === 'Reading') setTimeout(follow, 1500); else softGo(location.href, { push: false }); }).catch(function () { setTimeout(follow, 4000); });
    };
    setTimeout(follow, 1200);
  }
  $$('[data-review-on-change]', root).forEach(function (sel) {
    var form = sel.form;
    var change = form.querySelector('button.js-hide');
    if (change) change.hidden = true;
    sel.addEventListener('change', function () {
      var hidden = document.createElement('input');
      hidden.type = 'hidden'; hidden.name = 'do'; hidden.value = 'type';
      form.appendChild(hidden);
      form.requestSubmit();
    });
  });

  /* a wizard: every step is in the page. Next and Back show the other part at once; the draft is saved behind,
     and the parts that depend on it (documents required, the final check) are refreshed when the save returns.
     What the person is typing is never replaced. */
  $$('form[data-steps]', root).forEach(function (form) {
    var parts = $$('section[data-step]', form);
    var stepper = $('ol[data-stepper]');
    var tick = $('template[data-check-icon]');
    var stepInput = form.querySelector('input[name=step]');
    var current = Number(stepInput.value) || 0;
    var reached = current;
    var dirty = {};
    parts.forEach(function (s) {
      var touched = function () { dirty[s.dataset.step] = true; };
      s.addEventListener('input', touched);
      s.addEventListener('change', touched);
    });
    var onlyVisible = function () { $$('[data-only-visible]', form).forEach(function (el) { el.disabled = el.closest('section').hidden; }); };
    var shown = function () { return parts.filter(function (s) { return !s.hidden; })[0]; };
    var show = function (n) {
      parts.forEach(function (s) { s.hidden = Number(s.dataset.step) !== n; });
      onlyVisible();
      current = n;
      reached = Math.max(reached, n);
      stepInput.value = String(n);
      dirty[n] = false;
      if (stepper) $$('li', stepper).forEach(function (li, i) {
        li.classList.toggle('done', i < n);
        li.classList.toggle('now', i === n);
        var b = $('button', li);
        if (b) b.disabled = i > reached;
        var c = $('.c', li);
        if (c) c.innerHTML = i < n && tick ? tick.innerHTML : String(i + 1);
      });
      var u = new URL(location.href);
      u.searchParams.set('step', String(n));
      history.pushState(null, '', u.toString());
      pushed = true;
      window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
    };
    var valid = function () {
      var s = shown();
      if (!s) return true;
      var bad = $$('input, select, textarea', s).filter(function (el) { return !el.disabled && el.willValidate && !el.checkValidity(); });
      if (bad.length) { bad[0].reportValidity(); return false; }
      return true;
    };
    var saving = false;
    var again = false;
    var save = function () {
      if (saving) { again = true; return; }
      saving = true;
      var fd = new FormData(form);
      fd.set('nav', 'stay');
      fd.set('step', String(current));
      var sent = $$('input[type=file]', form).filter(function (i) { return i.files && i.files.length; });
      var waiting = shown();
      /* the last step shows the check of what was just saved: it waits for it */
      if (waiting && waiting.dataset.step === '4') waiting.classList.add('soft-busy');
      fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'text/html' } }).then(function (r) {
        return new URL(r.url).pathname === location.pathname ? r.text() : null;
      }).then(function (html) {
        if (!html) return;
        sent.forEach(function (i) { i.value = ''; });
        var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('form[data-steps]');
        if (!fresh) return;
        var id = fresh.querySelector('input[name=draftId]');
        if (id && id.value) {
          form.querySelector('input[name=draftId]').value = id.value;
          var u = new URL(location.href);
          u.searchParams.set('draft', id.value);
          history.replaceState(null, '', u.toString());
        }
        parts.forEach(function (s) {
          var n = Number(s.dataset.step);
          if (n === current && dirty[n]) return;
          var ns = fresh.querySelector('section[data-step="' + n + '"]');
          if (!ns) return;
          s.innerHTML = ns.innerHTML;
          dirty[n] = false;
          enhance(s);
        });
        onlyVisible();
        var side = $('[data-wiz-side]', form);
        var sideNew = fresh.querySelector('[data-wiz-side]');
        if (side && sideNew) side.innerHTML = sideNew.innerHTML;
      }).catch(function () {}).then(function () {
        parts.forEach(function (s) { s.classList.remove('soft-busy'); });
        saving = false;
        if (again) { again = false; save(); }
      });
    };
    var onClick = function (e) {
      var b = e.target.closest && e.target.closest('button[name=nav]');
      if (!b || b.form !== form || b.disabled) return;
      var m = /^go:(\d+)$/.exec(b.value);
      if (m) {
        e.preventDefault();
        var n = Number(m[1]);
        if (n === current || (n > current && !valid())) return;
        show(n);
        save();
        return;
      }
      if (b.value === 'submit' && !valid()) e.preventDefault();
    };
    form.addEventListener('click', onClick);
    if (stepper) stepper.addEventListener('click', onClick);
  });
  }

  /* ---------------------------------------- a form is sent in the background and only the page content changes:
     no reload; the scroll stays where it was unless the step changes, a refusal is shown or the answer points to a place.
     Signing in or out, the language, Smart Intake and anything that opens another page keep the ordinary way. */
  var pushed = false;
  function softable(form) {
    if (!form || !form.matches || form.matches('[data-no-soft]')) return false;
    if (!form.closest('#main') && !form.closest('.layer')) return false;
    var action = form.getAttribute('action') || location.pathname;
    return !/^\/(logout|language|login|forgot|accept|me\/provider)\b/.test(action.replace(location.origin, ''));
  }
  var busy = function (on) { var main = $('#main'); if (main) main.classList.toggle('soft-busy', on); };

  /* one answer from the server, put in place of the page content */
  function swapIn(r, opts) {
    var url = new URL(r.url);
    /* another page (a claim was sent, a draft was opened), a file, or the sign-in: go there the ordinary way */
    if (url.pathname !== location.pathname || !/text\/html/.test(r.headers.get('content-type') || '')) { location.href = r.url; return Promise.resolve(); }
    return r.text().then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var next = doc.querySelector('#main');
      if (!next) { location.href = r.url; return; }
      var main = $('#main');
      var step = function (search) { return new URLSearchParams(search).get('step'); };
      var moved = step(url.search) !== step(location.search);
      main.innerHTML = next.innerHTML;
      if (opts.push !== false && (opts.push || moved)) { history.pushState(null, '', r.url); pushed = true; } else history.replaceState(null, '', r.url);
      document.title = doc.title;
      Array.prototype.forEach.call(doc.querySelectorAll('#toasts .toast'), function (t) {
        var box = $('#toasts');
        if (!box) return;
        var el = document.importNode(t, true);
        box.appendChild(el);
        setTimeout(function () { el.remove(); }, 5000);
      });
      /* the bell keeps its own count: refresh it */
      var bellNow = doc.querySelector('#bell-count');
      var bell = $('#bell-count');
      if (bell && bellNow) { bell.hidden = bellNow.hidden; bell.textContent = bellNow.textContent; }
      var refused = main.querySelector('[role=alert]');
      if (opts.layer && !refused) opts.layer.hidden = true;
      enhance(main);
      if (window.vppIntake) window.vppIntake(main);
      var anchor = url.hash && document.getElementById(url.hash.slice(1));
      if (anchor) anchor.scrollIntoView({ block: anchor.id === 'end' ? 'end' : 'start', behavior: reduced ? 'auto' : 'smooth' });
      else if ((moved && !opts.keep) || refused) window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
      var ask = main.querySelector('textarea[data-submit-on-enter]');
      if (ask && opts.focus !== false) ask.focus();
    });
  }

  /* a link to another view of the same page (a tab, a filter, a page of results) */
  function softGo(url, opts) {
    busy(true);
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
      .then(function (r) { return swapIn(r, opts || {}); }, function () { location.href = url; })
      .catch(function () { location.href = url; })
      .then(function () { busy(false); });
  }

  /* a form sent in the background */
  function softSubmit(form, extra) {
    if (form.dataset.busy) return;
    form.dataset.busy = '1';
    var get = (form.getAttribute('method') || 'get').toLowerCase() === 'get';
    var fd = new FormData(form);
    if (extra && extra.name) fd.append(extra.name, extra.value);
    var target = form.action || location.href;
    var init = { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'text/html' } };
    if (get) {
      var u = new URL(target, location.href);
      u.search = new URLSearchParams(Array.from(fd.entries()).filter(function (x) { return typeof x[1] === 'string'; })).toString();
      target = u.toString();
      init = { credentials: 'same-origin', headers: { Accept: 'text/html' } };
    }
    var layer = form.closest('.layer');
    var done = function () { delete form.dataset.busy; busy(false); };
    busy(true);
    fetch(target, init).then(function (r) {
      /* a search adds a history entry; a step of a wizard adds one when the step changes */
      return swapIn(r, { push: get ? true : undefined, keep: get, layer: layer });
    }, function () {
      /* the request never left: send the form the ordinary way */
      done();
      form.submit();
    }).catch(function () { location.reload(); }).then(done);
  }
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!softable(form)) return;
    e.preventDefault();
    var by = e.submitter;
    softSubmit(form, by && by.name ? { name: by.name, value: by.value } : null);
  });

  /* tabs, filters and pages of a list stay on the page; a link to another page is an ordinary link */
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || !a.closest('#main') || a.target || a.hasAttribute('download') || a.matches('[data-no-soft]')) return;
    var url = new URL(a.href, location.href);
    if (url.origin !== location.origin || url.pathname !== location.pathname) return;
    if (url.search === location.search && url.hash) return; // a jump inside the page
    e.preventDefault();
    softGo(url.toString(), { push: true, keep: true, focus: false });
  });

  /* the browser's Back and Forward after an in-place change */
  window.addEventListener('popstate', function () { if (pushed) softGo(location.href, { push: false, keep: true, focus: false }); });

  /* the bell: "mark all as read" without leaving the page */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('form[action="/notifications/read"]')) return;
    e.preventDefault();
    fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'text/html' } }).then(function () {
      var bell = $('#bell-count');
      if (bell) bell.hidden = true;
      var btn = form.querySelector('button');
      if (btn) btn.hidden = true;
      var toggle = $('[data-notifications]');
      if (toggle) loadNotifications(toggle.dataset.notifications);
    });
  });

  enhance(document);

  /* ---------------------------------------- forms that send themselves */

  /* a key press sends the question; the page follows a batch being read; choosing a kind of document re-reads it */

  /* ---------------------------------------- sign-in */
  $$('[data-demo-email]').forEach(function (b) {
    b.addEventListener('click', function () { $('#si-email').value = b.dataset.demoEmail; $('#si-password').value = b.dataset.demoPassword; $('.btn-shine').focus(); });
  });
  $$('[data-show-password]').forEach(function (b) {
    b.addEventListener('click', function () { var i = b.parentNode.querySelector('input'); i.type = i.type === 'password' ? 'text' : 'password'; });
  });
  var boxes = $('[data-code-boxes]');
  if (boxes) {
    var inputs = $$('input', boxes);
    inputs.forEach(function (input, i) {
      input.addEventListener('input', function () {
        var digits = input.value.replace(/\D/g, '');
        if (digits.length > 1) {
          digits.slice(0, 6).split('').forEach(function (d, k) { if (inputs[k]) inputs[k].value = d; });
          (inputs[Math.min(digits.length, 5)] || input).focus();
        } else {
          input.value = digits;
          if (digits && inputs[i + 1]) inputs[i + 1].focus();
        }
        /* the person sends the code with the button; once the six digits are in, the button is next */
        if (inputs.every(function (x) { return x.value; })) { var send = $('#code-form button:not([type=button])'); if (send) send.focus(); }
      });
      input.addEventListener('keydown', function (e) { if (e.key === 'Backspace' && !input.value && inputs[i - 1]) inputs[i - 1].focus(); });
    });
  }

  /* ---------------------------------------- menus and dialogs */
  $$('[data-menu]').forEach(function (b) { b.addEventListener('click', function () { $('#side').classList.toggle('open'); }); });
  $$('[data-menu-close]').forEach(function (b) { b.addEventListener('click', function () { $('#side').classList.remove('open'); }); });
  var top = $('#top');
  if (top) window.addEventListener('scroll', function () { top.classList.toggle('scrolled', window.scrollY > 8); }, { passive: true });
  $$('[data-dropdown]').forEach(function (d) {
    var menu = $('[data-dropdown-menu]', d);
    var toggle = $('[data-dropdown-toggle]', d);
    toggle.addEventListener('click', function () {
      menu.hidden = !menu.hidden;
      if (!menu.hidden && toggle.dataset.notifications) loadNotifications(toggle.dataset.notifications);
    });
    document.addEventListener('mousedown', function (e) { if (!d.contains(e.target)) menu.hidden = true; });
  });
  function loadNotifications(url) {
    var box = $('#notif-rows');
    getJson(url).then(function (rows) {
      box.innerHTML = rows.length ? rows.map(function (n) {
        return '<a href="' + esc(n.link || '/') + '" class="' + (n.read ? '' : 'unread') + '"><b>' + esc(n.title) + '</b>' + (n.body ? '<span style="display:block">' + esc(n.body) + '</span>' : '') + '<span>' + esc(n.ago) + '</span></a>';
      }).join('') : '<div class="empty"><b>—</b></div>';
    }).catch(function () { box.innerHTML = ''; });
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') $$('.layer, .palette').forEach(function (l) { l.hidden = true; }); });

  /* ---------------------------------------- documents float over a dimmed page */
  var layer = $('#doc-layer');
  if (layer) {
    var frame = $('#doc-frame');
    $('#doc-close').addEventListener('click', function () { layer.hidden = true; frame.src = 'about:blank'; });
    $('#doc-print').addEventListener('click', function () { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { window.open(frame.src, '_blank'); } });
  }

  /* ---------------------------------------- the search palette (Ctrl K) */
  var palette = $('#palette');
  if (palette) {
    var q = $('#palette-q');
    var res = $('#palette-res');
    var sel = 0;
    var timer;
    var run = function () {
      getJson('/search?q=' + encodeURIComponent(q.value)).then(function (rows) {
        sel = 0;
        res.innerHTML = rows.length ? rows.map(function (r, i) {
          return '<a href="' + esc(r.to) + '" class="' + (i === 0 ? 'sel' : '') + '"><span class="tile t-teal" style="width:30px;height:30px;border-radius:9px">' + r.icon + '</span><div><b>' + esc(r.title) + '</b><small>' + esc(r.sub) + '</small></div></a>';
        }).join('') : '<div class="empty"><b>' + esc(data.tNothing) + '</b><p>' + esc(data.tOnly) + '</p></div>';
      }).catch(function () {});
    };
    var open = function () { palette.hidden = false; q.value = ''; q.focus(); run(); };
    $$('[data-palette]').forEach(function (b) { b.addEventListener('click', open); });
    window.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); open(); } });
    palette.addEventListener('mousedown', function (e) { if (e.target === palette) palette.hidden = true; });
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 200); });
    q.addEventListener('keydown', function (e) {
      var links = $$('a', res);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        sel = Math.max(0, Math.min(links.length - 1, sel + (e.key === 'ArrowDown' ? 1 : -1)));
        links.forEach(function (a, i) { a.classList.toggle('sel', i === sel); });
      }
      if (e.key === 'Enter' && links[sel]) location.href = links[sel].href;
    });
  }




  /* ---------------------------------------- cards lean towards the pointer */
  if (!reduced && !(window.matchMedia && matchMedia('(hover: none)').matches)) {
    var current = null;
    var reset = function (el) { el.style.setProperty('--rx', '0deg'); el.style.setProperty('--ry', '0deg'); el.classList.remove('tilting'); };
    document.addEventListener('pointermove', function (e) {
      var el = e.target.closest && e.target.closest('[data-tilt]');
      if (current && current !== el) reset(current);
      current = el;
      if (!el) return;
      var r = el.getBoundingClientRect();
      var px = (e.clientX - r.left) / r.width;
      var py = (e.clientY - r.top) / r.height;
      var max = Number(el.dataset.tilt) || 7;
      el.style.setProperty('--rx', ((0.5 - py) * max).toFixed(2) + 'deg');
      el.style.setProperty('--ry', ((px - 0.5) * max).toFixed(2) + 'deg');
      el.style.setProperty('--mx', (px * 100).toFixed(1) + '%');
      el.style.setProperty('--my', (py * 100).toFixed(1) + '%');
      el.classList.add('tilting');
    }, { passive: true });
  }


  /* ---------------------------------------- toasts leave by themselves */
  $$('#toasts .toast').forEach(function (t) { setTimeout(function () { t.remove(); }, 5000); });
  var toast = function (text, link) {
    var box = $('#toasts');
    if (!box) return;
    var el = document.createElement(link ? 'a' : 'div');
    el.className = 'toast t-teal';
    el.textContent = text;
    if (link) el.href = link;
    box.appendChild(el);
    setTimeout(function () { el.remove(); }, link ? 10000 : 5000);
  };


  /* ---------------------------------------- the live line: the CRM decides, the bell says so; the page is never reloaded under the person */
  if (data.uid) {
    var seen = null;
    var chip = $('#livechip');
    var bell = $('#bell-count');
    var tick = function () {
      getJson('/live').then(function (s) {
        if (chip) {
          chip.classList.toggle('live', !!s.up);
          chip.classList.toggle('crm', !s.up);
          var label = $('.livechip-t', chip);
          if (label) label.firstChild.nodeValue = (s.up ? chip.dataset.liveLabel : chip.dataset.downLabel) + (label.querySelector('time') ? ' · ' : '');
        }
        if (bell) { bell.hidden = !s.unread; bell.textContent = s.unread > 9 ? '9+' : s.unread; }
        /* the news is a notice that opens the record; what is on the screen stays until the person moves */
        if (seen !== null && s.latest > seen && s.title) toast(s.title, s.link);
        seen = s.latest;
      }).catch(function () {});
    };
    tick();
    /* every 20 seconds, and not while the tab is in the background */
    setInterval(function () { if (!document.hidden) tick(); }, 20000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
  }
})();
