/**
 * Mitoera Widget SDK
 *
 * Intègre un plan de salle interactif dans n'importe quelle page web via une iframe.
 *
 * ## Installation
 *
 *   <script src="https://api.mitoera.com/mitoera-widget.js"></script>
 *
 * ## Utilisation minimale
 *
 *   <div id="seating" style="width:100%;height:560px"></div>
 *   <script>
 *     const chart = new Mitoera.SeatingChart({
 *       divId:        'seating',
 *       workspaceKey: 'pk_pub_xxxx',   // clé publique — visible dans BO > Clés API
 *       event:        'mon-evenement', // slug ou UUID de l'événement
 *     });
 *     chart.render();
 *   </script>
 *
 * ## Options du constructeur
 *
 * @param {object}   options
 * @param {string}   options.divId
 *   ID du `<div>` conteneur. Le widget remplit entièrement ce div.
 *
 * @param {string}   options.workspaceKey
 *   Clé publique de l'espace de travail (préfixe `pk_pub_`).
 *   Obtenue dans le back-office > Paramètres > Clés API.
 *   Peut être exposée dans le code front-end.
 *
 * @param {string}   options.event
 *   Slug ou UUID de l'événement à afficher.
 *
 * @param {boolean}  [options.sandbox=false]
 *   Si `true`, le widget se connecte à l'environnement sandbox.
 *   Utiliser uniquement pour les tests — les réservations sandbox ne sont pas réelles.
 *
 * @param {object}   [options.categoryPrices]
 *   Prix à afficher dans le tooltip de chaque siège, par catégorie.
 *   Clé : ID de catégorie (string). Valeur : `{ price: number, currency: string }`.
 *   Exemple : `{ 'abc-123': { price: 15000, currency: 'MGA' } }`
 *   Peut être mis à jour après `render()` via `chart.setCategoryPrices(prices)`.
 *
 * ## Callbacks
 *
 * @param {function} [options.onReady]
 *   Appelé quand le widget est initialisé et prêt à recevoir des interactions.
 *   @param {{ sessionToken: string, holdToken: string, eventId: string }} data
 *
 * @param {function} [options.onSeatSelected]
 *   Appelé quand l'utilisateur clique sur un siège disponible pour le sélectionner.
 *   @param {{ seatKey: string, catId: string, catColor: string, catName: string }} seat
 *
 * @param {function} [options.onSeatDeselected]
 *   Appelé quand l'utilisateur désélectionne un siège, ou quand un siège est
 *   automatiquement désélectionné suite à un hold/book (mise à jour Mercure).
 *   @param {{ seatKey: string, catId: string, catColor: string, catName: string }} seat
 *
 * @param {function} [options.onSelectionChange]
 *   Appelé à chaque changement de sélection (sélection ou désélection).
 *   Reçoit le tableau complet des sièges actuellement sélectionnés.
 *   @param {Array<{ seatKey: string, catId: string, catColor: string, catName: string }>} seats
 *
 * @param {function} [options.onCheckout]
 *   Appelé quand l'utilisateur clique sur le bouton "Valider" dans le widget.
 *   N'est disponible que si `checkout: true` est passé (bouton affiché dans l'iframe).
 *   Utilisez ce callback pour déclencher votre propre flux de paiement.
 *   @param {Array<{ seatKey: string, catId: string, catName: string }>} seats
 *
 * ## Méthodes publiques
 *
 * @method render()
 *   Crée l'iframe et charge le plan de salle. À appeler une seule fois.
 *
 * @method destroy()
 *   Supprime l'iframe et nettoie tous les écouteurs d'événements.
 *   À appeler si le widget est retiré du DOM (navigation SPA, modal fermée, etc.).
 *
 * @method getSelectedSeats(): Array
 *   Retourne le tableau des sièges actuellement sélectionnés par l'utilisateur.
 *   Même structure que le paramètre `onSelectionChange`.
 *
 * @method getSessionToken(): string|null
 *   Retourne le sessionToken widget (JWT). Utilisez-le pour appeler les endpoints
 *   `/api/widget/**` depuis votre back-end ou depuis la page parente.
 *
 * @method getHoldToken(): string|null
 *   Retourne le holdToken de session. Géré automatiquement — rarement utile en direct.
 *
 * @method getEventId(): string|null
 *   Retourne l'UUID de l'événement résolu (même si `event` a été passé en slug).
 *
 * ## Flux recommandé pour une réservation
 *
 *   1. L'utilisateur sélectionne des sièges → `onSelectionChange` reçoit la liste
 *   2. Votre code appelle `POST /api/widget/events/{eventId}/hold` avec les seatKeys
 *      et `Authorization: Widget {sessionToken}` → les sièges sont bloqués 10 min
 *   3. L'utilisateur paie (Stripe, etc.)
 *   4. Votre back-end appelle `POST /api/widget/events/{eventId}/book` avec les mêmes seatKeys
 *   5. Les sièges passent en `booked` et sont retirés du plan en temps réel (Mercure)
 *
 * ## Mises à jour en temps réel (Mercure)
 *
 *   Le widget s'abonne automatiquement au hub Mercure de l'événement.
 *   Quand un autre utilisateur bloque ou réserve un siège, le widget le met à jour
 *   visuellement sans rechargement. Aucune configuration supplémentaire n'est requise.
 */

