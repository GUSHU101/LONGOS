/*
 * Shared campaign-offer behaviour.
 *
 * Section scripts returned by the visual-editor preview endpoint are inserted
 * as HTML and browsers intentionally do not execute them. Keeping this code
 * in the storefront bundle gives the public page and the editor iframe the
 * same live countdown implementation.
 */
(() => {
  'use strict';

  const selector = '[data-campaign-offer]';
  const pad = (value) => String(Math.max(0, value)).padStart(2, '0');

  const parseEndDate = (rawValue) => {
    const value = String(rawValue || '').trim();
    if (!value) return null;

    if (/^\d{10,13}$/.test(value)) {
      const timestamp = Number(value.length === 10 ? `${value}000` : value);
      const date = new Date(timestamp);
      return Number.isNaN(date.getTime()) ? null : date;
    }

    // Date inputs from the editor are sometimes saved without a timezone.
    // Parse that form as local store time instead of relying on inconsistent
    // browser handling of "YYYY-MM-DD HH:mm:ss".
    const localMatch = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T\s](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
    if (localMatch) {
      const [, year, month, day, hour = '0', minute = '0', second = '0'] = localMatch;
      const date = new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second));
      return Number.isNaN(date.getTime()) ? null : date;
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
  };

  const nextDailyReset = (now) => {
    const end = new Date(now);
    end.setHours(24, 0, 0, 0);
    return end;
  };

  const formatRemaining = (seconds) => {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainder = seconds % 60;

    return days > 0
      ? `${days}d ${pad(hours)}:${pad(minutes)}:${pad(remainder)}`
      : `${pad(hours)}:${pad(minutes)}:${pad(remainder)}`;
  };

  const setCopyHandler = (root) => {
    const button = root.querySelector('[data-copy-code]');
    if (!button || button.dataset.copyReady === 'true') return;

    button.dataset.copyReady = 'true';
    button.addEventListener('click', async () => {
      const code = button.dataset.copyCode || '';
      const label = button.querySelector('[data-copy-label]');
      const originalLabel = label?.textContent || '复制';

      let copied = false;
      try {
        if (!navigator.clipboard?.writeText) throw new Error('Clipboard API unavailable');
        await navigator.clipboard.writeText(code);
        copied = true;
      } catch (_) {
        const input = document.createElement('input');
        input.value = code;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.append(input);
        input.select();
        copied = document.execCommand('copy');
        input.remove();
      }

      if (label) label.textContent = copied ? '已复制' : '复制失败';
      window.setTimeout(() => { if (label) label.textContent = originalLabel; }, 1600);
    });
  };

  const initialise = (root) => {
    if (!(root instanceof Element)) return;

    const output = root.querySelector('[data-campaign-time]');
    const countdown = root.querySelector('[data-campaign-countdown]');
    const status = root.querySelector('[data-campaign-status]');
    const statusLabel = root.querySelector('[data-campaign-status-label]');
    const mode = root.dataset.timerMode === 'fixed' ? 'fixed' : root.dataset.timerMode === 'daily' ? 'daily' : 'none';
    const configuredEnd = mode === 'fixed' ? parseEndDate(root.dataset.endsAt) : null;
    setCopyHandler(root);

    if (!countdown || !output || mode === 'none' || (mode === 'fixed' && !configuredEnd)) {
      if (mode === 'fixed' && !configuredEnd) countdown?.setAttribute('hidden', '');
      return;
    }

    if (root._campaignOfferTimer) window.clearInterval(root._campaignOfferTimer);

    const update = () => {
      if (!root.isConnected) {
        window.clearInterval(root._campaignOfferTimer);
        root._campaignOfferTimer = null;
        return true;
      }
      const now = new Date();
      const end = mode === 'daily' ? nextDailyReset(now) : configuredEnd;
      const seconds = Math.max(0, Math.floor((end.getTime() - now.getTime()) / 1000));
      output.textContent = formatRemaining(seconds);
      output.dateTime = `PT${seconds}S`;

      const expired = mode === 'fixed' && seconds === 0;
      root.classList.toggle('is-expired', expired);
      countdown.dataset.state = expired ? 'expired' : 'running';
      if (statusLabel) {
        statusLabel.textContent = expired
          ? (status?.dataset.expiredLabel || '活动已结束')
          : (status?.dataset.activeLabel || '进行中');
      }
      status?.setAttribute('data-state', expired ? 'expired' : 'active');
      const copyButton = root.querySelector('[data-copy-code]');
      if (copyButton) {
        copyButton.disabled = expired;
        copyButton.setAttribute('aria-disabled', expired ? 'true' : 'false');
      }

      if (expired) {
        window.clearInterval(root._campaignOfferTimer);
        root._campaignOfferTimer = null;
      }

      return expired;
    };

    if (!update()) root._campaignOfferTimer = window.setInterval(update, 1000);
  };

  const initialiseAll = (context = document) => {
    const roots = [];
    if (context instanceof Element && context.matches(selector)) roots.push(context);
    if (context.querySelectorAll) roots.push(...context.querySelectorAll(selector));
    roots.forEach(initialise);
  };

  window.LongosCampaignOffer = { init: initialise, initAll: initialiseAll };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initialiseAll(), { once: true });
  else initialiseAll();
})();
