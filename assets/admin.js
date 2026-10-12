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
