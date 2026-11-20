'use strict';
// SGR Library V3.3.2 — progressive enhancement only. Every form still works without JavaScript.
(() => {
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  const MAX_UPLOAD = 8 * 1024 * 1024;

  function updateScore() {
    const all = $$('[data-score-student]:checked');
    $$('[data-score-count]').forEach(el => { el.textContent = String(all.length); });
    const summary = document.getElementById('score-selected');
    if (summary) {
      const names = all.slice(0, 3).map(el => el.closest('label')?.querySelector('span')?.firstChild?.textContent?.trim()).filter(Boolean);
      summary.textContent = all.length ? 'เลือกแล้ว: ' + names.join(', ') + (all.length > 3 ? ` และอีก ${all.length - 3} คน` : '') : 'ยังไม่ได้เลือกนักศึกษา';
    }
    if (all.length) $$('[data-score-error]').forEach(el => { el.hidden = true; });
  }
  function updateRoll() {
    const tally = { present: 0, leave: 0, absent: 0 };
    $$('.roll-student').forEach(row => {
      const present = !!row.querySelector('[data-roll-status="present"]:checked');
      const leave = !present && !!row.querySelector('[data-roll-status="leave"]:checked');
      const state = present ? 'present' : (leave ? 'leave' : 'absent');
      tally[state] += 1;
      row.classList.toggle('is-present', present);
      row.classList.toggle('is-leave', leave);
      const label = row.querySelector('[data-roll-indicator]');
      if (label) label.textContent = present ? 'มา' : (leave ? 'ลา' : 'ขาด');
    });
    Object.entries(tally).forEach(([k, v]) => $$(`[data-tally="${k}"]`).forEach(el => { el.textContent = String(v); }));
  }
  function updateChecked(form) {
    if (!form) return;
    const n = $$('.v32-checklist input[type="checkbox"]:checked', form).length;
    $$('[data-checked-count]', form).forEach(el => { el.textContent = n ? `เลือกแล้ว ${n} คน` : 'ยังไม่ได้เลือก'; });
  }
  function updateGrades(form) {
    if (!form) return;
    const n = $$('.grade-row input', form).filter(i => i.value.trim() !== '').length;
    $$('[data-grade-count]', form).forEach(el => { el.textContent = `กรอกแล้ว ${n} คน`; });
  }
  function openDetails(id) {
    const d = id && document.getElementById(id);
    if (d && d.tagName === 'DETAILS') {
      d.open = true;
      d.scrollIntoView({ block: 'start', behavior: 'smooth' });
      d.querySelector('input:not([type="hidden"]),select,textarea')?.focus({ preventScroll: true });
      return true;
    }
    return false;
  }
  function previewWallpaper(value) {
    const keep = document.body.className.split(/\s+/).filter(c => c && c !== 'has-wall' && !c.startsWith('wall-'));
    if (value !== 'none' && value !== 'custom') keep.push('has-wall', 'wall-' + value);
    if (value === 'custom' && document.querySelector('.wall-img')) keep.push('has-wall', 'wall-custom');
    document.body.className = keep.join(' ');
    const img = document.querySelector('.wall-img');
    if (img) img.hidden = value !== 'custom';
  }
  window.SGR = { updateRoll, updateScore };

  document.addEventListener('click', event => {
    const t = event.target;
    if (t.closest('[data-back]')) {
      if (history.length > 1 && document.referrer && new URL(document.referrer).origin === location.origin) history.back();
      else location.assign('/');
      return;
    }
    if (t.closest('[data-print]')) { window.print(); return; }
    const opener = t.closest('[data-open-details]');
    if (opener && openDetails(opener.dataset.openDetails)) {
      event.preventDefault();
      history.replaceState(null, '', '#' + opener.dataset.openDetails);
      return;
    }
    const fill = t.closest('[data-grade-fill]');
    if (fill) {
      const form = fill.closest('form');
      $$('.grade-row:not([hidden]) input', form).forEach(i => { if (i.value.trim() === '') i.value = fill.dataset.gradeFill; });
      updateGrades(form);
      return;
    }
    const dismiss = t.closest('[data-dismiss]');
    if (dismiss) { dismiss.parentElement?.remove(); return; }
    const pw = t.closest('[data-toggle-password]');
    if (pw) {
      const input = pw.parentElement?.querySelector('input');
      if (input) {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        pw.setAttribute('aria-pressed', String(show));
        pw.setAttribute('aria-label', show ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
        input.focus();
      }
      return;
    }
    const trigger = t.closest('[data-open-dialog]');
    if (trigger) {
      const el = document.getElementById(trigger.dataset.openDialog);
      if (el && typeof el.showModal === 'function') { el.showModal(); el.querySelector('input[type="search"]')?.focus(); }
      return;
    }
    const close = t.closest('[data-close-dialog]');
    if (close) { close.closest('dialog')?.close(); return; }
    if (t.closest('[data-select-visible]')) {
      $$('.student-choice:not([hidden]) input[data-score-student]').forEach(el => { el.checked = true; });
      updateScore();
    }
    if (t.closest('[data-clear-visible]')) {
      $$('.student-choice:not([hidden]) input[data-score-student]').forEach(el => { el.checked = false; });
      updateScore();
    }
    const pts = t.closest('[data-points]');
    if (pts) {
      const input = pts.closest('form')?.querySelector('input[name="points"]');
      if (input) { input.value = pts.dataset.points; input.focus(); }
    }
    if (t.closest('[data-present-all]')) {
      $$('.roll-student:not([hidden]) [data-roll-status="present"]').forEach(el => { el.checked = true; });
      $$('.roll-student:not([hidden]) [data-roll-status="leave"]').forEach(el => { el.checked = false; });
      updateRoll();
      t.closest('form')?.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (t.closest('[data-roll-clear]')) {
      $$('[data-roll-status]').forEach(el => { el.checked = false; });
      updateRoll();
      t.closest('form')?.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const check = t.closest('[data-check]');
    if (check) {
      const form = check.closest('form') || document;
      const mode = check.dataset.check;
      $$('.v32-checklist .v32-choice', form).forEach(row => {
        const box = row.querySelector('input[type="checkbox"]');
        if (!box || box.disabled) return;
        if (mode === 'none') box.checked = false;
        else if (!row.hidden && (mode === 'all' || row.dataset.status === mode)) box.checked = true;
      });
      updateChecked(form);
    }
  });

  document.addEventListener('submit', event => {
    const form = event.target;
    const confirmButton = event.submitter?.closest('[data-confirm-delete]');
    if (confirmButton && !window.confirm(confirmButton.dataset.confirmDelete)) { event.preventDefault(); return; }
    if (form.id === 'score-batch-form' && !document.querySelector('[data-score-student]:checked')) {
      event.preventDefault();
      $$('[data-score-error]').forEach(el => { el.hidden = false; });
      const d = document.getElementById('score-chooser');
      if (d && !d.open && typeof d.showModal === 'function') d.showModal();
      return;
    }
    if (form.id === 'v32-enroll' && event.submitter?.name === 'decision' && !form.querySelector('input[name="student_ids[]"]:checked')) {
      event.preventDefault();
      $$('[data-checked-count]', form).forEach(el => { el.textContent = 'กรุณาติ๊กเลือกอย่างน้อย 1 คน'; el.classList.add('form-error'); });
      return;
    }
    if (form.querySelector('[name="action"][value="bulk_roll"]')) {
      const absent = $$('.roll-student').filter(r => !r.querySelector('[data-roll-status]:checked')).length;
      if (!window.confirm(`บันทึกสถานะทั้งห้อง? นักศึกษาที่ไม่ได้เลือก “มา” หรือ “ลา” (${absent} คน) จะถูกบันทึกเป็น “ขาด”`)) { event.preventDefault(); return; }
    }
    // Prevent double submits on slow connections while keeping the clicked button's value.
    if (!event.defaultPrevented && form.method === 'post') {
      const pressed = event.submitter && event.submitter.tagName === 'BUTTON' ? event.submitter : form.querySelector('button[type="submit"]');
      setTimeout(() => { $$('button[type="submit"]', form).forEach(b => { b.disabled = true; }); pressed?.classList.add('busy'); }, 0);
      setTimeout(() => { $$('button[type="submit"]', form).forEach(b => { b.disabled = false; }); pressed?.classList.remove('busy'); }, 8000);
    }
  });

  document.addEventListener('change', event => {
    const t = event.target;
    if (t.closest('[data-score-student]')) updateScore();
    const status = t.closest('[data-roll-status]');
    if (status) {
      if (status.checked) status.closest('.roll-student')?.querySelectorAll('[data-roll-status]').forEach(el => { if (el !== status) el.checked = false; });
      updateRoll();
    }
    if (t.matches('.v32-checklist input[type="checkbox"]')) updateChecked(t.closest('form'));
    // Teacher account: a preset is previewed at once; choosing an image selects "my picture".
    if (t.matches('input[name="wallpaper"]') && t.checked) previewWallpaper(t.value);
    if (t.matches('input[name="wallpaper_file"]') && t.files && t.files[0]) {
      const custom = t.form?.querySelector('input[name="wallpaper"][value="custom"]');
      if (custom) custom.checked = true;
    }
    if (t.matches('input[type="file"]')) {
      const f = t.files && t.files[0];
      t.setCustomValidity(f && f.size > MAX_UPLOAD ? 'ไฟล์ใหญ่เกิน 8 MB กรุณาเลือกไฟล์ที่เล็กลง' : '');
      const zone = t.closest('.dropzone');
      const name = zone?.querySelector('[data-file-name]');
      if (name) name.textContent = f ? f.name : 'แตะเพื่อเลือกไฟล์';
      zone?.classList.toggle('has-file', !!f);
      if (f && f.size > MAX_UPLOAD) t.reportValidity();
    }
    // Filters switch instantly; <noscript> buttons cover browsers without JavaScript.
    if (t.matches('form.term-filter select[name="term"], form.filter.inline select')) t.form?.requestSubmit();
  });

  document.addEventListener('input', event => {
    const t = event.target;
    if (t.matches('[data-match]')) {
      const other = t.form?.querySelector(`[name="${t.dataset.match}"]`);
      t.setCustomValidity(other && t.value !== other.value ? 'รหัสผ่านทั้งสองช่องไม่ตรงกัน' : '');
    } else if (t.name === 'password' && t.form) {
      const confirm = t.form.querySelector('[data-match="password"]');
      if (confirm && confirm.value) confirm.setCustomValidity(confirm.value !== t.value ? 'รหัสผ่านทั้งสองช่องไม่ตรงกัน' : '');
    }
    if (t.matches('[data-student-search]')) {
      const q = t.value.toLocaleLowerCase('th').trim();
      $$('.student-choice').forEach(row => { row.hidden = !row.textContent.toLocaleLowerCase('th').includes(q); });
    }
    if (t.matches('[data-roster-search]')) {
      const q = t.value.trim().toLocaleLowerCase('th');
      const panel = t.closest('form, details, .section, .panel') || document;
      $$('.v32-checklist .v32-choice', panel).forEach(item => { item.hidden = !item.textContent.toLocaleLowerCase('th').includes(q); });
    }
    if (t.matches('[data-row-search]')) {
      const q = t.value.trim().toLocaleLowerCase('th');
      $$(t.dataset.rowSearch).forEach(row => { row.hidden = !row.textContent.toLocaleLowerCase('th').includes(q); });
    }
    if (t.matches('[data-grade-search]')) {
      const q = t.value.trim().toLocaleLowerCase('th');
      $$('.grade-row', t.form || document).forEach(row => { row.hidden = !row.textContent.toLocaleLowerCase('th').includes(q); });
    }
    if (t.matches('.grade-row input')) updateGrades(t.form);
    if (t.matches('[data-roll-search]')) {
      const q = t.value.trim().toLocaleLowerCase('th');
      $$('.roll-student').forEach(row => { row.hidden = !row.textContent.toLocaleLowerCase('th').includes(q); });
    }
  });

  // Dismiss dialogs on backdrop click only; native Esc still works.
  $$('dialog.student-dialog').forEach(dialog => {
    dialog.addEventListener('click', event => {
      const b = dialog.getBoundingClientRect();
      if (event.clientX < b.left || event.clientX > b.right || event.clientY < b.top || event.clientY > b.bottom) dialog.close();
    });
  });
  $$('form').forEach(updateChecked);
  if (location.hash.length > 1) openDetails(decodeURIComponent(location.hash.slice(1)));
  updateScore();
  updateRoll();
})();
