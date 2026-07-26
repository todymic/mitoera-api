/**
 * Placio Widget SDK — iframe edition
 *
 * Usage:
 *   <script src="https://cdn.placio.io/placio-widget.js"></script>
 *   <script>
 *     new Placio.SeatingChart({
 *       divId: 'my-div',
 *       workspaceKey: 'pk_pub_...',
 *       event: 'event-identifier',
 *       onSeatSelected:   (seat) => {},
 *       onSeatDeselected: (seat) => {},
 *       onCheckout:       (seats) => {},
 *       categoryPrices:   { 'cat-id': { price: 15000, currency: 'AR' } },
 *     }).render();
 *   </script>
 */

(function (global) {
  'use strict';

  const API_BASE = (function () {
    const scripts = document.querySelectorAll('script[src*="placio-widget"]');
    if (scripts.length) {
      try { return new URL(scripts[scripts.length - 1].src).origin; } catch (_) {}
    }
    return '';
  })();

  class SeatingChart {
    constructor(options) {
      this.divId            = options.divId;
      this.workspaceKey     = options.workspaceKey;
      this.eventId          = options.event;
      this.onSeatSelected    = options.onSeatSelected    || null;
      this.onSeatDeselected  = options.onSeatDeselected  || null;
      this.onSelectionChange = options.onSelectionChange || null;
      this.onCheckout        = options.onCheckout        || null;
      this.onReady           = options.onReady           || null;
      this.categoryPrices   = options.categoryPrices   || {};

      this._iframe          = null;
      this._sessionToken    = null;
      this._holdToken       = null;
      this._placioEventId   = null;
      this._lastSeats       = [];
      this._listener        = null;
    }

    render() {
      const container = document.getElementById(this.divId);
      if (!container) { console.error('[Placio] div not found:', this.divId); return; }

      container.innerHTML = '';
      container.style.cssText += ';overflow:hidden;';

      // Build iframe URL
      const url = new URL(`${API_BASE}/render.html`);
      url.searchParams.set('key',   this.workspaceKey);
      url.searchParams.set('event', this.eventId);
      if (this.onCheckout) url.searchParams.set('checkout', '1');

      const iframe = document.createElement('iframe');
      iframe.src = url.toString();
      const radius = getComputedStyle(container).borderRadius;
      iframe.style.cssText = `width:100%;height:100%;border:none;display:block;border-radius:${radius};`;
      iframe.allow = 'fullscreen';
      iframe.setAttribute('allowfullscreen', '');
      this._iframe = iframe;
      container.appendChild(iframe);

      // Listen for messages from this specific iframe
      this._listener = (e) => {
        if (e.source !== iframe.contentWindow) return;
        const { type, ...data } = e.data || {};

        switch (type) {
          case 'placio:ready':
            this._sessionToken  = data.sessionToken;
            this._holdToken     = data.holdToken;
            this._placioEventId = data.eventId;
            if (this.onReady) this.onReady({ sessionToken: data.sessionToken, holdToken: data.holdToken, eventId: data.eventId });
            // Push category prices now that the iframe is ready
            if (Object.keys(this.categoryPrices).length) {
              iframe.contentWindow.postMessage({
                type: 'placio:setCategoryPrices',
                prices: this.categoryPrices,
              }, '*');
            }
            break;

          case 'placio:seatSelected':
            if (this.onSeatSelected) this.onSeatSelected(data.seat);
            break;

          case 'placio:seatDeselected':
            if (this.onSeatDeselected) this.onSeatDeselected(data.seat);
            break;

          case 'placio:selectionChange':
            this._lastSeats = data.seats || [];
            if (this.onSelectionChange) this.onSelectionChange(this._lastSeats);
            break;

          case 'placio:checkout':
            if (this.onCheckout) this.onCheckout(data.seats || []);
            break;

          case 'placio:requestFullscreen': {
            this._savedIframeStyle = iframe.style.cssText;
            // Neutralize ancestor CSS that confines position:fixed to a sub-rect
            // (transform / will-change / filter / contain all create a new containing block)
            this._fsAffectedEls = [];
            let fsEl = iframe.parentElement;
            while (fsEl && fsEl !== document.documentElement) {
              const cs = getComputedStyle(fsEl);
              if (cs.transform !== 'none' || cs.filter !== 'none' || cs.perspective !== 'none' || (cs.willChange && cs.willChange !== 'auto')) {
                this._fsAffectedEls.push({
                  el: fsEl,
                  transform:  fsEl.style.transform,
                  filter:     fsEl.style.filter,
                  perspective:fsEl.style.perspective,
                  willChange: fsEl.style.willChange,
                });
                fsEl.style.transform   = 'none';
                fsEl.style.filter      = 'none';
                fsEl.style.perspective = 'none';
                fsEl.style.willChange  = 'auto';
              }
              fsEl = fsEl.parentElement;
            }
            iframe.style.cssText = 'position:fixed;inset:0;z-index:99999;width:100vw;height:100vh;border:none;display:block;border-radius:0;';
            document.body.style.overflow = 'hidden';
            break;
          }

          case 'placio:exitFullscreen': {
            for (const s of (this._fsAffectedEls || [])) {
              s.el.style.transform   = s.transform;
              s.el.style.filter      = s.filter;
              s.el.style.perspective = s.perspective;
              s.el.style.willChange  = s.willChange;
            }
            this._fsAffectedEls = [];
            iframe.style.cssText = this._savedIframeStyle || '';
            document.body.style.overflow = '';
            break;
          }

          case 'placio:error':
            console.error('[Placio]', data.message);
            break;
        }
      };
      window.addEventListener('message', this._listener);
    }

    // ── public API ─────────────────────────────────────────────────────────

    /** Returns the last known selection — updated on every seat click. */
    getSelectedSeats() { return this._lastSeats; }

    getSessionToken()  { return this._sessionToken; }
    getHoldToken()     { return this._holdToken; }
    getEventId()       { return this._placioEventId; }

    /** Remove the iframe and clean up listeners. */
    destroy() {
      if (this._listener) window.removeEventListener('message', this._listener);
      if (this._iframe)   this._iframe.remove();
      this._iframe = null;
    }
  }

  global.Placio = { SeatingChart };

})(window);
