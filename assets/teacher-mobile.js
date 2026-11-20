'use strict';
// Teacher roll call: keep an unsent draft per session in this browser tab (sessionStorage only).
// V3.3: the draft is offered with an in-page banner instead of a blocking confirm() on every visit.
(() => {
  const form = document.querySelector('#v32-roll-form');
  if (!form) return;
  const id = new URLSearchParams(location.search).get('id') || '0';
  const key = 'SGR33:attendance:draft:' + id;
  let saved = null;
  try { saved = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) { saved = null; }
  const refresh = () => { if (window.SGR) window.SGR.updateRoll(); };
  const save = () => {
    const present = [...form.querySelectorAll('[data-roll-status="present"]:checked')].map(x => x.value);
    const leave = [...form.querySelectorAll('[data-roll-status="leave"]:checked')].map(x => x.value);
    try { sessionStorage.setItem(key, JSON.stringify({ present, leave })); } catch (_) { /* storage may be disabled */ }
  };
  if (saved && Array.isArray(saved.present) && Array.isArray(saved.leave)) {
    const bar = document.createElement('div');
    bar.className = 'callout gold draft-banner';
    bar.setAttribute('role', 'status');
    bar.innerHTML = '<div><b>พบแบบร่างการเช็กชื่อที่ยังไม่ได้บันทึก</b><p>ต้องการเรียกคืนตัวเลือกที่ทำไว้หรือไม่</p></div>';
    const restore = document.createElement('button');
    restore.type = 'button'; restore.className = 'button primary small'; restore.textContent = 'เรียกคืน';
    const drop = document.createElement('button');
    drop.type = 'button'; drop.className = 'button ghost small'; drop.textContent = 'ทิ้งแบบร่าง';
    bar.append(restore, drop);
    form.prepend(bar);
    restore.addEventListener('click', () => {
      form.querySelectorAll('input[data-roll-status]').forEach(x => {
        x.checked = (x.dataset.rollStatus === 'present' ? saved.present : saved.leave).includes(x.value);
      });
      refresh(); bar.remove();
    });
    drop.addEventListener('click', () => { try { sessionStorage.removeItem(key); } catch (_) {} bar.remove(); });
  }
  form.addEventListener('change', () => requestAnimationFrame(save));
  form.addEventListener('submit', e => setTimeout(() => { if (!e.defaultPrevented) { try { sessionStorage.removeItem(key); } catch (_) {} } }, 0));
})();
