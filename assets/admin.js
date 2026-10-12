/* Pre-fills the "send to Telegram" text. Nothing is sent from here: the text is only a suggestion in a
   text field; the server sends exactly what the form posts, and only if the box is ticked. */
(function () {
  var HEADERS = { task: '🆕 تسک جدید', comment: '💬 کامنت جدید', status: '🔄 تغییر وضعیت' };

  function chosen(select) {
    return select && select.selectedIndex >= 0 ? select.options[select.selectedIndex].text : '';
  }

  function compose(box) {
    var d = box.dataset, form = box.closest('form'), f = form.elements;
    var isTask = d.event === 'task', isStatus = d.event === 'status';
    var title = isTask ? (f.title ? f.title.value.trim() : '') : d.title;
    var owner = isTask ? chosen(f.owner) : d.owner;
    var status = (isTask || isStatus) ? chosen(f.status) : d.status;
    return [
      HEADERS[d.event] || '📣 اطلاع‌رسانی',
      'عنوان: ' + title,
      'مسئول: ' + owner,
      'وضعیت: ' + status,
      'فرستنده: ' + d.sender,
      'لینک: ' + d.link
    ].join('\n');
  }

  function refresh(box) {
    var area = box.querySelector('textarea');
    if (area && !area.dataset.dirty) {
      area.value = compose(box);
    }
  }

  function boxOf(target) {
    var form = target.form;
    var box = form && form.querySelector('.mlt-notify');
    return box && box.querySelector('.mlt-notify-toggle').checked ? box : null;
  }

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.classList && t.classList.contains('mlt-notify-toggle')) {
      if (t.checked) { refresh(t.closest('.mlt-notify')); }
      return;
    }
    var box = boxOf(t);
    if (box) { refresh(box); }
  });

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t.matches && t.matches('.mlt-notify textarea')) {
      t.dataset.dirty = '1'; // the sender has taken over the text
      return;
    }
    var box = boxOf(t);
    if (box) { refresh(box); }
  });
})();

/* Clock bar: starts from the server's time (data-now, ms since epoch) and ticks with the browser's clock.
   Always UTC, Gregorian. */
(function () {
  var bar = document.querySelector('.mlt-topbar');
  if (!bar) return;
  var offset = parseInt(bar.dataset.now, 10) - Date.now();
  var days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  var two = function (n) { return (n < 10 ? '0' : '') + n; };
  function tick() {
    var d = new Date(Date.now() + offset);
    bar.querySelector('.mlt-clock-date').textContent = days[d.getUTCDay()] + ' ' + d.getUTCFullYear() + '-' + two(d.getUTCMonth() + 1) + '-' + two(d.getUTCDate());
    bar.querySelector('.mlt-clock-time').textContent = two(d.getUTCHours()) + ':' + two(d.getUTCMinutes()) + ' UTC';
  }
  tick();
  setInterval(tick, 1000);
})();

/* Timer bar: the net time of a running timer counts up in the browser (the server number is the starting point). */
(function () {
  var el = document.querySelector('.mlt-timer-net[data-running="1"]');
  if (!el) return;
  var base = parseInt(el.dataset.seconds, 10), t0 = Date.now();
  var two = function (n) { return (n < 10 ? '0' : '') + n; };
  setInterval(function () {
    var s = base + Math.floor((Date.now() - t0) / 1000);
    el.textContent = two(Math.floor(s / 3600)) + ':' + two(Math.floor(s % 3600 / 60)) + ':' + two(s % 60);
  }, 1000);
})();

/* Copy buttons: a task code ([data-copy]) or the text of a field ([data-copy-from]). */
(function () {
  function copy(text, button) {
    function done() {
      var old = button.dataset.label || button.textContent;
      button.dataset.label = old;
      button.textContent = 'کپی شد ✓';
      setTimeout(function () { button.textContent = old; }, 1200);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { fallback(text); done(); });
    } else {
      fallback(text); done();
    }
  }
  function fallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) { /* the text stays selectable by hand */ }
    document.body.removeChild(ta);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-copy], [data-copy-from]') : null;
    if (!b) return;
    if (b.dataset.copy) {
      copy(b.dataset.copy, b);
    } else {
      var src = document.querySelector(b.dataset.copyFrom);
      if (src) copy(src.value, b);
    }
  });
})();

/* Status select on a card: asks before "done" when the task has a done criterion, offers to give a reason when it is late,
   then submits (unless the Telegram box is ticked: then "save and send" does it). */
(function () {
  document.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel.classList || !sel.classList.contains('mlt-status-select')) return;
    var form = sel.form, card = sel.closest('.mlt-card');
    if (sel.value === 'done' && card) {
      var crit = card.dataset.criteria;
      if (crit && !window.confirm('معیار انجام:\n\n' + crit + '\n\nبرقرار است؟')) {
        sel.value = sel.dataset.current;
        return;
      }
      if (card.dataset.late === '1' && form.elements.late_reason) {
        var why = window.prompt('این تسک از سررسید گذشته. اگر دلیلی داری بنویس (اختیاری):');
        form.elements.late_reason.value = why ? why.trim() : '';
      }
    }
    if (!form.elements.notify.checked) form.submit();
  });
})();