(function (global) {
  'use strict';

  const API_BASE = (function () {
    const scripts = document.querySelectorAll('script[src*="mitoera-widget"]');
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
      this.sandbox          = options.sandbox === true;
      this.showLegend       = options.showLegend !== false;
      this.onSeatSelected    = options.onSeatSelected    || null;
      this.onSeatDeselected  = options.onSeatDeselected  || null;
      this.onSelectionChange = options.onSelectionChange || null;
      this.onCheckout        = options.onCheckout        || null;
      this.onReady           = options.onReady           || null;
      this.categoryPrices   = options.categoryPrices   || {};

      this._iframe          = null;
      this._sessionToken    = null;
      this._holdToken       = null;
      this._mitoerEventId   = null;
      this._lastSeats       = [];
      this._listener        = null;
    }

    render() {
      const container = document.getElementById(this.divId);
      if (!container) { console.error('[Mitoera] div not found:', this.divId); return; }

      container.innerHTML = '';
      container.style.cssText += ';overflow:hidden;position:relative;';

      // Build iframe URL — sandbox mode uses /sandbox-render served by app-sandbox
      const renderPath = this.sandbox ? '/sandbox-render' : '/render';
      const url = new URL(`${API_BASE}${renderPath}`);
      url.searchParams.set('key',   this.workspaceKey);
      url.searchParams.set('event', this.eventId);
      if (this.onCheckout) url.searchParams.set('checkout', '1');
      if (!this.showLegend) url.searchParams.set('legend', '0');

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
      fsBtn.style.cssText = 'position:absolute;bottom:10px;right:10px;z-index:200;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:none;border-radius:999px;background:rgba(255,255,255,0.92);backdrop-filter:blur(4px);box-shadow:0 1px 6px rgba(0,0,0,0.10),0 0 0 1px rgba(0,0,0,0.07);cursor:pointer;font-size:13px;font-weight:500;color:#1f2937;font-family:system-ui,sans-serif;white-space:nowrap;';
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
        iframe.style.borderRadius = inFs ? '0' : radius;
        iframe.contentWindow.postMessage({ type: 'mitoera:fullscreenChange', inFs }, '*');
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
          case 'mitoera:ready':
            this._sessionToken  = data.sessionToken;
            this._holdToken     = data.holdToken;
            this._mitoerEventId = data.eventId;
            if (this.onReady) this.onReady({ sessionToken: data.sessionToken, holdToken: data.holdToken, eventId: data.eventId });
            // Push category prices now that the iframe is ready
            if (Object.keys(this.categoryPrices).length) {
              iframe.contentWindow.postMessage({
                type: 'mitoera:setCategoryPrices',
                prices: this.categoryPrices,
              }, '*');
            }
            break;

          case 'mitoera:seatSelected':
            if (this.onSeatSelected) this.onSeatSelected(data.seat);
            break;

          case 'mitoera:seatDeselected':
            if (this.onSeatDeselected) this.onSeatDeselected(data.seat);
            break;

          case 'mitoera:selectionChange':
            this._lastSeats = data.seats || [];
            if (this.onSelectionChange) this.onSelectionChange(this._lastSeats);
            break;

          case 'mitoera:checkout':
            if (this.onCheckout) this.onCheckout(data.seats || []);
            break;



          case 'mitoera:error':
            console.error('[Mitoera]', data.message);
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
    getEventId()       { return this._mitoerEventId; }

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

  global.Mitoera = { SeatingChart };

})(window);
