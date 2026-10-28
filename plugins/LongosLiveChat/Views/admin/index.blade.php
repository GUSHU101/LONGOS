@extends('admin::layouts.master')

@section('title', __('LongosLiveChat::chat.title'))

@section('content-area-class', 'w-max-1600')

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
    #longos-live-chat-admin .llc-spin { animation: llc-spin .8s linear infinite; }
    @keyframes llc-spin { to { transform: rotate(360deg); } }
    @media (max-width: 991.98px) {
      #longos-live-chat-admin .llc-admin-sidebar { min-height: 0; border-right: 0; border-bottom: 1px solid #e4e7ec; }
      #longos-live-chat-admin .llc-admin-list { max-height: 300px; }
      #longos-live-chat-admin .llc-chat { min-height: 560px; }
      #longos-live-chat-admin .llc-admin-message { max-width: 90%; }
    }
  </style>
  <div id="longos-live-chat-admin" v-cloak class="card overflow-hidden">
    <div class="row g-0">
      <aside class="col-lg-4 llc-admin-sidebar">
        <div class="p-3 border-bottom">
          <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <h5 class="mb-0">{{ __('LongosLiveChat::chat.title') }}</h5>
            <button type="button" class="btn btn-light btn-sm" @click="loadConversations(true)" :disabled="loadingConversations" title="{{ __('LongosLiveChat::chat.refresh') }}" aria-label="{{ __('LongosLiveChat::chat.refresh') }}"><i class="bi bi-arrow-clockwise" :class="{'d-inline-block llc-spin': loadingConversations}" aria-hidden="true"></i></button>
          </div>
          <div class="input-group input-group-sm mb-2">
            <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
            <input v-model.trim="search" @keyup.enter="loadConversations(true)" class="form-control" maxlength="100" placeholder="{{ __('LongosLiveChat::chat.search') }}" aria-label="{{ __('LongosLiveChat::chat.search') }}">
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
      <main class="col-lg-8 d-flex flex-column llc-chat">
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
                <span class="d-block">@{{ message.body }}</span><small class="opacity-75">@{{ formatTime(message.created_at) }}</small>
              </span>
            </div>
          </div>
          <div class="llc-state px-3 pt-2" :class="chatError ? 'text-danger' : 'text-secondary'" role="status">@{{ chatError }}</div>
          <form @submit.prevent="send" class="p-3 pt-2 border-top d-flex gap-2 align-items-end">
            <label class="visually-hidden" for="longos-live-chat-admin-message">{{ __('LongosLiveChat::chat.placeholder') }}</label>
            <textarea id="longos-live-chat-admin-message" v-model="draft" @keydown.enter.exact.prevent="send" class="form-control" rows="2" maxlength="5000" :placeholder="labels.placeholder"></textarea>
            <button class="btn btn-primary px-4" :disabled="sending || !draft.trim()" :aria-busy="sending ? 'true' : 'false'">@{{ sending ? labels.sending : (pendingMessage && chatError ? labels.retrySend : labels.send) }}</button>
          </form>
        </template>
        <div v-else class="h-100 d-flex flex-column align-items-center justify-content-center text-secondary p-4 text-center"><i class="bi bi-chat-square-text fs-1 mb-2" aria-hidden="true"></i>{{ __('LongosLiveChat::chat.select') }}</div>
      </main>
    </div>
  </div>
@endsection

@push('footer')
<script>
  new Vue({
    el: '#longos-live-chat-admin',
    data: {
      conversations: [], selected: null, messages: [], draft: '', statusFilter: 'open', search: '', timer: null, presenceTimer: null,
      lastMessageId: 0, oldestMessageId: 0, hasOlder: false, sending: false, changingStatus: false, loadingOlder: false,
      loadingMessages: false, loadingConversations: false, selectionVersion: 0, conversationRequestVersion: 0,
      messageRequestVersion: 0, listError: '', chatError: '', pendingMessage: null, loadingMore: false, pollFailures: 0,
      hasMoreConversations: false, nextConversationPage: null,
      pollMilliseconds: {{ max(3, min(20, (int) plugin_setting('longos_live_chat.poll_seconds', 5))) * 1000 }},
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
      },
    },
    mounted() {
      this._conversationRequest = null;
      this._messageRequest = null;
      this.loadConversations(true).always(() => this.schedulePolling());
      this.heartbeat();
      document.addEventListener('visibilitychange', this.handleVisibilityChange);
      this.presenceTimer = window.setInterval(this.heartbeat, 30000);
    },
    beforeDestroy() {
      window.clearTimeout(this.timer);
      window.clearInterval(this.presenceTimer);
      this._conversationRequest?.abort();
      this._messageRequest?.abort();
      document.removeEventListener('visibilitychange', this.handleVisibilityChange);
    },
    methods: {
      errorMessage(xhr, fallback) { const errors = xhr && xhr.responseJSON && xhr.responseJSON.errors; return xhr && xhr.responseJSON && xhr.responseJSON.message || (errors ? Object.values(errors).flat()[0] : '') || fallback; },
      makeClientId() { return window.crypto && typeof window.crypto.randomUUID === 'function' ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => { const random = Math.random() * 16 | 0; return (char === 'x' ? random : (random & 0x3 | 0x8)).toString(16); }); },
      statusLabel(status) { return status === 'closed' ? this.labels.closed : this.labels.open; },
      resolvedRequest() { return $.Deferred().resolve().promise(); },
      csrfToken() { const meta = document.querySelector('meta[name="csrf-token"]'); return meta ? meta.content : ''; },
      apiRequest(options) {
        return $.ajax($.extend({
          cache: false,
          timeout: 12000,
          headers: {'X-CSRF-TOKEN': this.csrfToken(), Accept: 'application/json'},
        }, options));
      },
      schedulePolling(delay = null) {
        window.clearTimeout(this.timer);
        if (document.hidden) return;
        const retryDelay = Math.min(60000, this.pollMilliseconds * Math.pow(2, Math.min(this.pollFailures, 4)));
        this.timer = window.setTimeout(this.poll, delay === null ? retryDelay : delay);
      },
      poll() {
        if (document.hidden) return;
        const requests = [this.loadConversations(false)];
        if (this.selected) requests.push(this.loadMessages(false, true));
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
        if (document.hidden) { window.clearTimeout(this.timer); return; }
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
        this._messageRequest?.abort();
        this.selectionVersion += 1;
        this.messageRequestVersion += 1;
        this.loadingMessages = false;
        this.loadingOlder = false;
        this.selected = item;
        this.messages = [];
        this.draft = '';
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
            this.pendingMessage = null;
            this.selected.status = 'open';
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
    },
  });
</script>
@endpush
