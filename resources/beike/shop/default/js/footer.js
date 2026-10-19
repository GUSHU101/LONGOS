/* Mobile footer navigation and the accessible customer-support dialog. */
const footerMobileQuery = window.matchMedia('(max-width: 767.98px)');

function resetFooterAccordions() {
  $('.site-footer .footer-link-wrap').removeClass('active');
  $('.site-footer .footer-toggle').attr('aria-expanded', 'false');
}

$(document).on('click', '.site-footer .footer-toggle', function () {
  if (!footerMobileQuery.matches) return;
  const toggle = $(this);
  const section = toggle.closest('.footer-link-wrap');
  const expanded = toggle.attr('aria-expanded') === 'true';

  if (!expanded) {
    section.siblings('.footer-link-wrap').removeClass('active')
      .find('.footer-toggle').attr('aria-expanded', 'false');
  }
  toggle.attr('aria-expanded', String(!expanded));
  section.toggleClass('active', !expanded);
});

$(document).on('keydown', '.site-footer .footer-link-wrap', function (event) {
  if (!footerMobileQuery.matches || event.key !== 'Escape') return;
  const section = $(this);
  const toggle = section.find('.footer-toggle').first();
  if (toggle.attr('aria-expanded') !== 'true') return;
  section.removeClass('active');
  toggle.attr('aria-expanded', 'false').trigger('focus');
});

const handleFooterViewportChange = (event) => {
  if (!event.matches) resetFooterAccordions();
};

if (typeof footerMobileQuery.addEventListener === 'function') {
  footerMobileQuery.addEventListener('change', handleFooterViewportChange);
} else {
  footerMobileQuery.addListener(handleFooterViewportChange);
}

const initializeFooterContactDialog = () => {
  const dialog = document.querySelector('[data-footer-contact-dialog]');
  if (!(dialog instanceof HTMLElement)) return;
  if (dialog.dataset.contactInitialized === 'true') return;
  dialog.dataset.contactInitialized = 'true';

  let opener = null;
  const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const openButtons = document.querySelectorAll('[data-footer-contact-open]');

  const focusable = () => Array.from(dialog.querySelectorAll(focusableSelector))
    .filter((element) => !element.hasAttribute('hidden') && element.getAttribute('aria-hidden') !== 'true');

  const open = (button) => {
    opener = button;
    dialog.hidden = false;
    document.body.classList.add('longos-contact-open');
    openButtons.forEach((candidate) => candidate.setAttribute('aria-expanded', String(candidate === button)));
    window.requestAnimationFrame(() => focusable()[0]?.focus());
  };

  const close = () => {
    if (dialog.hidden) return;
    dialog.hidden = true;
    document.body.classList.remove('longos-contact-open');
    openButtons.forEach((button) => button.setAttribute('aria-expanded', 'false'));
    if (opener instanceof HTMLElement && opener.isConnected) opener.focus();
    opener = null;
  };

  openButtons.forEach((button) => button.addEventListener('click', () => open(button)));
  dialog.querySelectorAll('[data-footer-contact-close]').forEach((button) => button.addEventListener('click', close));
  dialog.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
      return;
    }
    if (event.key !== 'Tab') return;
    const items = focusable();
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeFooterContactDialog, { once: true });
} else {
  initializeFooterContactDialog();
}

window.LongosFooterContact = { initialize: initializeFooterContactDialog };
