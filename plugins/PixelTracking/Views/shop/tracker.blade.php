@php
  $metaPixels = \Plugin\PixelTracking\Services\PixelProfiles::meta();
  $tikTokPixels = \Plugin\PixelTracking\Services\PixelProfiles::tiktok();
  $snapchatPixels = \Plugin\PixelTracking\Services\PixelProfiles::snapchat();
  $consentCookie = (string) plugin_setting('pixel_tracking.consent_cookie', 'tracking_consent');
  if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $consentCookie) !== 1) {
    $consentCookie = 'tracking_consent';
  }
  $hasServerProfiles = collect([...$metaPixels, ...$tikTokPixels, ...$snapchatPixels])
    ->contains(static fn ($pixel) => !empty($pixel['access_token']));
  $publicConfig = [
    'endpoint' => shop_route('pixel_tracking.events.store'),
    'csrf' => csrf_token(),
    'serverTracking' => (bool) plugin_setting('pixel_tracking.server_tracking', true) && $hasServerProfiles,
    'consentRequired' => (bool) plugin_setting('pixel_tracking.require_consent', false),
    'consentCookie' => $consentCookie,
    'currency' => current_currency_code(),
    'checkoutPath' => parse_url(shop_route('checkout.index'), PHP_URL_PATH),
    'metaPixels' => array_values(array_filter(array_map(fn ($pixel) => $pixel['pixel_id'] ?? null, $metaPixels))),
    'tikTokPixels' => array_values(array_filter(array_map(fn ($pixel) => $pixel['pixel_code'] ?? null, $tikTokPixels))),
    'snapchatPixels' => array_values(array_filter(array_map(fn ($pixel) => $pixel['pixel_id'] ?? null, $snapchatPixels))),
  ];
