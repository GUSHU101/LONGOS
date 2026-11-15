@extends('admin::layouts.master')

@section('title', __('LongosLiveChat::chat.title'))

@section('content-area-class', 'w-max-1600')

@php
  $quickReplies = collect(preg_split('/\R/u', (string) plugin_setting('longos_live_chat.quick_replies', '')) ?: [])
    ->map(static fn ($reply) => mb_substr(trim((string) $reply), 0, 500))
    ->filter()
    ->take(12)
    ->values();
@endphp

@section('content')
  <style>
    #longos-live-chat-admin { min-height: min(760px, calc(100dvh - 145px)); border: 1px solid #e4e7ec; border-radius: 18px; box-shadow: 0 10px 30px rgba(16,24,40,.05); }
    #longos-live-chat-admin [v-cloak] { display: none; }
    #longos-live-chat-admin .llc-admin-sidebar { display: flex; flex-direction: column; min-height: 650px; border-right: 1px solid #e4e7ec; background: #fff; }
    #longos-live-chat-admin .llc-admin-list { flex: 1; min-height: 0; max-height: min(650px, calc(100dvh - 270px)); overflow-y: auto; }
    #longos-live-chat-admin .llc-conversation { transition: background-color .15s ease; }
    #longos-live-chat-admin .llc-conversation:hover, #longos-live-chat-admin .llc-conversation.active { background: #f2f4f7 !important; }
    #longos-live-chat-admin .llc-preview { display: block; overflow: hidden; color: #667085; text-overflow: ellipsis; white-space: nowrap; }
    #longos-live-chat-admin .llc-chat { min-height: 650px; }
    #longos-live-chat-admin .llc-messages { min-height: 0; overscroll-behavior: contain; }
    #longos-live-chat-admin .llc-admin-message { max-width: min(78%, 620px); overflow-wrap: anywhere; white-space: pre-wrap; }
    #longos-live-chat-admin .llc-history { display: block; margin: 0 auto 14px; border-radius: 999px; }
    #longos-live-chat-admin textarea { min-height: 48px; max-height: 130px; resize: vertical; }
    #longos-live-chat-admin .llc-state { min-height: 24px; font-size: 12px; }
    #longos-live-chat-admin .llc-health { min-height: 520px; }
    #longos-live-chat-admin .llc-message-meta { display: flex; justify-content: flex-end; gap: 5px; }
    #longos-live-chat-admin .llc-context { min-height: 650px; border-left: 1px solid #e4e7ec; background: #fff; }
    #longos-live-chat-admin .llc-presence-dot { display: inline-block; width: 8px; height: 8px; margin-right: 6px; border-radius: 50%; background: #98a2b3; }
    #longos-live-chat-admin .llc-presence-dot.online { background: #12b76a; box-shadow: 0 0 0 3px rgba(18,183,106,.12); }
    #longos-live-chat-admin .llc-admin-typing { display: flex; align-items: center; gap: 7px; width: max-content; margin: 0 0 12px; padding: 8px 12px; border: 1px solid #e4e7ec; border-radius: 13px; color: #667085; background: #fff; font-size: 12px; }
    #longos-live-chat-admin .llc-quick-replies { display: flex; gap: 6px; padding: 8px 12px 0; overflow-x: auto; scrollbar-width: thin; }
    #longos-live-chat-admin .llc-quick-reply { flex: 0 0 auto; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    #longos-live-chat-admin .llc-typing-dots { display: inline-flex; gap: 3px; }
    #longos-live-chat-admin .llc-typing-dots span { width: 5px; height: 5px; border-radius: 50%; background: currentColor; animation: llc-typing 1.2s infinite ease-in-out; }
    #longos-live-chat-admin .llc-typing-dots span:nth-child(2) { animation-delay: .16s; }
    #longos-live-chat-admin .llc-typing-dots span:nth-child(3) { animation-delay: .32s; }
    #longos-live-chat-admin .llc-spin { animation: llc-spin .8s linear infinite; }
    @keyframes llc-spin { to { transform: rotate(360deg); } }
    @keyframes llc-typing { 0%, 60%, 100% { transform: translateY(0); opacity: .45; } 30% { transform: translateY(-3px); opacity: 1; } }
    @media (max-width: 991.98px) {
      #longos-live-chat-admin .llc-admin-sidebar { min-height: 0; border-right: 0; border-bottom: 1px solid #e4e7ec; }
      #longos-live-chat-admin .llc-admin-list { max-height: 300px; }
      #longos-live-chat-admin .llc-chat { min-height: 560px; }
      #longos-live-chat-admin .llc-admin-message { max-width: 90%; }
    }
    @media (prefers-reduced-motion: reduce) { #longos-live-chat-admin .llc-typing-dots span, #longos-live-chat-admin .llc-spin { animation: none; } }
  </style>
  <div id="longos-live-chat-admin" v-cloak class="card overflow-hidden">
    <div v-if="sessionExpired" class="llc-health d-flex flex-column align-items-center justify-content-center p-5 text-center">
      <i class="bi bi-shield-lock fs-1 text-warning mb-3" aria-hidden="true"></i>
      <h5>{{ __('LongosLiveChat::chat.session_expired_title') }}</h5>
      <p class="text-secondary mb-3">{{ __('LongosLiveChat::chat.session_expired') }}</p>
      <button type="button" class="btn btn-primary" @click="reloadPage">{{ __('LongosLiveChat::chat.reload') }}</button>
    </div>
    <div v-else-if="healthError" class="llc-health d-flex flex-column align-items-center justify-content-center p-5 text-center">
      <i class="bi bi-exclamation-triangle fs-1 text-warning mb-3" aria-hidden="true"></i>
      <h5>{{ __('LongosLiveChat::chat.setup_title') }}</h5>
      <p class="text-secondary mb-3">@{{ healthError }}</p>
      <button type="button" class="btn btn-primary" @click="checkHealth" :disabled="checkingHealth">@{{ checkingHealth ? labels.loading : labels.retry }}</button>
    </div>
    <div v-else class="row g-0">
      <aside class="col-lg-4 col-xl-3 llc-admin-sidebar">
        <div class="p-3 border-bottom">
          <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <h5 class="mb-0 d-flex align-items-center gap-2">{{ __('LongosLiveChat::chat.title') }}<span v-if="unreadTotal" class="badge text-bg-danger rounded-pill">@{{ unreadTotal > 99 ? '99+' : unreadTotal }}</span></h5>
            <div class="d-flex gap-1">
              <button v-if="notificationsSupported" type="button" class="btn btn-light btn-sm" @click="enableNotifications" :disabled="notificationPermission === 'denied'" :title="notificationPermission === 'granted' ? labels.notificationsEnabled : labels.enableNotifications" :aria-label="notificationPermission === 'granted' ? labels.notificationsEnabled : labels.enableNotifications"><i class="bi" :class="notificationPermission === 'granted' ? 'bi-bell-fill text-primary' : 'bi-bell'" aria-hidden="true"></i></button>
              <button type="button" class="btn btn-light btn-sm" @click="loadConversations(true)" :disabled="loadingConversations" title="{{ __('LongosLiveChat::chat.refresh') }}" aria-label="{{ __('LongosLiveChat::chat.refresh') }}"><i class="bi bi-arrow-clockwise" :class="{'d-inline-block llc-spin': loadingConversations}" aria-hidden="true"></i></button>
            </div>
          </div>
          <div class="input-group input-group-sm mb-2">
            <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
            <input v-model.trim="search" @input="scheduleSearch" @keyup.enter="searchNow" class="form-control" maxlength="100" placeholder="{{ __('LongosLiveChat::chat.search') }}" aria-label="{{ __('LongosLiveChat::chat.search') }}">
          </div>
          <select v-model="statusFilter" @change="loadConversations(true)" class="form-select form-select-sm" aria-label="{{ __('LongosLiveChat::chat.title') }}">
            <option value="open">{{ __('LongosLiveChat::chat.open') }}</option>
            <option value="closed">{{ __('LongosLiveChat::chat.closed') }}</option>
            <option value="all">{{ __('LongosLiveChat::chat.all') }}</option>
          </select>
          <div class="llc-state mt-2" :class="listError ? 'text-danger' : 'text-secondary'" role="status">@{{ listError || (loadingConversations ? labels.loading : '') }}</div>
        </div>
        <div class="llc-admin-list">
          <button v-for="item in conversations" :key="item.id" @click="selectConversation(item)" type="button" class="llc-conversation w-100 p-3 border-0 border-bottom text-start bg-white" :class="{'active': selected && selected.id === item.id}">
            <div class="d-flex justify-content-between align-items-start gap-2"><strong class="text-truncate">@{{ item.name }}</strong><span v-if="item.unread_count" class="badge text-bg-danger rounded-pill">@{{ item.unread_count > 99 ? '99+' : item.unread_count }}</span></div>
            <small class="d-block text-secondary text-truncate">@{{ item.email || statusLabel(item.status) }}</small>
            <small v-if="item.preview" class="llc-preview mt-1">@{{ item.preview }}</small>
          </button>
          <div v-if="hasMoreConversations" class="p-3 text-center border-bottom"><button type="button" class="btn btn-sm btn-light border" @click="loadMoreConversations" :disabled="loadingMore">@{{ loadingMore ? labels.loading : labels.loadMore }}</button></div>
          <div v-if="!loadingConversations && !conversations.length" class="p-4 text-center text-secondary">{{ __('LongosLiveChat::chat.empty') }}</div>
        </div>
      </aside>
      <main class="col-lg-8 d-flex flex-column llc-chat" :class="selected ? 'col-xl-6' : 'col-xl-9'">
        <template v-if="selected">
          <div class="p-3 border-bottom d-flex justify-content-between align-items-center gap-3">
            <div class="min-w-0"><strong class="d-block text-truncate">@{{ selected.name }}</strong><small class="d-block text-secondary text-truncate">@{{ selected.email || statusLabel(selected.status) }}</small></div>
            <button v-if="selected.status === 'open'" type="button" @click="closeConversation" class="btn btn-outline-secondary btn-sm" :disabled="changingStatus">{{ __('LongosLiveChat::chat.close') }}</button>
            <button v-else type="button" @click="reopenConversation" class="btn btn-outline-primary btn-sm" :disabled="changingStatus">{{ __('LongosLiveChat::chat.reopen') }}</button>
          </div>
          <div ref="messages" class="llc-messages flex-grow-1 p-3 bg-light overflow-auto" role="log" aria-live="polite" aria-relevant="additions text">
            <button v-if="hasOlder" type="button" class="llc-history btn btn-sm btn-light border" @click="loadOlder" :disabled="loadingOlder">@{{ loadingOlder ? labels.loading : labels.loadOlder }}</button>
            <div v-if="loadingMessages && !messages.length" class="text-center text-secondary py-5">{{ __('LongosLiveChat::chat.loading') }}</div>
            <div v-else-if="!messages.length" class="text-center text-secondary py-5">{{ __('LongosLiveChat::chat.no_messages') }}</div>
            <div v-for="message in messages" :key="message.id" class="mb-3" :class="message.sender_type === 'admin' ? 'text-end' : ''">
              <span class="llc-admin-message d-inline-block text-start rounded-3 px-3 py-2" :class="message.sender_type === 'admin' ? 'text-bg-primary' : 'bg-white border'">
                <span class="d-block">@{{ message.body }}</span>
                <small class="llc-message-meta opacity-75"><span>@{{ formatTime(message.created_at) }}</span><span v-if="message.sender_type === 'admin'">· @{{ isReadByVisitor(message) ? labels.read : labels.sent }}</span></small>
              </span>
            </div>
            <div v-if="selected.visitor_typing" class="llc-admin-typing" role="status"><span>@{{ labels.visitorTyping }}</span><span class="llc-typing-dots" aria-hidden="true"><span></span><span></span><span></span></span></div>
          </div>
          <div class="llc-state px-3 pt-2" :class="chatError ? 'text-danger' : 'text-secondary'" role="status">@{{ chatError }}</div>
          <div v-if="quickReplies.length" class="llc-quick-replies" :aria-label="labels.quickReplies">
            <button v-for="reply in quickReplies" :key="reply" type="button" class="llc-quick-reply btn btn-sm btn-light border" :title="reply" @click="applyQuickReply(reply)">@{{ reply }}</button>
          </div>
          <form @submit.prevent="send" class="p-3 pt-2 border-top d-flex gap-2 align-items-end">
            <label class="visually-hidden" for="longos-live-chat-admin-message">{{ __('LongosLiveChat::chat.placeholder') }}</label>
            <textarea id="longos-live-chat-admin-message" v-model="draft" @input="handleDraftInput" @keydown.enter.exact.prevent="send" class="form-control" rows="2" maxlength="5000" :placeholder="labels.placeholder"></textarea>
            <button class="btn btn-primary px-4" :disabled="sending || !draft.trim()" :aria-busy="sending ? 'true' : 'false'">@{{ sending ? labels.sending : (pendingMessage && chatError ? labels.retrySend : labels.send) }}</button>
          </form>
        </template>
        <div v-else class="h-100 d-flex flex-column align-items-center justify-content-center text-secondary p-4 text-center"><i class="bi bi-chat-square-text fs-1 mb-2" aria-hidden="true"></i>{{ __('LongosLiveChat::chat.select') }}</div>
      </main>
      <aside v-if="selected" class="col-xl-3 d-none d-xl-flex flex-column llc-context p-4">
        <h6 class="mb-4">{{ __('LongosLiveChat::chat.context_title') }}</h6>
        <div class="mb-4">
          <div class="text-secondary small mb-1">{{ __('LongosLiveChat::chat.connection_status') }}</div>
          <div><span class="llc-presence-dot" :class="{online: selected.visitor_online}"></span>@{{ selected.visitor_online ? labels.visitorOnline : labels.visitorOffline }}</div>
        </div>
        <div class="mb-3"><div class="text-secondary small mb-1">{{ __('LongosLiveChat::chat.name_label') }}</div><div class="text-break">@{{ selected.name }}</div></div>
        <div class="mb-3"><div class="text-secondary small mb-1">{{ __('LongosLiveChat::chat.email_label') }}</div><a v-if="selected.email" class="text-break" :href="'mailto:' + selected.email">@{{ selected.email }}</a><span v-else class="text-secondary">—</span></div>
        <div class="mb-3"><div class="text-secondary small mb-1">{{ __('LongosLiveChat::chat.last_activity') }}</div><div>@{{ formatTime(selected.last_message_at) || '—' }}</div></div>
        <div class="mb-3">
          <div class="text-secondary small mb-1">{{ __('LongosLiveChat::chat.conversation_id') }}</div>
          <div class="d-flex align-items-start gap-2"><code class="small text-break flex-grow-1">@{{ selected.id }}</code><button type="button" class="btn btn-sm btn-light border" @click="copyConversationId" :aria-label="labels.copy">@{{ copied ? labels.copied : labels.copy }}</button></div>
        </div>
      </aside>
    </div>
  </div>
@endsection

@push('footer')
<script>
  new Vue({
    el: '#longos-live-chat-admin',
    data: {
      conversations: [], selected: null, messages: [], draft: '', quickReplies: @json($quickReplies), statusFilter: 'open', search: '', timer: null, presenceTimer: null, searchTimer: null, copyTimer: null,
      lastMessageId: 0, oldestMessageId: 0, hasOlder: false, sending: false, changingStatus: false, loadingOlder: false,
      loadingMessages: false, loadingConversations: false, selectionVersion: 0, conversationRequestVersion: 0,
      messageRequestVersion: 0, listError: '', chatError: '', pendingMessage: null, loadingMore: false, pollFailures: 0,
      hasMoreConversations: false, nextConversationPage: null,
      checkingHealth: true, healthError: '', sessionExpired: false, copied: false, lastTypingAt: 0, originalTitle: '',
      notificationsSupported: 'Notification' in window, notificationPermission: 'Notification' in window ? Notification.permission : 'unsupported',
      pollMilliseconds: {{ max(3, min(20, (int) plugin_setting('longos_live_chat.poll_seconds', 5))) * 1000 }},
      backgroundPollMilliseconds: {{ max(15, min(120, (int) plugin_setting('longos_live_chat.background_poll_seconds', 30))) * 1000 }},
      labels: {
        loading: @json(__('LongosLiveChat::chat.loading')),
        sending: @json(__('LongosLiveChat::chat.sending')),
        loadOlder: @json(__('LongosLiveChat::chat.load_older')),
        placeholder: @json(__('LongosLiveChat::chat.placeholder')),
        send: @json(__('LongosLiveChat::chat.send')),
        open: @json(__('LongosLiveChat::chat.open')),
        closed: @json(__('LongosLiveChat::chat.closed')),
        sendFailed: @json(__('LongosLiveChat::chat.send_failed')),
        reconnecting: @json(__('LongosLiveChat::chat.reconnecting')),
        loadMore: @json(__('LongosLiveChat::chat.load_more')),
        retrySend: @json(__('LongosLiveChat::chat.retry_send')),
        retry: @json(__('LongosLiveChat::chat.retry')),
        sent: @json(__('LongosLiveChat::chat.sent')),
        read: @json(__('LongosLiveChat::chat.read')),
        visitorTyping: @json(__('LongosLiveChat::chat.visitor_typing')),
        visitorOnline: @json(__('LongosLiveChat::chat.visitor_online')),
        visitorOffline: @json(__('LongosLiveChat::chat.visitor_offline')),
        copy: @json(__('LongosLiveChat::chat.copy')),
        copied: @json(__('LongosLiveChat::chat.copied')),
        enableNotifications: @json(__('LongosLiveChat::chat.enable_notifications')),
        notificationsEnabled: @json(__('LongosLiveChat::chat.notifications_enabled')),
        newMessage: @json(__('LongosLiveChat::chat.new_message')),
        quickReplies: @json(__('LongosLiveChat::chat.quick_replies')),
      },
    },
    computed: {
      unreadTotal() { return this.conversations.reduce((total, item) => total + (Number(item.unread_count) || 0), 0); },
    },
    mounted() {
      this._conversationRequest = null;
      this._messageRequest = null;
      this.originalTitle = document.title;
      this.checkHealth();
      document.addEventListener('visibilitychange', this.handleVisibilityChange);
    },
    beforeDestroy() {
      window.clearTimeout(this.timer);
      window.clearInterval(this.presenceTimer);
      window.clearTimeout(this.searchTimer);
      window.clearTimeout(this.copyTimer);
      this._conversationRequest?.abort();
      this._messageRequest?.abort();
      this._healthRequest?.abort();
      document.title = this.originalTitle || document.title;
      document.removeEventListener('visibilitychange', this.handleVisibilityChange);
    },
    methods: {
      errorMessage(xhr, fallback) { const errors = xhr && xhr.responseJSON && xhr.responseJSON.errors; return xhr && xhr.responseJSON && xhr.responseJSON.message || (errors ? Object.values(errors).flat()[0] : '') || fallback; },
      makeClientId() { return window.crypto && typeof window.crypto.randomUUID === 'function' ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => { const random = Math.random() * 16 | 0; return (char === 'x' ? random : (random & 0x3 | 0x8)).toString(16); }); },
      statusLabel(status) { return status === 'closed' ? this.labels.closed : this.labels.open; },
      draftKey(id) { return 'longos-live-chat-admin-draft:' + String(id || ''); },
      persistDraft() {
        if (!this.selected) return;
        try { window.sessionStorage.setItem(this.draftKey(this.selected.id), this.draft); } catch (error) {}
      },
      handleDraftInput(event) {
        this.persistDraft();
        if (event && event.target) {
          event.target.style.height = 'auto';
          event.target.style.height = Math.min(event.target.scrollHeight, 130) + 'px';
        }
        this.signalTyping();
      },
      applyQuickReply(reply) {
        const value = String(reply || '').trim();
        if (!value) return;
        this.draft = this.draft.trim() ? this.draft.replace(/\s+$/, '') + '\n' + value : value;
        this.persistDraft();
        this.$nextTick(() => document.getElementById('longos-live-chat-admin-message')?.focus());
      },
      signalTyping() {
        if (!this.selected || !this.draft.trim()) return;
        const now = Date.now();
        if (now - this.lastTypingAt < 3000) return;
        this.lastTypingAt = now;
        const url = @json(admin_route('longos_live_chat.typing', ['conversation' => '__id__'])).replace('__id__', encodeURIComponent(this.selected.id));
        this.apiRequest({url, method: 'POST'});
      },
      enableNotifications() {
        if (!this.notificationsSupported || this.notificationPermission === 'denied') return;
        if (this.notificationPermission === 'granted') return;
        try {
          const requested = Notification.requestPermission();
          if (!requested || typeof requested.then !== 'function') return;
          requested.then((permission) => {
            this.notificationPermission = permission;
            if (permission === 'granted' && document.hidden) this.schedulePolling(0);
          }).catch(() => { this.notificationPermission = Notification.permission; });
        } catch (error) { this.notificationPermission = Notification.permission; }
      },
      notifyIncoming(previous, incoming) {
        const priorUnread = new Map(previous.map((item) => [item.id, Number(item.unread_count) || 0]));
        incoming.forEach((item) => {
          const unread = Number(item.unread_count) || 0;
          if (unread <= (priorUnread.get(item.id) || 0)) return;
          if (!document.hidden || this.notificationPermission !== 'granted') return;
          try {
            const notice = new Notification(this.labels.newMessage, {body: item.name, tag: 'longos-live-chat-' + item.id, renotify: true});
            notice.onclick = () => { window.focus(); this.selectConversation(item); notice.close(); };
          } catch (error) {}
        });
      },
      updateUnreadTitle() {
        const unread = this.unreadTotal;
        document.title = unread > 0 ? '(' + (unread > 99 ? '99+' : unread) + ') ' + this.originalTitle : this.originalTitle;
      },
      restoreDraft(id) {
        try { return window.sessionStorage.getItem(this.draftKey(id)) || ''; } catch (error) { return ''; }
      },
      clearDraft(id) {
        try { window.sessionStorage.removeItem(this.draftKey(id)); } catch (error) {}
      },
      isReadByVisitor(message) {
        if (!message || !message.created_at || !this.selected || !this.selected.visitor_last_read_at) return false;
        return new Date(this.selected.visitor_last_read_at).getTime() >= new Date(message.created_at).getTime();
      },
      resolvedRequest() { return $.Deferred().resolve().promise(); },
      csrfToken() { const meta = document.querySelector('meta[name="csrf-token"]'); return meta ? meta.content : ''; },
      apiRequest(options) {
        const request = $.ajax($.extend({
          cache: false,
          timeout: 12000,
          headers: {'X-CSRF-TOKEN': this.csrfToken(), Accept: 'application/json'},
        }, options));
        request.fail((xhr) => {
          if (xhr && (xhr.status === 401 || xhr.status === 419)) {
            this.sessionExpired = true;
            window.clearTimeout(this.timer);
            window.clearInterval(this.presenceTimer);
          }
        });
        return request;
      },
      reloadPage() { window.location.reload(); },
      checkHealth() {
        if (this.checkingHealth && this._healthRequest) return this._healthRequest;
        this.checkingHealth = true;
        this.healthError = '';
        const request = this.apiRequest({url: @json(admin_route('longos_live_chat.health')), method: 'GET', dataType: 'json'});
        this._healthRequest = request;
        request.done(() => {
          this.loadConversations(true).always(() => this.schedulePolling());
          this.heartbeat();
          if (!this.presenceTimer) this.presenceTimer = window.setInterval(this.heartbeat, 30000);
        }).fail((xhr) => { this.healthError = this.errorMessage(xhr, @json(__('LongosLiveChat::chat.setup_unavailable'))); })
          .always(() => { this.checkingHealth = false; if (this._healthRequest === request) this._healthRequest = null; });
        return request;
      },
      scheduleSearch() {
        window.clearTimeout(this.searchTimer);
        this.searchTimer = window.setTimeout(() => this.loadConversations(true), 320);
      },
      searchNow() {
        window.clearTimeout(this.searchTimer);
        this.loadConversations(true);
      },
      schedulePolling(delay = null) {
        window.clearTimeout(this.timer);
        if (document.hidden && this.notificationPermission !== 'granted') return;
        const baseDelay = document.hidden ? this.backgroundPollMilliseconds : this.pollMilliseconds;
        const retryDelay = Math.min(120000, baseDelay * Math.pow(2, Math.min(this.pollFailures, 4)));
        this.timer = window.setTimeout(this.poll, delay === null ? retryDelay : delay);
      },
      poll() {
        if (this.sessionExpired || (document.hidden && this.notificationPermission !== 'granted')) return;
        const requests = [this.loadConversations(false)];
        if (this.selected && !document.hidden) requests.push(this.loadMessages(false, true));
        $.when.apply($, requests)
          .done(() => { this.pollFailures = 0; })
          .fail((xhr, status) => { if (status !== 'abort') this.pollFailures += 1; })
          .always(() => this.schedulePolling());
      },
      heartbeat() {
        if (document.hidden) return;
        this.apiRequest({url: @json(admin_route('longos_live_chat.heartbeat')), method: 'POST'});
      },
      handleVisibilityChange() {
        if (document.hidden) { this.schedulePolling(); return; }
        this.heartbeat();
        this.schedulePolling(0);
      },
      loadConversations(reset = true, append = false) {
        if (this.loadingConversations) {
          if (!reset) return this._conversationRequest || this.resolvedRequest();
          this._conversationRequest?.abort();
        }
        const page = append ? this.nextConversationPage : 1;
        if (!page) return this.resolvedRequest();
        const version = ++this.conversationRequestVersion;
        this.loadingConversations = true;
        this.loadingMore = append;
        this.listError = '';
        const request = this.apiRequest({
          url: @json(admin_route('longos_live_chat.conversations')),
          method: 'GET',
          dataType: 'json',
          data: {status: this.statusFilter, search: this.search, page},
        });
        this._conversationRequest = request;
        request
          .done((response) => {
            if (version !== this.conversationRequestVersion) return;
            const incoming = response.conversations || [];
            if (!reset && !append) this.notifyIncoming(this.conversations, incoming);
            if (append) {
              const seen = new Set(this.conversations.map((item) => item.id));
              this.conversations = this.conversations.concat(incoming.filter((item) => !seen.has(item.id)));
            } else if (reset) {
              this.conversations = incoming;
            } else {
              const incomingIds = new Set(incoming.map((item) => item.id));
              const retainedLimit = Math.max(50, this.conversations.length);
              const merged = incoming.concat(this.conversations.filter((item) => !incomingIds.has(item.id)));
              this.conversations = merged.slice(0, retainedLimit);
            }
            if (append || reset) {
              this.hasMoreConversations = !!response.has_more;
              this.nextConversationPage = response.next_page || null;
            }
            if (this.selected) {
              const refreshed = this.conversations.find((item) => item.id === this.selected.id);
              if (refreshed) {
                if (!document.hidden) refreshed.unread_count = 0;
                this.selected = Object.assign({}, this.selected, refreshed);
              }
              else if (reset) { this.selected = null; this.messages = []; }
            }
            this.updateUnreadTitle();
          })
          .fail((xhr, status) => { if (status !== 'abort' && version === this.conversationRequestVersion) this.listError = this.errorMessage(xhr, this.labels.reconnecting); })
          .always(() => {
            if (version === this.conversationRequestVersion) { this.loadingConversations = false; this.loadingMore = false; }
            if (this._conversationRequest === request) this._conversationRequest = null;
          });
        return request;
      },
      loadMoreConversations() { this.loadConversations(false, true); },
      selectConversation(item) {
        this.persistDraft();
        this._messageRequest?.abort();
        this.selectionVersion += 1;
        this.messageRequestVersion += 1;
        this.loadingMessages = false;
        this.loadingOlder = false;
        this.selected = item;
        this.copied = false;
        this.messages = [];
        this.draft = this.restoreDraft(item.id);
        this.lastMessageId = 0;
        this.oldestMessageId = 0;
        this.hasOlder = false;
        this.chatError = '';
        this.pendingMessage = null;
        this.loadMessages(true, false);
      },
      refreshMessageBounds() {
        let newest = 0;
        let oldest = 0;
        this.messages.forEach((item) => {
          const id = Number(item.id) || 0;
          if (!id) return;
          newest = Math.max(newest, id);
          oldest = oldest ? Math.min(oldest, id) : id;
        });
        this.lastMessageId = newest;
        this.oldestMessageId = oldest;
      },
      appendIncoming(items) {
        const seen = new Set(this.messages.map((item) => Number(item.id)));
        let added = false;
        (items || []).forEach((item) => { if (!seen.has(Number(item.id))) { this.messages.push(item); seen.add(Number(item.id)); added = true; } });
        this.refreshMessageBounds();
        return added;
      },
      messageUrl() { return @json(admin_route('longos_live_chat.messages', ['conversation' => '__id__'])).replace('__id__', encodeURIComponent(this.selected.id)); },
      loadMessages(scroll = true, incremental = false) {
        if (!this.selected) return this.resolvedRequest();
        if (this.loadingMessages) return this._messageRequest || this.resolvedRequest();
        const selectedId = this.selected.id;
        const selectionVersion = this.selectionVersion;
        const requestVersion = ++this.messageRequestVersion;
        const stickToBottom = this.isNearBottom();
        this.loadingMessages = true;
        this.chatError = '';
        let hasMore = false;
        const request = this.apiRequest({
          url: this.messageUrl(),
          method: 'GET',
          dataType: 'json',
          data: {after: incremental ? this.lastMessageId : 0},
        });
        this._messageRequest = request;
        request
          .done((response) => {
            if (!this.selected || this.selected.id !== selectedId || this.selectionVersion !== selectionVersion || requestVersion !== this.messageRequestVersion) return;
            this.selected = Object.assign({}, this.selected, response.conversation || {});
            const incoming = response.messages || [];
            const added = incremental ? this.appendIncoming(incoming) : (this.messages = incoming, this.refreshMessageBounds(), this.hasOlder = !!response.has_older, incoming.length > 0);
            this.selected.unread_count = 0;
            const listItem = this.conversations.find((item) => item.id === selectedId);
            if (listItem) listItem.unread_count = 0;
            this.updateUnreadTitle();
            if (scroll || (added && stickToBottom)) this.$nextTick(this.scrollBottom);
            hasMore = !!response.has_more;
          })
          .fail((xhr, status) => { if (status !== 'abort' && requestVersion === this.messageRequestVersion) this.chatError = this.errorMessage(xhr, this.labels.reconnecting); })
          .always(() => {
            if (requestVersion === this.messageRequestVersion) this.loadingMessages = false;
            if (this._messageRequest === request) this._messageRequest = null;
            if (hasMore && this.selected && this.selected.id === selectedId) window.setTimeout(() => this.loadMessages(false, true), 0);
          });
        return request;
      },
      loadOlder() {
        if (!this.selected || !this.oldestMessageId || this.loadingOlder || this.loadingMessages) return;
        const selectedId = this.selected.id;
        const previousHeight = this.$refs.messages ? this.$refs.messages.scrollHeight : 0;
        this.loadingOlder = true;
        this.chatError = '';
        this.apiRequest({url: this.messageUrl(), method: 'GET', dataType: 'json', data: {before: this.oldestMessageId}}).done((response) => {
          if (!this.selected || this.selected.id !== selectedId) return;
          const seen = new Set(this.messages.map((item) => Number(item.id)));
          const older = (response.messages || []).filter((item) => !seen.has(Number(item.id)));
          this.messages = older.concat(this.messages);
          this.hasOlder = !!response.has_older;
          this.refreshMessageBounds();
          this.$nextTick(() => { if (this.$refs.messages) this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight - previousHeight; });
        }).fail((xhr) => { this.chatError = this.errorMessage(xhr, this.labels.reconnecting); })
          .always(() => { this.loadingOlder = false; });
      },
      send() {
        if (!this.selected || !this.draft.trim() || this.sending) return;
        const body = this.draft.trim();
        if (!this.pendingMessage || this.pendingMessage.body !== body) this.pendingMessage = {id: this.makeClientId(), body};
        const selectedId = this.selected.id;
        const selectionVersion = this.selectionVersion;
        this.sending = true;
        this.chatError = '';
        this.apiRequest({url: @json(admin_route('longos_live_chat.messages.store', ['conversation' => '__id__'])).replace('__id__', encodeURIComponent(selectedId)), method: 'POST', data: {message: body, client_message_id: this.pendingMessage.id}})
          .done((response) => {
            if (!this.selected || this.selected.id !== selectedId || this.selectionVersion !== selectionVersion) return;
            this.appendIncoming([response.message]);
            this.draft = '';
            this.clearDraft(selectedId);
            this.pendingMessage = null;
            this.selected = Object.assign({}, this.selected, response.conversation || {}, {status: 'open'});
            this.$nextTick(this.scrollBottom);
            this.loadConversations(false);
          })
          .fail((xhr) => { this.chatError = this.errorMessage(xhr, this.labels.sendFailed); })
          .always(() => { this.sending = false; });
      },
      closeConversation() {
        if (!this.selected || this.changingStatus) return;
        const selectedId = this.selected.id;
        this.changingStatus = true;
        this.chatError = '';
        this.apiRequest({url: @json(admin_route('longos_live_chat.close', ['conversation' => '__id__'])).replace('__id__', encodeURIComponent(selectedId)), method: 'POST'})
          .done((response) => { if (this.selected && this.selected.id === selectedId) { this.selected.status = response.conversation.status; this.loadConversations(true); } })
          .fail((xhr) => { this.chatError = this.errorMessage(xhr, this.labels.reconnecting); })
          .always(() => { this.changingStatus = false; });
      },
      reopenConversation() {
        if (!this.selected || this.changingStatus) return;
        const selectedId = this.selected.id;
        this.changingStatus = true;
        this.chatError = '';
        this.apiRequest({url: @json(admin_route('longos_live_chat.reopen', ['conversation' => '__id__'])).replace('__id__', encodeURIComponent(selectedId)), method: 'POST'})
          .done((response) => { if (this.selected && this.selected.id === selectedId) { this.selected.status = response.conversation.status; this.loadConversations(true); } })
          .fail((xhr) => { this.chatError = this.errorMessage(xhr, this.labels.reconnecting); })
          .always(() => { this.changingStatus = false; });
      },
      isNearBottom() {
        const element = this.$refs.messages;
        return !element || element.scrollHeight - element.scrollTop - element.clientHeight < 96;
      },
      scrollBottom() { const element = this.$refs.messages; if (element) element.scrollTop = element.scrollHeight; },
      formatTime(value) { return value ? (window.bk?.formatDateTime?.(value) || new Date(value).toLocaleString()) : ''; },
      copyConversationId() {
        if (!this.selected) return;
        const value = this.selected.id;
        const fallback = () => {
          const input = document.createElement('textarea');
          input.value = value;
          input.setAttribute('readonly', '');
          input.style.position = 'fixed';
          input.style.opacity = '0';
          document.body.appendChild(input);
          input.select();
          document.execCommand('copy');
          input.remove();
        };
        const operation = navigator.clipboard && navigator.clipboard.writeText ? navigator.clipboard.writeText(value) : Promise.resolve().then(fallback);
        operation.catch(() => fallback()).then(() => {
          this.copied = true;
          window.clearTimeout(this.copyTimer);
          this.copyTimer = window.setTimeout(() => { this.copied = false; }, 1600);
        });
      },
    },
  });
</script>
@endpush
