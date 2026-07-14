/*
 * Tan90 Master Data & Configuration — Laravel module UI shell.
 * Pure presentation (sidebar/theme toggle, toasts, confirm dialogs). All
 * data logic (CRUD, approvals, audit) is server-rendered Blade + form posts,
 * unlike the standalone demo where app.js also owned the data layer.
 */
(function () {
  'use strict';

  var UI_KEY = 'tan90_master_data_ui_v1';

  function loadUi() {
    try {
      return Object.assign({ theme: 'dark' }, JSON.parse(localStorage.getItem(UI_KEY) || '{}'));
    } catch (_) {
      return { theme: 'dark' };
    }
  }

  function saveUi(ui) {
    localStorage.setItem(UI_KEY, JSON.stringify(ui));
  }

  var ui = loadUi();
  document.body.classList.toggle('light-theme', ui.theme === 'light');

  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-action="toggle-sidebar"]');
    if (toggle) {
      document.querySelector('.sidebar')?.classList.toggle('open');
      document.querySelector('.mobile-overlay')?.classList.toggle('hidden');
    }

    var closeSidebar = event.target.closest('[data-action="close-sidebar"]');
    if (closeSidebar) {
      document.querySelector('.sidebar')?.classList.remove('open');
    }

    var themeToggle = event.target.closest('[data-action="toggle-theme"]');
    if (themeToggle) {
      ui.theme = ui.theme === 'dark' ? 'light' : 'dark';
      saveUi(ui);
      document.body.classList.toggle('light-theme', ui.theme === 'light');
    }

    var confirmTrigger = event.target.closest('[data-confirm]');
    if (confirmTrigger && !window.confirm(confirmTrigger.getAttribute('data-confirm'))) {
      event.preventDefault();
      event.stopPropagation();
    }
  });

  // Auto-dismiss server-rendered flash toasts after a few seconds.
  document.querySelectorAll('.toast[data-autohide]').forEach(function (toast) {
    setTimeout(function () {
      toast.remove();
    }, 4200);
  });
})();
