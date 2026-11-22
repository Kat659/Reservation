<?php
require_once "auth.php";
require_login();
require_once "db.php";
require_once "layout.php";

if (is_admin()) {
    flash('info', 'Administrators review reservations instead of creating them.');
    header("Location: admin.php");
    exit();
}

$uid = (int)$_SESSION['user_id'];
$facilities = $conn->query("SELECT * FROM facilities WHERE status='available' ORDER BY facility_name")->fetch_all(MYSQLI_ASSOC);
$equipment = $conn->query("SELECT * FROM equipment WHERE status='available' ORDER BY equipment_name")->fetch_all(MYSQLI_ASSOC);
$reserved = equipment_reserved_map($conn);

$v = [
    'facility' => (int)($_GET['facility'] ?? 0),
    'equipment' => ((int)($_GET['equipment'] ?? 0)) ? [(int)$_GET['equipment'] => 1] : [],  // id => quantity
    'date' => $_GET['date'] ?? date('Y-m-d'),
    'start' => '',
    'end' => '',
    'purpose' => '',
    'remarks' => '',
];
$problems = [];

// ---- Edit mode: reserve.php?edit=ID ----
$editId = (int)($_GET['edit'] ?? $_POST['edit_id'] ?? 0);
$editing = null;
if ($editId) {
    $g = $conn->prepare("SELECT * FROM reservations WHERE id = ? AND user_id = ?");
    $g->bind_param("ii", $editId, $uid);
    $g->execute();
    $editing = $g->get_result()->fetch_assoc();
    $g->close();
    if (!$editing) { flash('error', 'That reservation was not found.'); header("Location: reservations.php"); exit(); }
    if ($why = edit_block_reason($editing)) { flash('error', e($why)); header("Location: reservations.php"); exit(); }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $v['facility'] = (int)$editing['facility_id'];
        $v['equipment'] = reservation_items($conn, $editId);
        $v['date'] = $editing['reservation_date'];
        $v['start'] = substr($editing['start_time'], 0, 5);
        $v['end'] = substr($editing['end_time'], 0, 5);
        $v['purpose'] = $editing['purpose'];
        $v['remarks'] = (string)$editing['remarks'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $v['facility'] = (int)($_POST['facility'] ?? 0);
    $v['equipment'] = [];
    foreach ((array)($_POST['equipment'] ?? []) as $eidPost) {
        $eidPost = (int)$eidPost;
        if ($eidPost > 0) $v['equipment'][$eidPost] = max(1, (int)($_POST['qty'][$eidPost] ?? 1));
    }
    $v['date'] = $_POST['date'] ?? '';
    $v['start'] = $_POST['start'] ?? '';
    $v['end'] = $_POST['end'] ?? '';
    $v['purpose'] = trim($_POST['purpose'] ?? '');
    $v['remarks'] = trim($_POST['remarks'] ?? '');

    if (!$v['facility'] && !$v['equipment']) $problems[] = ['level' => 'booked', 'msg' => 'Choose a facility, equipment, or both.'];
    if ($v['purpose'] === '') $problems[] = ['level' => 'booked', 'msg' => 'Please enter the purpose of your reservation.'];
    if ($p = validate_slot($v['date'], $v['start'], $v['end'])) $problems[] = ['level' => 'booked', 'msg' => $p];

    if (!$problems) {
        $fid = $v['facility'] ?: null;
        $eqs = $v['equipment'];

        // Lock the rows so two students clicking at the same moment cannot both get the slot
        $conn->begin_transaction();
        try {
            if ($editing) {
                // re-check inside the transaction: the reservation may have been approved/changed meanwhile
                $l = $conn->prepare("SELECT * FROM reservations WHERE id = ? AND user_id = ? FOR UPDATE");
                $l->bind_param("ii", $editId, $uid); $l->execute();
                $fresh = $l->get_result()->fetch_assoc(); $l->close();
                if (!$fresh || ($why = edit_block_reason($fresh))) {
                    $conn->rollback();
                    flash('error', e($why ?? 'That reservation can no longer be edited.'));
                    header("Location: reservations.php"); exit();
                }
            }
            if ($fid) { $l = $conn->prepare("SELECT id FROM facilities WHERE id = ? FOR UPDATE"); $l->bind_param("i", $fid); $l->execute(); $l->get_result(); $l->close(); }
            foreach (array_keys($eqs) as $eid) { $l = $conn->prepare("SELECT id FROM equipment WHERE id = ? FOR UPDATE"); $l->bind_param("i", $eid); $l->execute(); $l->get_result(); $l->close(); }

            $check = check_request($conn, $fid, $eqs, $v['date'], $v['start'], $v['end'], $uid, $editId);
            $blocked = $check['blocked'];
            $problems = $check['items'];

            if ($blocked) {
                $conn->rollback();
            } else {
                $s = $v['start'] . ':00';
                $en = $v['end'] . ':00';
                if ($editing) {
                    // An edited reservation goes back to Pending: the administrator approves it again
                    $up = $conn->prepare("UPDATE reservations SET facility_id=?, reservation_date=?, start_time=?, end_time=?, purpose=?, remarks=?,
                        status='Pending', reviewed_by=NULL, reviewed_at=NULL, edited_at=NOW() WHERE id=? AND user_id=?");
                    $up->bind_param("isssssii", $fid, $v['date'], $s, $en, $v['purpose'], $v['remarks'], $editId, $uid);
                    $up->execute(); $up->close();
                    $conn->query("DELETE FROM reservation_items WHERE reservation_id = " . (int)$editId);
                    $newId = $editId;
                    $ref = $editing['reference_code'];
                } else {
                    // ONE reservation holds the facility and all chosen equipment
                    $ref = make_reference_code($conn);
                    $ins = $conn->prepare("INSERT INTO reservations (reference_code, user_id, facility_id, reservation_date, start_time, end_time, purpose, remarks, status)
                        VALUES (?,?,?,?,?,?,?,?,'Pending')");
                    $ins->bind_param("siisssss", $ref, $uid, $fid, $v['date'], $s, $en, $v['purpose'], $v['remarks']);
                    $ins->execute();
                    $newId = $conn->insert_id;
                    $ins->close();
                }
                foreach ($eqs as $eid => $qty) {
                    $it = $conn->prepare("INSERT INTO reservation_items (reservation_id, equipment_id, quantity) VALUES (?,?,?)");
                    $it->bind_param("iii", $newId, $eid, $qty);
                    $it->execute();
                    $it->close();
                }
                $conn->commit();

                // Notifications: confirm to the requester, alert the administrators
                $what = [];
                foreach ($facilities as $f) if ((int)$f['id'] === $fid) $what[] = $f['facility_name'];
                foreach ($equipment as $it) if (isset($eqs[(int)$it['id']])) $what[] = $eqs[(int)$it['id']] . ' × ' . $it['equipment_name'];
                $summary = implode(', ', $what) . ' on ' . fmt_date($v['date']) . ', ' . fmt_time($v['start']) . ' – ' . fmt_time($v['end']);
                $who = user_label($conn, $uid);
                if ($editing) {
                    notify($conn, $uid, 'Reservation updated', 'Your changes to ' . $ref . ' (' . $summary . ') were saved and are waiting for the administrator to approve.', 'reservations.php');
                    notify_admins($conn, 'Reservation edited - needs approval', $who . ' edited ' . $ref . ': ' . $summary . '', 'admin.php');
                    flash('success', 'Reservation updated. It is Pending again until the administrator approves it.');
                } else {
                    notify($conn, $uid, 'Reservation submitted', 'Your request for ' . $summary . ' was submitted and is waiting for approval. Ref: ' . $ref, 'reservations.php');
                    notify_admins($conn, 'New reservation request', $who . ' requested ' . $summary . '. Please approve or decline.', 'admin.php');
                    flash('success', 'Reservation submitted. It is Pending until the administrator approves it. You will get a notification.');
                }
                header("Location: reservations.php");
                exit();
            }
        } catch (Throwable $ex) {
            $conn->rollback();
            $problems[] = ['level' => 'booked', 'msg' => 'Something went wrong while saving. Please try again.'];
        }
    }
}

page_header($editing ? 'Edit reservation' : 'New reservation', $editing ? 'reservations' : 'reserve');
?>
<div class="split res-split">
    <div class="card">
        <div class="card-head"><div><h2><?= $editing ? 'Edit reservation ' . e($editing['reference_code']) : 'Reservation details' ?></h2><p><?= $editing ? 'You can edit it until it starts. After saving, it goes back to Pending and the administrator approves it again.' : 'We check the schedule while you fill this in. The administrator approves your request.' ?></p></div></div>

        <?php if ($problems):
            $cls = 'pending';
            foreach ($problems as $pr) { if ($pr['level'] === 'booked') { $cls = 'booked'; break; } }
        ?>
            <div class="avail <?= e($cls) ?>">
                <strong><?= icon('alert', 16) ?> <?= $cls === 'pending' ? 'Time slot already requested' : 'Reservation not saved' ?></strong>
                <ul><?php foreach ($problems as $pr): ?><li><?= e($pr['msg']) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="POST" id="resForm" class="res-form">
            <?= csrf_field() ?>
            <?php if ($editing): ?><input type="hidden" name="edit_id" value="<?= (int)$editId ?>"><?php endif; ?>
            <div class="row" style="grid-template-columns:1fr">
                <div class="field">
                    <label for="facility">Facility</label>
                    <select id="facility" name="facility">
                        <option value="0">— None —</option>
                        <?php foreach ($facilities as $f): ?>
                            <option value="<?= (int)$f['id'] ?>" data-label="<?= e($f['facility_name']) ?> (<?= (int)$f['capacity'] ?> seats)" <?= $v['facility'] === (int)$f['id'] ? 'selected' : '' ?>><?= e($f['facility_name']) ?> (<?= (int)$f['capacity'] ?> seats) · Available</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label>Equipment <small style="display:inline">(tick everything you need)</small></label>
                <div class="eq-list" id="eqList">
                    <?php foreach ($equipment as $it): $iid = (int)$it['id']; $sel = isset($v['equipment'][$iid]); $out = false; ?>
                        <div class="eq-item <?= $sel ? 'on' : '' ?>" <?= $out ? 'style="opacity:.55"' : '' ?> data-id="<?= $iid ?>" data-total="<?= (int)$it['quantity'] ?>" data-label="<?= e($it['equipment_name']) ?>">
                            <label>
                                <input type="checkbox" name="equipment[]" value="<?= $iid ?>" <?= $sel ? 'checked' : '' ?> <?= $out ? 'disabled' : '' ?>>
                                <span><?= e($it['equipment_name']) ?><span class="eq-note"><?= $out ? 'Unavailable – all reserved' : e(equipment_stock_text($it, $reserved)) ?></span></span>
                            </label>
                            <div class="eq-q"><input type="number" name="qty[<?= $iid ?>]" min="1" max="<?= (int)$it['quantity'] ?>" value="<?= $sel ? (int)$v['equipment'][$iid] : 1 ?>" aria-label="Quantity of <?= e($it['equipment_name']) ?>"></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$equipment): ?><small>No equipment is available right now.</small><?php endif; ?>
                </div>
            </div>
            <div class="when-box">
            <div class="when-head">
                <span class="when-title"><?= icon('calendar', 16) ?> When do you need it?</span>
            </div>
            <div class="row3 when-panel">
                <div class="field">
                    <label for="date">Date</label>
                    <input id="date" type="date" name="date" min="<?= e(date('Y-m-d')) ?>" value="<?= e($v['date']) ?>" required>
                </div>
                <div class="field">
                    <label for="start">Start time</label>
                    <input id="start" type="time" name="start" max="<?= e(CLOSE_TIME) ?>" value="<?= e($v['start']) ?>" required>
                </div>
                <div class="field">
                    <label for="end">End time</label>
                    <input id="end" type="time" name="end" max="<?= e(CLOSE_TIME) ?>" value="<?= e($v['end']) ?>" required>
                </div>
            </div>
            </div>
            <div class="field">
                <label for="purpose">Purpose</label>
                <input id="purpose" name="purpose" maxlength="255" value="<?= e($v['purpose']) ?>" placeholder="e.g. Programming laboratory activity" required>
            </div>
            <div class="field">
                <label for="remarks">Remarks</label>
                <textarea id="remarks" name="remarks"><?= e($v['remarks']) ?></textarea>
            </div>
            <button class="btn btn-submit" type="submit" id="submitBtn"><?= $editing ? 'Review changes' : 'Review &amp; submit' ?></button>
            <?php if ($editing): ?><a class="btn btn-light" href="reservations.php">Discard changes</a><?php endif; ?>
            <small style="display:block;margin-top:10px">Reserve at any time, but it must end by <?= e(fmt_time(CLOSE_TIME)) ?> (closing time).</small>
        </form>
    </div>

    <div class="res-side">
        <div class="card res-avail">
            <div class="card-head"><h2>Availability</h2></div>
            <div id="availBox" class="avail"><strong>Pick a resource, date and time</strong><span>We will tell you right away if it is free.</span></div>
            <h3 style="margin:14px 0 4px">Already taken on this day</h3>
            <div class="slots" id="slots"><div class="slot s-free"><span>Choose a resource and date to see the schedule.</span></div></div>
        </div>
        <div class="card res-summary" id="picCard">
            <div class="card-head"><h2>Your selection</h2></div>
            <p class="sum-empty" id="sumEmpty">Nothing chosen yet. Pick a facility or tick equipment and it will be listed here.</p>
            <ul class="sum-list" id="sumList">
            <?php foreach ($facilities as $f): ?>
                <li class="sum-item" data-pic="f<?= (int)$f['id'] ?>" style="display:none">
                    <?= resource_pic('facility', $f, 'sum-pic') ?>
                    <div class="sum-info"><b><?= e($f['facility_name']) ?></b><span>Facility · <?= (int)$f['capacity'] ?> seats</span></div>
                </li>
            <?php endforeach; ?>
            <?php foreach ($equipment as $it): ?>
                <li class="sum-item" data-pic="e<?= (int)$it['id'] ?>" style="display:none">
                    <?= resource_pic('equipment', $it, 'sum-pic') ?>
                    <div class="sum-info"><b><?= e($it['equipment_name']) ?></b><span>Equipment</span></div>
                    <span class="sum-qty">× 1</span>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<div class="rv-modal" id="reviewModal" hidden>
    <div class="rv-box" role="dialog" aria-modal="true" aria-labelledby="rvTitle">
        <h2 id="rvTitle">Check your reservation</h2>
        <p class="rv-note"><?= $editing ? 'Your changes are sent to the administrator for approval.' : 'Make sure everything is correct. The administrator will approve or decline it.' ?></p>
        <div id="reviewBody"></div>
        <div class="rv-actions">
            <button type="button" class="btn btn-light" id="rvBack">Go back &amp; edit</button>
            <button type="button" class="btn" id="rvOk"><?= $editing ? 'Save changes' : 'Confirm &amp; submit' ?></button>
        </div>
    </div>
</div>

<script>
(function () {
  var f = document.getElementById('facility'), list = document.getElementById('eqList'),
      d = document.getElementById('date'), s = document.getElementById('start'), en = document.getElementById('end'),
      box = document.getElementById('availBox'), slots = document.getElementById('slots'),
      btn = document.getElementById('submitBtn'), picCard = document.getElementById('picCard');
  var EX = <?= (int)$editId ?>, timer = null, icon = '<?= addslashes(str_replace(["\r", "\n"], '', icon('alert', 16))) ?>';
  var items = Array.prototype.slice.call(list.querySelectorAll('.eq-item'));

  function esc(t) { var x = document.createElement('div'); x.textContent = t; return x.innerHTML; }
  function chk(it) { return it.querySelector('input[type=checkbox]'); }
  function qin(it) { return it.querySelector('.eq-q input'); }
  function picked() { return items.filter(function (it) { return chk(it).checked; }); }
  function eqParam() { return picked().map(function (it) { return it.dataset.id + ':' + (qin(it).value || 1); }).join(','); }

  function showPics() {
    var count = 0;
    picCard.querySelectorAll('[data-pic]').forEach(function (n) {
      var eqItem = null;
      picked().forEach(function (it) { if (n.dataset.pic === 'e' + it.dataset.id) eqItem = it; });
      var on = (n.dataset.pic === 'f' + f.value && f.value !== '0') || !!eqItem;
      n.style.display = on ? 'flex' : 'none';
      if (eqItem) { var q = n.querySelector('.sum-qty'); if (q) q.textContent = '× ' + (qin(eqItem).value || 1); }
      if (on) count++;
    });
    document.getElementById('sumEmpty').style.display = count ? 'none' : 'block';
    items.forEach(function (it) { it.classList.toggle('on', chk(it).checked); });
  }

  function refreshOptions() {
    var url = 'resource_status.php?date=' + encodeURIComponent(d.value) + '&start=' + encodeURIComponent(s.value) + '&end=' + encodeURIComponent(en.value) + '&exclude=' + EX;
    fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      Array.prototype.forEach.call(f.options, function (o) {
        if (o.value === '0') return;
        var st = j.facilities[o.value];
        var tag = st === 'booked' ? 'Booked at this time' : (st === 'pending' ? 'Requested (pending)' : 'Available');
        o.textContent = o.getAttribute('data-label') + ' · ' + tag;
      });
      items.forEach(function (it) {
        var st = j.equipment[it.dataset.id]; if (!st) return;
        var when = j.timed ? ' at this time' : ' now';
        it.querySelector('.eq-note').textContent = st.left > 0 ? st.left + ' of ' + st.total + ' available' + when : 'None left' + when;
        it.classList.toggle('off', st.left <= 0);
        qin(it).max = st.left > 0 ? st.left : st.total;
      });
    }).catch(function () {});
  }

  function check() {
    showPics();
    var eqp = eqParam();
    if (f.value === '0' && !eqp) {
      box.className = 'avail'; box.innerHTML = '<strong>Pick a resource, date and time</strong><span>We will tell you right away if it is free.</span>';
      slots.innerHTML = '<div class="slot s-free"><span>Choose a resource and date to see the schedule.</span></div>';
      btn.disabled = false;
      return;
    }
    var url = 'check_availability.php?facility=' + f.value + '&eq=' + encodeURIComponent(eqp) +
              '&date=' + encodeURIComponent(d.value) + '&start=' + encodeURIComponent(s.value) + '&end=' + encodeURIComponent(en.value) + '&exclude=' + EX;
    fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j.slots && j.slots.length) {
        slots.innerHTML = j.slots.map(function (x) {
          var cls = x.status === 'Pending' ? 's-pending' : 's-booked';
          return '<div class="slot ' + cls + '"><span>' + esc(x.label) + '</span><span>' + (x.status === 'Pending' ? 'Requested (pending)' : 'Reserved') + '</span></div>';
        }).join('');
      } else {
        slots.innerHTML = '<div class="slot s-free"><span>Nothing booked yet – the whole day is open.</span></div>';
      }
      if (j.status === 'ok') {
        box.className = 'avail ok'; box.innerHTML = '<strong>Available</strong><span>No conflict found. You may submit your request.</span>'; btn.disabled = false;
      } else if (j.status === 'booked' || j.status === 'pending' || j.status === 'invalid') {
        var title = j.status === 'pending' ? 'Someone requested this time first' : (j.status === 'invalid' ? 'Check your date and time' : 'Reservation conflict');
        box.className = 'avail ' + (j.status === 'pending' ? 'pending' : 'booked');
        box.innerHTML = '<strong>' + icon + ' ' + title + '</strong><ul>' + j.items.map(function (i) { return '<li>' + esc(i.msg) + '</li>'; }).join('') + '</ul>';
        btn.disabled = true;
      } else {
        box.className = 'avail'; box.innerHTML = '<strong>Fill in date and times</strong><span>Then we will check the schedule.</span>'; btn.disabled = false;
      }
    }).catch(function () {});
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(dt) { return dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate()); }
  function fire(el) { el.dispatchEvent(new Event('change', { bubbles: true })); }
  var dc=document.getElementById('dateChips'); if(dc) dc.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-day]'); if (!b) return;
    var dt = new Date(); dt.setDate(dt.getDate() + (+b.dataset.day)); d.value = ymd(dt); fire(d);
  });
  var lc=document.getElementById('lenChips'); if(lc) lc.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-hrs]'); if (!b) return;
    if (!s.value) { var n = new Date(); s.value = pad(n.getHours() + 1 > 23 ? 23 : n.getHours() + 1) + ':00'; }
    var p = s.value.split(':'), mins = (+p[0]) * 60 + (+p[1]) + (+b.dataset.hrs) * 60;
    mins = Math.min(mins, 21 * 60);   // closing time
    en.value = pad(Math.floor(mins / 60)) + ':' + pad(mins % 60); fire(s); fire(en);
  });
  function markChips() {
    var chips = document.querySelectorAll('#dateChips .chip-btn'), now = new Date();
    chips.forEach(function (c) { var dt = new Date(); dt.setDate(now.getDate() + (+c.dataset.day)); c.classList.toggle('on', ymd(dt) === d.value); });
    var p1 = (s.value || '').split(':'), p2 = (en.value || '').split(':');
    var diff = s.value && en.value ? ((+p2[0]) * 60 + (+p2[1]) - (+p1[0]) * 60 - (+p1[1])) / 60 : 0;
    document.querySelectorAll('#lenChips .chip-btn').forEach(function (c) { c.classList.toggle('on', diff === +c.dataset.hrs); });
  }
  [d, s, en].forEach(function (el) { el.addEventListener('change', markChips); el.addEventListener('input', markChips); });
  markChips();
  // clicking anywhere on a date/time box opens its picker (clock for time)
  [d, s, en].forEach(function (el) { el.addEventListener('click', function () { try { if (el.showPicker) el.showPicker(); } catch (e) {} }); });
  function later() { clearTimeout(timer); timer = setTimeout(function () { refreshOptions(); check(); }, 250); }
  [f, d, s, en].concat(items.map(chk), items.map(qin)).forEach(function (el) { el.addEventListener('change', later); el.addEventListener('input', later); });
  // ---- Review step: show a summary; the student can go back and edit, or confirm ----
  var form = document.getElementById('resForm'), modal = document.getElementById('reviewModal'),
      body = document.getElementById('reviewBody'), confirmed = false;
  function fmtDate(v) { var p = v.split('-'); return new Date(p[0], p[1] - 1, p[2]).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }); }
  function fmtTime(v) { var p = v.split(':'), h = +p[0]; return (h % 12 || 12) + ':' + p[1] + (h < 12 ? ' AM' : ' PM'); }
  function row(k, v) { return '<div class="rv-row"><span>' + k + '</span><b>' + esc(v || '—') + '</b></div>'; }
  form.addEventListener('submit', function (ev) {
    if (confirmed) return;
    ev.preventDefault();
    if (!form.reportValidity()) return;
    var eq = picked().map(function (it) { return qin(it).value + ' × ' + it.dataset.label; }).join(', ');
    var fac = f.value !== '0' ? f.options[f.selectedIndex].getAttribute('data-label') : '';
    if (!fac && !eq) { box.className = 'avail booked'; box.innerHTML = '<strong>' + icon + ' Choose a facility, equipment, or both.</strong>'; return; }
    body.innerHTML = row('Facility', fac) + row('Equipment', eq) + row('Date', fmtDate(d.value)) +
      row('Time', fmtTime(s.value) + ' – ' + fmtTime(en.value)) + row('Purpose', document.getElementById('purpose').value) +
      row('Remarks', document.getElementById('remarks').value);
    modal.hidden = false;
  });
  document.getElementById('rvBack').addEventListener('click', function () { modal.hidden = true; });
  document.getElementById('rvOk').addEventListener('click', function () { confirmed = true; modal.hidden = true; form.submit(); });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.hidden = true; });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') modal.hidden = true; });

  refreshOptions();
  check();
})();
</script>
<?php page_footer();
