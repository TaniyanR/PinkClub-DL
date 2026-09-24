/* Anonymous engagement telemetry shared across the PinkClub editions. */
(() => {
  'use strict';

  if (navigator.webdriver === true || window.__pcfEngagementTrackingStarted === true) return;
  if (navigator.doNotTrack === '1' || window.doNotTrack === '1') return;
  window.__pcfEngagementTrackingStarted = true;

  const startedAt = Date.now();
  const eventKey = (() => {
    try {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID().toLowerCase();
      if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
      }
    } catch (_) {}
    return `${Date.now().toString(16)}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`.slice(0, 64);
  })();

  let visibleSince = document.visibilityState === 'visible' ? Date.now() : 0;
  let activeMs = 0;
  let maxScroll = 0;
  let lastSentDuration = 0;

  const updateScroll = () => {
    const doc = document.documentElement;
    const body = document.body;
    const height = Math.max(doc ? doc.scrollHeight : 0, body ? body.scrollHeight : 0, window.innerHeight || 0);
    const denominator = Math.max(1, height - (window.innerHeight || 0));
    const percent = denominator <= 1 ? 100 : Math.max(0, Math.min(100, Math.round(((window.scrollY || 0) / denominator) * 100)));
    if (percent > maxScroll) maxScroll = percent;
  };

  const closeVisibleWindow = () => {
    if (visibleSince > 0) {
      activeMs += Math.max(0, Date.now() - visibleSince);
      visibleSince = 0;
    }
  };
  const openVisibleWindow = () => {
    if (visibleSince === 0 && document.visibilityState === 'visible') visibleSince = Date.now();
  };

  const endpoint = (() => {
    const path = window.location.pathname;
    const slash = path.lastIndexOf('/');
    return `${path.slice(0, slash + 1)}analytics_engagement.php`;
  })();

  const sendSnapshot = () => {
    const now = Date.now();
    const wasVisible = visibleSince > 0;
    if (wasVisible) closeVisibleWindow();
    updateScroll();
    const duration = Math.max(0, Math.min(43200, Math.round((now - startedAt) / 1000)));
    const active = Math.max(0, Math.min(duration, Math.round(activeMs / 1000)));
    if (duration < 2 || duration === lastSentDuration) {
      if (wasVisible && document.visibilityState === 'visible') openVisibleWindow();
      return;
    }
    lastSentDuration = duration;
    const data = new FormData();
    data.append('event_key', eventKey);
    data.append('path', window.location.pathname + window.location.search);
    data.append('duration', String(duration));
    data.append('active', String(active));
    data.append('scroll', String(maxScroll));
    if (!(navigator.sendBeacon && navigator.sendBeacon(endpoint, data)) && window.fetch) {
      window.fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true }).catch(() => {});
    }
    if (wasVisible && document.visibilityState === 'visible') openVisibleWindow();
  };

  window.addEventListener('scroll', updateScroll, { passive: true });
  window.addEventListener('pagehide', sendSnapshot);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      closeVisibleWindow();
      sendSnapshot();
    } else {
      openVisibleWindow();
    }
  });
  window.addEventListener('pageshow', openVisibleWindow);
  window.setInterval(() => {
    if (document.visibilityState === 'visible') sendSnapshot();
  }, 60000);
  updateScroll();
})();
