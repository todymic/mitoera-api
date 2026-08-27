/**
 * PlaceRender — Standalone seating chart renderer
 * Rendering ported from place-ui/src/admin/components/PreviewPlan.vue
 * Seat-key formula mirrors place-symfony EventService::createSeatsFromObjects
 *
 *   const r = new PlaceRender({ container, data, onSeatSelected, onSeatDeselected, readOnly });
 *   r.render();
 *   r.updateSeats(seats);
 *   r.getSelectedSeats();   // → [{ seatKey, catId, catColor, catName }]
 *   r.destroy();
 */
(function (global) {
  'use strict';

  // ─── label helpers (port of place-ui/src/services/seatLabel.js) ──────────────

  function _letters(n, upper) {
    let s = '', x = n;
    do { s = String.fromCharCode((upper ? 65 : 97) + (x % 26)) + s; x = Math.floor(x / 26) - 1; } while (x >= 0);
    return s;
  }
  const _ROM = [[1000,'M'],[900,'CM'],[500,'D'],[400,'CD'],[100,'C'],[90,'XC'],[50,'L'],[40,'XL'],[10,'X'],[9,'IX'],[5,'V'],[4,'IV'],[1,'I']];
  function _roman(n) { let v = n+1, r=''; for(const[a,b] of _ROM){while(v>=a){r+=b;v-=a;}} return r||String(n+1); }
  function axisLabel(idx, total, fmt, dir) {
    const i = dir === 'reversed' ? Math.max(0, total - 1 - idx) : idx;
    return fmt === 'A-Z' ? _letters(i,true) : fmt === 'a-z' ? _letters(i,false) : fmt === 'I-X' ? _roman(i) : String(i+1);
  }
  function seatLabel(ri, ci, rows, cols, cfg) {
    return axisLabel(ri, rows, cfg.rowFormat||'A-Z', cfg.rowDirection||'normal')
         + axisLabel(ci, cols, cfg.colFormat||'1-9', cfg.colDirection||'normal');
  }

  // ─── seat-key formulas (mirrors EventService.php) ────────────────────────────
  function seatRowKey(obj, ri, ci) {
    const s = obj.section||obj.label||obj.id||'S';
    return `${s}-${axisLabel(ri,obj.rows,obj.rowFormat,obj.rowDirection)}-${axisLabel(ci,obj.cols,obj.colFormat,obj.colDirection)}`;
  }
  function tableSectionKey(obj, ti, si) { return `${obj.section||obj.label||obj.id||'TS'}-${ti+1}-${si+1}`; }
  function tableZoneKey(obj, i)          { return `${obj.section||obj.label||obj.id||'T'}-${i+1}`; }

  // ─── geometry helpers ─────────────────────────────────────────────────────────
  const TS_PAD = 4;
  function tableZoneSize(t)    { return (t.tableSize||30) + 2*(t.seatSize||15) + 16; }
  function tsSectionUnit(ts)   { return (ts.tableSize||30) + 2*(ts.seatSize||15) + 16; }
  function tsSectionWidth(ts)  { const u=tsSectionUnit(ts),n=ts.tableCount||3,sp=ts.tableSpacing??2; return n*u+(n-1)*sp+2*TS_PAD; }
  function tsSectionHeight(ts) { const u=tsSectionUnit(ts),r=ts.tableRows||1, sp=ts.tableSpacing??2; return r*u+(r-1)*sp+2*TS_PAD; }

  function computeBbox(objects) {
    let x0=Infinity,y0=Infinity,x1=-Infinity,y1=-Infinity;
    for (const o of objects) {
      const x=o.left||0, y=o.top||0; let w=0,h=0;
      const ss=o.seatSize||22, g=o.seatGap??4;
      if (o._type==='zone'||o._type==='freeZone')          { w=o.width||80;  h=o.height||60; }
      else if (o._type==='seatRow')                        { w=(o.cols||1)*(ss+g); h=(o.rows||1)*(ss+g)+14; }
      else if (o._type==='tableZone')                      { const s=tableZoneSize(o); w=s; h=s; }
      else if (o._type==='tableSection')                   { w=tsSectionWidth(o); h=tsSectionHeight(o); }
      x0=Math.min(x0,x); y0=Math.min(y0,y); x1=Math.max(x1,x+w); y1=Math.max(y1,y+h);
    }
    if(!isFinite(x0)) return {minX:0,minY:0,w:400,h:300};
    const p=40; return {minX:x0-p,minY:y0-p,w:x1-x0+p*2,h:y1-y0+p*2};
  }

  // ─── DOM / color helpers ──────────────────────────────────────────────────────
  function el(tag) { return document.createElement(tag); }
  function css(e, s) { Object.assign(e.style, s); return e; }
  function rgba(color, a) {
    if (!color||color[0]!=='#') return `rgba(156,163,175,${a})`;
    return `rgba(${parseInt(color.slice(1,3),16)},${parseInt(color.slice(3,5),16)},${parseInt(color.slice(5,7),16)},${a})`;
  }

  // ─── PlaceRender ─────────────────────────────────────────────────────────────
  class PlaceRender {
    constructor(opts) {
      this._root      = typeof opts.container==='string' ? document.getElementById(opts.container) : opts.container;
      this._data      = opts.data || {};
      this._onSel     = opts.onSeatSelected   || null;
      this._onDesel   = opts.onSeatDeselected || null;
      this._readOnly  = opts.readOnly || false;
      this._showLegend = opts.showLegend !== false;
      this._selected  = new Set(opts.selectedSeats || []);

      this._catMap     = {};
      this._statusMap  = {};
      this._seatCatMap = {};   // seatKey → catId

      this._bbox      = null;
      this._zoom      = 1;
      this._panX      = 0;
      this._panY      = 0;
      this._dragging  = false;
      this._dragStart = {x:0,y:0};
      this._didDrag   = false;

      this._canvas    = null;
      this._viewport  = null;
      this._tooltip   = null;
      this._mmWrap    = null;
      this._mmRect    = null;
      this._zoomBadge = null;
      this._fsBtn     = null;
      this._cw        = 0;
      this._ch        = 0;

      this._seatSectionMap = {}; // seatKey → section label
      this._lensWrap  = null;
      this._secBadges = []; // center badges shown at low zoom
      this._minZoom   = 0.1;
      this._animFrame = null;

      // set by widget to refresh checkout bar on selection change
      this._onSelectionChange = null;

      this._activePointers = new Map(); // pointerId → {x,y} for pinch-to-zoom
      this._pinchDist  = null;
      this._pinchZoom  = null;
      this._mobileStep = 0; // 0=overview, 1=section zoomed, 2=seat zoomed
      this._mobilePendingTooltip = null;
      this._currentSectionEl = null; // section element active au step 1+
      this._filterCatId = null; // active category filter

      this._boundMove = this._onPointerMove.bind(this);
      this._boundUp   = this._onPointerUp.bind(this);
    }

    // ── public ──────────────────────────────────────────────────────────────────

    render() {
      this._injectBounceStyle();
      this._buildMaps();
      this._setupDOM();
      this._drawAll();
      this._fitToContainer();
      this._updateMinimap();
      return this;
    }

    updateSeats(seats) {
      // Full replace when called with all seats (initial load); merge when called with a partial list (Mercure)
      for (const s of seats||[]) {
        this._statusMap[s.seatKey] = s.status;
        // Deselect seats that are no longer available
        if (s.status !== 'available' && this._selected.has(s.seatKey)) {
          this._selected.delete(s.seatKey);
          const catId = this._seatCatMap[s.seatKey] || null;
          if (this._onDesel) this._onDesel({ seatKey: s.seatKey, catId, catColor: this._catColor(catId), catName: this._catName(catId) });
        }
      }
      if (this._onSelectionChange) this._onSelectionChange();
      this._refreshColors();
    }

    /** Replace the full seat status map (initial load only) */
    _resetSeats(seats) {
      this._statusMap = {};
      for (const s of seats||[]) this._statusMap[s.seatKey] = s.status;
    }

    /** Returns [{ seatKey, catId, catColor, catName }] */
    onFullscreenChange(inFs) {
      const root = this._root;
      root.style.transition = 'opacity 300ms ease';
      root.style.opacity = '0';
      requestAnimationFrame(() => requestAnimationFrame(() => {
        this._cw = root.clientWidth;
        this._ch = root.clientHeight;
        if (inFs) {
          const {w, h, minX, minY} = this._bbox;
          const scale = Math.min(Math.min(this._cw / w, this._ch / h) * 0.92, 1.8);
          const px = -minX * scale + (this._cw - w * scale) / 2;
          const py = -minY * scale + (this._ch - h * scale) / 2;
          this._animateZoom(scale, px, py, 380);
          if (this._zoomOutBtn) this._zoomOutBtn.style.display = '';
          this._updateZoomOutBtn();
        } else {
          this._fitToContainer();
          this._updateZoomOutBtn();
        }
        this._updateMinimap();
        root.style.opacity = '1';
        setTimeout(() => { root.style.transition = ''; }, 320);
      }));
    }

    getSelectedSeats() {
      return [...this._selected].map(key => {
        const catId = this._seatCatMap[key] || null;
        return { seatKey:key, catId, catColor:this._catColor(catId), catName:this._catName(catId) };
      });
    }

    selectSeats(keys) {
      let changed = false;
      for (const key of keys) {
        if (this._bookingStatus(key) === 'available' && !this._selected.has(key)) {
          this._selected.add(key);
          changed = true;
          // Fire onSeatSelected per seat, same as a manual click
          if (this._onSel) {
            const catId = this._seatCatMap[key];
            this._onSel({ seatKey: key, catId, catColor: this._catColor(catId), catName: this._catName(catId) });
          }
        }
      }
      if (changed) {
        this._refreshColors();
        this._updateLens();
        if (this._onSelectionChange) this._onSelectionChange();
      }
    }

    reload({ chartObjects, categories, seats }) {
      // Preserve selections and seat status map
      const prevSelected = new Set(this._selected);
      this._data = { ...this._data, chartObjects, categories: categories || this._data.categories, seats: seats || [] };
      this._buildMaps();
      // Re-apply previous seat statuses on top of fresh server data
      for (const key of prevSelected) this._selected.add(key);
      // Clear canvas and redraw
      if (this._canvas) this._canvas.innerHTML = '';
      this._secBadges = [];
      this._drawAll();
      this._fitToContainer();
      this._updateMinimap();
    }

    destroy() {
      document.removeEventListener('pointermove', this._boundMove);
      document.removeEventListener('pointerup',   this._boundUp);
      if (this._root) this._root.innerHTML = '';
    }

    // ── maps ────────────────────────────────────────────────────────────────────

    _buildMaps() {
      for (const c of this._data.categories||[]) this._catMap[c.id] = c;
      // Merge external price data (keyed by catId) into catMap
      for (const [catId, info] of Object.entries(this._data.categoryPrices||{})) {
        if (this._catMap[catId]) Object.assign(this._catMap[catId], info);
      }
      for (const s of this._data.seats||[]) this._statusMap[s.seatKey] = s.status;
    }

    _catColor(id) { return this._catMap[id]?.color || '#6366f1'; }
    _catName(id)  { return this._catMap[id]?.name  || ''; }
    _bookingStatus(key) { return this._statusMap[key] || 'available'; }

    // ── DOM setup ───────────────────────────────────────────────────────────────

    _injectBounceStyle() {
      if (document.getElementById('pr-animate-css')) return;
      const l = document.createElement('link');
      l.id   = 'pr-animate-css';
      l.rel  = 'stylesheet';
      l.href = 'https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css';
      document.head.appendChild(l);
    }

    _setupDOM() {
      const root = this._root;
      root.innerHTML = '';
      css(root, {
        position:'relative', overflow:'hidden', fontFamily:'system-ui,sans-serif',
        background:'#ffffff', userSelect:'none',
        width:root.style.width||'100%', height:root.style.height||'100%',
      });
      this._cw = root.clientWidth  || 600;
      this._ch = root.clientHeight || 500;
      this._bbox = computeBbox(this._data.chartObjects||[]);

      const vp = css(el('div'), {position:'absolute',inset:'0',overflow:'hidden',cursor:'grab',touchAction:'none'});
      root.appendChild(vp);
      this._viewport = vp;

      const canvas = css(el('div'), {position:'absolute',top:'0',left:'0',transformOrigin:'0 0',background:'#ffffff',willChange:'transform'});
      vp.appendChild(canvas);
      this._canvas = canvas;

      this._tooltip = this._buildTooltip(root);
      this._buildFullscreenBtn(root);
      this._buildControls(root);
      this._buildMinimap(root);
      this._buildLens(root);
      this._buildMobileModal(root);
      this._buildLegend(root);

      vp.addEventListener('wheel',       this._onWheel.bind(this), {passive:false});
      vp.addEventListener('pointerdown', this._onPointerDown.bind(this));
      document.addEventListener('pointermove', this._boundMove);
      document.addEventListener('pointerup',   this._boundUp);
    }

    // ── zoom / pan ──────────────────────────────────────────────────────────────

    _overviewZoom() {
      // Initial zoom = min(fit-to-container, 50%) so the plan always fills the div
      const {w, h} = this._bbox;
      const fit = Math.min(this._cw / w, this._ch / h) * 0.92;
      return Math.min(fit, 0.5);
    }

    _fitToContainer() {
      const {w,h,minX,minY} = this._bbox;
      const scale = this._overviewZoom();
      this._minZoom = scale;
      this._maxWheelZoom = scale * 1.8;
      this._zoom = scale;
      this._panX = -minX*scale + (this._cw - w*scale)/2;
      this._panY = -minY*scale + (this._ch - h*scale)/2;
      this._mobileStep = 0;
      this._applyTransform();
    }

    // Animate back to overview zoom centered on the plan
    _zoomToFit50(dur) {
      const {w,h,minX,minY} = this._bbox;
      const scale = this._overviewZoom();
      const px2 = -minX*scale + (this._cw - w*scale)/2;
      const py2 = -minY*scale + (this._ch - h*scale)/2;
      this._mobileStep = 0;
      this._mobilePendingTooltip = null;
      this._currentSectionEl = null;
      this._hideTooltip();
      this._animateZoom(scale, px2, py2, dur || 380);
    }

    _applyTransform(animated) {
      if (animated) {
        this._canvas.style.transition = 'none';
      } else {
        this._canvas.style.transition = 'none';
      }
      this._canvas.style.transform = `translate(${this._panX}px,${this._panY}px) scale(${this._zoom})`;
      if (this._mmWrap) this._mmWrap.style.display = this._zoom > 1 ? 'block' : 'none';
      this._updateZoomOutBtn();
      this._updateMinimap();
      this._updateLens();
      this._updateSecBadges();
    }

    _updateSecBadges() {
      const show = this._zoom <= 0.6;
      const scale = show ? Math.min(3, 1 / this._zoom) : 1;
      for (const b of this._secBadges) {
        const hovering = !!(b._wrapEl && b._wrapEl.matches(':hover'));
        const visible = show && !hovering;
        b.style.display = visible ? '' : 'none';
        if (show) b.style.transform = `translate(-50%,-50%) scale(${scale})`;
        if (b._seatsEl) b._seatsEl.style.filter = visible ? 'blur(1.5px)' : '';
      }
    }

    // ── animation ───────────────────────────────────────────────────────────────

    _animateZoom(z2, px2, py2, dur) {
      dur = dur || 340;
      if (this._animFrame) { cancelAnimationFrame(this._animFrame); this._animFrame = null; }
      if (this._lensWrap) this._lensWrap.innerHTML = '';

      // On mobile: use CSS transition (GPU-accelerated, no JS per-frame overhead)
      if (this._isMobile()) {
        this._zoom = z2; this._panX = px2; this._panY = py2;
        this._canvas.style.transition = `transform ${dur}ms cubic-bezier(0.32,0.72,0,1)`;
        this._canvas.style.transform  = `translate(${px2}px,${py2}px) scale(${z2})`;
        if (this._mmWrap) this._mmWrap.style.display = z2 > 1 ? 'block' : 'none';
        // Mark animating so taps are blocked; clear after transition ends
        this._animFrame = 1; // truthy sentinel
        const onEnd = () => {
          this._canvas.style.transition = 'none';
          this._canvas.removeEventListener('transitionend', onEnd);
          this._animFrame = null;
          this._updateMinimap();
          this._updateZoomOutBtn();
          this._updateSecBadges();
          this._updateLens();
          if (this._mobilePendingTooltip) {
            const { section, catId, el: pendingEl } = this._mobilePendingTooltip;
            this._mobilePendingTooltip = null;
            this._showMobileSectionLabel(section, catId, pendingEl);
          }
        };
        this._canvas.addEventListener('transitionend', onEnd, {once: true});
        // Safety fallback in case transitionend doesn't fire
        setTimeout(() => { if (this._animFrame === 1) onEnd(); }, dur + 100);
        return;
      }

      const z1 = this._zoom, px1 = this._panX, py1 = this._panY;
      const t0 = performance.now();
      const tick = (now) => {
        let t = Math.min(1, (now - t0) / dur);
        t = 1 - Math.pow(1 - t, 3);
        this._zoom = z1 + (z2 - z1) * t;
        this._panX = px1 + (px2 - px1) * t;
        this._panY = py1 + (py2 - py1) * t;
        this._canvas.style.transform = `translate(${this._panX}px,${this._panY}px) scale(${this._zoom})`;
        if (this._mmWrap) this._mmWrap.style.display = this._zoom > 1 ? 'block' : 'none';
        this._updateMinimap();
        this._updateZoomOutBtn();
        if (t < 1) {
          this._animFrame = requestAnimationFrame(tick);
        } else {
          this._animFrame = null;
          this._updateLens();
          this._updateSecBadges();
        }
      };
      this._animFrame = requestAnimationFrame(tick);
    }

    _zoomCenteredOn(cx, cy, ratio) {
      const nz = Math.max(this._minZoom, Math.min(6, this._zoom*ratio));
      const r  = nz/this._zoom;
      this._panX = cx - r*(cx - this._panX);
      this._panY = cy - r*(cy - this._panY);
      this._zoom = nz;
      this._applyTransform(true);
      this._updateMinimap();
    }

    _zoomCenteredOnSmooth(cx, cy, ratio) {
      const nz  = Math.max(this._minZoom, Math.min(6, this._zoom * ratio));
      const r   = nz / this._zoom;
      const px2 = cx - r * (cx - this._panX);
      const py2 = cy - r * (cy - this._panY);
      this._animateZoom(nz, px2, py2);
    }

    _zoomToLevel(targetZoom, cx, cy) {
      const z2  = Math.max(this._minZoom, Math.min(6, targetZoom));
      const r   = z2 / this._zoom;
      const px2 = cx - r * (cx - this._panX);
      const py2 = cy - r * (cy - this._panY);
      this._animateZoom(z2, px2, py2, 280);
    }

    // Zoom to fit a canvas-local bounding box in the viewport
    _zoomToFitBbox(bx, by, bw, bh) {
      const pad   = 60;
      const z2    = Math.min((this._cw - pad*2) / Math.max(bw, 1), (this._ch - pad*2) / Math.max(bh, 1), 5);
      const px2   = -(bx + bw/2) * z2 + this._cw / 2;
      const py2   = -(by + bh/2) * z2 + this._ch / 2;
      this._animateZoom(Math.max(this._minZoom, z2), px2, py2, 420);
    }

    _onWheel(e) {
      e.preventDefault();
    }

    _onPointerDown(e) {
      // Minimap drag: detect if pointerdown is over the minimap vpr
      if (this._mmVpr && this._mmWrap && this._mmWrap.style.display !== 'none') {
        const mmBr  = this._mmWrap.getBoundingClientRect();
        const vprX  = parseFloat(this._mmVpr.getAttribute('x')  || 0);
        const vprY  = parseFloat(this._mmVpr.getAttribute('y')  || 0);
        const vprW  = parseFloat(this._mmVpr.getAttribute('width')  || 0);
        const vprH  = parseFloat(this._mmVpr.getAttribute('height') || 0);
        const ms    = this._mmMs || 1;
        // Hit-test against the blue rect area (with generous padding for touch)
        const lx = e.clientX - mmBr.left;
        const ly = e.clientY - mmBr.top;
        const pad = 12;
        if (lx >= vprX - pad && lx <= vprX + vprW + pad &&
            ly >= vprY - pad && ly <= vprY + vprH + pad) {
          this._mmDragging   = true;
          this._mmDragStart  = {x: e.clientX, y: e.clientY, panX: this._panX, panY: this._panY};
          this._mmDragPtId   = e.pointerId;
          css(this._mmVpr, {cursor:'grabbing'});
          e.stopPropagation();
          return;
        }
      }

      this._activePointers.set(e.pointerId, {x: e.clientX, y: e.clientY});
      if (this._activePointers.size === 2) {
        this._dragging = false;
        const pts = [...this._activePointers.values()];
        this._pinchDist = Math.hypot(pts[1].x - pts[0].x, pts[1].y - pts[0].y);
        this._pinchZoom = this._zoom;
        this._pinchPanX = this._panX;
        this._pinchPanY = this._panY;
        this._pinchMidX = (pts[0].x + pts[1].x) / 2;
        this._pinchMidY = (pts[0].y + pts[1].y) / 2;
      } else if (this._activePointers.size === 1 && e.button === 0) {
        this._dragging  = true;
        this._didDrag   = false;
        this._dragStart = {x: e.clientX - this._panX, y: e.clientY - this._panY};
        this._viewport.style.cursor = 'grabbing';
      }
    }

    _onPointerMove(e) {
      // Minimap drag takes priority
      if (this._mmDragging && e.pointerId === this._mmDragPtId) {
        const ms    = this._mmMs, minX = this._mmMinX, minY = this._mmMinY;
        const MW    = this._mmMW, MH   = this._mmMH;
        const ox    = this._mmOx, oy   = this._mmOy;
        const dx    = e.clientX - this._mmDragStart.x;
        const dy    = e.clientY - this._mmDragStart.y;
        let newPanX = this._mmDragStart.panX - this._zoom * dx / ms;
        let newPanY = this._mmDragStart.panY - this._zoom * dy / ms;
        const vprW  = (this._cw / this._zoom) * ms;
        const vprH  = (this._ch / this._zoom) * ms;
        const rectX = ox + (-newPanX / this._zoom - minX) * ms;
        const rectY = oy + (-newPanY / this._zoom - minY) * ms;
        newPanX -= (Math.max(0, Math.min(MW - vprW, rectX)) - rectX) * this._zoom / ms;
        newPanY -= (Math.max(0, Math.min(MH - vprH, rectY)) - rectY) * this._zoom / ms;
        this._panX = newPanX;
        this._panY = newPanY;
        this._applyTransform(false);
        return;
      }

      if (!this._activePointers.has(e.pointerId)) return;
      this._activePointers.set(e.pointerId, {x: e.clientX, y: e.clientY});

      if (this._activePointers.size === 2) {
        const pts  = [...this._activePointers.values()];
        const dist = Math.hypot(pts[1].x - pts[0].x, pts[1].y - pts[0].y);
        const midX = (pts[0].x + pts[1].x) / 2;
        const midY = (pts[0].y + pts[1].y) / 2;
        const scale = dist / (this._pinchDist || dist);
        const nz = Math.max(this._minZoom, Math.min(6, this._pinchZoom * scale));
        const r  = nz / (this._pinchZoom || nz);
        this._panX = midX - r * (this._pinchMidX - this._pinchPanX) - this._pinchMidX + midX;
        this._panY = midY - r * (this._pinchMidY - this._pinchPanY) - this._pinchMidY + midY;
        this._zoom = nz;
        this._applyTransform();
        return;
      }

      if (!this._dragging) return;
      const dx = e.clientX - this._dragStart.x - this._panX;
      const dy = e.clientY - this._dragStart.y - this._panY;
      if (Math.abs(dx)+Math.abs(dy) > (this._isMobile() ? 14 : 8)) {
        if (!this._didDrag) this._hideTooltip();
        this._didDrag = true;
      }
      this._panX = e.clientX - this._dragStart.x;
      this._panY = e.clientY - this._dragStart.y;
      this._applyTransform();
      this._updateMinimap();
    }

    _onPointerUp(e) {
      if (this._mmDragging && e && e.pointerId === this._mmDragPtId) {
        this._mmDragging = false;
        if (this._mmVpr) css(this._mmVpr, {cursor:'grab'});
        return;
      }
      if (e) this._activePointers.delete(e.pointerId);
      if (this._activePointers.size < 2) this._pinchDist = null;
      if (this._activePointers.size === 0) {
        this._dragging = false;
        if (this._viewport) this._viewport.style.cursor = 'grab';
      }
    }

    // ── fullscreen button (top-right) ────────────────────────────────────────────

    _buildFullscreenBtn(root) {
      // When embedded in an iframe, the host app handles fullscreen — hide this button
      if (window !== window.top) return;
      const btn = css(el('button'), {
        position:'absolute', top:'10px', right:'10px', zIndex:'110',
        display:'inline-flex', alignItems:'center', gap:'6px',
        padding:'6px 12px', border:'none', borderRadius:'999px',
        background:'rgba(255,255,255,0.92)', backdropFilter:'blur(4px)',
        boxShadow:'0 1px 6px rgba(0,0,0,0.10), 0 0 0 1px rgba(0,0,0,0.07)',
        cursor:'pointer', fontSize:'13px', fontWeight:'500', color:'#1f2937',
        transition:'background 0.15s',
        whiteSpace:'nowrap',
      });
      const iconExpand = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>`;
      const iconShrink = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/></svg>`;
      const span = el('span');
      const isMobile = () => window.innerWidth < 768;
      const setFs = (inFs) => {
        btn.innerHTML = '';
        const ic = el('span'); ic.innerHTML = inFs ? iconShrink : iconExpand;
        ic.style.display = 'flex';
        btn.appendChild(ic);
        if (!isMobile()) {
          span.textContent = inFs ? 'Quitter le plein écran' : 'Plein écran';
          btn.appendChild(span);
        }
        btn.style.padding = isMobile() ? '7px' : '6px 12px';
      };
      setFs(false);
      btn.addEventListener('mouseenter', () => btn.style.background = '#ffffff');
      btn.addEventListener('mouseleave', () => btn.style.background = 'rgba(255,255,255,0.92)');
      const enterFs = () => {
        if (this._isMobile()) {
          const p = root.requestFullscreen
            ? root.requestFullscreen({ navigationUI: 'hide' })
            : root.webkitRequestFullscreen
            ? (root.webkitRequestFullscreen(), Promise.resolve())
            : Promise.reject(new Error('not supported'));
          p.catch(() => {});
        } else {
          window.parent.postMessage({ type: 'placio:requestFullscreen' }, '*');
          this._isFullscreen = true;
          setFs(true);
          fadeAndRender(true);
        }
      };
      const exitFs = () => {
        if (this._isMobile()) {
          if (document.exitFullscreen) document.exitFullscreen();
          else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
        } else {
          window.parent.postMessage({ type: 'placio:exitFullscreen' }, '*');
          this._isFullscreen = false;
          setFs(false);
          fadeAndRender(false);
        }
      };
      const fadeAndRender = (inFs) => {
        root.style.transition = 'opacity 300ms ease';
        root.style.opacity = '0';
        requestAnimationFrame(() => requestAnimationFrame(() => {
          this._cw = root.clientWidth;
          this._ch = root.clientHeight;
          if (inFs) {
            const {w,h,minX,minY} = this._bbox;
            const scale = 1.5;
            const px = -minX*scale + (this._cw - w*scale)/2;
            const py = -minY*scale + (this._ch - h*scale)/2;
            this._animateZoom(scale, px, py, 380);
            if (this._zoomOutBtn) this._zoomOutBtn.style.display = 'none';
          } else {
            this._fitToContainer();
            this._updateZoomOutBtn();
          }
          this._updateMinimap();
          root.style.opacity = '1';
          setTimeout(() => { root.style.transition = ''; }, 320);
        }));
      };
      const onFsChange = () => {
        const inFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
        this._isFullscreen = inFs;
        setFs(inFs);
        fadeAndRender(inFs);
      };
      document.addEventListener('fullscreenchange', onFsChange);
      document.addEventListener('webkitfullscreenchange', onFsChange);
      btn.addEventListener('click', () => {
        this._isFullscreen ? exitFs() : enterFs();
      });
      root.appendChild(btn);
    }

    // ── zoom-out button (bottom-left, visible when zoom > 50%) ──────────────────

    _buildControls(root) {
      // Zoom-out magnifier: returns to 50% overview
      const btn = css(el('button'), {
        position:'absolute', bottom:'10px', left:'10px', zIndex:'110',
        width:'40px', height:'40px', borderRadius:'50%',
        background:'#fff', border:'none',
        boxShadow:'0 2px 10px rgba(0,0,0,0.14)',
        cursor:'pointer', display:'flex',
        alignItems:'center', justifyContent:'center', padding:'0',
        opacity:'0', pointerEvents:'none',
        transition:'transform 0.15s, box-shadow 0.15s, opacity 0.25s ease',
      });
      btn.title = 'Vue d\'ensemble (50%)';
      btn.innerHTML = `<svg width="20" height="20" viewBox="0 0 20 20" fill="none">
        <circle cx="8.5" cy="8.5" r="5.5" stroke="#374151" stroke-width="1.8"/>
        <line x1="5.5" y1="8.5" x2="11.5" y2="8.5" stroke="#374151" stroke-width="1.8" stroke-linecap="round"/>
        <line x1="12.5" y1="12.5" x2="16" y2="16" stroke="#374151" stroke-width="1.8" stroke-linecap="round"/>
      </svg>`;
      btn.addEventListener('mouseenter', () => { btn.style.boxShadow='0 4px 16px rgba(0,0,0,0.2)'; btn.style.transform='scale(1.08)'; });
      btn.addEventListener('mouseleave', () => { btn.style.boxShadow='0 2px 10px rgba(0,0,0,0.14)'; btn.style.transform=''; });
      btn.addEventListener('click', () => this._zoomToFit50());
      this._zoomOutBtn = btn;
      root.appendChild(btn);
    }

    _updateZoomOutBtn() {
      if (!this._zoomOutBtn) return;
      const show = !this._isFullscreen && (this._mobileStep >= 1 || this._zoom > 0.55);
      this._zoomOutBtn.style.opacity = show ? '1' : '0';
      this._zoomOutBtn.style.pointerEvents = show ? '' : 'none';
    }

    // ── minimap ─────────────────────────────────────────────────────────────────

    _buildMinimap(root) {
      const isMobile = this._cw < 600;
      const MW=Math.round(Math.min(isMobile?180:260, Math.max(isMobile?100:140, this._cw * 0.22)));
      const MH=Math.round(Math.min(isMobile?120:180, Math.max(isMobile?70:90,   this._ch * 0.22)));
      // On mobile, sit above the zoom-out button (bottom:60px) to avoid overlap
      const mmBottom = isMobile ? '60px' : '10px';
      const wrap = css(el('div'), {
        position:'absolute', bottom:mmBottom, right:'10px', zIndex:'20',
        width:MW+'px', height:MH+'px',
        background:'rgba(255,255,255,0.95)', border:'1px solid #e2e8f0',
        borderRadius:'8px', boxShadow:'0 2px 8px rgba(0,0,0,0.08)', overflow:'hidden',
        display:'none',
      });
      this._mmWrap = wrap;


      const {w,h,minX,minY} = this._bbox;
      const ms = Math.min((MW-8)/w, (MH-20)/h);
      const ox=4, oy=16;
      this._mmScale=ms; this._mmOffX=ox; this._mmOffY=oy;

      const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
      svg.setAttribute('width',MW); svg.setAttribute('height',MH);
      css(svg, {position:'absolute',top:'0',left:'0'});

      for (const o of this._data.chartObjects||[]) {
        const isFZ = o._type==='freeZone';
        const color = isFZ ? (o.color||'#6b7280') : this._catColor(o.categoryId);
        const rx=(o.left||0)-minX, ry=(o.top||0)-minY;
        let rw=0, rh=0;
        if (o._type==='zone'||isFZ)       { rw=o.width||80; rh=o.height||60; }
        else if (o._type==='seatRow')     { const ss=o.seatSize||22,g=o.seatGap??4; rw=(o.cols||1)*(ss+g); rh=(o.rows||1)*(ss+g)+14; }
        else if (o._type==='tableZone')   { const s=tableZoneSize(o); rw=s; rh=s; }
        else if (o._type==='tableSection'){ rw=tsSectionWidth(o); rh=tsSectionHeight(o); }
        if (!rw||!rh) continue;
        const px=ox+rx*ms, py=oy+ry*ms, pw=Math.max(3,rw*ms), ph=Math.max(3,rh*ms);
        const rect = document.createElementNS('http://www.w3.org/2000/svg','rect');
        rect.setAttribute('x', px); rect.setAttribute('y', py);
        rect.setAttribute('width', pw); rect.setAttribute('height', ph);
        rect.setAttribute('rx', 2);
        rect.setAttribute('fill', rgba(color, isFZ ? 0.55 : 0.25));
        rect.setAttribute('stroke', rgba(color, isFZ ? 0.8 : 0.65));
        rect.setAttribute('stroke-width', '0.8');
        svg.appendChild(rect);
      }

      const vpr = document.createElementNS('http://www.w3.org/2000/svg','rect');
      vpr.setAttribute('fill','rgba(59,130,246,0.08)');
      vpr.setAttribute('stroke','#3b82f6');
      vpr.setAttribute('stroke-width','1.5');
      vpr.setAttribute('rx','2');
      svg.appendChild(vpr);
      this._mmRect = vpr;

      css(vpr, {cursor:'grab'});
      css(svg, {touchAction:'none'});
      this._mmVpr  = vpr;
      this._mmMs   = ms; this._mmOx = ox; this._mmOy = oy;
      this._mmMinX = minX; this._mmMinY = minY;
      this._mmMW   = MW;  this._mmMH  = MH;

      // Attach pointerdown directly on the SVG wrap so it fires even over the viewport
      svg.addEventListener('pointerdown', (e) => {
        const mmBr = wrap.getBoundingClientRect();
        const lx = e.clientX - mmBr.left;
        const ly = e.clientY - mmBr.top;
        const vx = parseFloat(vpr.getAttribute('x')      || 0);
        const vy = parseFloat(vpr.getAttribute('y')      || 0);
        const vw = parseFloat(vpr.getAttribute('width')  || 0);
        const vh = parseFloat(vpr.getAttribute('height') || 0);
        const pad = 14;
        if (lx >= vx - pad && lx <= vx + vw + pad && ly >= vy - pad && ly <= vy + vh + pad) {
          this._mmDragging  = true;
          this._mmDragStart = {x: e.clientX, y: e.clientY, panX: this._panX, panY: this._panY};
          this._mmDragPtId  = e.pointerId;
          svg.setPointerCapture(e.pointerId);
          css(vpr, {cursor:'grabbing'});
          e.stopPropagation();
          e.preventDefault();
        }
      }, {passive: false});

      svg.addEventListener('pointermove', (e) => {
        if (!this._mmDragging || e.pointerId !== this._mmDragPtId) return;
        const ms = this._mmMs, minX = this._mmMinX, minY = this._mmMinY;
        const MW = this._mmMW,  MH  = this._mmMH;
        const ox = this._mmOx,  oy  = this._mmOy;
        const dx = e.clientX - this._mmDragStart.x;
        const dy = e.clientY - this._mmDragStart.y;
        let newPanX = this._mmDragStart.panX - this._zoom * dx / ms;
        let newPanY = this._mmDragStart.panY - this._zoom * dy / ms;
        const vprW  = (this._cw / this._zoom) * ms;
        const vprH  = (this._ch / this._zoom) * ms;
        const rectX = ox + (-newPanX / this._zoom - minX) * ms;
        const rectY = oy + (-newPanY / this._zoom - minY) * ms;
        newPanX -= (Math.max(0, Math.min(MW - vprW, rectX)) - rectX) * this._zoom / ms;
        newPanY -= (Math.max(0, Math.min(MH - vprH, rectY)) - rectY) * this._zoom / ms;
        this._panX = newPanX; this._panY = newPanY;
        this._applyTransform(false);
        e.stopPropagation();
      });

      svg.addEventListener('pointerup', (e) => {
        if (!this._mmDragging || e.pointerId !== this._mmDragPtId) return;
        this._mmDragging = false;
        svg.releasePointerCapture(e.pointerId);
        css(vpr, {cursor:'grab'});
        e.stopPropagation();
      });

      wrap.appendChild(svg);
      root.appendChild(wrap);
    }

    _updateMinimap() {
      if (!this._mmRect) return;
      const {minX,minY}=this._bbox, ms=this._mmScale, ox=this._mmOffX, oy=this._mmOffY;
      this._mmRect.setAttribute('x',      ox + (-this._panX/this._zoom - minX)*ms);
      this._mmRect.setAttribute('y',      oy + (-this._panY/this._zoom - minY)*ms);
      this._mmRect.setAttribute('width',  Math.max(4,(this._cw/this._zoom)*ms));
      this._mmRect.setAttribute('height', Math.max(4,(this._ch/this._zoom)*ms));
    }

    // ── tooltip ──────────────────────────────────────────────────────────────────

    _buildTooltip(root) {
      const tip = css(el('div'), {
        position:'absolute', zIndex:'50', pointerEvents:'none',
        background:'#fff', border:'1px solid #e5e7eb',
        borderRadius:'12px', boxShadow:'0 4px 20px rgba(0,0,0,0.12)',
        minWidth:'180px', overflow:'hidden',
        opacity:'0', visibility:'hidden',
        transition:'opacity 0.15s ease, visibility 0.15s ease',
      });
      root.appendChild(tip);
      return tip;
    }

    _showTooltip(seatEl, info) {
      const {key, section, rowLabel, colLabel, label, catId, planStatus} = info;
      const color=this._catColor(catId), name=this._catName(catId);
      const cat  = this._catMap[catId];
      const price = cat?.price != null
        ? new Intl.NumberFormat('fr-MG').format(cat.price) + ' ' + (cat.currency || 'MGA')
        : null;
      const bs  = this._bookingStatus(key);
      const sel = this._selected.has(key);

      const isUnavailable = planStatus === 'disabled' || bs === 'booked' || bs === 'canceled' || bs === 'hold';

      let barBg, barLeft, barRight;
      if (isUnavailable) {
        barBg    = '#9ca3af';
        barLeft  = `<span style="font-size:16px;font-weight:700;color:#fff">${bs==='hold' ? 'En attente' : 'Indisponible'}</span>`;
        barRight = '';
      } else if (sel) {
        barBg   = color;
        barLeft = `<div style="display:flex;align-items:center;gap:8px">
          <span style="width:20px;height:20px;border-radius:50%;background:rgba(255,255,255,0.25);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0"><svg viewBox="0 0 12 12" width="12" height="12" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1.5,6 5,9.5 10.5,2.5"/></svg></span>
          <div style="display:flex;flex-direction:column;line-height:1.3">
            <span style="font-size:14px;font-weight:800;color:#fff">Sélectionné</span>
            <span style="font-size:13px;font-weight:700;color:rgba(255,255,255,0.85)">${name}</span>
          </div>
        </div>`;
        barRight = price ? `<span style="font-size:20px;font-weight:800;color:#fff;white-space:nowrap">${price}</span>` : '';
      } else {
        barBg    = color;
        barLeft  = `<span style="font-size:16px;font-weight:700;color:#fff">${name}</span>`;
        barRight = price ? `<span style="font-size:20px;font-weight:800;color:#fff;white-space:nowrap">${price}</span>` : '';
      }

      this._tooltip.innerHTML = `
        <div style="display:flex;gap:0;padding:10px 4px 6px">
          ${[['Section',section||'—'],['Rangée',rowLabel||'—'],['Siège',colLabel||label||'—']].map(([k,v])=>`
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;padding:0 10px">
              <span style="font-size:10px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap">${k}</span>
              <span style="font-size:16px;font-weight:700;color:#111827;margin-top:3px;white-space:nowrap">${v}</span>
            </div>`).join('<div style="width:1px;background:#f3f4f6;margin:4px 0"></div>')}
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 14px;background:${barBg};margin-top:4px;gap:10px">
          ${barLeft}${barRight}
        </div>`;
      const cr=this._root.getBoundingClientRect(), er=seatEl.getBoundingClientRect();
      let left=er.left-cr.left+er.width/2-90;
      let top=er.top-cr.top-this._tooltip.offsetHeight-8;
      if (top<4) top=er.top-cr.top+er.height+8;
      this._tooltip.style.borderRadius = '12px';
      this._tooltip.style.minWidth = '180px';
      this._tooltip.style.left = Math.max(4,Math.min(left,this._cw-188))+'px';
      this._tooltip.style.top  = top+'px';
      this._tooltip.style.visibility='visible';
      this._tooltip.style.opacity='1';
    }
    _hideTooltip() { this._tooltip.style.opacity='0'; this._tooltip.style.visibility='hidden'; }

    _isMobile() { return window.innerWidth < 768; }

    _applyFilter(catId) {
      this._filterCatId = catId;
      this._canvas.querySelectorAll('[data-plancat]').forEach(node => {
        const match = !catId || node.dataset.plancat === catId;
        node.style.opacity    = match ? '1'   : '0.15';
        node.style.filter     = match ? ''    : 'blur(1.5px)';
        node.style.transition = 'opacity 0.2s, filter 0.2s';
        node.style.pointerEvents = match ? '' : 'none';
      });
      this._updateLegendState();
    }

    _updateLegendState() {
      if (!this._legendPills) return;
      const catId = this._filterCatId;
      const cats  = Object.values(this._catMap);
      for (const cat of cats) {
        const pill  = this._legendPills[cat.id];
        if (!pill) continue;
        const color    = cat.color || '#6366f1';
        const selected = catId && cat.id === catId;   // this specific cat is filtered
        const dimmed   = catId && cat.id !== catId;   // another cat is filtered
        if (this._isMobile()) {
          pill.style.background = selected ? color : '#fff';
          pill.style.border     = selected ? `2px solid ${color}` : '2px solid #e5e7eb';
          pill.style.boxShadow  = selected ? `0 2px 6px ${color}33` : '0 1px 3px rgba(0,0,0,0.06)';
          pill.style.opacity    = dimmed ? '0.4' : '1';
          pill.querySelectorAll('span').forEach(s => {
            s.style.color = selected ? '#fff' : '#111827';
          });
          const dot = pill.querySelector('[data-dot]');
          if (dot) dot.style.background = selected ? 'rgba(255,255,255,0.6)' : (cat.color || '#6366f1');
        } else {
          pill.style.opacity    = dimmed ? '0.4' : '1';
          pill.style.border     = selected ? `2px solid ${color}` : '2px solid #e5e7eb';
          pill.style.background = selected ? `${color}12` : '#fff';
          pill.style.boxShadow  = selected ? `0 4px 12px ${color}44` : '0 1px 3px rgba(0,0,0,0.06)';
        }
      }
      // Reserved pill
      const rp = this._legendPills.__reserved;
      if (rp) rp.style.opacity = !catId ? '1' : '0.35';
      // "Tout afficher" + count
      if (this._legendShowAll) {
        this._legendShowAll.style.display = catId ? 'flex' : 'none';
      }
    }

    _buildLegend(root) {
      if (!this._showLegend) return;
      const cats = Object.values(this._catMap);
      if (!cats.length) return;
      const mobile = this._isMobile();

      const style = document.createElement('style');
      style.textContent = '.pr-legend::-webkit-scrollbar{display:none}';
      document.head.appendChild(style);

      const bar = css(el('div'), {
        position:'absolute', top:'0', left:'0', right:'0', zIndex:'25',
        background:'rgba(255,255,255,0.95)',
        backdropFilter:'blur(6px)',
        borderBottom:'1px solid rgba(0,0,0,0.06)',
      });
      bar.className = 'pr-legend';
      this._legendPills = {};

      // Pills — 2-column grid on mobile, horizontal scroll on desktop
      const rootH = root.getBoundingClientRect().height || 500;
      const maxLegendH = Math.min(Math.round(rootH * 0.35), 160);
      const grid = css(el('div'), mobile ? {
        display:'grid', gridTemplateColumns:'1fr 1fr',
        gap:'4px', padding:'6px 10px 6px', overflowY:'auto', maxHeight: maxLegendH + 'px',
        scrollbarWidth:'none',
      } : {
        display:'flex', alignItems:'center', gap:'6px',
        padding:'6px 10px', overflowX:'auto', scrollbarWidth:'none',
      });

      for (const cat of cats) {
        const color = cat.color || '#6366f1';
        const price = cat.price != null
          ? new Intl.NumberFormat('fr-MG').format(cat.price) + ' ' + (cat.currency || 'AR')
          : null;
        const pill = css(el('div'), {
          display:'inline-flex', alignItems:'center', gap:'5px',
          padding:'3px 8px', borderRadius:'999px', flexShrink:'0',
          background:'#fff', border:'2px solid #e5e7eb',
          boxShadow:'0 1px 3px rgba(0,0,0,0.06)',
          cursor:'pointer', userSelect:'none', transition:'all 0.18s ease',
        });

        const dot = css(el('span'), {
          width:'8px', height:'8px', borderRadius:'50%',
          background:color, flexShrink:'0', boxShadow:`0 0 0 2px ${color}33`,
        });
        dot.dataset.dot = '1';

        const lbl = css(el('span'), {
          fontSize:'10px', fontWeight:'700', color:'#111827', whiteSpace:'nowrap',
        });
        lbl.textContent = cat.name || '';

        pill.appendChild(dot);
        pill.appendChild(lbl);

        if (price) {
          const p = css(el('span'), {
            fontSize:'9px', fontWeight:'600', color:'#6b7280', whiteSpace:'nowrap',
          });
          p.textContent = price;
          pill.appendChild(p);
        }

        pill.addEventListener('click', () => {
          this._applyFilter(this._filterCatId === cat.id ? null : cat.id);
        });
        this._legendPills[cat.id] = pill;
        grid.appendChild(pill);
      }

      // Reserved pill
      const resPill = css(el('div'), {
        display:'inline-flex', alignItems:'center', gap:'5px',
        padding:'3px 8px', borderRadius:'999px', flexShrink:'0',
        background:'#f9fafb', border:'1px solid #e5e7eb',
        transition:'opacity 0.18s',
      });
      const resDot = css(el('span'), {width:'8px', height:'8px', borderRadius:'50%', background:'#9ca3af', flexShrink:'0'});
      const resLbl = css(el('span'), {fontSize:'10px', fontWeight:'700', color:'#9ca3af', whiteSpace:'nowrap'});
      resLbl.textContent = 'Réservé';
      resPill.appendChild(resDot); resPill.appendChild(resLbl);
      this._legendPills.__reserved = resPill;
      grid.appendChild(resPill);
      bar.appendChild(grid);

      // Desktop: chevrons + wheel-to-horizontal-scroll
      if (!mobile) {
        grid.addEventListener('wheel', (e) => {
          if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) {
            grid.scrollLeft += e.deltaY;
            e.preventDefault();
          }
        }, { passive: false });

        const chevronStyle = (side) => ({
          position:'absolute', top:'0', bottom:'0', [side]:'0', zIndex:'10',
          width:'32px', display:'flex', alignItems:'center', justifyContent:'center',
          background: side === 'left'
            ? 'linear-gradient(to right, rgba(255,255,255,1) 55%, rgba(255,255,255,0))'
            : 'linear-gradient(to left, rgba(255,255,255,1) 55%, rgba(255,255,255,0))',
          border:'none', cursor:'pointer', pointerEvents:'auto', padding:'0',
          opacity:'0', transition:'opacity 0.15s ease',
        });

        const chevL = css(el('button'), chevronStyle('left'));
        chevL.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>`;
        const chevR = css(el('button'), chevronStyle('right'));
        chevR.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>`;

        chevL.addEventListener('click', () => { grid.scrollBy({ left: -120, behavior: 'smooth' }); });
        chevR.addEventListener('click', () => { grid.scrollBy({ left:  120, behavior: 'smooth' }); });

        bar.appendChild(chevL);
        bar.appendChild(chevR);

        const updateChevrons = () => {
          const maxScroll = grid.scrollWidth - grid.clientWidth;
          chevL.style.opacity = grid.scrollLeft > 2 ? '1' : '0';
          chevL.style.pointerEvents = grid.scrollLeft > 2 ? 'auto' : 'none';
          chevR.style.opacity = maxScroll > 2 && grid.scrollLeft < maxScroll - 2 ? '1' : '0';
          chevR.style.pointerEvents = maxScroll > 2 && grid.scrollLeft < maxScroll - 2 ? 'auto' : 'none';
        };
        grid.addEventListener('scroll', updateChevrons, { passive: true });
        requestAnimationFrame(updateChevrons);
        window.addEventListener('resize', updateChevrons);
      }

      this._legendShowAll = null;

      root.appendChild(bar);
      this._legendBar = bar;
      this._viewport.style.top = mobile ? (bar.offsetHeight || 80) + 'px' : '40px';
      // Recalc after paint
      requestAnimationFrame(() => {
        if (this._viewport) this._viewport.style.top = bar.offsetHeight + 'px';
      });
    }

    _buildMobileModal(root) {
      // Backdrop
      const overlay = css(el('div'), {
        position:'absolute', inset:'0', zIndex:'200',
        background:'rgba(0,0,0,0.35)',
        opacity:'0', visibility:'hidden',
        transition:'opacity 0.2s ease, visibility 0.2s ease',
      });
      // Sheet
      const sheet = css(el('div'), {
        position:'absolute', bottom:'0', left:'0', right:'0',
        background:'#fff', borderRadius:'20px 20px 0 0',
        boxShadow:'0 -4px 32px rgba(0,0,0,0.18)',
        overflow:'hidden',
        transform:'translateY(100%)',
        transition:'transform 0.25s cubic-bezier(0.32,0.72,0,1)',
        pointerEvents:'auto',
      });
      // Handle bar
      const handle = css(el('div'), {
        width:'36px', height:'4px', borderRadius:'2px',
        background:'#d1d5db', margin:'12px auto 8px',
      });
      sheet.appendChild(handle);
      const body = el('div');
      sheet.appendChild(body);
      overlay.appendChild(sheet);
      overlay.addEventListener('pointerdown', (e) => {
        if (e.target === overlay) this._hideMobileModal();
      });
      root.appendChild(overlay);
      this._mobileOverlay = overlay;
      this._mobileSheet   = sheet;
      this._mobileBody    = body;
    }

    _showMobileModal(seatEl, info) {
      const {key, section, rowLabel, colLabel, label, catId, planStatus} = info;
      const color = this._catColor(catId), name = this._catName(catId);
      const cat   = this._catMap[catId];
      const price = cat?.price != null
        ? new Intl.NumberFormat('fr-MG').format(cat.price) + ' ' + (cat.currency || 'MGA')
        : null;
      const bs      = this._bookingStatus(key);
      const sel     = this._selected.has(key);
      const unavail = planStatus === 'disabled' || bs === 'booked' || bs === 'canceled' || bs === 'hold';

      const barBg = unavail ? '#9ca3af' : color;
      const statusLabel = unavail
        ? (bs === 'hold' ? 'En attente' : 'Indisponible')
        : name;

      this._mobileBody.innerHTML = `
        <div style="display:flex;padding:12px 8px 10px;gap:0">
          ${[['Section', section||'—'], ['Rangée', rowLabel||'—'], ['Siège', colLabel||label||'—']].map(([k,v],i) => `
            <div style="${i===0?'flex:1.4':'flex:1'};display:flex;flex-direction:column;align-items:center;padding:0 8px;min-width:0">
              <span style="font-size:10px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap">${k}</span>
              <span style="font-size:${i===0?'11':'18'}px;font-weight:800;color:#111827;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%">${v}</span>
            </div>`).join('<div style="width:1px;background:#f3f4f6;margin:4px 0;flex-shrink:0"></div>')}
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:${barBg};gap:12px">
          <span style="font-size:17px;font-weight:700;color:#fff">${statusLabel}</span>
          ${price && !unavail ? `<span style="font-size:18px;font-weight:800;color:#fff;white-space:nowrap">${price}</span>` : ''}
        </div>
        <div style="display:flex;gap:10px;padding:14px 16px;padding-bottom:calc(14px + env(safe-area-inset-bottom,0px))">
          <button id="_mm-close" style="flex:1;padding:12px;border:none;border-radius:12px;background:#f3f4f6;font-size:15px;font-weight:600;color:#374151;cursor:pointer">Fermer</button>
          ${!unavail ? `<button id="_mm-select" style="flex:2;padding:12px;border:none;border-radius:12px;background:${sel ? '#6b7280' : color};font-size:15px;font-weight:700;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px">
            ${sel ? '✕ Désélectionner' : '✓ Sélectionner'}
          </button>` : ''}
        </div>`;

      this._mobileBody.querySelector('#_mm-close')?.addEventListener('click', () => this._hideMobileModal());
      this._mobileBody.querySelector('#_mm-select')?.addEventListener('click', () => {
        this._onSeatClick(key, planStatus, seatEl);
        this._hideMobileModal();
      });

      this._mobileOverlay.style.visibility = 'visible';
      this._mobileOverlay.style.opacity    = '1';
      this._mobileSheet.style.transform    = 'translateY(0)';
    }

    _hideMobileModal() {
      this._mobileOverlay.style.opacity = '0';
      this._mobileSheet.style.transform = 'translateY(100%)';
      setTimeout(() => { this._mobileOverlay.style.visibility = 'hidden'; }, 220);
    }

    _showMobileSectionLabel(section, catId, sectionEl) {
      const color = this._catColor(catId), name = this._catName(catId);
      const cat   = this._catMap[catId];
      const price = cat?.price != null
        ? new Intl.NumberFormat('fr-MG').format(cat.price) + ' ' + (cat.currency || 'MGA')
        : null;
      this._tooltip.innerHTML = `
        <div style="display:flex;align-items:center;gap:8px;padding:8px 14px 8px 12px;white-space:nowrap">
          <span style="width:10px;height:10px;border-radius:50%;background:${color};flex-shrink:0;display:inline-block"></span>
          <span style="font-size:13px;font-weight:700;color:#111827">${section}</span>
          <span style="font-size:12px;color:#d1d5db">·</span>
          <span style="font-size:13px;font-weight:500;color:${color}">${name}</span>
          ${price ? `<span style="font-size:12px;font-weight:600;color:#6b7280;margin-left:2px">${price}</span>` : ''}
        </div>`;
      this._tooltip.style.borderRadius = '999px';
      this._tooltip.style.minWidth = 'auto';
      this._tooltip.style.visibility = 'visible';
      this._tooltip.style.opacity = '1';
      const tw = this._tooltip.offsetWidth;
      const th = this._tooltip.offsetHeight;
      // Position below the section element, centered on it
      let left = (this._cw - tw) / 2;
      let top  = this._ch / 2 + 20; // fallback: below center
      if (sectionEl) {
        const sr = sectionEl.getBoundingClientRect();
        const rr = this._root.getBoundingClientRect();
        left = sr.left - rr.left + (sr.width - tw) / 2;
        top  = sr.bottom - rr.top + 10;
        // If pill overflows bottom, place above section instead
        if (top + th > this._ch - 8) top = sr.top - rr.top - th - 10;
      }
      this._tooltip.style.left = Math.max(8, Math.min(left, this._cw - tw - 8)) + 'px';
      this._tooltip.style.top  = Math.max(8, top) + 'px';
    }

    _showSectionTooltip(anchorEl, section, catId) {
      if (this._mobileStep !== 1) return;
      const color = this._catColor(catId), name = this._catName(catId);
      const cat   = this._catMap[catId];
      const price = cat?.price != null
        ? new Intl.NumberFormat('fr-MG').format(cat.price) + ' ' + (cat.currency || 'MGA')
        : null;
      this._tooltip.innerHTML = `
        <div style="padding:10px 16px 6px;text-align:center">
          <span style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.1em">Section</span><br>
          <span style="font-size:20px;font-weight:800;color:#111827;letter-spacing:0.02em">${section}</span>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 14px;background:${color};margin-top:4px;gap:12px">
          <span style="font-size:20px;font-weight:800;color:#fff;white-space:nowrap">${name}</span>
          ${price ? `<span style="font-size:20px;font-weight:800;color:#fff;white-space:nowrap">${price}</span>` : ''}
        </div>`;
      const cr = this._root.getBoundingClientRect();
      const er = anchorEl.getBoundingClientRect();
      const tw = this._tooltip.offsetWidth;
      let left = er.left - cr.left + er.width / 2 - tw / 2;
      let top  = er.top  - cr.top  - this._tooltip.offsetHeight - 8;
      if (top < 4) top = er.top - cr.top + er.height + 8;
      this._tooltip.style.left = Math.max(4, Math.min(left, this._cw - tw - 4)) + 'px';
      this._tooltip.style.top  = top + 'px';
      this._tooltip.style.visibility='visible';
      this._tooltip.style.opacity='1';
    }

    // ── microscope lens ──────────────────────────────────────────────────────────

    _buildLens(root) {
      // Single container; circles rebuilt on each update
      // pointerEvents:'none' on container so only circle elements receive clicks
      const wrap = css(el('div'), { position:'absolute', inset:'0', pointerEvents:'none', zIndex:'100' });
      this._lensWrap = wrap;
      root.appendChild(wrap);
      // circles inside use pointerEvents:'auto' individually
    }

    // Called on selection change and zoom change
    _updateLens() {
      if (!this._lensWrap) return;
      this._lensWrap.innerHTML = '';

      // La loupe n'a de sens qu'en vue d'ensemble (step 0). Au-delà, le plan est
      // déjà zoomé sur une section — afficher les cercles interférerait avec la nav.
      if (this._zoom > 0.5 || this._selected.size === 0 || this._mobileStep > 0) return;

      // Group selected keys by section
      const bySection = {};
      for (const key of this._selected) {
        const sec = this._seatSectionMap[key] || '__none__';
        (bySection[sec] = bySection[sec] || []).push(key);
      }

      for (const [section, keys] of Object.entries(bySection)) {
        this._drawOneCircle(section, keys);
      }
    }

    _drawOneCircle(section, selectedKeys) {
      const selSet = new Set(selectedKeys);

      // Walk offsetLeft/offsetTop jusqu'au canvas : coordonnées layout, indépendantes
      // des transforms CSS parents (notamment scale(0.92) au chargement de #chart).
      // Converti en coords viewport via : vp = canvasLocal * zoom + pan.
      const seatData = [];
      for (const e of this._canvas.querySelectorAll('[data-sk]')) {
        if (this._seatSectionMap[e.dataset.sk] !== section) continue;
        let x = 0, y = 0, cur = e;
        while (cur && cur !== this._canvas) { x += cur.offsetLeft || 0; y += cur.offsetTop || 0; cur = cur.offsetParent; }
        const w = (e.offsetWidth  || 18);
        const h = (e.offsetHeight || 18);
        seatData.push({
          key: e.dataset.sk,
          cat: e.dataset.cat,
          vx: (x + w / 2) * this._zoom + this._panX,
          vy: (y + h / 2) * this._zoom + this._panY,
          w:  w * this._zoom,
          h:  h * this._zoom,
          borderRadius: e.style.borderRadius || '50%',
        });
      }
      if (!seatData.length) return;

      const selData = seatData.filter(s => selSet.has(s.key));
      if (!selData.length) return;

      // Centroid of selected seats in viewport coords
      let cx = 0, cy = 0;
      for (const s of selData) { cx += s.vx; cy += s.vy; }
      cx /= selData.length; cy /= selData.length;

      // Radius sized on selected seats only — unselected context dots get clipped
      let maxDist = 0;
      for (const s of selData) maxDist = Math.max(maxDist, Math.sqrt((s.vx-cx)**2 + (s.vy-cy)**2));
      const seatR = (selData[0].w / 2) || 8;
      const pad   = this._isMobile() ? 10 : 14;
      const R = Math.max(this._isMobile() ? 24 : 30, maxDist + seatR + pad);
      const D = R * 2;

      const catColor = this._catColor(this._seatCatMap[selectedKeys[0]]);

      // Outer ring — clickable, zooms to section
      const circleOuter = css(el('div'), {
        position:'absolute',
        left:(cx-R)+'px', top:(cy-R)+'px',
        width:D+'px', height:D+'px',
        borderRadius:'50%',
        border:`3px solid ${catColor}`,
        boxShadow:'0 4px 24px rgba(0,0,0,0.18)',
        cursor:'pointer', pointerEvents:'auto',
        zIndex: String(this._lensWrap.children.length + 1),
        transition:'border 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease',
      });
      circleOuter.addEventListener('click', () => {
        // Même comportement qu'un clic de siège au step 0 : zoom sur la section + step 1
        const card = [...this._canvas.querySelectorAll('[data-section]')]
          .find(e => e.dataset.section === section);
        if (card) {
          const cardBr   = card.getBoundingClientRect();
          const canvasBr = this._canvas.getBoundingClientRect();
          const ox = (cardBr.left - canvasBr.left) / this._zoom;
          const oy = (cardBr.top  - canvasBr.top)  / this._zoom;
          const ow = cardBr.width  / this._zoom;
          const oh = cardBr.height / this._zoom;
          const pad = 20;
          const z2  = Math.min((this._cw - pad*2) / Math.max(ow, 1), (this._ch - pad*2) / Math.max(oh, 1), 1.5);
          const px2 = -(ox + ow/2) * z2 + this._cw / 2;
          const py2 = -(oy + oh/2) * z2 + this._ch / 2;
          this._mobileStep       = 1;
          this._currentSectionEl = card;
          this._animateZoom(z2, px2, py2, 350);
        } else {
          this._zoomToLevel(1.5, cx, cy);
        }
      });
      circleOuter.addEventListener('mouseenter', () => {
        circleOuter.style.border=`4px solid ${catColor}`;
        circleOuter.style.boxShadow=`0 8px 36px rgba(0,0,0,0.22), 0 0 0 3px ${rgba(catColor,0.2)}`;
        circleOuter.style.transform='scale(1.04)';
      });
      circleOuter.addEventListener('mouseleave', () => {
        circleOuter.style.border=`3px solid ${catColor}`;
        circleOuter.style.boxShadow='0 4px 24px rgba(0,0,0,0.18)';
        circleOuter.style.transform='scale(1)';
      });

      // Inner clip — white background, seats shown at actual scale
      // Selected seats: category color. Others: light grey.
      const circle = css(el('div'), {
        position:'absolute', left:'0', top:'0',
        width:'100%', height:'100%',
        borderRadius:'50%', overflow:'hidden',
        background:'rgba(255,255,255,0.92)',
        pointerEvents:'none',
      });

      for (const s of seatData) {
        const isSel = selSet.has(s.key);
        const c     = this._catColor(s.cat);
        const dot   = css(el('div'), {
          position:     'absolute',
          left:         (s.vx - cx + R - s.w/2) + 'px',
          top:          (s.vy - cy + R - s.h/2) + 'px',
          width:        s.w + 'px',
          height:       s.h + 'px',
          borderRadius: s.borderRadius,
          background:   isSel ? c : '#d1d5db',
          boxShadow:    isSel ? `0 0 0 2px rgba(255,255,255,0.8), 0 0 0 3.5px ${rgba(c,0.4)}` : 'none',
          zIndex:       isSel ? '2' : '1',
        });
        if (isSel) {
          dot.innerHTML = `<svg viewBox="0 0 12 12" style="position:absolute;inset:0;margin:auto;width:55%;height:55%" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1.5,6 5,9.5 10.5,2.5"/></svg>`;
        }
        circle.appendChild(dot);
      }

      circleOuter.appendChild(circle);
      this._lensWrap.appendChild(circleOuter);
    }

    _sectionCanvasBbox(section) {
      let bx0=Infinity,by0=Infinity,bx1=-Infinity,by1=-Infinity;
      this._canvas.querySelectorAll('[data-sk]').forEach(e => {
        if (this._seatSectionMap[e.dataset.sk] !== section) return;
        let x=0, y=0, cur=e;
        while (cur && cur !== this._canvas) { x+=cur.offsetLeft||0; y+=cur.offsetTop||0; cur=cur.offsetParent; }
        bx0=Math.min(bx0,x); by0=Math.min(by0,y);
        bx1=Math.max(bx1,x+(e.offsetWidth||22)); by1=Math.max(by1,y+(e.offsetHeight||22));
      });
      return isFinite(bx0) ? {x:bx0, y:by0, w:bx1-bx0, h:by1-by0} : null;
    }

    _hideLens() {
      if (this._lensWrap) this._lensWrap.innerHTML = '';
    }

    // ── seat styles ───────────────────────────────────────────────────────────────
    // planStatus: 'enabled' | 'disabled' | 'deleted'
    // bookingStatus: 'available' | 'booked' | 'hold' | 'canceled'

    _seatStyle(key, catId, planStatus) {
      const color = this._catColor(catId);
      const bs    = this._bookingStatus(key);
      const sel   = this._selected.has(key);
      let bg, fg, border='none', boxShadow='none';

      if (planStatus === 'disabled' || bs === 'booked' || bs === 'canceled') {
        bg='#e5e7eb'; fg='#9ca3af'; border='1px solid #d1d5db';
      } else if (bs === 'hold') {
        bg='#d1d5db'; fg='#6b7280'; border='1px solid #9ca3af';
      } else if (sel) {
        bg=color; fg='#fff';
        // outer ring fixed + inset white painted over the blue fill; on hover inset shrinks → blue fill grows
        boxShadow=color+' 0px 0px 0px 1.5px, rgba(255,255,255,0.9) 0px 0px 0px 2px inset';
      } else {
        bg=color; fg='#fff';
      }
      return {bg, fg, border, boxShadow};
    }

    _cursor(key, planStatus) {
      if (this._readOnly||planStatus==='disabled'||planStatus==='deleted') return 'default';
      const bs=this._bookingStatus(key);
      return bs==='available' ? 'pointer' : 'not-allowed';
    }

    _isClickable(key, planStatus) {
      if (this._readOnly||planStatus!=='enabled') return false;
      return this._bookingStatus(key)==='available';
    }

    _onSeatClick(key, planStatus, seatEl) {
      if (this._didDrag) return;
      if (!this._isClickable(key, planStatus)) return;

      // If not at 150 %, zoom to 150 % centred on seat first (no selection yet)
      if (this._zoom < 1.49) {
        const vr = this._viewport.getBoundingClientRect();
        const er = seatEl.getBoundingClientRect();
        this._zoomToLevel(1.5, er.left+er.width/2-vr.left, er.top+er.height/2-vr.top);
        return;
      }

      const catId = this._seatCatMap[key];
      const info  = { seatKey:key, catId, catColor:this._catColor(catId), catName:this._catName(catId) };

      if (this._selected.has(key)) {
        this._selected.delete(key);
        if (this._onDesel) this._onDesel(info);
      } else {
        this._selected.add(key);
        if (this._onSel) this._onSel(info);
      }
      this._refreshColors();
      this._updateLens();
      if (this._onSelectionChange) this._onSelectionChange();
      // Bounce animation via animate.css
      seatEl.classList.remove('animate__animated', 'animate__pulse');
      seatEl.style.setProperty('--animate-duration', '0.4s');
      void seatEl.offsetWidth;
      seatEl.classList.add('animate__animated', 'animate__pulse');
    }

    _setSeatContent(e, selected, label) {
      if (selected) {
        // SVG checkmark: position:absolute + inset:0 + margin:auto = perfect centering at any size
        // (parent always has position:relative from _makeSeat — never reset it here)
        e.innerHTML = `<svg viewBox="0 0 12 12" style="position:absolute;inset:0;margin:auto;width:44%;height:44%" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1.5,6 5,9.5 10.5,2.5"/></svg>`;
      } else {
        e.textContent = label;
      }
    }

    _refreshColors() {
      this._canvas.querySelectorAll('[data-sk]').forEach(e => {
        const key=e.dataset.sk, catId=e.dataset.cat, ps=e.dataset.ps;
        const {bg,fg,border,boxShadow} = this._seatStyle(key, catId, ps);
        const sel = this._selected.has(key);
        e.style.background    = bg;
        e.style.color         = fg;
        e.style.border        = border;
        e.style.outline       = 'none';
        e.style.boxShadow     = boxShadow || 'none';
        e.style.filter        = '';
        e.style.cursor        = this._cursor(key, ps);
        e.style.zIndex        = sel ? '3' : '';
        if (ps !== 'deleted') this._setSeatContent(e, sel, e.dataset.label || '');
      });
    }

    // ── seat element factory ──────────────────────────────────────────────────────

    _makeSeat(key, catId, planStatus, size, shape, labelText, tipInfo) {
      this._seatCatMap[key]     = catId;
      this._seatSectionMap[key] = tipInfo.section || '';
      const {bg,fg,border} = this._seatStyle(key, catId, planStatus);
      const fs = Math.max(8, Math.floor(size * 0.4));
      const w  = shape==='rounded' ? Math.round(size*1.5) : size;

      const s = css(el('div'), {
        position:'relative',
        display:'flex', alignItems:'center', justifyContent:'center',
        fontWeight:'700', lineHeight:'1', userSelect:'none', boxSizing:'border-box',
        transition:'filter 0.1s',
        background:bg, color:fg, border,
        cursor:this._cursor(key, planStatus),
        fontSize:fs+'px',
        height:size+'px', width:w+'px', minWidth:w+'px',
        padding:shape==='rounded' ? '0 4px' : '0',
        borderRadius: shape==='round' ? '50%' : shape==='rounded' ? '10px' : '4px',
        visibility:planStatus==='deleted' ? 'hidden' : 'visible',
        boxShadow: this._selected.has(key) ? this._catColor(catId)+' 0px 0px 0px 1.5px, rgba(255,255,255,0.9) 0px 0px 0px 2px inset' : 'none',
      });
      const displayLabel = '';
      s.dataset.sk     = key;
      s.dataset.cat    = catId;
      s.dataset.ps     = planStatus;
      s.dataset.label  = displayLabel;
      this._setSeatContent(s, this._selected.has(key), displayLabel);

      if (planStatus!=='deleted') {
        s.addEventListener('mouseenter', () => {
          if (!this._isMobile() && this._mobileStep >= 2 && planStatus !== 'disabled') {
            this._showTooltip(s, {...tipInfo, key, planStatus});
            if (this._selected.has(key)) {
              s.style.boxShadow = this._catColor(catId)+' 0px 0px 0px 1.5px, rgba(255,255,255,0.9) 0px 0px 0px 1px inset';
            } else if (this._isClickable(key, planStatus)) {
              s.style.filter = 'brightness(1.12)';
            }
          }
        });
        s.addEventListener('mouseleave', () => {
          if (this._mobileStep >= 2) this._hideTooltip();
          s.style.filter = '';
          if (this._selected.has(key)) {
            s.style.boxShadow = this._catColor(catId)+' 0px 0px 0px 1.5px, rgba(255,255,255,0.9) 0px 0px 0px 2px inset';
          }
        });
        s.addEventListener('pointerdown', () => { this._didDrag=false; });
        s.addEventListener('pointerup', (e) => {
          e.stopPropagation();
          this._onPointerUp(e);
          if (this._didDrag) return;
          if (this._animFrame) return;
          const vr = this._viewport.getBoundingClientRect();
          const sr = s.getBoundingClientRect();
          const cx = sr.left - vr.left + sr.width / 2;
          const cy = sr.top  - vr.top  + sr.height / 2;
          const step = this._mobileStep;
          if (step === 0) {
            // Étape 1 : zoom sur la section entière
            const card = s.closest('[data-section]');
            if (card) {
              const cardBr   = card.getBoundingClientRect();
              const canvasBr = this._canvas.getBoundingClientRect();
              const ox = (cardBr.left - canvasBr.left) / this._zoom;
              const oy = (cardBr.top  - canvasBr.top)  / this._zoom;
              const ow = cardBr.width  / this._zoom;
              const oh = cardBr.height / this._zoom;
              const pad = 20;
              const z2  = Math.min((this._cw - pad*2) / Math.max(ow, 1), (this._ch - pad*2) / Math.max(oh, 1), 1.5);
              const px2 = -(ox + ow/2) * z2 + this._cw / 2;
              const py2 = -(oy + oh/2) * z2 + this._ch / 2;
              this._mobileStep = 1;
              this._currentSectionEl = card;
              const sectionName = card.dataset.section || this._catName(catId);
              this._mobilePendingTooltip = { section: sectionName, catId, el: card };
              this._animateZoom(z2, px2, py2, 350);
            } else {
              this._mobileStep = 2;
              this._zoomToLevel(Math.max(this._zoom * 1.6, 3), cx, cy);
            }
          } else if (step === 1) {
            // Étape 2 : zoom sur le siège
            this._hideTooltip();
            this._mobileStep = 2;
            this._zoomToLevel(Math.max(this._zoom * 1.6, 3), cx, cy);
          } else {
            if (this._isMobile()) {
              this._mobileStep = 0;
              this._showMobileModal(s, {...tipInfo, key, planStatus});
            } else {
              this._onSeatClick(key, planStatus, s);
              if (planStatus !== 'disabled') this._showTooltip(s, {...tipInfo, key, planStatus});
            }
          }
        });
      }
      return s;
    }

    // ── section click helper ──────────────────────────────────────────────────────

    _addSectionClick(el, sectionLabel, catId) {
      if (sectionLabel) el.dataset.section = sectionLabel;
      el.style.cursor = 'pointer';
      // Stop pointerdown from reaching the viewport drag handler → no accidental drag on sections
      el.addEventListener('pointerdown', (e) => {
        e.stopPropagation();
        this._didDrag = false;
      });
      el.addEventListener('pointerup', (e) => {
        e.stopPropagation();
        this._onPointerUp();
        // Step 1 pan-then-release still counts as a section tap
        if (this._didDrag && this._mobileStep !== 1) return;
        if (this._animFrame) return;
        if (this._mobileStep === 0) {
          // Step 0 → 1 : zoom sur la section entière
          const br = el.getBoundingClientRect();
          const canvasBr = this._canvas.getBoundingClientRect();
          const ox = (br.left - canvasBr.left) / this._zoom;
          const oy = (br.top  - canvasBr.top)  / this._zoom;
          const ow = br.width  / this._zoom;
          const oh = br.height / this._zoom;
          const pad = 32;
          const z2  = Math.min((this._cw - pad*2) / Math.max(ow, 1), (this._ch - pad*2) / Math.max(oh, 1), 1.5);
          const px2 = -(ox + ow/2) * z2 + this._cw / 2;
          const py2 = -(oy + oh/2) * z2 + this._ch / 2;
          this._mobileStep = 1;
          this._currentSectionEl = el;
          this._mobilePendingTooltip = { section: sectionLabel, catId, el };
          this._animateZoom(z2, px2, py2, 350);
          return;
        }
        if (this._mobileStep === 1) {
          // Step 1 → 2 : zoom to seat level
          const br = el.getBoundingClientRect();
          const vr = this._viewport.getBoundingClientRect();
          const cx = (br.left + br.width  / 2) - vr.left;
          const cy = (br.top  + br.height / 2) - vr.top;
          this._hideTooltip();
          this._mobileStep = 2;
          this._zoomToLevel(Math.max(this._zoom * 1.6, 3), cx, cy);
          return;
        }
      });
    }

    // ── draw all ──────────────────────────────────────────────────────────────────

    _drawAll() {
      for (const o of this._data.chartObjects||[]) {
        switch(o._type) {
          case 'zone':         this._drawZone(o);         break;
          case 'freeZone':     this._drawFreeZone(o);     break;
          case 'seatRow':      this._drawSeatRow(o);      break;
          case 'tableZone':    this._drawTableZone(o);    break;
          case 'tableSection': this._drawTableSection(o); break;
        }
      }
    }

    // ── zone ──────────────────────────────────────────────────────────────────────

    _drawZone(z) {
      const color=this._catColor(z.categoryId);
      const wrap = css(el('div'), {
        position:'absolute', top:(z.top||0)+'px', left:(z.left||0)+'px',
        width:(z.width||80)+'px', height:(z.height||60)+'px',
        background:rgba(color,0.08), border:`1px solid ${rgba(color,0.33)}`,
        borderRadius:z.shape==='pill' ? '999px' : '8px',
        display:'flex', flexDirection:'column', alignItems:'center', justifyContent:'center',
        textAlign:'center', padding:'0 8px',
      });
      const lbl=css(el('span'),{fontWeight:'700',color,fontSize:(z.labelFontSize||11)+'px'});
      lbl.textContent=z.label||''; wrap.appendChild(lbl);
      if (z.categoryId) wrap.dataset.plancat = z.categoryId;
      this._addSectionClick(wrap, z.label||'', z.categoryId);
      this._canvas.appendChild(wrap);
    }

    // ── freeZone ──────────────────────────────────────────────────────────────────

    _drawFreeZone(fz) {
      // Icon ID → emoji (mirrors icons.ts FREE_ZONE_ICONS)
      const ICON_MAP = {
        none:'',
        stage:'🎤', door:'🚪', toilets:'🚻', 'toilets-accessible':'♿',
        restaurant:'🍽️', bar:'🍸', stairs:'🪜', 'stage-light':'💡', blocked:'🚫',
      };
      // Background with optional pattern (mirrors patternStyle from icons.ts)
      const c = fz.color || '#6b7280';
      let bgStyle = {};
      if (fz.pattern === 'stripes') {
        bgStyle = { backgroundColor: c+'12', backgroundImage: `repeating-linear-gradient(45deg,${c}55 0,${c}55 6px,transparent 6px,transparent 14px)` };
      } else if (fz.pattern === 'dots') {
        bgStyle = { backgroundColor: c+'12', backgroundImage: `radial-gradient(${c}99 1.4px,transparent 1.4px)`, backgroundSize:'10px 10px' };
      } else {
        bgStyle = { background: c };
      }
      const wrap=css(el('div'),{
        position:'absolute', top:(fz.top||0)+'px', left:(fz.left||0)+'px',
        width:(fz.width||80)+'px', height:(fz.height||60)+'px',
        ...bgStyle,
        border:`1px solid ${c}40`,
        borderRadius:'8px', display:'flex', flexDirection:'column',
        alignItems:'center', justifyContent:'center', textAlign:'center',
        gap:'2px', pointerEvents:'none',
      });
      const iconChar = fz.icon ? (ICON_MAP[fz.icon] || fz.icon) : null;
      if (iconChar) {
        const ic=css(el('span'),{fontSize:(fz.iconSize||Math.max(12,(fz.height||60)*0.32))+'px',lineHeight:'1'});
        ic.textContent=iconChar; wrap.appendChild(ic);
      }
      const lbl=css(el('span'),{fontWeight:'700',textTransform:'uppercase',letterSpacing:'0.05em',color:fz.textColor||'#fff',fontSize:(fz.labelFontSize||10)+'px'});
      lbl.textContent=fz.label||''; wrap.appendChild(lbl);
      this._canvas.appendChild(wrap);
    }

    // ── seatRow ───────────────────────────────────────────────────────────────────

    _drawSeatRow(row) {
      const color=this._catColor(row.categoryId);
      const ss=row.seatSize||22;
      const disabled=row.disabledSeats||[], deleted=row.deletedSeats||[];
      const overrides=row.categoryOverrides||{};

      const wrapper=css(el('div'),{position:'absolute',top:(row.top||0)+'px',left:(row.left||0)+'px',paddingTop:'14px'});


      const card=css(el('div'),{
        position:'relative',
        background:rgba(color,0.08), border:`1px solid ${rgba(color,0.33)}`,
        borderRadius:'8px', padding:'6px',
      });
      const centerBadge=css(el('div'),{
        display:'none', position:'absolute', top:'50%', left:'50%',
        transform:'translate(-50%,-50%)',
        background:color, borderRadius:'6px', padding:'2px 7px',
        fontWeight:'700', fontSize:'11px', color:'#fff',
        whiteSpace:'nowrap', zIndex:'5', pointerEvents:'none',
        boxShadow:'0 1px 4px rgba(0,0,0,0.18)', letterSpacing:'0.03em',
        textAlign:'center',
      });
      centerBadge.textContent=this._catName(row.categoryId)||row.section;
      card.appendChild(centerBadge);
      this._secBadges.push(centerBadge);

      const colW=row.shape==='rounded' ? Math.round(ss*1.5) : ss;
      const grid=css(el('div'),{
        display:'grid', gridTemplateColumns:`repeat(${row.cols||1},${colW}px)`, gap:'6px',
      });
      centerBadge._seatsEl=grid;
      centerBadge._wrapEl=wrapper;

      const labelOverrides = row.seatLabelOverrides || {};
      for (let r=0;r<(row.rows||1);r++) {
        for (let c=0;c<(row.cols||1);c++) {
          const pk=`${r}-${c}`;
          const isDel=deleted.includes(pk), isDis=!isDel&&disabled.includes(pk);
          const catId=overrides[pk]||row.categoryId;
          const ps=isDel ? 'deleted' : isDis ? 'disabled' : 'enabled';
          const rl=axisLabel(r,row.rows,row.rowFormat,row.rowDirection);
          const cl=axisLabel(c,row.cols,row.colFormat,row.colDirection);
          const lbl=labelOverrides[pk] ?? seatLabel(r,c,row.rows,row.cols,row);
          const key=seatRowKey(row,r,c);
          grid.appendChild(this._makeSeat(key,catId,ps,ss,row.shape,lbl,{
            section:row.section||this._catName(row.categoryId), rowLabel:rl, colLabel:cl, label:lbl, catId,
          }));
        }
      }
      card.appendChild(grid); wrapper.appendChild(card);
      wrapper.dataset.plancat = row.categoryId || '';
      wrapper.addEventListener('mouseenter', () => { this._updateSecBadges(); this._showSectionTooltip(card, row.section||this._catName(row.categoryId), row.categoryId); });
      wrapper.addEventListener('mouseleave', () => { this._updateSecBadges(); this._hideTooltip(); });
      this._addSectionClick(wrapper, row.section||this._catName(row.categoryId), row.categoryId);
      this._canvas.appendChild(wrapper);
    }

    // ── tableZone ─────────────────────────────────────────────────────────────────

    _drawTableZone(t) {
      const color=this._catColor(t.categoryId);
      const sz=tableZoneSize(t), ts=t.tableSize||30, ss=t.seatSize||15;
      const count=t.seatCount||6, disabled=t.disabledSeats||[];

      const wrapper=css(el('div'),{
        position:'absolute', top:(t.top||0)+'px', left:(t.left||0)+'px',
        width:sz+'px', height:sz+'px', transform:`rotate(${t.rotation||0}deg)`,
      });

      // seatsLayer: separate container for seats+disc so the badge (sibling) isn't blurred
      const tzSeatsLayer=css(el('div'),{position:'absolute',inset:'0',pointerEvents:'none'});

      for (let i=0;i<count;i++) {
        if (disabled.includes(i)) continue;
        const angle=(2*Math.PI*i)/count - Math.PI/2;
        const cx=sz/2+(ts/2+ss/2)*Math.cos(angle)-ss/2;
        const cy=sz/2+(ts/2+ss/2)*Math.sin(angle)-ss/2;
        const key=tableZoneKey(t,i);
        const seat=this._makeSeat(key,t.categoryId,'enabled',ss,'round',String(i+1),{
          section:t.section||this._catName(t.categoryId), rowLabel:'', colLabel:String(i+1), label:String(i+1), catId:t.categoryId,
        });
        css(seat,{position:'absolute',left:cx+'px',top:cy+'px',pointerEvents:'auto'});
        tzSeatsLayer.appendChild(seat);
      }

      const disc=css(el('div'),{
        position:'absolute', left:(sz-ts)/2+'px', top:(sz-ts)/2+'px',
        width:ts+'px', height:ts+'px',
        background:rgba(color,0.13), border:`2px solid ${rgba(color,0.53)}`,
        borderRadius:'50%', display:'flex', alignItems:'center', justifyContent:'center', pointerEvents:'none',
      });
      disc.dataset.lensHide = '1';
      tzSeatsLayer.appendChild(disc);
      wrapper.appendChild(tzSeatsLayer);

      const tzCenterBadge=css(el('div'),{
        display:'none', position:'absolute', top:'50%', left:'50%',
        transform:'translate(-50%,-50%)',
        background:color, borderRadius:'6px', padding:'2px 7px',
        fontWeight:'700', fontSize:'11px', color:'#fff',
        whiteSpace:'nowrap', zIndex:'5', pointerEvents:'none',
        boxShadow:'0 1px 4px rgba(0,0,0,0.18)', letterSpacing:'0.03em',
        textAlign:'center',
      });
      tzCenterBadge.textContent=this._catName(t.categoryId)||t.section;
      tzCenterBadge._seatsEl=tzSeatsLayer;
      tzCenterBadge._wrapEl=wrapper;
      wrapper.appendChild(tzCenterBadge);
      this._secBadges.push(tzCenterBadge);
      wrapper.addEventListener('mouseenter', () => { this._updateSecBadges(); tzSeatsLayer.style.filter=''; this._showSectionTooltip(wrapper, t.section||this._catName(t.categoryId), t.categoryId); });
      wrapper.addEventListener('mouseleave', () => { this._updateSecBadges(); this._hideTooltip(); });
      wrapper.dataset.plancat = t.categoryId || '';
      this._addSectionClick(wrapper, t.section||this._catName(t.categoryId), t.categoryId);
      this._canvas.appendChild(wrapper);
    }

    // ── tableSection ──────────────────────────────────────────────────────────────

    _drawTableSection(ts) {
      const color=this._catColor(ts.categoryId);
      const u=tsSectionUnit(ts), sp=ts.tableSpacing??2;
      const tcols=ts.tableCount||3, trows=ts.tableRows||1;
      const spt=ts.seatsPerTable||6, tsize=ts.tableSize||30, ss=ts.seatSize||15;
      const disabled=ts.disabledSeats||[], deletedTables=ts.deletedTables||[];

      const wrapper=css(el('div'),{
        position:'absolute', top:(ts.top||0)+'px', left:(ts.left||0)+'px',
        width:tsSectionWidth(ts)+'px', height:tsSectionHeight(ts)+'px',
        background:rgba(color,0.08), border:`1px solid ${rgba(color,0.33)}`,
        borderRadius:'10px', transform:`rotate(${ts.rotation||0}deg)`,
      });
      const tsCenterBadge=css(el('div'),{
        display:'none', position:'absolute', top:'50%', left:'50%',
        transform:'translate(-50%,-50%)',
        background:color, borderRadius:'6px', padding:'2px 7px',
        fontWeight:'700', fontSize:'11px', color:'#fff',
        whiteSpace:'nowrap', zIndex:'5', pointerEvents:'none',
        boxShadow:'0 1px 4px rgba(0,0,0,0.18)', letterSpacing:'0.03em',
        textAlign:'center',
      });
      tsCenterBadge.textContent=this._catName(ts.categoryId)||ts.section;
      wrapper.appendChild(tsCenterBadge);
      this._secBadges.push(tsCenterBadge);

      // seatsLayer: separate container so badge (sibling) isn't blurred
      const tsSeatsLayer=css(el('div'),{position:'absolute',inset:'0',pointerEvents:'none'});
      tsCenterBadge._seatsEl=tsSeatsLayer;
      tsCenterBadge._wrapEl=wrapper;

      wrapper.addEventListener('mouseenter', () => { this._updateSecBadges(); tsSeatsLayer.style.filter=''; this._showSectionTooltip(wrapper, ts.section||this._catName(ts.categoryId), ts.categoryId); });
      wrapper.addEventListener('mouseleave', () => { this._updateSecBadges(); this._hideTooltip(); });

      for (let ri=0;ri<trows;ri++) {
        for (let ci=0;ci<tcols;ci++) {
          const ti=ri*tcols+ci;
          if (deletedTables.includes(ti)) continue;

          for (let si=0;si<spt;si++) {
            const pk=`${ti}-${si}`, isDis=disabled.includes(pk);
            const ps=isDis ? 'disabled' : 'enabled';
            const key=tableSectionKey(ts,ti,si);
            const angle=(2*Math.PI*si)/spt - Math.PI/2;
            const cx=TS_PAD+ci*(u+sp)+u/2+(tsize/2+ss/2)*Math.cos(angle)-ss/2;
            const cy=TS_PAD+ri*(u+sp)+u/2+(tsize/2+ss/2)*Math.sin(angle)-ss/2;
            const seat=this._makeSeat(key,ts.categoryId,ps,ss,'round',String(si+1),{
              section:ts.section||this._catName(ts.categoryId), rowLabel:`T${ti+1}`, colLabel:String(si+1), label:String(si+1), catId:ts.categoryId,
            });
            css(seat,{position:'absolute',left:cx+'px',top:cy+'px',pointerEvents:'auto'});
            tsSeatsLayer.appendChild(seat);
          }

          // Table disc
          const disc=css(el('div'),{
            position:'absolute',
            left:(TS_PAD+ci*(u+sp)+(u-tsize)/2)+'px',
            top: (TS_PAD+ri*(u+sp)+(u-tsize)/2)+'px',
            width:tsize+'px', height:tsize+'px',
            background:rgba(color,0.13), border:`2px solid ${rgba(color,0.53)}`,
            borderRadius:'50%', pointerEvents:'none',
            display:'flex', alignItems:'center', justifyContent:'center',
          });
          disc.dataset.lensHide = '1';
          tsSeatsLayer.appendChild(disc);
        }
      }
      wrapper.appendChild(tsSeatsLayer);

      wrapper.dataset.plancat = ts.categoryId || '';
      this._addSectionClick(wrapper, ts.section||this._catName(ts.categoryId), ts.categoryId);
      this._canvas.appendChild(wrapper);
    }
  }

  global.PlaceRender = PlaceRender;

})(window);
