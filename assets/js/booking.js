// Booking form: calendar with blocked dates + live price preview.
// The server recalculates everything on submit; this is only a preview.
(function () {
  const form = document.getElementById('bookingForm');
  if (!form) return;

  const accSel = document.getElementById('accSelect');
  const dateInput = document.getElementById('dateInput');
  const inEl = document.getElementById('checkIn');
  const outEl = document.getElementById('checkOut');
  const adults = document.getElementById('numAdults');
  const kids = document.getElementById('numKids');
  const swimWrap = document.getElementById('swimWrap');
  const quoteBox = document.getElementById('quoteBox');
  const warn = document.getElementById('quoteWarn');
  const submitBtn = document.getElementById('submitBtn');
  const capText = document.getElementById('capacityText');
  const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

  let fp = null, blocked = [], isNight = false, lastQuote = null, timer = null;

  function selectedOpt() { return accSel.options[accSel.selectedIndex]; }

  function rangeHasBlocked(a, b) {
    const d = new Date(a);
    while (d < b) { if (blocked.includes(ymd(d))) return true; d.setDate(d.getDate() + 1); }
    return false;
  }

  async function loadUnit(keepDates) {
    const opt = selectedOpt();
    if (!opt || !opt.value) { dateInput.disabled = true; return; }
    isNight = opt.dataset.type === 'overnight_room';
    swimWrap.style.display = isNight ? 'none' : '';
    capText.innerHTML = '<i class="bi bi-people"></i> Fits up to <strong>' + opt.dataset.capacity + '</strong> guests' +
      (isNight ? '<br><i class="bi bi-check2-circle text-success"></i> Entrance, swimming &amp; breakfast included' : '');
    document.getElementById('dateLabel').textContent = isNight ? 'Check-in and check-out' : 'Date of visit';

    try {
      const url = form.dataset.availUrl + '?accommodation_id=' + opt.value + (form.dataset.exclude ? '&exclude=' + form.dataset.exclude : '');
      blocked = (await (await fetch(url)).json()).blocked || [];
    } catch (e) { blocked = []; }

    const keepIn = keepDates ? inEl.value : '', keepOut = keepDates ? outEl.value : '';
    if (fp) fp.destroy();
    dateInput.disabled = false;
    dateInput.placeholder = isNight ? 'Pick check-in, then check-out' : 'Pick a date';
    fp = flatpickr(dateInput, {
      mode: isNight ? 'range' : 'single',
      minDate: 'today',
      dateFormat: 'Y-m-d', altInput: true, altFormat: 'D, M j, Y',
      disable: isNight ? [] : blocked,
      onDayCreate: (d, s, inst, el) => { if (isNight && blocked.includes(ymd(el.dateObj))) el.classList.add('flatpickr-disabled'); },
      onChange: dates => {
        inEl.value = dates[0] ? ymd(dates[0]) : '';
        outEl.value = isNight && dates[1] ? ymd(dates[1]) : '';
        requestQuote();
      }
    });
    if (keepIn && (isNight ? keepOut : true)) {
      fp.setDate(isNight ? [keepIn, keepOut] : keepIn, false);
      inEl.value = keepIn; outEl.value = isNight ? keepOut : '';
    } else { inEl.value = ''; outEl.value = ''; }
    requestQuote();
  }

  function requestQuote() {
    clearTimeout(timer);
    timer = setTimeout(fetchQuote, 250);
  }

  async function fetchQuote() {
    const opt = selectedOpt();
    warn.classList.add('d-none');
    if (!opt || !opt.value || !inEl.value || (isNight && !outEl.value)) {
      quoteBox.innerHTML = '<span class="text-muted">Choose a unit and ' + (isNight ? 'your check-in and check-out dates' : 'a date') + ' to see the price.</span>';
      lastQuote = null; return;
    }
    const cap = parseInt(opt.dataset.capacity, 10), total = (parseInt(adults.value, 10) || 0) + (parseInt(kids.value, 10) || 0);
    const fd = new FormData(form);
    fd.delete('csrf_token');
    if (form.dataset.exclude) fd.append('exclude', form.dataset.exclude);
    let q;
    try { q = await (await fetch(form.dataset.quoteUrl, { method: 'POST', body: fd })).json(); } catch (e) { return; }
    if (!q.ok) { quoteBox.textContent = q.message; return; }
    lastQuote = q;

    let html = '';
    q.items.forEach(it => {
      html += '<div class="summary-line"><span>' + escapeHtml(it.description) + (it.quantity > 1 && it.item_type !== 'accommodation' ? ' &times; ' + it.quantity : '') +
        '</span><span>' + peso(it.amount) + '</span></div>';
    });
    html += '<div class="summary-line summary-total fs-6"><span>Total</span><span>' + peso(q.total) + '</span></div>';
    html += '<div class="summary-line text-primary fw-semibold"><span>Downpayment (' + q.percent + '%)</span><span>' + peso(q.downpayment) + '</span></div>';
    html += '<div class="summary-line text-muted"><span>Balance on arrival</span><span>' + peso(q.total - q.downpayment) + '</span></div>';
    quoteBox.innerHTML = html;

    const problems = [];
    if (!q.available) problems.push('Sorry, this unit is already booked for that date. Please pick another date or unit.');
    if (isNight && rangeHasBlocked(new Date(inEl.value), new Date(outEl.value))) problems.push('Some nights in your range are already booked.');
    if (total > cap) problems.push('Too many guests: this unit fits up to ' + cap + '.');
    // Add-ons with a daily stock: show what's left for these dates and cap the quantity
    Object.entries(q.addon_left || {}).forEach(([id, left]) => {
      const tile = form.querySelector('.addon-tile[data-addon="' + id + '"]');
      if (!tile) return;
      const input = tile.querySelector('input'), note = tile.querySelector('.addon-left'), name = tile.querySelector('strong').textContent;
      input.max = Math.min(50, left);
      if (note) {
        note.textContent = left === 0 ? 'Fully booked on these dates' : left + ' left on these dates';
        note.classList.toggle('is-out', left === 0);
      }
      if ((parseInt(input.value, 10) || 0) > left) problems.push(left === 0 ? name + ' is fully booked on these dates.' : 'Only ' + left + ' ' + name + ' left on these dates.');
    });
    if (problems.length) { warn.innerHTML = problems.join('<br>'); warn.classList.remove('d-none'); }
    submitBtn.disabled = problems.length > 0;
  }

  function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

  accSel.addEventListener('change', () => loadUnit(false));
  [adults, kids, document.getElementById('includeSwim')].forEach(el => el && el.addEventListener('input', requestQuote));
  form.querySelectorAll('.quote-input').forEach(el => el.addEventListener('input', requestQuote));

  // Food search
  const fs = document.getElementById('foodSearch');
  if (fs) fs.addEventListener('input', () => {
    const term = fs.value.toLowerCase();
    document.querySelectorAll('.food-row').forEach(r => r.style.display = r.dataset.name.includes(term) ? '' : 'none');
  });

  // Walk-in helpers
  const payAmount = document.getElementById('payAmount');
  const fillDown = document.getElementById('fillDown'), fillFull = document.getElementById('fillFull');
  if (fillDown) fillDown.addEventListener('click', e => { e.preventDefault(); if (lastQuote) payAmount.value = lastQuote.downpayment.toFixed(2); });
  if (fillFull) fillFull.addEventListener('click', e => { e.preventDefault(); if (lastQuote) payAmount.value = lastQuote.total.toFixed(2); });

  form.addEventListener('submit', e => {
    if (!inEl.value || (isNight && !outEl.value)) {
      e.preventDefault();
      warn.innerHTML = 'Please choose your ' + (isNight ? 'check-in and check-out dates.' : 'date.');
      warn.classList.remove('d-none');
      return;
    }
    const agree = document.getElementById('agree');
    if (agree && !agree.checked) {
      e.preventDefault();
      warn.innerHTML = 'Please agree to the booking policy.';
      warn.classList.remove('d-none');
      return;
    }
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
  });

  if (accSel.value) loadUnit(true);
})();
