// ============================================================
// GUECO OPTICAL — Main JavaScript
// Theme Toggle, Sidebar, Toasts, Modals, Utilities
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

  // ── Theme ──────────────────────────────────────────────
  const html = document.documentElement;

  function applyTheme(theme) {
    if (theme !== 'light' && theme !== 'dark') theme = 'dark';
    html.setAttribute('data-theme', theme);
    try {
      localStorage.setItem('gueco_theme', theme);
      localStorage.setItem('gueco-theme', theme);
      localStorage.setItem('guecoTheme', theme);
      localStorage.setItem('theme', theme);
      document.cookie = "gueco_theme=" + theme + "; path=/; max-age=31536000; SameSite=Lax";
      document.cookie = "theme=" + theme + "; path=/; max-age=31536000; SameSite=Lax";
    } catch(e) {}
    const icon = document.getElementById('themeIcon');
    if (icon) {
      icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
    window.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme: theme } }));
  }

  // Sync icon and state with already initialized attribute
  const activeTheme = html.getAttribute('data-theme') || localStorage.getItem('gueco_theme') || localStorage.getItem('gueco-theme') || localStorage.getItem('theme') || localStorage.getItem('guecoTheme') || 'dark';
  applyTheme(activeTheme);

  // Toggle button
  const themeToggle = document.getElementById('themeToggle');
  if (themeToggle) {
    themeToggle.addEventListener('click', (e) => {
      e.preventDefault();
      const current = html.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      applyTheme(next);
    });
  }

  // ── Sidebar Mobile Toggle ──────────────────────────────
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');
  const sidebarBackdrop = document.getElementById('sidebarBackdrop');

  window.toggleSidebarMobile = function (forceState) {
    if (!sidebar) return;
    const willOpen = (typeof forceState === 'boolean') ? forceState : !sidebar.classList.contains('open');
    if (willOpen) {
      sidebar.classList.add('open');
      if (sidebarBackdrop) sidebarBackdrop.classList.add('show');
      document.body.classList.add('sidebar-open');
    } else {
      sidebar.classList.remove('open');
      if (sidebarBackdrop) sidebarBackdrop.classList.remove('show');
      document.body.classList.remove('sidebar-open');
    }
  };

  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      window.toggleSidebarMobile();
    });
  }

  if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', (e) => {
      e.preventDefault();
      window.toggleSidebarMobile(false);
    });
  }

  // ── Active Nav Link ────────────────────────────────────
  const currentPath = window.location.pathname.split('/').pop();
  document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
    const href = link.getAttribute('href')?.split('/').pop();
    if (href && href === currentPath) {
      link.classList.add('active');
    }
  });

  // ── Web Audio API Notification Sound Effects ─────────────
  // ── Web Audio API Notification Sound Effects ─────────────
  let notifAudioCtx = null;
  function getAudioContext() {
    if (!notifAudioCtx) {
      const AudioCtxClass = window.AudioContext || window.webkitAudioContext;
      if (AudioCtxClass) {
        notifAudioCtx = new AudioCtxClass();
      }
    }
    if (notifAudioCtx && notifAudioCtx.state === 'suspended') {
      notifAudioCtx.resume().catch(() => {});
    }
    return notifAudioCtx;
  }

  // Pre-unlock audio on user gesture
  ['click', 'touchstart', 'keydown', 'mousedown'].forEach(evt => {
    document.addEventListener(evt, () => {
      const ctx = getAudioContext();
      if (ctx && ctx.state === 'suspended') {
        ctx.resume().catch(() => {});
      }
    }, { passive: true });
  });

  window.playNotificationSound = function (type = 'default') {
    try {
      const ctx = getAudioContext();
      if (!ctx) return;

      const triggerChime = () => {
        const now = ctx.currentTime;
        if (type === 'warning' || type === 'low_stock') {
          // Distinctive 2-tone warm alert chime for inventory low stock / walk-in waiting
          const osc1 = ctx.createOscillator();
          const gain1 = ctx.createGain();
          osc1.type = 'sine';
          osc1.frequency.setValueAtTime(659.25, now); // E5
          gain1.gain.setValueAtTime(0.35, now);
          gain1.gain.exponentialRampToValueAtTime(0.01, now + 0.16);
          osc1.connect(gain1);
          gain1.connect(ctx.destination);
          osc1.start(now);
          osc1.stop(now + 0.18);

          const osc2 = ctx.createOscillator();
          const gain2 = ctx.createGain();
          osc2.type = 'sine';
          osc2.frequency.setValueAtTime(880, now + 0.14); // A5
          gain2.gain.setValueAtTime(0.38, now + 0.14);
          gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.48);
          osc2.connect(gain2);
          gain2.connect(ctx.destination);
          osc2.start(now + 0.14);
          osc2.stop(now + 0.50);
        } else {
          // Bright, pleasing optical clinic notification bell chime (Ready for POS / new appointment)
          const osc1 = ctx.createOscillator();
          const gain1 = ctx.createGain();
          osc1.type = 'sine';
          osc1.frequency.setValueAtTime(880, now); // A5
          gain1.gain.setValueAtTime(0.32, now);
          gain1.gain.exponentialRampToValueAtTime(0.01, now + 0.14);
          osc1.connect(gain1);
          gain1.connect(ctx.destination);
          osc1.start(now);
          osc1.stop(now + 0.15);

          const osc2 = ctx.createOscillator();
          const gain2 = ctx.createGain();
          osc2.type = 'sine';
          osc2.frequency.setValueAtTime(1318.51, now + 0.11); // E6
          gain2.gain.setValueAtTime(0.35, now + 0.11);
          gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.48);
          osc2.connect(gain2);
          gain2.connect(ctx.destination);
          osc2.start(now + 0.11);
          osc2.stop(now + 0.50);
        }
      };

      if (ctx.state === 'suspended') {
        ctx.resume().then(triggerChime).catch(triggerChime);
      } else {
        triggerChime();
      }
    } catch (err) {
      console.debug('[Audio] Notification sound failed:', err);
    }
  };

  // ── Toast Notifications (Multi-Second Display & Audio) ─────────
  window.showToast = function (message, type = 'info', duration = 4000, playSound = true) {
    if (playSound) {
      window.playNotificationSound(type);
    }

    let container = document.querySelector('.toast-container');
    if (!container) {
      container = document.createElement('div');
      container.className = 'toast-container';
      document.body.appendChild(container);
    }
    container.style.position = 'fixed';
    container.style.top = '24px';
    container.style.right = '24px';
    container.style.zIndex = '999999';
    container.style.pointerEvents = 'none';

    const icons = {
      success: 'check-circle',
      error: 'times-circle',
      danger: 'times-circle',
      info: 'info-circle',
      warning: 'triangle-exclamation'
    };
    const icon = icons[type] || icons.info;

    const toast = document.createElement('div');
    toast.className = `toast ${type} show`;
    toast.style.display = 'flex';
    toast.style.pointerEvents = 'auto';
    toast.style.opacity = '1';
    toast.style.visibility = 'visible';
    toast.innerHTML = `
      <i class="fas fa-${icon} toast-icon" style="font-size:1.15rem;flex-shrink:0"></i>
      <div style="flex:1;">${message}</div>
      <button type="button" class="toast-close-btn" aria-label="Close" onclick="this.closest('.toast').remove()">
        <i class="fas fa-times"></i>
      </button>`;

    container.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(-15px) scale(0.96)';
      setTimeout(() => toast.remove(), 350);
    }, duration);
  };

  // Show PHP-passed flash messages as SweetAlert Modal Popup
  const flashMsg = document.getElementById('flashMsg');
  if (flashMsg) {
    const msg = flashMsg.dataset.msg;
    const type = flashMsg.dataset.type || 'info';
    if (msg) {
      if (typeof Swal !== 'undefined') {
        const iconType = type === 'success' ? 'success' : (type === 'danger' || type === 'error' ? 'error' : (type === 'warning' ? 'warning' : 'info'));
        const titleText = type === 'success' ? 'Success!' : (type === 'danger' || type === 'error' ? 'Error' : 'Notice');
        Swal.fire({
          title: titleText,
          text: msg,
          icon: iconType,
          confirmButtonColor: 'var(--clr-primary)',
          background: 'var(--bg-card)',
          color: 'var(--text-primary)',
          timer: 3000,
          timerProgressBar: true
        });
      } else {
        showToast(msg, type);
      }
    }
  }

  // ── Modal Helpers & Unsaved Changes Confirmation ───────
  function getModalFormState(form) {
    if (!form) return {};
    const state = {};
    const elements = form.querySelectorAll('input, select, textarea');
    elements.forEach((el, idx) => {
      // Exclude hidden tokens/actions
      if (el.type === 'hidden' && (el.name === 'csrf_token' || el.name === 'action')) return;
      const key = el.name || el.id || `field_${idx}`;
      if (el.type === 'checkbox' || el.type === 'radio') {
        state[key] = el.checked;
      } else {
        state[key] = (el.value || '').trim();
      }
    });
    return state;
  }

  function snapshotModalForms(m) {
    if (!m) return;
    m.querySelectorAll('form').forEach(form => {
      form._initialFormState = getModalFormState(form);
    });
  }

  function isModalDirty(m) {
    if (!m) return false;
    const forms = m.querySelectorAll('form');
    for (const form of forms) {
      if (form._isSubmitting) continue;
      const initial = form._initialFormState || {};
      const current = getModalFormState(form);
      for (const key in current) {
        const initVal = initial[key] !== undefined ? initial[key] : (typeof current[key] === 'boolean' ? false : '');
        if (current[key] !== initVal) {
          return true;
        }
      }
    }
    return false;
  }

  function resetModalForms(m) {
    if (!m) return;
    m.querySelectorAll('form').forEach(form => {
      form.reset();
      delete form._initialFormState;
    });
  }

  window.openModal = function (id) {
    const m = document.getElementById(id);
    if (m) {
      m.classList.add('active');
      m.classList.add('open');
      document.body.style.overflow = 'hidden';
      // Snapshot immediately and after brief timeout for dynamically populated edit modals
      snapshotModalForms(m);
      setTimeout(() => snapshotModalForms(m), 50);

      // Track submit to bypass discard prompt
      m.querySelectorAll('form').forEach(form => {
        if (!form._hasSubmitListener) {
          form.addEventListener('submit', () => { form._isSubmitting = true; });
          form._hasSubmitListener = true;
        }
      });
    }
  };

  window.closeModal = function (id, force = false) {
    const m = document.getElementById(id);
    if (!m) return;

    if (!force && isModalDirty(m)) {
      const modalHeader = (m.querySelector('.modal-header')?.textContent || '').toLowerCase();
      const formAction = (m.querySelector('input[name="action"]')?.value || '').toLowerCase();
      const isAdd = formAction === 'add' || modalHeader.includes('add') || modalHeader.includes('new') || modalHeader.includes('create') || modalHeader.includes('write');

      const titleText = isAdd ? 'Cancel Adding?' : 'Discard Changes?';
      const promptText = isAdd 
        ? 'Are you sure you want to cancel? The information you entered will not be saved.'
        : 'Are you sure you want to close without saving your changes?';
      const confirmBtnText = isAdd ? 'Yes, cancel' : 'Yes, discard';
      const cancelBtnText = isAdd ? 'Continue editing' : 'Keep editing';

      if (typeof Swal !== 'undefined') {
        Swal.fire({
          title: titleText,
          text: promptText,
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: 'var(--clr-danger)',
          cancelButtonColor: 'var(--clr-primary)',
          confirmButtonText: confirmBtnText,
          cancelButtonText: cancelBtnText,
          background: 'var(--bg-card)',
          color: 'var(--text-primary)'
        }).then((result) => {
          if (result.isConfirmed) {
            resetModalForms(m);
            window.closeModal(id, true);
          }
        });
        return;
      } else if (confirm(promptText)) {
        resetModalForms(m);
      } else {
        return;
      }
    }

    m.classList.remove('active');
    m.classList.remove('open');
    if (force) resetModalForms(m);
    if (!document.querySelector('.modal-overlay.active, .modal-overlay.open')) {
      document.body.style.overflow = '';
    }
  };

  // Close modal on overlay click (with dirty check)
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) {
        window.closeModal(overlay.id);
      }
    });
  });

  // Close modals on Escape key (with dirty check)
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const activeModal = document.querySelector('.modal-overlay.active, .modal-overlay.open');
      if (activeModal) {
        window.closeModal(activeModal.id);
      }
    }
  });

  // ── Confirm Action / Delete ────────────────────────────
  document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const msg = btn.dataset.confirm || 'Are you sure you want to proceed?';
      
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          title: 'Confirm Action',
          text: msg,
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: 'var(--clr-primary)',
          cancelButtonColor: 'var(--clr-danger)',
          confirmButtonText: 'Yes, proceed',
          cancelButtonText: 'Cancel',
          background: 'var(--bg-card)',
          color: 'var(--text-primary)'
        }).then((result) => {
          if (result.isConfirmed) {
            const form = btn.closest('form');
            if (form) {
              form.submit();
            } else if (btn.tagName === 'A' && btn.href) {
              window.location.href = btn.href;
            }
          }
        });
      } else {
        if (confirm(msg)) {
          const form = btn.closest('form');
          if (form) form.submit();
          else if (btn.tagName === 'A' && btn.href) window.location.href = btn.href;
        }
      }
    });
  });

  // ── Role Detection on Login ────────────────────────────
  const emailInput = document.getElementById('loginEmail');
  const roleDetected = document.getElementById('roleDetected');
  const roleLabel = document.getElementById('roleLabel');

  if (emailInput && roleDetected) {
    let debounceTimer;
    emailInput.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      const val = emailInput.value.trim();
      if (val.length < 4) {
        roleDetected.classList.remove('visible');
        return;
      }
      debounceTimer = setTimeout(async () => {
        try {
          const res = await fetch('check_role.php?email=' + encodeURIComponent(val));
          const data = await res.json();
          if (data.role) {
            roleLabel.textContent = data.role_label;
            roleDetected.classList.add('visible');
          } else {
            roleDetected.classList.remove('visible');
          }
        } catch { /* silent */ }
      }, 400);
    });
  }

  // ── Password Toggle ────────────────────────────────────
  document.querySelectorAll('[data-toggle-pass]').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = document.getElementById(btn.dataset.togglePass);
      if (!target) return;
      const isPass = target.type === 'password';
      target.type = isPass ? 'text' : 'password';
      btn.querySelector('i').className = isPass ? 'fas fa-eye-slash' : 'fas fa-eye';
    });
  });

  // ── Auto-dismiss alerts ────────────────────────────────
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
    const delay = parseInt(alert.dataset.autoDismiss) || 4000;
    setTimeout(() => {
      alert.style.transition = 'opacity .5s ease';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 500);
    }, delay);
  });

  // ── Table Row Click ────────────────────────────────────
  document.querySelectorAll('tr[data-href]').forEach(row => {
    row.style.cursor = 'pointer';
    row.addEventListener('click', () => {
      window.location.href = row.dataset.href;
    });
  });

  // ── Number Formatting ──────────────────────────────────
  window.formatCurrency = (num) =>
    '₱' + parseFloat(num || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // ── Print Page ─────────────────────────────────────────
  window.printPage = function () { window.print(); };

  // ── Input Filter (numbers only) ────────────────────────
  document.querySelectorAll('[data-numeric]').forEach(input => {
    input.addEventListener('input', () => {
      input.value = input.value.replace(/[^0-9.]/g, '');
    });
  });

  // ── Notification Polling (Appointments & Inventory Low Stock) ──
  let knownApptIds = null;
  let knownLowStockIds = null;
  const notifBtn = document.querySelector('.notif-btn') || document.querySelector('#notifDropdownWrap .header-icon-btn');

  function getAlertedIds(storageKey) {
    try {
      const stored = sessionStorage.getItem(storageKey);
      return stored ? new Set(JSON.parse(stored)) : new Set();
    } catch(e) {
      return new Set();
    }
  }

  function addAlertedId(storageKey, id) {
    try {
      const set = getAlertedIds(storageKey);
      set.add(String(id));
      sessionStorage.setItem(storageKey, JSON.stringify([...set]));
    } catch(e) {}
  }

  function formatTime(timeStr) {
    if (!timeStr) return '';
    const [h, m] = timeStr.split(':');
    let hours = parseInt(h);
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12 || 12;
    return `${hours}:${m} ${ampm}`;
  }

  function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  async function checkAppointments() {
    try {
      const apiPath = window.location.pathname.includes('gueco-optical') 
                      ? '/gueco-optical/api/check_appointments.php' 
                      : '/api/check_appointments.php';

      const res = await fetch(apiPath + '?t=' + new Date().getTime(), { cache: 'no-store' });
      const data = await res.json();
      
      if (data && typeof data.count !== 'undefined') {
        const count = parseInt(data.count) || 0;
        const appts = data.appointments || [];
        const walkins = data.walkin_items || [];
        const readyPos = data.ready_pos_items || [];
        const lowStock = data.low_stock_items || [];
        const role = data.role || '';

        // Determine portal path safely from role with current URL directory fallback
        let portalDir = 'admin';
        if (window.location.pathname.includes('/saleslady/')) {
          portalDir = 'saleslady';
        } else if (window.location.pathname.includes('/doctor/')) {
          portalDir = 'doctor';
        }
        const rolePath = (role === 'doctor' || role === 'saleslady' || role === 'admin') ? role : portalDir;
        const prefix = window.location.pathname.includes('gueco-optical') ? '/gueco-optical/' : '/';
        const apptsLink = prefix + rolePath + '/appointments.php';
        const invLink = prefix + (rolePath === 'saleslady' ? 'saleslady' : 'admin') + '/inventory.php';

        // 1. Process New Online Pending Appointments
        if (knownApptIds !== null) {
          appts.forEach(appt => {
            const currentId = String(appt.id);
            if (!knownApptIds.has(currentId)) {
              const msg = `<strong>${escapeHtml(appt.patient_name)}</strong> booked an appointment for <strong>${formatDate(appt.appointment_date)}</strong> at <strong>${formatTime(appt.appointment_time)}</strong>.`;
              window.showToast(msg, 'info', 4000); 
            }
          });
        }

        // 2. Process Doctor Walk-in Waiting Alerts (Clickable popup with chime)
        if (rolePath === 'doctor' || rolePath === 'admin') {
          const alertedWalkins = getAlertedIds('goc_alerted_walkins');
          walkins.forEach(walkin => {
            const currentId = String(walkin.id);
            if (!alertedWalkins.has(currentId)) {
              addAlertedId('goc_alerted_walkins', currentId);
              const walkinUrl = prefix + 'doctor/appointments.php?highlight=' + walkin.id;
              const msg = `
                <a href="${walkinUrl}" style="text-decoration:none; color:inherit; display:flex; align-items:flex-start; gap:8px;">
                  <div style="flex:1;">
                    <div><span class="badge bg-warning text-dark me-1" style="font-size:0.7rem;"><i class="fas fa-bolt"></i> Walk-in</span> <strong>${escapeHtml(walkin.patient_name)}</strong> is waiting for doctor checkup.</div>
                    <div class="small text-muted mt-1"><i class="fas fa-calendar-check me-1 text-primary"></i>Click to open Doctor Calendar</div>
                  </div>
                </a>
              `;
              window.showToast(msg, 'warning', 6000, true);
            }
          });
        }

        // 3. Process Saleslady Ready for POS Alerts (Clickable popup with chime)
        if (rolePath === 'saleslady' || rolePath === 'admin') {
          const alertedReadyPos = getAlertedIds('goc_alerted_ready_pos');
          readyPos.forEach(item => {
            const currentId = String(item.id);
            if (!alertedReadyPos.has(currentId)) {
              addAlertedId('goc_alerted_ready_pos', currentId);
              const posUrl = prefix + 'saleslady/pos.php?patient_id=' + item.patient_id + '&appt_id=' + item.id;
              const msg = `
                <a href="${posUrl}" style="text-decoration:none; color:inherit; display:flex; align-items:flex-start; gap:8px;">
                  <div style="flex:1;">
                    <div><span class="badge bg-success text-white me-1" style="font-size:0.7rem;"><i class="fas fa-check-circle"></i> Ready for POS</span> <strong>${escapeHtml(item.patient_name)}</strong> completed exam & prescription is written.</div>
                    <div class="small text-muted mt-1"><i class="fas fa-cash-register me-1 text-success"></i>Click to open POS Billing</div>
                  </div>
                </a>
              `;
              window.showToast(msg, 'success', 6000, true);
            }
          });
        }

        // 4. Process New Low Stock Alerts
        if (knownLowStockIds !== null && (rolePath === 'admin' || rolePath === 'saleslady')) {
          lowStock.forEach(item => {
            const currentStockId = String(item.id);
            if (!knownLowStockIds.has(currentStockId)) {
              const prodName = escapeHtml(item.name + (item.variant_name ? ' — ' + item.variant_name : ''));
              const msg = `<strong>Low Stock Alert:</strong> ${prodName} has only <strong>${item.stock_quantity}</strong> remaining (Alert: &le; ${item.low_stock_alert}).`;
              window.showToast(msg, 'warning', 4000);
            }
          });
        }
        
        // Update tracked IDs
        knownApptIds = new Set(appts.map(a => String(a.id)));
        knownLowStockIds = new Set(lowStock.map(p => String(p.id)));
        
        // Update badge on bell
        let notifBadge = document.querySelector('.notif-badge');
        if (count > 0) {
          if (notifBadge) {
            notifBadge.textContent = count > 99 ? 99 : count;
          } else if (notifBtn) {
            const badge = document.createElement('span');
            badge.className = 'notif-badge';
            badge.id = 'notifBadgeEl';
            badge.textContent = count > 99 ? 99 : count;
            notifBtn.appendChild(badge);
          }
        } else if (notifBadge) {
          notifBadge.remove();
        }
        
        // Dynamically update dropdown list if present
        const dropdownMenu = document.querySelector('#notifDropdownWrap .dropdown-menu');
        if (dropdownMenu) {
          let html = `
            <li class="px-3 py-2 d-flex align-items-center justify-content-between border-bottom" style="background: var(--bg-table-head);">
              <span class="fw-bold" style="font-size: 0.85rem; color: var(--text-primary);"><i class="fas fa-bell me-2" style="color: var(--clr-primary);"></i>Notifications</span>
              ${count > 0 ? `<span class="badge rounded-pill bg-danger" style="font-size: 0.68rem; font-weight: 700;">${count} New</span>` : ''}
            </li>
            <div style="max-height: 380px; overflow-y: auto;" class="custom-scroll">
          `;

          if (appts.length === 0 && lowStock.length === 0 && walkins.length === 0 && readyPos.length === 0) {
            html += `
              <li class="p-4 text-center text-muted" style="font-size: 0.86rem;">
                <i class="fas fa-bell-slash d-block mb-2 text-muted" style="font-size: 1.8rem; opacity: 0.4;"></i>
                <span>No new notifications</span>
              </li>
            `;
          } else {
            // Render Walk-in Waiting section for doctor/admin
            if (walkins.length > 0 && (rolePath === 'doctor' || rolePath === 'admin')) {
              html += `
                <li class="dropdown-header text-uppercase text-warning fw-bold d-flex align-items-center justify-content-between px-3 pt-2 pb-1" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                  <span><i class="fas fa-bolt me-1 text-warning"></i> Walk-in Waiting</span>
                  <span class="badge bg-warning-subtle text-warning-emphasis" style="background: rgba(245,158,11,0.18);">${walkins.length}</span>
                </li>
              `;
              walkins.slice(0, 5).forEach(walkin => {
                html += `
                  <li>
                    <a class="dropdown-item px-3 py-2 d-flex align-items-start gap-2 border-bottom border-light" href="${prefix}doctor/appointments.php?highlight=${walkin.id}">
                      <div style="width: 30px; height: 30px; border-radius: 8px; background: rgba(245, 158, 11, 0.15); color: #D97706; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.82rem; margin-top: 2px;">
                        <i class="fas fa-bolt"></i>
                      </div>
                      <div class="flex-grow-1 text-truncate">
                        <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">
                          ${escapeHtml(walkin.patient_name)}
                        </div>
                        <small class="text-warning-emphasis d-block text-truncate" style="font-size: 0.74rem;">
                          Walk-in Waiting &bull; Arrived at ${formatTime(walkin.appointment_time)}
                        </small>
                      </div>
                    </a>
                  </li>
                `;
              });
            }

            // Render Ready for Billing section for saleslady/admin
            if (readyPos.length > 0 && (rolePath === 'saleslady' || rolePath === 'admin')) {
              html += `
                <li class="dropdown-header text-uppercase text-success fw-bold d-flex align-items-center justify-content-between px-3 pt-2 pb-1" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                  <span><i class="fas fa-check-circle me-1 text-success"></i> Ready for Billing</span>
                  <span class="badge bg-success-subtle text-success" style="background: rgba(16,185,129,0.18);">${readyPos.length}</span>
                </li>
              `;
              readyPos.slice(0, 5).forEach(item => {
                html += `
                  <li>
                    <a class="dropdown-item px-3 py-2 d-flex align-items-start gap-2 border-bottom border-light" href="${prefix}saleslady/pos.php?patient_id=${item.patient_id}&appt_id=${item.id}">
                      <div style="width: 30px; height: 30px; border-radius: 8px; background: rgba(16, 185, 129, 0.15); color: #10B981; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.82rem; margin-top: 2px;">
                        <i class="fas fa-cash-register"></i>
                      </div>
                      <div class="flex-grow-1 text-truncate">
                        <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">
                          ${escapeHtml(item.patient_name)}
                        </div>
                        <small class="text-success d-block text-truncate" style="font-size: 0.74rem;">
                          Exam Done &bull; Ready for POS Billing
                        </small>
                      </div>
                    </a>
                  </li>
                `;
              });
            }

            // Render Low Stock section if available
            if (lowStock.length > 0 && (rolePath === 'admin' || rolePath === 'saleslady')) {
              html += `
                <li class="dropdown-header text-uppercase text-danger fw-bold d-flex align-items-center justify-content-between px-3 pt-2 pb-1" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                  <span><i class="fas fa-boxes-stacked me-1"></i> Low Stock Alerts</span>
                  <span class="badge bg-danger-soft text-danger" style="background: rgba(239,68,68,0.12);">${data.low_stock_count || lowStock.length}</span>
                </li>
              `;
              lowStock.slice(0, 5).forEach(item => {
                const prodName = escapeHtml(item.name + (item.variant_name ? ' — ' + item.variant_name : ''));
                html += `
                  <li>
                    <a class="dropdown-item px-3 py-2 d-flex align-items-start gap-2 border-bottom border-light" href="${invLink}?highlight=${item.id}">
                      <div style="width: 30px; height: 30px; border-radius: 8px; background: rgba(239, 68, 68, 0.12); color: #EF4444; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.82rem; margin-top: 2px;">
                        <i class="fas fa-triangle-exclamation"></i>
                      </div>
                      <div class="flex-grow-1 text-truncate">
                        <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">
                          ${prodName}
                        </div>
                        <div style="font-size: 0.74rem; color: #EF4444; font-weight: 600;">
                          Only ${item.stock_quantity} left <span class="text-muted fw-normal">(Threshold &le; ${item.low_stock_alert})</span>
                        </div>
                      </div>
                    </a>
                  </li>
                `;
              });
            }

            // Render Appointments section if available
            if (appts.length > 0) {
              html += `
                <li class="dropdown-header text-uppercase text-primary fw-bold d-flex align-items-center justify-content-between px-3 pt-2 pb-1" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                  <span><i class="fas fa-calendar-check me-1"></i> Appointments</span>
                  <span class="badge bg-primary-soft text-primary" style="background: rgba(0,173,239,0.12);">${data.appt_count || appts.length}</span>
                </li>
              `;
              appts.slice(0, 5).forEach(appt => {
                html += `
                  <li>
                    <a class="dropdown-item px-3 py-2 d-flex align-items-start gap-2 border-bottom border-light" href="${apptsLink}?highlight=${appt.id}">
                      <div style="width: 30px; height: 30px; border-radius: 8px; background: rgba(0, 173, 239, 0.12); color: var(--clr-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.82rem; margin-top: 2px;">
                        <i class="fas fa-user-clock"></i>
                      </div>
                      <div class="flex-grow-1 text-truncate">
                        <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">
                          ${escapeHtml(appt.patient_name)}
                        </div>
                        <small class="text-muted d-block text-truncate" style="font-size: 0.74rem;">
                          ${formatDate(appt.appointment_date)} at ${formatTime(appt.appointment_time)}
                          ${appt.purpose ? `&bull; <span class="text-capitalize">${escapeHtml(appt.purpose.replace(/_/g, ' '))}</span>` : ''}
                        </small>
                      </div>
                    </a>
                  </li>
                `;
              });
            }
          }

          html += `
            </div>
            <li class="p-2 border-top d-flex flex-column gap-1" style="background: var(--bg-table-head);">
              <button type="button" class="dropdown-item text-center rounded py-2 fw-bold text-primary border-0 shadow-none" style="font-size: 0.82rem; background: var(--bg-card); cursor: pointer;" onclick="openModal('allNotificationsModal')">
                <i class="fas fa-bell me-1"></i> View Notifications
              </button>
              <div class="d-flex justify-content-between px-1 pt-1" style="font-size: 0.74rem;">
                <a class="text-muted text-decoration-none" href="${apptsLink}" title="Go to Appointments Queue">
                  <i class="fas fa-calendar-alt me-1"></i> Appointments
                </a>
                ${(rolePath === 'admin' || rolePath === 'saleslady') ? `
                  <a class="${lowStock.length > 0 ? 'text-danger fw-semibold' : 'text-muted'} text-decoration-none" href="${invLink}" title="Go to Inventory">
                    <i class="fas fa-boxes-stacked me-1"></i> Inventory ${lowStock.length > 0 ? `(${data.low_stock_count || lowStock.length})` : ''}
                  </a>
                ` : ''}
              </div>
            </li>
          `;

          dropdownMenu.innerHTML = html;
        }

        // Live update All Notifications modal body if open or rendered
        const modalBody = document.getElementById('allNotificationsModalBody');
        if (modalBody) {
          if (appts.length === 0 && lowStock.length === 0 && walkins.length === 0 && readyPos.length === 0) {
            modalBody.innerHTML = `
              <div class="text-center py-5 text-muted">
                <i class="fas fa-bell-slash mb-3" style="font-size: 2.5rem; opacity: 0.35;"></i>
                <h6 class="fw-bold">No active notifications</h6>
                <p class="small mb-0">All appointments are handled and inventory stock levels are healthy.</p>
              </div>
            `;
          } else {
            let mHtml = '';

            // Walk-in patients waiting section
            if (walkins.length > 0 && (rolePath === 'doctor' || rolePath === 'admin')) {
              mHtml += `
                <div class="mb-4">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-bold text-warning-emphasis text-uppercase small" style="letter-spacing: 0.5px;">
                      <i class="fas fa-bolt me-1 text-warning"></i> Walk-in Patients Waiting (${walkins.length})
                    </span>
                    <a href="${prefix}doctor/appointments.php" class="small text-warning-emphasis fw-semibold text-decoration-none">Open Calendar &rarr;</a>
                  </div>
                  <div class="list-group list-group-flush border rounded-3 overflow-hidden">
              `;
              walkins.forEach(walkin => {
                mHtml += `
                  <div class="list-group-item d-flex align-items-center justify-content-between py-2 px-3">
                    <div class="d-flex align-items-center gap-2 text-truncate me-2">
                      <div style="width: 28px; height: 28px; border-radius: 8px; background: rgba(245, 158, 11, 0.15); color: #D97706; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.8rem;">
                        <i class="fas fa-bolt"></i>
                      </div>
                      <div class="text-truncate">
                        <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">${escapeHtml(walkin.patient_name)}</div>
                        <small class="text-muted" style="font-size: 0.72rem;">Arrived at ${formatTime(walkin.appointment_time)} &bull; Waiting for Doctor Exam</small>
                      </div>
                    </div>
                    <a href="${prefix}doctor/appointments.php?highlight=${walkin.id}" class="btn btn-sm btn-outline-warning py-0 px-2" style="font-size: 0.74rem;">Examine</a>
                  </div>
                `;
              });
              mHtml += `</div></div>`;
            }

            // Ready for POS billing section
            if (readyPos.length > 0 && (rolePath === 'saleslady' || rolePath === 'admin')) {
              mHtml += `
                <div class="mb-4">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-bold text-success text-uppercase small" style="letter-spacing: 0.5px;">
                      <i class="fas fa-check-circle me-1 text-success"></i> Ready for POS Billing (${readyPos.length})
                    </span>
                    <a href="${prefix}saleslady/pos.php" class="small text-success fw-semibold text-decoration-none">Open POS &rarr;</a>
                  </div>
                  <div class="list-group list-group-flush border rounded-3 overflow-hidden">
              `;
              readyPos.forEach(item => {
                mHtml += `
                  <div class="list-group-item d-flex align-items-center justify-content-between py-2 px-3">
                    <div class="d-flex align-items-center gap-2 text-truncate me-2">
                      <div style="width: 28px; height: 28px; border-radius: 8px; background: rgba(16, 185, 129, 0.15); color: #10B981; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.8rem;">
                        <i class="fas fa-cash-register"></i>
                      </div>
                      <div class="text-truncate">
                        <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">${escapeHtml(item.patient_name)}</div>
                        <small class="text-muted" style="font-size: 0.72rem;">Doctor exam finished &bull; Prescription written</small>
                      </div>
                    </div>
                    <a href="${prefix}saleslady/pos.php?patient_id=${item.patient_id}&appt_id=${item.id}" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size: 0.74rem;">Bill Now</a>
                  </div>
                `;
              });
              mHtml += `</div></div>`;
            }
            if (lowStock.length > 0 && (rolePath === 'admin' || rolePath === 'saleslady')) {
              mHtml += `
                <div class="mb-4">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-bold text-danger text-uppercase small" style="letter-spacing: 0.5px;">
                      <i class="fas fa-boxes-stacked me-1"></i> Low Stock Alerts (${data.low_stock_count || lowStock.length})
                    </span>
                    <a href="${invLink}" class="small text-danger fw-semibold text-decoration-none">Manage Inventory &rarr;</a>
                  </div>
                  <div class="list-group list-group-flush border rounded-3 overflow-hidden">
              `;
              lowStock.forEach(item => {
                const prodName = escapeHtml(item.name + (item.variant_name ? ' — ' + item.variant_name : ''));
                mHtml += `
                  <div class="list-group-item d-flex align-items-center justify-content-between py-2 px-3">
                    <div class="d-flex align-items-center gap-2 text-truncate me-2">
                      <div style="width: 28px; height: 28px; border-radius: 8px; background: rgba(239, 68, 68, 0.12); color: #EF4444; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.8rem;">
                        <i class="fas fa-triangle-exclamation"></i>
                      </div>
                      <div class="text-truncate">
                        <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">${prodName}</div>
                        <small class="text-muted" style="font-size: 0.72rem;">Alert threshold: &le; ${item.low_stock_alert} units</small>
                      </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                      <span class="badge bg-danger-soft text-danger fw-bold px-2 py-1" style="background: rgba(239, 68, 68, 0.12); font-size: 0.75rem; white-space: nowrap;">
                        Only ${item.stock_quantity} left
                      </span>
                      <a href="${invLink}?highlight=${item.id}" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 0.74rem;">View</a>
                    </div>
                  </div>
                `;
              });
              mHtml += `</div></div>`;
            }

            if (appts.length > 0) {
              mHtml += `
                <div class="mb-2">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-bold text-primary text-uppercase small" style="letter-spacing: 0.5px;">
                      <i class="fas fa-calendar-check me-1"></i> Pending Appointments (${data.appt_count || appts.length})
                    </span>
                    <a href="${apptsLink}" class="small text-primary fw-semibold text-decoration-none">Open Queue &rarr;</a>
                  </div>
                  <div class="list-group list-group-flush border rounded-3 overflow-hidden">
              `;
              appts.forEach(appt => {
                mHtml += `
                  <div class="list-group-item d-flex align-items-center justify-content-between py-2 px-3">
                    <div class="d-flex align-items-center gap-2 text-truncate me-2">
                      <div style="width: 28px; height: 28px; border-radius: 8px; background: rgba(0, 173, 239, 0.12); color: var(--clr-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.8rem;">
                        <i class="fas fa-user-clock"></i>
                      </div>
                      <div class="text-truncate">
                        <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">${escapeHtml(appt.patient_name)}</div>
                        <small class="text-muted" style="font-size: 0.72rem;">
                          ${formatDate(appt.appointment_date)} at ${formatTime(appt.appointment_time)}
                          ${appt.purpose ? `&bull; <span class="text-capitalize">${escapeHtml(appt.purpose.replace(/_/g, ' '))}</span>` : ''}
                        </small>
                      </div>
                    </div>
                    <a href="${apptsLink}?highlight=${appt.id}" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.74rem;">View</a>
                  </div>
                `;
              });
              mHtml += `</div></div>`;
            }
            modalBody.innerHTML = mHtml;
          }
        }
      }
    } catch (e) {
      console.error('[Notification Poll] Error:', e);
    }
  }

  // Check immediately if we have a notification bell or staff header, then poll every 10 seconds
  if (notifBtn || document.getElementById('notifDropdownWrap')) {
    checkAppointments();
    setInterval(checkAppointments, 10000);
  }

  // Universal highlight handler for rows directed from notifications (e.g. inventory or appointments)
  function handleNotificationHighlight() {
    const urlParams = new URLSearchParams(window.location.search);
    const highlightId = urlParams.get('highlight');
    if (!highlightId) return;

    // Check for appointment row or inventory row
    const target = document.getElementById('appt-row-' + highlightId) ||
                   document.getElementById('row-prod-' + highlightId) ||
                   document.querySelector(`tr[data-variant-ids*="${highlightId}"]`);

    if (target) {
      target.classList.add('appt-highlight-pulse');
      setTimeout(() => {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }, 350);

      // If it's a product variant in inventory, switch the variant dropdown to that item
      const select = target.querySelector('.variant-picker-select');
      if (select) {
        for (let opt of select.options) {
          if (opt.value === highlightId) {
            select.value = highlightId;
            select.dispatchEvent(new Event('change'));
            break;
          }
        }
      }
    }
  }

  handleNotificationHighlight();
});