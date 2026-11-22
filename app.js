// Mobile menu
(function () {
  var b = document.getElementById('burger'), s = document.getElementById('scrim');
  if (b) b.addEventListener('click', function () { document.body.classList.toggle('menu-open'); });
  if (s) s.addEventListener('click', function () { document.body.classList.remove('menu-open'); });
})();

// Show / hide password
document.querySelectorAll('.pw button').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = btn.parentNode.querySelector('input');
    input.type = input.type === 'password' ? 'text' : 'password';
  });
});

// Confirm pop-up: <form data-confirm="Are you sure?" data-confirm-title="..." data-confirm-ok="..." data-confirm-cancel="...">
(function () {
  var overlay = null, pendingForm = null;
  function build() {
    overlay = document.createElement('div');
    overlay.className = 'cf-modal'; overlay.hidden = true;
    overlay.innerHTML = '<div class="cf-box" role="alertdialog" aria-modal="true" aria-labelledby="cfTitle" aria-describedby="cfMsg">' +
      '<div class="cf-icon">!</div><h2 id="cfTitle"></h2><p id="cfMsg"></p>' +
      '<div class="cf-actions"><button type="button" class="btn btn-light" id="cfNo"></button><button type="button" class="btn btn-danger-solid" id="cfYes"></button></div></div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) hide(); });
    document.getElementById('cfNo').addEventListener('click', hide);
    document.getElementById('cfYes').addEventListener('click', function () {
      var f = pendingForm; hide();
      if (f) { f.dataset.confirmed = '1'; if (f.requestSubmit) f.requestSubmit(); else f.submit(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.hidden) hide(); });
  }
  function hide() { overlay.hidden = true; pendingForm = null; document.body.classList.remove('cf-open'); }
  function show(form) {
    if (!overlay) build();
    pendingForm = form;
    document.getElementById('cfTitle').textContent = form.getAttribute('data-confirm-title') || 'Are you sure?';
    document.getElementById('cfMsg').textContent = form.getAttribute('data-confirm');
    document.getElementById('cfYes').textContent = form.getAttribute('data-confirm-ok') || 'Yes, continue';
    document.getElementById('cfNo').textContent = form.getAttribute('data-confirm-cancel') || 'Go back';
    overlay.hidden = false; document.body.classList.add('cf-open');
    document.getElementById('cfNo').focus();
  }
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (f.dataset.confirmed === '1') return;
      ev.preventDefault(); show(f);
    });
  });
})();

// Print buttons
document.querySelectorAll('[data-print]').forEach(function (b) {
  b.addEventListener('click', function () { window.print(); });
});



// Notification pop-up (bell) + live toasts
(function () {
  var btn = document.getElementById('bellBtn'), pop = document.getElementById('notifPop');
  if (!btn || !pop) return;
  var tok = (document.getElementById('csrfTok') || {}).value || '';
  var body = document.getElementById('notifBody');
  function esc(t) { var x = document.createElement('div'); x.textContent = t == null ? '' : t; return x.innerHTML; }
  function post(name) {
    var data = new URLSearchParams(); data.append('csrf', tok); data.append(name, '1');
    return fetch('notifications.php', { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
  }
  function close() { pop.hidden = true; if (typeof stack !== 'undefined') stack.style.display = ''; btn.setAttribute('aria-expanded', 'false'); }
  function setBadge(n) {
    var badge = document.getElementById('bellBadge');
    if (n > 0) {
      if (!badge) { badge = document.createElement('span'); badge.className = 'badge'; badge.id = 'bellBadge'; btn.appendChild(badge); }
      badge.textContent = n > 9 ? '9+' : n;
    } else if (badge) { badge.remove(); }
    btn.classList.toggle('has-new', n > 0);
  }
  function renderList(list) {
    if (!list.length) { body.innerHTML = '<p class="notif-empty">You have no notifications yet.</p>'; return; }
    body.innerHTML = list.map(function (n) {
      return '<a class="notif ' + (n.is_read ? '' : 'unread') + '" href="notifications.php?open=' + n.id + '"><strong>' + (n.is_read ? '' : '<span class="dot"></span>') + esc(n.title) + '</strong><span>' + esc(n.message) + '</span><small>' + esc(n.ago) + '</small></a>';
    }).join('');
  }

  // ---- toasts (pop up on screen) ----
  var stack = document.createElement('div'); stack.className = 'toast-stack'; stack.setAttribute('aria-live', 'polite'); document.body.appendChild(stack);
  function clearToasts() { while (stack.firstChild) stack.firstChild.remove(); }
  function toast(n) {
    if (!pop.hidden) return;   // the list is open: do not cover it
    var t = document.createElement('a');
    t.className = 'toast'; t.href = 'notifications.php?open=' + n.id;
    t.innerHTML = '<span class="toast-ico"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span><span class="toast-txt"><strong>' + esc(n.title) + '</strong><span>' + esc(n.message) + '</span></span><button type="button" class="toast-x" aria-label="Dismiss">&times;</button>';
    t.querySelector('.toast-x').addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); gone(); });
    function gone() { t.classList.add('out'); setTimeout(function () { t.remove(); }, 250); }
    stack.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('in'); });
    setTimeout(gone, 8000);
    while (stack.children.length > 4) stack.firstChild.remove();
  }

  var first = true;
  function poll() {
    if (document.hidden) return;
    fetch('notifications.php?poll=1' + (first ? '&init=3' : ''), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        first = false;
        setBadge(j.unread);
        if (j.list && pop.hidden) renderList(j.list);
        (j.new || []).forEach(toast);
      }).catch(function () {});
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    pop.hidden = !pop.hidden;
    btn.setAttribute('aria-expanded', pop.hidden ? 'false' : 'true');
    if (!pop.hidden) { clearToasts(); stack.style.display = 'none'; } else stack.style.display = '';   // pop-up cards must never sit on top of the list
    if (!pop.hidden && document.getElementById('bellBadge')) { post('mark_all'); setBadge(0); }
  });
  pop.addEventListener('click', function (e) {
    e.stopPropagation();
    var a = e.target.closest ? e.target.closest('a.notif') : null;
    if (a) { e.preventDefault(); window.location.href = a.getAttribute('href'); }
  });
  document.addEventListener('click', function (e) { if (!pop.hidden && !pop.contains(e.target) && !btn.contains(e.target)) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  document.getElementById('notifClear').addEventListener('click', function () {
    post('clear_all'); renderList([]); setBadge(0);
  });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  poll();
  setInterval(poll, 8000);
})();

