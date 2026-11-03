(() => {
  'use strict';
  const root = document.getElementById('longos-global-translate');
  if (!root || root.dataset.ready) return;
  root.dataset.ready = '1';
  const toggle = root.querySelector('.lgt-widget__toggle');
  const close = root.querySelector('.lgt-widget__close');
  const panel = root.querySelector('.lgt-widget__panel');
  const options = root.querySelector('.lgt-widget__options');
  const status = root.querySelector('.lgt-widget__status');
  const current = root.querySelector('.lgt-widget__current');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let config;
  const label = locale => {
    try { return typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames([document.documentElement.lang || 'en'], {type: 'language'}).of(locale) || locale : locale; }
    catch (_) { return locale; }
  };
  const setOpen = open => { root.classList.toggle('is-open', open); toggle.setAttribute('aria-expanded', String(open)); panel.setAttribute('aria-hidden', String(!open)); if (open) options.querySelector('button')?.focus(); else toggle.focus(); };
  const request = (url, init = {}) => fetch(url, {credentials:'same-origin', headers:{Accept:'application/json','X-CSRF-TOKEN':csrf,...(init.headers || {})}, ...init}).then(async response => { const body = await response.json().catch(() => ({})); if (!response.ok) throw new Error(body.message || 'Request failed'); return body; });
  const contentKey = async (kind, entity, field) => {
    if (!window.crypto?.subtle) return null;
    const bytes = new TextEncoder().encode(`lgt-content-v2:${kind}:${entity}:${field}:en`);
    const hash = await crypto.subtle.digest('SHA-256', bytes);
    return [...new Uint8Array(hash)].map(byte => byte.toString(16).padStart(2,'0')).join('');
  };
  const replaceKnownNodes = async locale => {
    if (!config) return;
    // Bounded by design: a storefront can translate its primary content
    // regions without turning this into an unbounded DOM traversal.
    const roots = [...document.querySelectorAll('[data-lgt-root]')].slice(0, 30);
    if (locale === config.source_locale) {
      roots.forEach(region => region.querySelectorAll('[data-lgt-field]').forEach(node => { if (node.dataset.lgtOriginal) node.textContent = node.dataset.lgtOriginal; }));
      return;
    }
    for (const region of roots) {
      const kind = region.dataset.lgtKind, entity = Number(region.dataset.lgtEntity);
      if (!['product','category','page','builder'].includes(kind) || !Number.isInteger(entity) || entity < 1) continue;
      const dictionary = await request(config.dictionary_url.replace('__kind__', encodeURIComponent(kind)).replace('__entity__', String(entity)) + `?locale=${encodeURIComponent(locale)}`);
      for (const node of region.querySelectorAll('[data-lgt-field]')) {
        node.dataset.lgtOriginal ||= node.textContent;
        const key = await contentKey(kind, entity, node.dataset.lgtField);
        if (key && typeof dictionary.items?.[key] === 'string') node.textContent = dictionary.items[key];
      }
    }
  };
  const render = () => { options.replaceChildren(); const all = ['auto', ...new Set([config.source_locale, ...(config.locales || [])])]; all.forEach(locale => { const button = document.createElement('button'); button.type='button'; button.className='lgt-widget__option'; button.dataset.locale=locale; button.setAttribute('aria-pressed', String(locale === config.locale)); button.innerHTML = `<span></span><small></small>`; button.firstChild.textContent=locale === 'auto' ? 'Automatic' : label(locale); button.lastChild.textContent=locale === 'auto' ? 'AUTO' : locale.toUpperCase(); button.addEventListener('click', async () => { status.textContent=''; button.disabled=true; try { const saved=await request(root.dataset.localeUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({locale})}); if (locale === 'auto') { window.location.reload(); return; } config.locale=saved.locale; current.textContent=saved.locale.toUpperCase(); render(); await replaceKnownNodes(saved.locale); status.textContent='Language updated.'; } catch (error) { status.textContent=error.message; } finally { button.disabled=false; } }); options.append(button); }); current.textContent=(config.locale || config.source_locale).toUpperCase(); };
  toggle.addEventListener('click', () => setOpen(!root.classList.contains('is-open'))); close.addEventListener('click', () => setOpen(false)); document.addEventListener('keydown', event => { if (event.key === 'Escape' && root.classList.contains('is-open')) setOpen(false); });
  request(root.dataset.configUrl).then(data => { if (!data.enabled) return; config=data; render(); replaceKnownNodes(config.locale).catch(() => { /* Translation display is optional and must not affect shopping. */ }); }).catch(() => root.remove());
})();
