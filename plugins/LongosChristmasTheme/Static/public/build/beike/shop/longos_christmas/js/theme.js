(() => {
  'use strict';

  const ready = (callback) => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback, { once: true });
      return;
    }
    callback();
  };

  ready(() => {
    const header = document.querySelector('[data-christmas-header]');
    if (header) {
      let ticking = false;
      const syncHeader = () => {
        header.classList.toggle('is-scrolled', window.scrollY > 12);
        ticking = false;
      };
      window.addEventListener('scroll', () => {
        if (!ticking) {
          window.requestAnimationFrame(syncHeader);
          ticking = true;
        }
      }, { passive: true });
      syncHeader();
    }

    const desktopItems = Array.from(document.querySelectorAll('.christmas-menu__item.has-children'));
    const closeDesktopMenus = (except = null) => {
      desktopItems.forEach((item) => {
        if (item === except) return;
        item.classList.remove('is-open');
        item.querySelector(':scope > .christmas-menu__link')?.setAttribute('aria-expanded', 'false');
      });
    };

    desktopItems.forEach((item) => {
      const trigger = item.querySelector(':scope > .christmas-menu__link');
      const panel = item.querySelector(':scope > .christmas-mega-menu');
      if (!trigger || !panel) return;

      const open = () => {
        closeDesktopMenus(item);
        item.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
      };
      const close = () => {
        item.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
      };

      item.addEventListener('mouseenter', open);
      item.addEventListener('mouseleave', close);
      item.addEventListener('focusin', open);
      item.addEventListener('focusout', (event) => {
        if (!item.contains(event.relatedTarget)) close();
      });
      trigger.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          open();
          panel.querySelector('a, button')?.focus();
        }
      });
    });

    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      const activeItem = document.querySelector('.christmas-menu__item.is-open');
      if (!activeItem) return;
      const trigger = activeItem.querySelector(':scope > .christmas-menu__link');
      closeDesktopMenus();
      trigger?.focus();
    });
    document.addEventListener('pointerdown', (event) => {
      if (!event.target.closest('.christmas-menu__item.has-children')) closeDesktopMenus();
    });

    document.querySelectorAll('.christmas-footer .footer-toggle').forEach((toggle) => {
      const targetId = toggle.getAttribute('aria-controls');
      const panel = targetId ? document.getElementById(targetId) : null;
      if (!panel) return;
      toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        panel.classList.toggle('is-open', !expanded);
        toggle.querySelector('i')?.classList.toggle('bi-plus-lg', expanded);
        toggle.querySelector('i')?.classList.toggle('bi-dash-lg', !expanded);
      });
    });

    document.querySelectorAll('.christmas-mobile-menu__row button[aria-controls]').forEach((toggle) => {
      const panel = document.getElementById(toggle.getAttribute('aria-controls'));
      if (!panel) return;
      panel.addEventListener('shown.bs.collapse', () => toggle.setAttribute('aria-expanded', 'true'));
      panel.addEventListener('hidden.bs.collapse', () => toggle.setAttribute('aria-expanded', 'false'));
    });
  });
})();
