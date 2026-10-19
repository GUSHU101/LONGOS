@php
  $pollSeconds = max(3, min(20, (int) plugin_setting('longos_live_chat.poll_seconds', 5)));
  $backgroundPollSeconds = max(15, min(120, (int) plugin_setting('longos_live_chat.background_poll_seconds', 30)));
  $chatInitUrl = shop_route('live_chat.init');
  $chatApiUrl = substr($chatInitUrl, 0, -strlen('/init'));
  $position = plugin_setting('longos_live_chat.bubble_position', 'bottom_right');
  $position = in_array($position, ['bottom_left', 'bottom_right', 'top_left', 'top_right'], true) ? $position : 'bottom_right';
  $offsetX = max(0, min(96, (int) plugin_setting('longos_live_chat.bubble_offset_x', 20)));
  $offsetY = max(0, min(96, (int) plugin_setting('longos_live_chat.bubble_offset_y', 20)));
  $buttonSize = max(44, min(72, (int) plugin_setting('longos_live_chat.bubble_button_size', 56)));
  $panelWidth = max(300, min(480, (int) plugin_setting('longos_live_chat.panel_width', 380)));
  $panelHeight = max(360, min(760, (int) plugin_setting('longos_live_chat.panel_height', 570)));
@endphp
<style>
  #longos-live-chat { --llc-primary: #155eef; --llc-primary-hover: #0046c7; --llc-on-primary: #fff; --llc-focus: #155eef; position: fixed; z-index: 1080; font-family: inherit; color: #101828; }
  #longos-live-chat.position-bottom_right { right: calc(var(--llc-offset-x) + env(safe-area-inset-right)); bottom: calc(var(--llc-offset-y) + env(safe-area-inset-bottom)); }
  #longos-live-chat.position-bottom_left { left: calc(var(--llc-offset-x) + env(safe-area-inset-left)); bottom: calc(var(--llc-offset-y) + env(safe-area-inset-bottom)); }
  #longos-live-chat.position-top_right { right: calc(var(--llc-offset-x) + env(safe-area-inset-right)); top: calc(var(--llc-offset-y) + env(safe-area-inset-top)); display: flex; flex-direction: column-reverse; align-items: flex-end; }
  #longos-live-chat.position-top_left { left: calc(var(--llc-offset-x) + env(safe-area-inset-left)); top: calc(var(--llc-offset-y) + env(safe-area-inset-top)); display: flex; flex-direction: column-reverse; align-items: flex-start; }
  #longos-live-chat *, #longos-live-chat *::before, #longos-live-chat *::after { box-sizing: border-box; }
  #longos-live-chat button, #longos-live-chat textarea, #longos-live-chat input { font: inherit; }
  #longos-live-chat .llc-toggle { position: relative; display: grid; place-items: center; width: var(--llc-button-size); height: var(--llc-button-size); border: 0; border-radius: 50%; color: var(--llc-on-primary); background: var(--llc-primary); box-shadow: 0 10px 28px rgba(21,94,239,.32); font-size: 24px; cursor: pointer; transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease; }
  #longos-live-chat .llc-unread { position: absolute; top: -5px; right: -5px; display: none; min-width: 22px; height: 22px; padding: 0 6px; place-items: center; border: 2px solid #fff; border-radius: 999px; color: #fff; background: #d92d20; font-size: 11px; font-weight: 700; line-height: 18px; }
  #longos-live-chat .llc-unread.visible { display: grid; }
  #longos-live-chat .llc-toggle:hover { background: var(--llc-primary-hover); transform: translateY(-2px); box-shadow: 0 14px 34px rgba(21,94,239,.38); }
  #longos-live-chat .llc-toggle:focus-visible, #longos-live-chat button:focus-visible, #longos-live-chat input:focus-visible, #longos-live-chat textarea:focus-visible { outline: 3px solid var(--llc-focus); outline-offset: 3px; }
  #longos-live-chat .llc-panel { display: none; width: min(var(--llc-panel-width), calc(100vw - 28px)); height: min(var(--llc-panel-height), calc(100dvh - 106px)); margin-bottom: 12px; overflow: hidden; border: 1px solid #e4e7ec; border-radius: 18px; background: #fff; box-shadow: 0 24px 64px rgba(16,24,40,.24); }
  #longos-live-chat.position-top_left .llc-panel, #longos-live-chat.position-top_right .llc-panel { margin-top: 12px; margin-bottom: 0; }
  #longos-live-chat.open .llc-panel { display: flex; flex-direction: column; }
  #longos-live-chat .llc-panel > .llc-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; min-height: 0; padding: 15px 16px; border: 0 !important; color: var(--llc-on-primary) !important; background: var(--llc-primary) !important; box-shadow: none !important; position: static !important; z-index: auto !important; }
  #longos-live-chat .llc-head :is(div, small, i) { color: var(--llc-on-primary) !important; }
  #longos-live-chat .llc-head-title { min-width: 0; }
  #longos-live-chat .llc-head small { display: block; margin-top: 3px; opacity: 1; line-height: 1.4; }
  #longos-live-chat .llc-close { flex: 0 0 auto; display: grid; place-items: center; width: 44px; height: 44px; margin: -5px -6px -5px 0; border: 0; border-radius: 11px; color: var(--llc-on-primary); background: rgba(0,0,0,.16); cursor: pointer; }
  #longos-live-chat .llc-close:hover { background: rgba(0,0,0,.26); }
  #longos-live-chat .llc-close:focus-visible { outline-color: var(--llc-on-primary); box-shadow: 0 0 0 2px var(--llc-primary); }
  #longos-live-chat .llc-status { min-height: 25px; padding: 5px 14px; border-bottom: 1px solid #eaecf0; color: #475467; background: #f9fafb; font-size: 12px; }
  #longos-live-chat .llc-status.error { color: #b42318; background: #fef3f2; }
  #longos-live-chat .llc-profile { display: none; grid-template-columns: 1fr 1fr; gap: 8px; padding: 10px 12px; border-bottom: 1px solid #eaecf0; background: #fff; }
  #longos-live-chat .llc-profile input { width: 100%; min-width: 0; padding: 8px 10px; border: 1px solid #d0d5dd; border-radius: 9px; color: #101828; background: #fff; }
  #longos-live-chat .llc-history { display: none; align-self: center; margin: 10px auto 0; padding: 6px 12px; border: 1px solid #d0d5dd; border-radius: 999px; color: #344054; background: #fff; font-size: 12px; cursor: pointer; }
  #longos-live-chat .llc-body { flex: 1; min-height: 0; padding: 4px 14px 14px; overflow-y: auto; overscroll-behavior: contain; background: #f8fafc; scrollbar-gutter: stable; }
  #longos-live-chat .llc-message { max-width: 84%; margin: 10px 0 0; padding: 9px 12px; border-radius: 13px; line-height: 1.45; white-space: pre-wrap; overflow-wrap: anywhere; }
  #longos-live-chat .llc-message.visitor { margin-left: auto; color: var(--llc-on-primary); border-bottom-right-radius: 4px; background: var(--llc-primary); }
  #longos-live-chat .llc-message.admin, #longos-live-chat .llc-message.system { border: 1px solid #e4e7ec; border-bottom-left-radius: 4px; background: #fff; }
  #longos-live-chat .llc-meta { display: block; margin-top: 3px; font-size: 11px; opacity: .7; }
  #longos-live-chat .llc-message.visitor .llc-meta { color: var(--llc-on-primary); opacity: 1; }
  #longos-live-chat .llc-form { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; padding: 11px 12px; border-top: 1px solid #e4e7ec; background: #fff; }
  #longos-live-chat .llc-form textarea { width: 100%; min-width: 0; min-height: 44px; max-height: 112px; padding: 10px 11px; resize: none; border: 1px solid #d0d5dd; border-radius: 11px; color: #101828; background: #fff; }
  #longos-live-chat .llc-send { align-self: end; display: grid; place-items: center; min-width: 44px; height: 44px; border: 0; border-radius: 11px; color: var(--llc-on-primary); background: var(--llc-primary); cursor: pointer; }
  #longos-live-chat .llc-send:hover:not(:disabled) { background: var(--llc-primary-hover); }
  #longos-live-chat .llc-send:disabled { cursor: wait; opacity: .58; }
  #longos-live-chat .llc-sr-only { position: absolute !important; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
  @media (max-width: 576px) {
    #longos-live-chat.open { inset: 0 !important; display: block; padding: 0; }
    #longos-live-chat.open .llc-panel { width: 100%; height: 100dvh; max-width: none; margin: 0; border: 0; border-radius: 0; }
    #longos-live-chat.open .llc-toggle { display: none; }
    #longos-live-chat .llc-profile { grid-template-columns: 1fr; }
  }
  @media (prefers-reduced-motion: reduce) { #longos-live-chat .llc-toggle { transition: none; } }
</style>
<div id="longos-live-chat" class="position-{{ $position }}" style="--llc-offset-x: {{ $offsetX }}px; --llc-offset-y: {{ $offsetY }}px; --llc-button-size: {{ $buttonSize }}px; --llc-panel-width: {{ $panelWidth }}px; --llc-panel-height: {{ $panelHeight }}px;">
  <section id="longos-live-chat-panel" class="llc-panel" role="dialog" aria-modal="false" aria-labelledby="longos-live-chat-title" aria-hidden="true">
    <header class="llc-head">
      <div class="llc-head-title">
        <div id="longos-live-chat-title" class="fw-semibold"><i class="bi bi-chat-dots-fill me-2" aria-hidden="true"></i>{{ __('LongosLiveChat::chat.start') }}</div>
        <small>{{ __('LongosLiveChat::chat.start_hint') }}</small>
      </div>
      <button type="button" class="llc-close" aria-label="{{ __('LongosLiveChat::chat.close_window') }}"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </header>
    <div class="llc-status" role="status" aria-live="polite">{{ __('LongosLiveChat::chat.loading') }}</div>
    <div class="llc-profile">
      <label><span class="llc-sr-only">{{ __('LongosLiveChat::chat.name') }}</span><input class="llc-name" maxlength="120" autocomplete="name" placeholder="{{ __('LongosLiveChat::chat.name') }}"></label>
      <label><span class="llc-sr-only">{{ __('LongosLiveChat::chat.email') }}</span><input class="llc-email" maxlength="255" type="email" inputmode="email" autocomplete="email" placeholder="{{ __('LongosLiveChat::chat.email') }}"></label>
    </div>
    <button type="button" class="llc-history">{{ __('LongosLiveChat::chat.load_older') }}</button>
    <div class="llc-body" role="log" aria-live="polite" aria-relevant="additions text" aria-label="{{ __('LongosLiveChat::chat.title') }}"></div>
    <form class="llc-form">
      <label class="llc-sr-only" for="longos-live-chat-message">{{ __('LongosLiveChat::chat.placeholder') }}</label>
      <textarea id="longos-live-chat-message" maxlength="5000" rows="1" placeholder="{{ __('LongosLiveChat::chat.placeholder') }}"></textarea>
      <button type="submit" class="llc-send" aria-label="{{ __('LongosLiveChat::chat.send') }}"><i class="bi bi-send-fill" aria-hidden="true"></i></button>
    </form>
  </section>
  <button type="button" class="llc-toggle" aria-label="{{ __('LongosLiveChat::chat.start') }}" aria-controls="longos-live-chat-panel" aria-expanded="false"><i class="bi bi-chat-dots-fill" aria-hidden="true"></i><span class="llc-unread" aria-hidden="true"></span></button>
</div>
<script>
  (function ($) {
    const root = $('#longos-live-chat');
    if (!root.length || root.data('longos-chat-ready')) return;
    root.data('longos-chat-ready', true);

    const api = @json($chatApiUrl);
    const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';
    const basePollMilliseconds = {{ $pollSeconds * 1000 }};
    const backgroundPollMilliseconds = {{ $backgroundPollSeconds * 1000 }};
    const labels = {
      connected: @json(__('LongosLiveChat::chat.connected')),
      reconnecting: @json(__('LongosLiveChat::chat.reconnecting')),
      initFailed: @json(__('LongosLiveChat::chat.init_failed')),
      sendFailed: @json(__('LongosLiveChat::chat.send_failed')),
      loading: @json(__('LongosLiveChat::chat.loading')),
      online: @json(__('LongosLiveChat::chat.operator_online')),
      offline: @json(__('LongosLiveChat::chat.operator_offline')),
      unread: @json(__('LongosLiveChat::chat.unread_replies')),
      start: @json(__('LongosLiveChat::chat.start')),
    };
    let conversation = null;
    let lastMessageId = 0;
    let oldestMessageId = 0;
    let hasOlder = false;
    let timer = null;
    let loadingMessages = false;
    let loadingOlder = false;
    let initializing = null;
    let sending = false;
    let pollFailures = 0;
    let pendingMessage = null;
    let unreadReplies = 0;
    let operatorOnline = false;

    const apiRequest = (options) => $.ajax($.extend({
      headers: {'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json'},
      cache: false,
    }, options));
    const status = (message, error = false) => root.find('.llc-status').text(message || '').toggleClass('error', error);
    const errorMessage = (xhr, fallback) => xhr && xhr.responseJSON && (xhr.responseJSON.message || Object.values(xhr.responseJSON.errors || {}).flat()[0]) || fallback;
    const notify = (message) => {
      status(message, true);
      if (window.layer && typeof window.layer.msg === 'function') window.layer.msg(message);
    };
    const updatePresence = (online) => {
      operatorOnline = !!online;
      status(operatorOnline ? labels.online : labels.offline);
    };
    const updateUnreadBadge = () => {
      const badge = root.find('.llc-unread');
      badge.text(unreadReplies > 99 ? '99+' : String(unreadReplies)).toggleClass('visible', unreadReplies > 0);
      root.find('.llc-toggle').attr('aria-label', unreadReplies > 0 ? `${labels.start} - ${unreadReplies} ${labels.unread}` : labels.start);
    };
    const makeClientId = () => window.crypto && typeof window.crypto.randomUUID === 'function'
      ? window.crypto.randomUUID()
      : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
          const random = Math.random() * 16 | 0;
          return (char === 'x' ? random : (random & 0x3 | 0x8)).toString(16);
        });
    const messageElement = (message) => {
      const when = message.created_at ? new Date(message.created_at).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) : '';
      const sender = ['visitor', 'admin', 'system'].includes(message.sender_type) ? message.sender_type : 'system';
      const item = $('<div>', {class: `llc-message ${sender}`, 'data-message-id': Number(message.id)}).text(message.body || '');
      item.append($('<span>', {class: 'llc-meta'}).text(when));
      return item;
    };
    const rememberMessage = (message) => {
      const id = Number(message.id) || 0;
      if (!id || root.find(`.llc-message[data-message-id="${id}"]`).length) return false;
      lastMessageId = Math.max(lastMessageId, id);
      oldestMessageId = oldestMessageId ? Math.min(oldestMessageId, id) : id;
      return true;
    };
    const appendMessages = (messages, countUnread = false) => {
      let added = false;
      (messages || []).forEach((message) => {
        if (!rememberMessage(message)) return;
        root.find('.llc-body').append(messageElement(message));
        if (countUnread && message.sender_type === 'admin') unreadReplies += 1;
        added = true;
      });
      if (countUnread) updateUnreadBadge();
      return added;
    };
    const prependMessages = (messages) => {
      const body = root.find('.llc-body');
      const previousHeight = body.prop('scrollHeight');
      const fragment = document.createDocumentFragment();
      (messages || []).forEach((message) => {
        if (!rememberMessage(message)) return;
        fragment.appendChild(messageElement(message)[0]);
      });
      body.prepend(fragment);
      body.scrollTop(body.prop('scrollHeight') - previousHeight);
    };
    const scrollBottom = () => { const body = root.find('.llc-body'); body.scrollTop(body.prop('scrollHeight')); };
    const updateHistoryButton = () => root.find('.llc-history').toggle(!!hasOlder).prop('disabled', loadingOlder);
    const schedulePolling = () => {
      clearTimeout(timer);
      if (!conversation || document.hidden) return;
      const baseDelay = root.hasClass('open') ? basePollMilliseconds : backgroundPollMilliseconds;
      const delay = Math.min(120000, baseDelay * Math.pow(2, Math.min(pollFailures, 4)));
      timer = window.setTimeout(loadMessages, delay);
    };
    const loadMessages = () => {
      if (!conversation || loadingMessages || document.hidden) return;
      const visible = root.hasClass('open');
      loadingMessages = true;
      let hasMore = false;
      apiRequest({url: `${api}/${encodeURIComponent(conversation.id)}/messages`, method: 'GET', dataType: 'json', data: {after: lastMessageId, mark_read: visible ? 1 : 0}})
        .done((response) => {
          pollFailures = 0;
          updatePresence(response.operator_online);
          const nowVisible = root.hasClass('open');
          if (appendMessages(response.messages || [], !nowVisible) && nowVisible) scrollBottom();
          if (nowVisible && !visible) acknowledgeMessages();
          hasMore = !!response.has_more;
        })
        .fail(() => { pollFailures += 1; status(labels.reconnecting, true); })
        .always(() => {
          loadingMessages = false;
          if (hasMore) window.setTimeout(loadMessages, 0);
          else schedulePolling();
        });
    };
    const initialize = () => {
      if (conversation) return $.Deferred().resolve().promise();
      if (initializing) return initializing;
      status(labels.loading);
      initializing = apiRequest({url: `${api}/init`, method: 'POST', dataType: 'json'})
        .done((response) => {
          conversation = response.conversation;
          hasOlder = !!response.has_older;
          appendMessages(response.messages || []);
          root.find('.llc-profile').css('display', 'grid');
          updateHistoryButton();
          pollFailures = 0;
          updatePresence(response.operator_online);
          scrollBottom();
        })
        .fail((xhr) => notify(errorMessage(xhr, labels.initFailed)))
        .always(() => { initializing = null; });
      return initializing;
    };
    const acknowledgeMessages = () => {
      if (!conversation) return;
      unreadReplies = 0;
      updateUnreadBadge();
      apiRequest({url: `${api}/${encodeURIComponent(conversation.id)}/read`, method: 'POST', dataType: 'json'})
        .fail(() => { /* The next visible poll retries read acknowledgement. */ });
    };
    const setOpen = (open) => {
      root.toggleClass('open', open);
      root.find('.llc-toggle').attr('aria-expanded', open ? 'true' : 'false');
      root.find('.llc-panel').attr('aria-hidden', open ? 'false' : 'true');
      if (!open) { root.find('.llc-toggle').trigger('focus'); schedulePolling(); return; }
      initialize().done(() => {
        if (!root.hasClass('open')) return;
        acknowledgeMessages();
        loadMessages();
        root.find('.llc-form textarea').trigger('focus');
      });
    };

    root.find('.llc-toggle').on('click', () => setOpen(!root.hasClass('open')));
    root.find('.llc-close').on('click', () => setOpen(false));
    root.find('.llc-history').on('click', function () {
      if (!conversation || !oldestMessageId || loadingOlder) return;
      loadingOlder = true;
      updateHistoryButton();
      apiRequest({url: `${api}/${encodeURIComponent(conversation.id)}/messages`, method: 'GET', dataType: 'json', data: {before: oldestMessageId}})
        .done((response) => { hasOlder = !!response.has_older; prependMessages(response.messages || []); updatePresence(response.operator_online); })
        .fail((xhr) => notify(errorMessage(xhr, labels.reconnecting)))
        .always(() => { loadingOlder = false; updateHistoryButton(); });
    });
    root.find('.llc-form').on('submit', function (event) {
      event.preventDefault();
      const textarea = root.find('.llc-form textarea');
      const message = String(textarea.val() || '').trim();
      if (!message || !conversation || sending) return;
      if (!pendingMessage || pendingMessage.body !== message) pendingMessage = {id: makeClientId(), body: message};
      sending = true;
      textarea.prop('disabled', true);
      root.find('.llc-send').prop('disabled', true);
      apiRequest({
        url: `${api}/${encodeURIComponent(conversation.id)}/messages`,
        method: 'POST',
        dataType: 'json',
        data: {
          message,
          client_message_id: pendingMessage.id,
          name: root.find('.llc-name').val(),
          email: root.find('.llc-email').val(),
        },
      }).done((response) => {
        appendMessages([response.message]);
        textarea.val('');
        pendingMessage = null;
        updatePresence(operatorOnline);
        scrollBottom();
      }).fail((xhr) => notify(errorMessage(xhr, labels.sendFailed)))
        .always(() => {
          sending = false;
          textarea.prop('disabled', false);
          root.find('.llc-send').prop('disabled', false);
          textarea.trigger('focus');
        });
    });
    root.find('.llc-form textarea').on('keydown', function (event) {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        root.find('.llc-form').trigger('submit');
      }
    });
    $(document).on('keydown.longosLiveChat', function (event) {
      if (event.key === 'Escape' && root.hasClass('open')) setOpen(false);
    });
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) clearTimeout(timer);
      else if (conversation) loadMessages();
    });
  })(jQuery);
</script>