// Filters apply by themselves: dropdowns at once, search boxes shortly after you stop typing
document.querySelectorAll('form.toolbar').forEach(function (form) {
  var btn = form.querySelector('button[type=submit]'); if (btn) btn.style.display = 'none';
  form.querySelectorAll('select, input[type=date]').forEach(function (el) {
    el.addEventListener('change', function () { form.submit(); });
  });
  var box = form.querySelector('input[type=search]');
  if (box) {
    var t = null;
    box.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { form.submit(); }, 600); });
    if (box.value && new URLSearchParams(location.search).has(box.name)) { box.focus(); box.setSelectionRange(box.value.length, box.value.length); }
  }
});

// Users page: show Course / Year / Block only when the role is Student
function updateStudentFields(roleBox) {
  var form = roleBox.closest('form');
  var fields = form.querySelector('.student-fields');
  if (roleBox.value == 'student') {
    fields.style.display = '';
  } else {
    fields.style.display = 'none';
  }
}

var roleBoxes = document.querySelectorAll('select.role-select');
for (var i = 0; i < roleBoxes.length; i++) {
  updateStudentFields(roleBoxes[i]);
  roleBoxes[i].onchange = function () {
    updateStudentFields(this);
  };
}

// Edit forms (Users, Facilities, Equipment) open as a pop-up instead of squeezing into the table cell
(function () {
  var overlay = null, box = null, titleEl = null, bodyEl = null, current = null, holder = null;
  function build() {
    overlay = document.createElement('div'); overlay.className = 'rv-modal'; overlay.hidden = true;
    overlay.innerHTML = '<div class="rv-box edit-box" role="dialog" aria-modal="true"><div class="edit-head"><h2></h2><button type="button" class="edit-x" aria-label="Close">&times;</button></div><div class="edit-body"></div></div>';
    document.body.appendChild(overlay);
    box = overlay.firstChild; titleEl = box.querySelector('h2'); bodyEl = box.querySelector('.edit-body');
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    box.querySelector('.edit-x').addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.hidden) close(); });
  }
  function close() {
    if (!current) return;
    while (bodyEl.firstChild) current.appendChild(bodyEl.firstChild);
    current.open = false; current = null; overlay.hidden = true; document.body.classList.remove('cf-open');
  }
  document.querySelectorAll('td details.manage').forEach(function (d) {
    var sum = d.querySelector('summary');
    if (!sum || !d.querySelector('form')) return;
    d.addEventListener('toggle', function () {
      if (!d.open || current) return;
      if (!overlay) build();
      var nm = d.querySelector('input[name=full_name],input[name=facility_name],input[name=equipment_name],input[name=name]');
      var name = nm ? nm.value.trim() : '';
      titleEl.textContent = (name.length > 60 ? name.slice(0, 60) + '…' : name) || 'Edit';
      current = d;
      Array.prototype.slice.call(d.children).forEach(function (c) { if (c !== sum) bodyEl.appendChild(c); });
      overlay.hidden = false; document.body.classList.add('cf-open');
      var f = bodyEl.querySelector('input:not([type=hidden]),select'); if (f) f.focus();
    });
  });
})();

// "Reset password" on a request opens that user's Edit pop-up on the password field
document.querySelectorAll('[data-open-edit]').forEach(function (b) {
  b.addEventListener('click', function () {
    var d = document.getElementById(b.getAttribute('data-open-edit'));
    if (!d) { alert('Find this user in the list below and click Edit.'); return; }
    d.open = true;
    setTimeout(function () { var i = document.querySelector('.edit-body input[name=new_password]'); if (i) i.focus(); }, 50);
  });
});
