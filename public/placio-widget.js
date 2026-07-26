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
      container.style.cssText += ';overflow:hidden;position:relative;';

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

      // ── fullscreen button (inside container, above iframe) ───────────────
      const iconExpand = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>`;
      const iconShrink = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/></svg>`;
      const fsBtn = document.createElement('button');
      fsBtn.style.cssText = 'position:absolute;top:10px;right:10px;z-index:200;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:none;border-radius:999px;background:rgba(255,255,255,0.92);backdrop-filter:blur(4px);box-shadow:0 1px 6px rgba(0,0,0,0.10),0 0 0 1px rgba(0,0,0,0.07);cursor:pointer;font-size:13px;font-weight:500;color:#1f2937;font-family:system-ui,sans-serif;white-space:nowrap;';
      const fsIcon = document.createElement('span');
      fsIcon.style.display = 'flex';
      const fsLabel = document.createElement('span');
      const setFsState = (inFs) => {
        fsIcon.innerHTML = inFs ? iconShrink : iconExpand;
        fsLabel.textContent = inFs ? 'Quitter le plein écran' : 'Plein écran';
      };
      setFsState(false);
      fsBtn.appendChild(fsIcon);
      fsBtn.appendChild(fsLabel);
      fsBtn.addEventListener('mouseenter', () => { fsBtn.style.background = '#fff'; });
      fsBtn.addEventListener('mouseleave', () => { fsBtn.style.background = 'rgba(255,255,255,0.92)'; });
      fsBtn.addEventListener('click', async () => {
        try {
          if (document.fullscreenElement === container) {
            await document.exitFullscreen();
          } else {
            await container.requestFullscreen();
          }
        } catch (_) {}
      });
      const onFsChange = () => {
        const inFs = document.fullscreenElement === container;
        setFsState(inFs);
        if (inFs) {
          iframe.style.borderRadius = '0';
        } else {
          iframe.style.borderRadius = radius;
        }
      };
      document.addEventListener('fullscreenchange', onFsChange);
      this._fsBtn = fsBtn;
      this._onFsChange = onFsChange;
      container.appendChild(fsBtn);

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
      if (this._listener)   window.removeEventListener('message', this._listener);
      if (this._onFsChange) document.removeEventListener('fullscreenchange', this._onFsChange);
      if (this._iframe)     this._iframe.remove();
      if (this._fsBtn)      this._fsBtn.remove();
      this._iframe = null;
      this._fsBtn  = null;
    }
  }

  global.Placio = { SeatingChart };

})(window);