@endphp
<script>
  window.BeikePixelTracking = @json($publicConfig);
  (function (window, document, config) {
    if (!config || (!config.metaPixels.length && !config.tikTokPixels.length && !config.snapchatPixels.length)) return;

    const cookieValue = (name) => new RegExp('(?:^|; )' + String(name).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)').exec(document.cookie)?.[1] || '';
    const hasConsent = () => !config.consentRequired || /^(1|true|yes)$/i.test(cookieValue(config.consentCookie));
    const createEventId = () => window.crypto?.randomUUID?.() || ('evt_' + Date.now() + '_' + Math.random().toString(36).slice(2));
    const sentEvents = new Set();
    const pendingEvents = [];
    let started = false;

    const tiktokProperties = (properties) => {
      const result = {...properties};
      if (Array.isArray(result.contents)) {
        result.contents = result.contents.map((item) => ({
          content_id: String(item.id ?? item.content_id ?? ''),
          content_type: result.content_type || 'product',
          quantity: Math.max(1, Number(item.quantity || 1)),
          price: Math.max(0, Number(item.item_price ?? item.price ?? 0)),
        })).filter((item) => item.content_id);
      }
      if (Array.isArray(result.content_ids)) {
        result.content_ids = result.content_ids.map(String).filter(Boolean).slice(0, 50);
      }
      return result;
    };

    const snapchatEventNames = {
      PageView: 'PAGE_VIEW', ViewContent: 'VIEW_CONTENT', AddToCart: 'ADD_CART',
      InitiateCheckout: 'START_CHECKOUT', Purchase: 'PURCHASE', Search: 'SEARCH',
      CompleteRegistration: 'SIGN_UP',
    };
    const snapchatProperties = (properties, eventId) => {
      const result = {client_dedup_id: eventId};
      if (properties.value != null) result.price = Number(properties.value);
      if (properties.currency) result.currency = String(properties.currency).toUpperCase();
      if (properties.order_id) result.transaction_id = String(properties.order_id);
      if (properties.search_string) result.search_string = String(properties.search_string).slice(0, 191);
      if (Array.isArray(properties.content_ids)) result.item_ids = properties.content_ids.map(String).filter(Boolean).slice(0, 50);
      if (Array.isArray(properties.contents)) {
        result.number_items = properties.contents.reduce((total, item) => total + Math.max(1, Number(item.quantity || 1)), 0);
      }
      return result;
    };

    const emit = (name, properties = {}, eventId = createEventId()) => {
      if (!hasConsent()) return eventId;
      const eventKey = `${name}:${eventId}`;
      if (sentEvents.has(eventKey)) return eventId;
      sentEvents.add(eventKey);

      properties = {...properties};
      if (properties.value != null && !properties.currency) properties.currency = config.currency;
      if (properties.currency) properties.currency = String(properties.currency).toUpperCase();

      config.metaPixels.forEach((pixelId) => {
        if (window.fbq) window.fbq('trackSingle', pixelId, name, properties, {eventID: eventId});
      });
      config.tikTokPixels.forEach((pixelId) => {
        const instance = typeof window.ttq?.instance === 'function' ? window.ttq.instance(pixelId) : null;
        if (instance?.track) instance.track(name === 'Purchase' ? 'CompletePayment' : name, tiktokProperties(properties), {event_id: eventId});
      });
      const snapchatEvent = snapchatEventNames[name];
      if (snapchatEvent && window.snaptr) {
        config.snapchatPixels.forEach((pixelId) => window.snaptr('track', pixelId, snapchatEvent, snapchatProperties(properties, eventId)));
      }

      if (config.serverTracking && name !== 'Purchase' && name !== 'CompleteRegistration') {
        fetch(config.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          keepalive: true,
          headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json'},
          body: JSON.stringify({event_name: name, event_id: eventId, url: window.location.href, properties}),
        }).catch(() => {});
      }

      return eventId;
    };

    const track = (name, properties = {}, eventId = createEventId()) => {
      if (!started) {
        if (hasConsent()) start();
        if (!started) {
          if (pendingEvents.length < 20) pendingEvents.push([name, properties, eventId]);
          return eventId;
        }
      }
      return emit(name, properties, eventId);
    };

    const loadMeta = () => {
      if (!config.metaPixels.length) return;
      !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
      config.metaPixels.forEach((pixelId) => window.fbq('init', pixelId));
    };

    const loadTikTok = () => {
      if (!config.tikTokPixels.length) return;
      window.TiktokAnalyticsObject = 'ttq'; window.ttq = window.ttq || []; window.ttq.methods = window.ttq.methods || ['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'];
      window.ttq.setAndDefer = window.ttq.setAndDefer || function(t,e){t[e]=function(){t.push([e].concat([].slice.call(arguments,0)))}}; for (let i=0;i<window.ttq.methods.length;i++) window.ttq.setAndDefer(window.ttq,window.ttq.methods[i]);
      window.ttq.instance = window.ttq.instance || function(e){window.ttq._i=window.ttq._i||{}; const q=window.ttq._i[e]=window.ttq._i[e]||[]; for(let i=0;i<window.ttq.methods.length;i++) window.ttq.setAndDefer(q,window.ttq.methods[i]); return q};
      window.ttq.load = window.ttq.load || function(e,n){const r='https://analytics.tiktok.com/i18n/pixel/events.js'; window.ttq._i=window.ttq._i||{}; window.ttq._i[e]=window.ttq._i[e]||[]; window.ttq._i[e]._u=r; window.ttq._t=window.ttq._t||{}; window.ttq._t[e]=+new Date; window.ttq._o=window.ttq._o||{}; window.ttq._o[e]=n||{}; const s=document.createElement('script');s.async=true;s.src=r+'?sdkid='+encodeURIComponent(e)+'&lib=ttq'; const first=document.getElementsByTagName('script')[0]; first?.parentNode ? first.parentNode.insertBefore(s,first) : document.head.appendChild(s)};
      config.tikTokPixels.forEach((pixelId) => window.ttq.load(pixelId));
    };

    const loadSnapchat = () => {
      if (!config.snapchatPixels.length) return;
      !function(e,t,n){if(e.snaptr)return;const a=e.snaptr=function(){a.handleRequest?a.handleRequest.apply(a,arguments):a.queue.push(arguments)};a.queue=[];const s=t.createElement('script');s.async=true;s.src=n;const first=t.getElementsByTagName('script')[0];first?.parentNode?first.parentNode.insertBefore(s,first):t.head.appendChild(s)}(window,document,'https://sc-static.net/scevent.min.js');
      config.snapchatPixels.forEach((pixelId) => window.snaptr('init', pixelId));
    };

    const bindCommerceEvents = () => {
      window.addEventListener('longos:cart-added', (event) => {
        const tracking = event.detail?.cart?.tracking || {};
        const contentId = String(tracking.content_id || event.detail?.product_id || event.detail?.cart?.product_id || event.detail?.sku_id || '');
        const quantity = Math.max(1, Number(event.detail?.quantity || 1));
        const itemPrice = Number(tracking.item_price);
        const properties = contentId ? {
          content_ids: [contentId],
          contents: [{id: contentId, quantity, ...(Number.isFinite(itemPrice) ? {item_price: itemPrice} : {})}],
          content_type: 'product',
        } : {};
        if (Number.isFinite(itemPrice)) {
          properties.value = itemPrice * quantity;
          properties.currency = String(tracking.currency || config.currency).toUpperCase();
        }
        emit('AddToCart', properties);
      });
    };

    function start() {
      if (started || !hasConsent()) return false;
      started = true;
      loadMeta();
      loadTikTok();
      loadSnapchat();
      bindCommerceEvents();
      emit('PageView');
      if (window.location.pathname.replace(/\/$/, '') === String(config.checkoutPath || '').replace(/\/$/, '')) emit('InitiateCheckout');
      const searchTerm = new URLSearchParams(window.location.search).get('keyword');
      if (searchTerm) emit('Search', {search_string: searchTerm.slice(0, 191)});
      pendingEvents.splice(0).forEach(([name, properties, eventId]) => emit(name, properties, eventId));
      return true;
    }

    window.bkTrackEvent = track;
    window.LongosPixelTracking = Object.freeze({start, event: track, hasConsent});
    window.addEventListener('longos:tracking-consent-granted', start);
    window.addEventListener('longos:consent-updated', () => {
      if (hasConsent()) {
        if (window.fbq) window.fbq('consent', 'grant');
        if (window.ttq?.enableCookie) window.ttq.enableCookie();
        start();
        return;
      }

      pendingEvents.splice(0);
      if (window.fbq) window.fbq('consent', 'revoke');
      if (window.ttq?.disableCookie) window.ttq.disableCookie();
    });
    start();
  })(window, document, window.BeikePixelTracking);
</script>
