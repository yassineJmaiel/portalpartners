/* Smart Intake in the browser. Scans and photos are read here by Tesseract (pinned, from jsDelivr):
   the document never leaves for a third party, only the program and its language files are downloaded.
   The review page draws each PDF page with pdf.js and marks where a value was read. */
(function () {
  'use strict';
  var PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
  var PDFJS_WORKER = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
  var VERSION = '5.1.1';
  var BASE = 'https://cdn.jsdelivr.net/npm';
  /* Latin pages are read without the Arabic model: with it loaded, Tesseract turned "g/L" into Arabic
     letters and lost decimal points. A page the Latin read makes little of is read again with Arabic. */
  var LATIN = ['eng', 'fra'];
  var ARABIC = ['ara', 'eng'];
  var scripts = {};
  var workers = {};
  var progress = null;

  function loadScript(src, global) {
    if (window[global]) return Promise.resolve(window[global]);
    scripts[src] = scripts[src] || new Promise(function (ok, no) {
      var s = document.createElement('script');
      s.src = src;
      s.onload = function () { ok(window[global]); };
      s.onerror = function () { delete scripts[src]; no(new Error('load')); };
      document.head.appendChild(s);
    });
    return scripts[src];
  }

  function pdfLib() {
    return loadScript(PDFJS, 'pdfjsLib').then(function (lib) { lib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER; return lib; });
  }

  function worker(langs) {
    var key = langs.join('+');
    workers[key] = workers[key] || loadScript(BASE + '/tesseract.js@' + VERSION + '/dist/tesseract.min.js', 'Tesseract').then(function (T) {
      return T.createWorker(langs, 1, {
        workerPath: BASE + '/tesseract.js@' + VERSION + '/dist/worker.min.js',
        corePath: BASE + '/tesseract.js-core@' + VERSION,
        langPath: 'https://tessdata.projectnaptha.com/4.0.0',
        logger: function (m) { if (progress) progress(m); }
      });
    }).then(function (w) {
      /* keep the gaps between table columns: they tell a price from a word */
      return w.setParameters({ preserve_interword_spaces: '1' }).then(function () { return w; });
    }).catch(function (e) { delete workers[key]; throw e; });
    return workers[key];
  }

  /* a cleaner page for the reader: large enough, in greys, contrast stretched from the darkest to the lightest tones on the page */
  function cleaned(source, width, height) {
    var scale = Math.min(3, Math.max(1, 2400 / Math.max(width, height)));
    var canvas = document.createElement('canvas');
    canvas.width = Math.round(width * scale);
    canvas.height = Math.round(height * scale);
    var g = canvas.getContext('2d', { willReadFrequently: true });
    g.imageSmoothingQuality = 'high';
    g.drawImage(source, 0, 0, canvas.width, canvas.height);
    var im = g.getImageData(0, 0, canvas.width, canvas.height);
    var d = im.data;
    var hist = new Uint32Array(256);
    var i;
    for (i = 0; i < d.length; i += 4) hist[Math.round(0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2])] += 1;
    var cut = (d.length / 4) * 0.01;
    var lo = 0, hi = 255, n = 0;
    while (lo < 255 && (n += hist[lo]) < cut) lo += 1;
    n = 0;
    while (hi > 0 && (n += hist[hi]) < cut) hi -= 1;
    var span = Math.max(1, hi - lo);
    for (i = 0; i < d.length; i += 4) {
      var v = Math.max(0, Math.min(255, ((0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]) - lo) * 255 / span));
      d[i] = v; d[i + 1] = v; d[i + 2] = v;
    }
    g.putImageData(im, 0, 0);
    return canvas;
  }

  function recognize(langs, canvas) {
    return worker(langs).then(function (w) { return w.recognize(canvas); }).then(function (res) {
      var data = res.data;
      var lines = (data.lines || []).map(function (l) {
        return { text: String(l.text || '').trim(), confidence: Math.round(l.confidence || 0) / 100,
          box: [l.bbox.x0 / canvas.width, l.bbox.y0 / canvas.height, l.bbox.x1 / canvas.width, l.bbox.y1 / canvas.height].map(function (x) { return Math.max(0, Math.min(1, Math.round(x * 10000) / 10000)); }) };
      }).filter(function (l) { return l.text; });
      return { text: lines.map(function (l) { return l.text; }).join('\n'), confidence: (data.confidence || 0) / 100, lines: lines };
    });
  }

  function readImage(source, width, height) {
    var canvas = cleaned(source, width, height);
    return recognize(LATIN, canvas).then(function (latin) {
      var letters = latin.text.replace(/[^\p{L}]/gu, '').length;
      if (latin.confidence >= 0.6 && letters >= 40) return latin;
      /* little read, or read badly: perhaps it is written in Arabic */
      return recognize(ARABIC, canvas).then(function (arabic) { return arabic.confidence > latin.confidence ? arabic : latin; }).catch(function () { return latin; });
    });
  }

  function ocrImage(file) {
    return createImageBitmap(file).then(function (bmp) {
      var canvas = document.createElement('canvas');
      canvas.width = bmp.width; canvas.height = bmp.height;
      canvas.getContext('2d').drawImage(bmp, 0, 0);
      return readImage(canvas, bmp.width, bmp.height);
    }).then(function (page) { return { engine: 'Tesseract ' + VERSION, pages: [page] }; });
  }

  /* a PDF with no text on any page is a scan: each page drawn at about 200 dpi, then read (10 pages at most) */
  function ocrPdfIfScan(file) {
    var doc;
    return pdfLib().then(function (lib) { return file.arrayBuffer().then(function (data) { return lib.getDocument({ data: data }).promise; }); }).then(function (d) {
      doc = d;
      var checks = [];
      for (var i = 1; i <= doc.numPages; i++) checks.push(doc.getPage(i).then(function (pg) { return pg.getTextContent(); }).then(function (c) { return c.items.map(function (x) { return x.str || ''; }).join('').replace(/\s+/g, '').length; }));
      return Promise.all(checks);
    }).then(function (lengths) {
      if (lengths.reduce(function (a, b) { return a + b; }, 0) >= 40 * Math.max(1, lengths.length)) return null;
      var pages = [];
      var next = function (i) {
        if (i > Math.min(doc.numPages, 10)) return Promise.resolve({ engine: 'Tesseract ' + VERSION, pages: pages });
        return doc.getPage(i).then(function (pg) {
          var viewport = pg.getViewport({ scale: 2.8 });
          var canvas = document.createElement('canvas');
          canvas.width = viewport.width; canvas.height = viewport.height;
          return pg.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise.then(function () { return readImage(canvas, canvas.width, canvas.height); });
        }).then(function (page) { pages.push(page); return next(i + 1); });
      };
      return next(1);
    });
  }

  /* the upload: scans are read here first, then everything is sent */
  var form = document.querySelector('form[data-intake-upload]');
  if (form) {
    var input = form.querySelector('input[type=file]');
    var stage = form.querySelector('[data-stage]');
    var hidden = form.querySelector('input[name=ocr]');
    var submitting = false;
    form.addEventListener('submit', function (e) {
      if (submitting) return;
      /* the reader's program and language files are fetched only when the person asks for it */
      var consent = form.querySelector('input[name=read_scans]');
      if (!consent || !consent.checked) return;
      e.preventDefault();
      var files = Array.prototype.slice.call(input.files);
      if (!files.length) return;
      var out = {};
      var say = function (text) { stage.hidden = false; stage.textContent = text; };
      var one = function (i) {
        if (i >= files.length) {
          hidden.value = JSON.stringify(out);
          submitting = true;
          say(form.dataset.tSending);
          form.submit();
          return;
        }
        var f = files[i];
        progress = function (m) {
          if (m.status === 'recognizing text') say(form.dataset.tReading.replace('{n}', f.name).replace('{p}', Math.round((m.progress || 0) * 100)));
          else say(form.dataset.tPreparing);
        };
        var isImage = /^image\//.test(f.type) || /\.(png|jpe?g|webp|gif)$/i.test(f.name);
        var isPdf = f.type === 'application/pdf' || /\.pdf$/i.test(f.name);
        var read = isImage ? ocrImage(f) : (isPdf ? ocrPdfIfScan(f) : Promise.resolve(null));
        read.then(function (r) { if (r) out[i] = r; }).catch(function () { /* the server says it could not be read; a person enters it */ }).then(function () { one(i + 1); });
      };
      say(form.dataset.tPreparing);
      one(0);
    });
  }

  /* ---------------------------------------- the review: the exact page, and where a value was read */
  function findBoxes(items, f) {
    if (!f) return [];
    var norm = function (s) { return String(s).toLowerCase().replace(/\s+/g, ' ').trim(); };
    var v = norm(f.value === null || f.value === undefined ? '' : f.value);
    var snippet = norm(f.snippet || '');
    var hits = v.length >= 2 ? items.filter(function (it) { return norm(it.text).indexOf(v) >= 0; }) : [];
    if (!hits.length && v) hits = items.filter(function (it) { return norm(it.text).length >= 3 && v.indexOf(norm(it.text)) >= 0; });
    if (!hits.length && snippet) {
      var s = snippet.slice(0, 60);
      hits = items.filter(function (it) { var t = norm(it.text); return t.length >= 4 && (s.indexOf(t) >= 0 || t.indexOf(s) >= 0); });
    }
    return hits.slice(0, 6).map(function (it) { return it.box; }).filter(Boolean);
  }

  function initDocs(root) {
  Array.prototype.forEach.call(root.querySelectorAll('article.idoc'), function (doc) {
    var sheet = doc.querySelector('.pdfsheet');
    if (!sheet) return;
    var canvasBox = sheet.querySelector('.pdfcanvas');
    var bar = doc.querySelector('.markbar');
    var pageLabel = doc.querySelector('[data-page-label]');
    var pages = JSON.parse(doc.dataset.pages || '[1]');
    var ocr = JSON.parse(doc.dataset.ocr || '[]');
    var current = pages[0] || 1;
    var items = [];
    var focus = null;
    var pdf = null;
    var linesOf = function (page) { var p = ocr.filter(function (x) { return x.page === page; })[0]; return p ? p.lines : []; };
    var marks = function () {
      Array.prototype.forEach.call(sheet.querySelectorAll('.mark'), function (m) { m.remove(); });
      if (!focus || (focus.page || pages[0] || 1) !== current) return;
      var found = findBoxes(items.length ? items : linesOf(current), focus);
      var approx = !found.length;
      var boxes = found.length ? found : (focus.box ? [focus.box] : []);
      boxes.forEach(function (b) {
        var m = document.createElement('span');
        m.className = 'mark' + (approx ? ' approx' : '');
        m.setAttribute('aria-hidden', 'true');
        m.style.left = (b[0] * 100) + '%'; m.style.top = (b[1] * 100) + '%';
        m.style.width = ((b[2] - b[0]) * 100) + '%'; m.style.height = ((b[3] - b[1]) * 100) + '%';
        sheet.appendChild(m);
      });
      var first = sheet.querySelector('.mark');
      if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
    };
    var draw = function (page) {
      if (!pdf) { marks(); return Promise.resolve(); }
      return pdf.getPage(Math.min(page, pdf.numPages)).then(function (pg) {
        var width = sheet.clientWidth || 520;
        var base = pg.getViewport({ scale: 1 });
        var viewport = pg.getViewport({ scale: (width / base.width) * (window.devicePixelRatio || 1) });
        var canvas = document.createElement('canvas');
        canvas.width = viewport.width; canvas.height = viewport.height;
        canvas.style.width = '100%';
        return pg.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise.then(function () { return pg.getTextContent(); }).then(function (content) {
          items = content.items.filter(function (it) { return it.str && it.str.trim(); }).map(function (it) {
            var h = it.height || Math.abs(it.transform[3]) || 10;
            var r = base.convertToViewportRectangle([it.transform[4], it.transform[5], it.transform[4] + (it.width || 0), it.transform[5] + h]);
            return { text: it.str, box: [Math.min(r[0], r[2]) / base.width, Math.min(r[1], r[3]) / base.height, Math.max(r[0], r[2]) / base.width, Math.max(r[1], r[3]) / base.height] };
          });
          canvasBox.textContent = '';
          canvasBox.appendChild(canvas);
          current = page;
          if (pageLabel) pageLabel.textContent = String(page);
          marks();
        });
      });
    };
    if (sheet.dataset.file) {
      pdfLib().then(function (lib) {
        /* the bytes come as text, never as a PDF: a download manager on the computer would take a PDF away from the page and offer to save it */
        return fetch(sheet.dataset.file + '?as=data', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
          var bin = atob(j.data);
          var out = new Uint8Array(bin.length);
          for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
          return lib.getDocument({ data: out }).promise;
        });
      }).then(function (d) { pdf = d; return draw(current); }).catch(function () {
        canvasBox.textContent = sheet.dataset.tNoview || '';
      });
    }
    doc.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-show]') : null;
      if (t) {
        if (t.tagName === 'BUTTON') e.preventDefault();
        focus = JSON.parse(t.dataset.show);
        focus.page = focus.page && pages.indexOf(focus.page) >= 0 ? focus.page : (pages[0] || 1);
        if (bar) {
          bar.hidden = false;
          bar.querySelector('span[data-read]').textContent = bar.dataset.tRead.replace('{v}', String(focus.value === null || focus.value === undefined ? (focus.snippet || '') : focus.value).slice(0, 60));
        }
        if (focus.page !== current) draw(focus.page); else marks();
      }
      if (e.target.closest && e.target.closest('[data-mark-clear]')) {
        e.preventDefault();
        focus = null;
        if (bar) bar.hidden = true;
        marks();
      }
    });
    doc.addEventListener('focusin', function (e) {
      var t = e.target.dataset ? e.target : null;
      if (t && t.dataset.showOnFocus) {
        focus = JSON.parse(t.dataset.showOnFocus);
        focus.page = focus.page && pages.indexOf(focus.page) >= 0 ? focus.page : (pages[0] || 1);
        if (focus.page !== current) draw(focus.page); else marks();
      }
    });
  });
  }
  initDocs(document);
  /* app.js calls this after it puts new content in the page */
  window.vppIntake = initDocs;
})();
