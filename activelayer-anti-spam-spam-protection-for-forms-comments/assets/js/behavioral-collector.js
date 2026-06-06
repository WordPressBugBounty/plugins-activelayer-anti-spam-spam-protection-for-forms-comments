(() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropSymbols = Object.getOwnPropertySymbols;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __propIsEnum = Object.prototype.propertyIsEnumerable;
  var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
  var __spreadValues = (a, b) => {
    for (var prop in b || (b = {}))
      if (__hasOwnProp.call(b, prop))
        __defNormalProp(a, prop, b[prop]);
    if (__getOwnPropSymbols)
      for (var prop of __getOwnPropSymbols(b)) {
        if (__propIsEnum.call(b, prop))
          __defNormalProp(a, prop, b[prop]);
      }
    return a;
  };
  var __publicField = (obj, key, value) => __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value);

  // assets/js/src/behavioral/trackers/KeypressTracker.js
  var KeypressTracker = class {
    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param {Object}   signals           Reference to signals object to update.
     * @param {Function} onFirstInteraction Callback for first interaction.
     * @param {number}   maxSamples        Maximum timing samples to keep.
     */
    constructor(signals, onFirstInteraction, maxSamples = 100) {
      this.signals = signals;
      this.onFirstInteraction = onFirstInteraction;
      this.maxSamples = maxSamples;
      this._state = {
        lastKeydownTime: null,
        lastKeyupTime: null,
        keydowns: {}
      };
      this._handlers = {};
    }
    /**
     * Check if a key is a modifier key.
     *
     * Modifier keys are excluded from basic keypress count and timing
     * since they are typically held while pressing other keys.
     *
     * @since 1.1.0
     * @private
     *
     * @param {string} key The key value from the event.
     * @return {boolean} True if the key is a modifier.
     */
    _isModifierKey(key) {
      return ["Shift", "Control", "Alt", "Meta", "CapsLock"].indexOf(key) !== -1;
    }
    /**
     * Check if a key is a correction key.
     *
     * Correction keys (Backspace, Delete) and navigation keys (arrows,
     * Home, End, PageUp, PageDown) indicate editing behavior that can
     * distinguish humans from bots.
     *
     * @since 1.1.0
     * @private
     *
     * @param {string} key The key value from the event.
     * @return {boolean} True if the key is a correction key.
     */
    _isCorrectionKey(key) {
      return [
        "Backspace",
        "Delete",
        "ArrowUp",
        "ArrowDown",
        "ArrowLeft",
        "ArrowRight",
        "Home",
        "End",
        "PageUp",
        "PageDown"
      ].indexOf(key) !== -1;
    }
    /**
     * Handle keydown events.
     *
     * Records the keydown time for each key to calculate duration
     * when the corresponding keyup event fires.
     *
     * @since 1.1.0
     * @private
     *
     * @param {KeyboardEvent} event The keydown event.
     * @return {void}
     */
    _handleKeydown(event) {
      const now = Date.now();
      const key = event.key;
      this.onFirstInteraction();
      if (this._isModifierKey(key)) {
        return;
      }
      if (typeof this._state.keydowns[key] === "undefined") {
        this._state.keydowns[key] = now;
      }
      this._state.lastKeydownTime = now;
    }
    /**
     * Handle keyup events.
     *
     * Calculates key press duration and interval between keypresses,
     * storing timing data in the Akismet-style [duration, interval] format.
     *
     * @since 1.1.0
     * @private
     *
     * @param {KeyboardEvent} event The keyup event.
     * @return {void}
     */
    _handleKeyup(event) {
      const now = Date.now();
      const key = event.key;
      if (this._isModifierKey(key)) {
        return;
      }
      const keydownTime = this._state.keydowns[key];
      let duration = 0;
      if (typeof keydownTime !== "undefined") {
        duration = now - keydownTime;
        delete this._state.keydowns[key];
      }
      let interval = 0;
      if (this._state.lastKeyupTime !== null) {
        interval = now - this._state.lastKeyupTime;
      }
      this._state.lastKeyupTime = now;
      this.signals.keypress_count++;
      if (this.signals.keypress_timings.length < this.maxSamples) {
        this.signals.keypress_timings.push([duration, interval]);
      }
      if (this._isCorrectionKey(key)) {
        if (this.signals.correction_keys.length < this.maxSamples) {
          this.signals.correction_keys.push(this.signals.keypress_count - 1);
        }
      }
      if (event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) {
        if (this.signals.modifier_keys.length < this.maxSamples) {
          this.signals.modifier_keys.push(this.signals.keypress_count - 1);
        }
      }
    }
    /**
     * Handle focus change to a new input field.
     *
     * Resets timing state to prevent cross-field timing contamination.
     * The interval between the last keypress in the previous field and
     * the first keypress in the new field should not be measured.
     *
     * @since 1.1.0
     * @private
     *
     * @param {FocusEvent} event The focusin event.
     * @return {void}
     */
    _handleFocusIn(event) {
      const target = event.target;
      if (!target || !target.tagName) {
        return;
      }
      const tagName = target.tagName.toUpperCase();
      if (tagName !== "INPUT" && tagName !== "TEXTAREA" && tagName !== "SELECT") {
        return;
      }
      if (tagName === "INPUT" && target.type === "hidden") {
        return;
      }
      this._state.lastKeyupTime = null;
      this._state.keydowns = {};
    }
    /**
     * Attach event listeners to a form element.
     *
     * @since 1.1.0
     *
     * @param {HTMLFormElement} form The form element to track.
     * @return {void}
     */
    attach(form) {
      this._handlers.keydown = this._handleKeydown.bind(this);
      this._handlers.keyup = this._handleKeyup.bind(this);
      this._handlers.focusin = this._handleFocusIn.bind(this);
      form.addEventListener("keydown", this._handlers.keydown, false);
      form.addEventListener("keyup", this._handlers.keyup, false);
      form.addEventListener("focusin", this._handlers.focusin, false);
      this._form = form;
    }
    /**
     * Detach event listeners from the form element.
     *
     * @since 1.1.0
     *
     * @return {void}
     */
    detach() {
      if (this._form && this._handlers.keydown) {
        this._form.removeEventListener("keydown", this._handlers.keydown, false);
        this._form.removeEventListener("keyup", this._handlers.keyup, false);
        this._form.removeEventListener("focusin", this._handlers.focusin, false);
      }
      this._form = null;
      this._handlers = {};
      this._state = {
        lastKeydownTime: null,
        lastKeyupTime: null,
        keydowns: {}
      };
    }
  };

  // assets/js/src/behavioral/trackers/MouseTracker.js
  var _MouseTracker = class _MouseTracker {
    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param {Object}   signals           Reference to signals object to update.
     * @param {Function} onFirstInteraction Callback for first interaction.
     */
    constructor(signals, onFirstInteraction) {
      this.signals = signals;
      this.onFirstInteraction = onFirstInteraction;
      this._state = {
        lastMoveTime: null,
        lastPosition: null,
        path: [],
        lastClickTime: null,
        mousedownTime: null,
        lastThrottled: 0
      };
      this._handlers = {};
    }
    /**
     * Handle mousedown event.
     *
     * Records the timestamp when a mouse button is pressed.
     * This is the start of a potential click.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _handleMousedown() {
      this._state.mousedownTime = Date.now();
      this.onFirstInteraction();
    }
    /**
     * Handle mouseup event.
     *
     * Completes the click by calculating duration and interval,
     * then stores the timing data.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _handleMouseup() {
      const now = Date.now();
      if (this._state.mousedownTime === null) {
        return;
      }
      const duration = now - this._state.mousedownTime;
      let interval = 0;
      if (this._state.lastClickTime !== null) {
        interval = now - this._state.lastClickTime;
      }
      if (this.signals.click_timings.length < _MouseTracker.MAX_CLICK_TIMINGS) {
        this.signals.click_timings.push([duration, interval]);
      }
      this.signals.click_count++;
      this._state.lastClickTime = now;
      this._state.mousedownTime = null;
    }
    /**
     * Handle mouse move events.
     *
     * @since 1.1.0
     * @private
     *
     * @param {MouseEvent} event The mouse move event.
     * @return {void}
     */
    _handleMouseMove(event) {
      const now = Date.now();
      if (now - this._state.lastThrottled < _MouseTracker.THROTTLE_INTERVAL) {
        return;
      }
      this._state.lastThrottled = now;
      this.onFirstInteraction();
      const currentPosition = {
        x: event.clientX,
        y: event.clientY,
        time: now
      };
      let distance = 0;
      if (this._state.lastPosition) {
        const dx = currentPosition.x - this._state.lastPosition.x;
        const dy = currentPosition.y - this._state.lastPosition.y;
        distance = Math.sqrt(dx * dx + dy * dy);
      }
      let timeSinceLastMove = 0;
      if (this._state.lastMoveTime) {
        timeSinceLastMove = now - this._state.lastMoveTime;
      }
      this.signals.move_count++;
      if (this._state.lastMoveTime !== null && this.signals.move_timings.length < _MouseTracker.MAX_MOVE_TIMINGS) {
        this.signals.move_timings.push([
          Math.round(timeSinceLastMove),
          Math.round(distance * 10) / 10
          // Round to 1 decimal.
        ]);
      }
      if (this._state.path.length < _MouseTracker.MAX_PATH_POINTS) {
        this._state.path.push(currentPosition);
      }
      this._state.lastMoveTime = now;
      this._state.lastPosition = currentPosition;
    }
    /**
     * Get the mouse path for efficiency calculation.
     *
     * @since 1.1.0
     *
     * @return {Array} Array of path points with x, y, time.
     */
    getPath() {
      return this._state.path;
    }
    /**
     * Calculate mouse path efficiency.
     *
     * Compares the actual path distance to the straight-line distance
     * between first and last points.
     *
     * @since 1.1.0
     *
     * @return {number|null} Efficiency ratio (0-1), or null if insufficient data.
     */
    calculateEfficiency() {
      const path = this._state.path;
      if (path.length < 2) {
        return null;
      }
      const first = path[0];
      const last = path[path.length - 1];
      const straightDistance = Math.sqrt(
        Math.pow(last.x - first.x, 2) + Math.pow(last.y - first.y, 2)
      );
      if (straightDistance === 0) {
        return null;
      }
      let pathDistance = 0;
      for (let i = 1; i < path.length; i++) {
        pathDistance += Math.sqrt(
          Math.pow(path[i].x - path[i - 1].x, 2) + Math.pow(path[i].y - path[i - 1].y, 2)
        );
      }
      const efficiency = straightDistance / pathDistance;
      return Math.round(efficiency * 1e3) / 1e3;
    }
    /**
     * Attach event listeners to a form element.
     *
     * @since 1.1.0
     *
     * @param {HTMLFormElement} form The form element to track.
     * @return {void}
     */
    attach(form) {
      this._handlers.mousedown = this._handleMousedown.bind(this);
      this._handlers.mouseup = this._handleMouseup.bind(this);
      this._handlers.mousemove = this._handleMouseMove.bind(this);
      form.addEventListener("mousedown", this._handlers.mousedown, { passive: true });
      form.addEventListener("mouseup", this._handlers.mouseup, { passive: true });
      form.addEventListener("mousemove", this._handlers.mousemove, { passive: true });
      this._form = form;
    }
    /**
     * Detach event listeners from the form element.
     *
     * @since 1.1.0
     *
     * @return {void}
     */
    detach() {
      if (this._form) {
        if (this._handlers.mousedown) {
          this._form.removeEventListener("mousedown", this._handlers.mousedown, { passive: true });
        }
        if (this._handlers.mouseup) {
          this._form.removeEventListener("mouseup", this._handlers.mouseup, { passive: true });
        }
        if (this._handlers.mousemove) {
          this._form.removeEventListener("mousemove", this._handlers.mousemove, { passive: true });
        }
      }
      this._form = null;
      this._handlers = {};
      this._state = {
        lastMoveTime: null,
        lastPosition: null,
        path: [],
        lastClickTime: null,
        mousedownTime: null,
        lastThrottled: 0
      };
    }
  };
  /**
   * Throttle interval for high-frequency events (ms).
   *
   * @since 1.1.0
   * @type {number}
   */
  __publicField(_MouseTracker, "THROTTLE_INTERVAL", 50);
  /**
   * Maximum number of path points to store.
   *
   * @since 1.1.0
   * @type {number}
   */
  __publicField(_MouseTracker, "MAX_PATH_POINTS", 500);
  /**
   * Maximum number of click timing samples to store.
   *
   * @since 1.1.0
   * @type {number}
   */
  __publicField(_MouseTracker, "MAX_CLICK_TIMINGS", 200);
  /**
   * Maximum number of move timing samples to store.
   *
   * @since 1.1.0
   * @type {number}
   */
  __publicField(_MouseTracker, "MAX_MOVE_TIMINGS", 500);
  var MouseTracker = _MouseTracker;

  // assets/js/src/behavioral/trackers/TouchTracker.js
  var _TouchTracker = class _TouchTracker {
    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param {Object}   signals           Reference to signals object to update.
     * @param {Function} onFirstInteraction Callback for first interaction.
     */
    constructor(signals, onFirstInteraction) {
      this.signals = signals;
      this.onFirstInteraction = onFirstInteraction;
      this._state = {
        lastTouchTime: null,
        lastTouchmoveTime: null,
        touchStartTime: null,
        lastThrottled: 0
      };
      this._handlers = {};
    }
    /**
     * Handle touchstart event.
     *
     * Records the timestamp when a touch begins.
     * This is the start of a potential touch interaction.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _handleTouchstart() {
      const now = Date.now();
      this._state.touchStartTime = now;
      this.onFirstInteraction();
    }
    /**
     * Handle touchend event.
     *
     * Completes the touch by calculating duration and interval,
     * then stores the timing data.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _handleTouchend() {
      const now = Date.now();
      if (this._state.touchStartTime === null) {
        return;
      }
      const duration = now - this._state.touchStartTime;
      let interval = 0;
      if (this._state.lastTouchTime !== null) {
        interval = now - this._state.lastTouchTime;
      }
      this.signals.touch_timings.push([duration, interval]);
      this.signals.touch_count++;
      this._state.lastTouchTime = now;
      this._state.touchStartTime = null;
    }
    /**
     * Handle touchmove event (throttled).
     *
     * Counts touch movement events with throttling to avoid
     * excessive tracking of high-frequency events.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _handleTouchmove() {
      const now = Date.now();
      if (now - this._state.lastThrottled < _TouchTracker.THROTTLE_INTERVAL) {
        return;
      }
      this._state.lastThrottled = now;
      this.signals.touchmove_count++;
      this._state.lastTouchmoveTime = now;
    }
    /**
     * Attach event listeners to a form element.
     *
     * @since 1.1.0
     *
     * @param {HTMLFormElement} form The form element to track.
     * @return {void}
     */
    attach(form) {
      this._handlers.touchstart = this._handleTouchstart.bind(this);
      this._handlers.touchend = this._handleTouchend.bind(this);
      this._handlers.touchmove = this._handleTouchmove.bind(this);
      form.addEventListener("touchstart", this._handlers.touchstart, { passive: true });
      form.addEventListener("touchend", this._handlers.touchend, { passive: true });
      form.addEventListener("touchmove", this._handlers.touchmove, { passive: true });
      this._form = form;
    }
    /**
     * Detach event listeners from the form element.
     *
     * @since 1.1.0
     *
     * @return {void}
     */
    detach() {
      if (this._form) {
        if (this._handlers.touchstart) {
          this._form.removeEventListener("touchstart", this._handlers.touchstart, { passive: true });
        }
        if (this._handlers.touchend) {
          this._form.removeEventListener("touchend", this._handlers.touchend, { passive: true });
        }
        if (this._handlers.touchmove) {
          this._form.removeEventListener("touchmove", this._handlers.touchmove, { passive: true });
        }
      }
      this._form = null;
      this._handlers = {};
      this._state = {
        lastTouchTime: null,
        lastTouchmoveTime: null,
        touchStartTime: null,
        lastThrottled: 0
      };
    }
  };
  /**
   * Throttle interval for high-frequency events (ms).
   *
   * @since 1.1.0
   * @type {number}
   */
  __publicField(_TouchTracker, "THROTTLE_INTERVAL", 50);
  var TouchTracker = _TouchTracker;

  // assets/js/src/behavioral/trackers/ScrollTracker.js
  var _ScrollTracker = class _ScrollTracker {
    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param {Object}   signals           Reference to signals object to update.
     * @param {Function} onFirstInteraction Callback for first interaction.
     */
    constructor(signals, onFirstInteraction) {
      this.signals = signals;
      this.onFirstInteraction = onFirstInteraction;
      this._state = {
        lastScrollTime: null,
        lastThrottled: 0,
        scrollSessionTimeout: null,
        isInScrollSession: false
      };
      this._handlers = {};
    }
    /**
     * Handle scroll events.
     *
     * Uses a debounce pattern to count scroll "sessions" rather than
     * individual scroll events. A new session starts on first scroll,
     * and the count increments when the session ends (no scroll for 150ms).
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _handleScroll() {
      const now = Date.now();
      if (now - this._state.lastThrottled < _ScrollTracker.THROTTLE_INTERVAL) {
        return;
      }
      this._state.lastThrottled = now;
      this.onFirstInteraction();
      this._state.lastScrollTime = now;
      if (!this._state.isInScrollSession) {
        this._state.isInScrollSession = true;
      }
      if (this._state.scrollSessionTimeout) {
        clearTimeout(this._state.scrollSessionTimeout);
      }
      const self = this;
      this._state.scrollSessionTimeout = setTimeout(function() {
        if (self._state.isInScrollSession) {
          self.signals.scroll_count++;
          self._state.isInScrollSession = false;
        }
      }, _ScrollTracker.SCROLL_SESSION_GAP);
    }
    /**
     * Attach event listeners to the document.
     *
     * @since 1.1.0
     *
     * @return {void}
     */
    attach() {
      this._handlers.scroll = this._handleScroll.bind(this);
      document.addEventListener("scroll", this._handlers.scroll, { passive: true });
    }
    /**
     * Detach event listeners from the document.
     *
     * @since 1.1.0
     *
     * @return {void}
     */
    detach() {
      if (this._handlers.scroll) {
        document.removeEventListener("scroll", this._handlers.scroll, { passive: true });
      }
      if (this._state.scrollSessionTimeout) {
        clearTimeout(this._state.scrollSessionTimeout);
      }
      this._handlers = {};
      this._state = {
        lastScrollTime: null,
        lastThrottled: 0,
        scrollSessionTimeout: null,
        isInScrollSession: false
      };
    }
  };
  /**
   * Throttle interval for high-frequency events (ms).
   *
   * @since 1.1.0
   * @type {number}
   */
  __publicField(_ScrollTracker, "THROTTLE_INTERVAL", 50);
  /**
   * Gap that ends a scroll session (ms).
   *
   * @since 1.1.0
   * @type {number}
   */
  __publicField(_ScrollTracker, "SCROLL_SESSION_GAP", 150);
  var ScrollTracker = _ScrollTracker;

  // assets/js/src/behavioral/trackers/FocusTracker.js
  var FocusTracker = class {
    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param {Object}   signals           Reference to signals object to update.
     * @param {Function} onFirstInteraction Callback for first interaction.
     */
    constructor(signals, onFirstInteraction) {
      this.signals = signals;
      this.onFirstInteraction = onFirstInteraction;
      this._handlers = {};
    }
    /**
     * Handle focus events on form fields.
     *
     * @since 1.1.0
     * @private
     *
     * @param {FocusEvent} event The focus event.
     * @return {void}
     */
    _handleFocus(event) {
      const target = event.target;
      if (!target || !target.tagName) {
        return;
      }
      const tagName = target.tagName.toUpperCase();
      if (tagName !== "INPUT" && tagName !== "TEXTAREA" && tagName !== "SELECT") {
        return;
      }
      if (tagName === "INPUT" && target.type === "hidden") {
        return;
      }
      this.signals.focus_count++;
      this.onFirstInteraction();
    }
    /**
     * Attach event listeners to a form element.
     *
     * @since 1.1.0
     *
     * @param {HTMLFormElement} form The form element to track.
     * @return {void}
     */
    attach(form) {
      this._handlers.focusin = this._handleFocus.bind(this);
      form.addEventListener("focusin", this._handlers.focusin, false);
      this._form = form;
    }
    /**
     * Detach event listeners from the form element.
     *
     * @since 1.1.0
     *
     * @return {void}
     */
    detach() {
      if (this._form && this._handlers.focusin) {
        this._form.removeEventListener("focusin", this._handlers.focusin, false);
      }
      this._form = null;
      this._handlers = {};
    }
  };

  // assets/js/src/behavioral/utils.js
  function debounce(fn, delay) {
    let timeoutId = null;
    return function(...args) {
      if (timeoutId) {
        clearTimeout(timeoutId);
      }
      timeoutId = setTimeout(() => {
        fn.apply(this, args);
        timeoutId = null;
      }, delay);
    };
  }
  function calculateVariance(values) {
    if (!Array.isArray(values) || values.length < 2) {
      return null;
    }
    const n = values.length;
    const mean = values.reduce((a, b) => a + b, 0) / n;
    const squaredDiffs = values.map((v) => Math.pow(v - mean, 2));
    const variance = squaredDiffs.reduce((a, b) => a + b, 0) / n;
    return Math.round(variance * 100) / 100;
  }

  // assets/js/src/behavioral/trackers/PageInteractionTracker.js
  var PageInteractionTracker = class {
    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param {Object}   signals           Reference to signals object to update.
     * @param {Function} onFirstInteraction Callback for first interaction.
     * @param {Function} logError          Callback for logging errors.
     */
    constructor(signals, onFirstInteraction, logError) {
      this.signals = signals;
      this.onFirstInteraction = onFirstInteraction;
      this.logError = logError;
      this._form = null;
      this._handlers = {};
    }
    /**
     * Handle click events on the document.
     *
     * Only counts clicks that occur outside the tracked form,
     * indicating user engagement with the broader page.
     *
     * @since 1.1.0
     * @private
     *
     * @param {MouseEvent} event The click event.
     * @return {void}
     */
    _handlePageClick(event) {
      if (this._form && !this._form.contains(event.target)) {
        this.signals.page_click_count++;
        this.onFirstInteraction();
      }
    }
    /**
     * Handle text selection change.
     *
     * Checks if there's actual selected text and increments the counter.
     * Uses debouncing to count selection "actions" not every change.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _handleSelection() {
      const self = this;
      try {
        const selection = window.getSelection();
        if (selection && selection.toString().length > 0) {
          self.signals.text_selection_count++;
          self.onFirstInteraction();
        }
      } catch (e) {
        self.logError("_handleSelection", "Failed to track selection: " + e.message);
      }
    }
    /**
     * Attach event listeners.
     *
     * @since 1.1.0
     *
     * @param {HTMLFormElement} form The form element being tracked.
     * @return {void}
     */
    attach(form) {
      this._form = form;
      this._handlers.pageClick = this._handlePageClick.bind(this);
      this._handlers.selection = debounce(this._handleSelection.bind(this), 200);
      document.addEventListener("click", this._handlers.pageClick, false);
      document.addEventListener("selectionchange", this._handlers.selection);
    }
    /**
     * Detach event listeners.
     *
     * @since 1.1.0
     *
     * @return {void}
     */
    detach() {
      if (this._handlers.pageClick) {
        document.removeEventListener("click", this._handlers.pageClick, false);
      }
      if (this._handlers.selection) {
        document.removeEventListener("selectionchange", this._handlers.selection);
      }
      this._form = null;
      this._handlers = {};
    }
  };

  // assets/js/src/behavioral/trackers/DeviceInfoCollector.js
  var DeviceInfoCollector = class {
    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param {Object}   signals  Reference to signals object to update.
     * @param {Function} logError Callback for logging errors.
     */
    constructor(signals, logError) {
      this.signals = signals;
      this.logError = logError;
    }
    /**
     * Collect all device info signals.
     *
     * Gathers static device data immediately. This method
     * should be called during collector initialization.
     *
     * @since 1.1.0
     *
     * @return {void}
     */
    collect() {
      this._collectScreenInfo();
      this._collectTimezone();
      this._collectTouchCapability();
      this._collectHardwareConcurrency();
      this._collectDeviceMemory();
      this._collectConnectionType();
    }
    /**
     * Collect screen information.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _collectScreenInfo() {
      try {
        this.signals.screen_info = {
          width: window.screen.width,
          height: window.screen.height,
          availWidth: window.screen.availWidth,
          availHeight: window.screen.availHeight,
          colorDepth: window.screen.colorDepth,
          pixelRatio: window.devicePixelRatio || 1
        };
      } catch (e) {
        this.logError("_collectScreenInfo", "Failed to get screen info");
      }
    }
    /**
     * Collect timezone offset.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _collectTimezone() {
      try {
        this.signals.timezone = (/* @__PURE__ */ new Date()).getTimezoneOffset();
      } catch (e) {
        this.logError("_collectTimezone", "Failed to get timezone");
      }
    }
    /**
     * Detect touch capability.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _collectTouchCapability() {
      this.signals.has_touch = "ontouchstart" in window || navigator.maxTouchPoints > 0 || // @ts-ignore - For older browsers.
      navigator.msMaxTouchPoints > 0;
    }
    /**
     * Collect hardware concurrency (CPU cores).
     *
     * Number of logical CPU cores available. Headless browsers
     * often report unusual values (e.g., 1 core).
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _collectHardwareConcurrency() {
      try {
        this.signals.hardware_concurrency = navigator.hardwareConcurrency || null;
      } catch (e) {
        this.signals.hardware_concurrency = null;
      }
    }
    /**
     * Collect device memory.
     *
     * Approximate device RAM in gigabytes. Not available in all browsers
     * (Safari, Firefox don't support it). Headless environments may
     * report 0 or very low values.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _collectDeviceMemory() {
      try {
        this.signals.device_memory = navigator.deviceMemory || null;
      } catch (e) {
        this.signals.device_memory = null;
      }
    }
    /**
     * Collect network connection type.
     *
     * Effective connection type (4g, 3g, 2g, slow-2g). Not available
     * in all browsers. Datacenter environments may report unusual types.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _collectConnectionType() {
      try {
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (connection && connection.effectiveType) {
          this.signals.connection_type = connection.effectiveType;
          this.signals.connection_downlink = connection.downlink || null;
          this.signals.connection_rtt = connection.rtt || null;
        } else {
          this.signals.connection_type = null;
          this.signals.connection_downlink = null;
          this.signals.connection_rtt = null;
        }
      } catch (e) {
        this.signals.connection_type = null;
        this.signals.connection_downlink = null;
        this.signals.connection_rtt = null;
      }
    }
  };

  // assets/js/src/honeypot/HoneypotTracker.js
  var _HoneypotTracker = class _HoneypotTracker {
    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param {Object} signals Reference to signals object to update.
     */
    constructor(signals) {
      this.signals = signals;
      this._handlers = {};
      this._field = null;
    }
    /**
     * Handle focus event on honeypot field.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _handleFocus() {
      this.signals.honeypot_focused = true;
      this.signals.honeypot_focus_time = Date.now();
    }
    /**
     * Handle input event on honeypot field.
     *
     * @since 1.1.0
     * @private
     *
     * @param {Event} event The input event.
     * @return {void}
     */
    _handleInput(event) {
      this.signals.honeypot_filled = true;
      this.signals.honeypot_value = event.target.value;
      this.signals.honeypot_input_time = Date.now();
    }
    /**
     * Attach event listeners to honeypot field.
     *
     * @since 1.1.0
     *
     * @param {HTMLFormElement} form The form element containing honeypot.
     * @return {void}
     */
    attach(form) {
      this._field = form.querySelector("." + _HoneypotTracker.FIELD_CLASS);
      if (!this._field) {
        return;
      }
      this._handlers.focus = this._handleFocus.bind(this);
      this._handlers.input = this._handleInput.bind(this);
      this._field.addEventListener("focus", this._handlers.focus, false);
      this._field.addEventListener("input", this._handlers.input, false);
      if (this._field.value) {
        this.signals.honeypot_filled = true;
        this.signals.honeypot_value = this._field.value;
        this.signals.honeypot_prefilled = true;
      }
    }
    /**
     * Detach event listeners from honeypot field.
     *
     * @since 1.1.0
     *
     * @return {void}
     */
    detach() {
      if (this._field && this._handlers.focus) {
        this._field.removeEventListener("focus", this._handlers.focus, false);
        this._field.removeEventListener("input", this._handlers.input, false);
      }
      this._field = null;
      this._handlers = {};
    }
  };
  /**
   * Honeypot field CSS class.
   *
   * @type {string}
   */
  __publicField(_HoneypotTracker, "FIELD_CLASS", "activelayer-hp-field");
  var HoneypotTracker = _HoneypotTracker;

  // assets/js/src/behavioral/BehavioralCollector.js
  var _BehavioralCollector = class _BehavioralCollector {
    /**
     * Constructor.
     *
     * @since 1.1.0
     */
    constructor() {
      this._form = null;
      this._initialized = false;
      this._trackers = {};
      this.signals = this._createEmptySignals();
    }
    /**
     * Create an empty signals object.
     *
     * @since 1.1.0
     * @private
     *
     * @return {BehavioralSignals} Empty signals structure.
     */
    _createEmptySignals() {
      return {
        // Timestamps.
        input_begin: null,
        form_submit: null,
        // Keypress tracking.
        keypress_count: 0,
        keypress_timings: [],
        modifier_keys: [],
        correction_keys: [],
        keystroke_variance: null,
        // Mouse click tracking.
        click_count: 0,
        click_timings: [],
        // Mouse move tracking.
        move_count: 0,
        move_timings: [],
        mouse_efficiency: null,
        // Touch tracking.
        touch_count: 0,
        touch_timings: [],
        touchmove_count: 0,
        // Scroll tracking.
        scroll_count: 0,
        // CleanTalk-inspired signals.
        focus_count: 0,
        page_click_count: 0,
        text_selection_count: 0,
        screen_info: null,
        timezone: null,
        // Metadata.
        has_touch: false,
        collection_errors: [],
        // Honeypot signals.
        honeypot_filled: false,
        honeypot_value: null,
        honeypot_prefilled: false,
        honeypot_focused: false,
        honeypot_focus_time: null,
        honeypot_input_time: null
      };
    }
    /**
     * Mark the first interaction time if not already set.
     *
     * @since 1.1.0
     * @private
     *
     * @return {void}
     */
    _markInputBegin() {
      if (this.signals.input_begin === null) {
        this.signals.input_begin = Date.now();
      }
    }
    /**
     * Log an error to the collection_errors array.
     *
     * @since 1.1.0
     * @private
     *
     * @param {string} method  The method where the error occurred.
     * @param {string} message The error message.
     * @return {void}
     */
    _logError(method, message) {
      this.signals.collection_errors.push({
        method,
        message,
        timestamp: Date.now()
      });
    }
    /**
     * Initialize the collector for a specific form.
     *
     * Binds event listeners to the form and document to collect
     * behavioral signals during user interaction.
     *
     * @since 1.1.0
     *
     * @param {HTMLFormElement} form The form element to track.
     * @return {boolean} True if initialization succeeded.
     */
    init(form) {
      if (!form || !(form instanceof HTMLFormElement)) {
        this._logError("init", "Invalid form element provided");
        return false;
      }
      if (this._initialized) {
        this.destroy();
      }
      this._form = form;
      this.signals = this._createEmptySignals();
      try {
        const markInputBegin = this._markInputBegin.bind(this);
        const logError = this._logError.bind(this);
        this._trackers.keypress = new KeypressTracker(
          this.signals,
          markInputBegin,
          _BehavioralCollector.MAX_TIMING_SAMPLES
        );
        this._trackers.keypress.attach(form);
        this._trackers.mouse = new MouseTracker(this.signals, markInputBegin);
        this._trackers.mouse.attach(form);
        this._trackers.touch = new TouchTracker(this.signals, markInputBegin);
        this._trackers.touch.attach(form);
        this._trackers.scroll = new ScrollTracker(this.signals, markInputBegin);
        this._trackers.scroll.attach();
        this._trackers.focus = new FocusTracker(this.signals, markInputBegin);
        this._trackers.focus.attach(form);
        this._trackers.pageInteraction = new PageInteractionTracker(
          this.signals,
          markInputBegin,
          logError
        );
        this._trackers.pageInteraction.attach(form);
        this._trackers.deviceInfo = new DeviceInfoCollector(this.signals, logError);
        this._trackers.deviceInfo.collect();
        this._trackers.honeypot = new HoneypotTracker(this.signals);
        this._trackers.honeypot.attach(form);
        this._initialized = true;
        return true;
      } catch (e) {
        this._logError("init", e.message);
        return false;
      }
    }
    /**
     * Destroy the collector and clean up event listeners.
     *
     * @since 1.1.0
     *
     * @return {void}
     */
    destroy() {
      if (!this._initialized) {
        return;
      }
      Object.values(this._trackers).forEach((tracker) => {
        if (tracker && typeof tracker.detach === "function") {
          tracker.detach();
        }
      });
      this._trackers = {};
      this._form = null;
      this._initialized = false;
    }
    /**
     * Get the collected signals as a fingerprint object.
     *
     * Prepares the signals for transmission to the API, including
     * any final calculations (variance, efficiency, etc.).
     *
     * @since 1.1.0
     *
     * @return {BehavioralSignals} The collected behavioral signals.
     */
    getFingerprint() {
      this.signals.form_submit = Date.now();
      if (this.signals.keypress_timings.length >= 3) {
        this.signals.keystroke_variance = calculateVariance(
          this.signals.keypress_timings.map((t) => t[0])
        );
      }
      if (this._trackers.mouse) {
        const efficiency = this._trackers.mouse.calculateEfficiency();
        if (efficiency !== null) {
          this.signals.mouse_efficiency = efficiency;
        }
      }
      const result = __spreadValues({}, this.signals);
      result.keypress_timings = this.prepareTimingArray(
        this.signals.keypress_timings,
        _BehavioralCollector.MAX_TIMING_SAMPLES
      );
      result.click_timings = this.prepareTimingArray(
        this.signals.click_timings,
        _BehavioralCollector.MAX_TIMING_SAMPLES
      );
      result.move_timings = this.prepareTimingArray(
        this.signals.move_timings,
        _BehavioralCollector.MAX_TIMING_SAMPLES
      );
      result.touch_timings = this.prepareTimingArray(
        this.signals.touch_timings,
        _BehavioralCollector.MAX_TIMING_SAMPLES
      );
      result.modifier_keys = this.prepareTimingArray(
        this.signals.modifier_keys,
        _BehavioralCollector.MAX_TIMING_SAMPLES
      );
      result.correction_keys = this.prepareTimingArray(
        this.signals.correction_keys,
        _BehavioralCollector.MAX_TIMING_SAMPLES
      );
      return result;
    }
    /**
     * Prepare a timing array for transmission.
     *
     * Limits the array to the specified number of samples using
     * Akismet-style sampling (keep first, last, and evenly distributed middle samples).
     *
     * @since 1.1.0
     *
     * @param {Array}  array The timing array to prepare.
     * @param {number} limit Maximum number of samples to keep.
     * @return {Array} The prepared timing array.
     */
    prepareTimingArray(array, limit = 100) {
      if (!Array.isArray(array) || array.length === 0) {
        return [];
      }
      if (array.length <= limit) {
        return array.slice();
      }
      const result = [];
      const step = (array.length - 1) / (limit - 1);
      for (let i = 0; i < limit; i++) {
        const index = Math.round(i * step);
        result.push(array[index]);
      }
      return result;
    }
  };
  /**
   * Maximum number of timing samples to keep.
   *
   * @since 1.1.0
   * @type {number}
   */
  __publicField(_BehavioralCollector, "MAX_TIMING_SAMPLES", 100);
  var BehavioralCollector = _BehavioralCollector;

  // assets/js/src/behavioral/index.js
  if (typeof window.ActiveLayer === "undefined") {
    window.ActiveLayer = {};
  }
  window.ActiveLayer.BehavioralCollector = BehavioralCollector;
  window.ActiveLayer._collectors = window.ActiveLayer._collectors || /* @__PURE__ */ new Map();
  window.ActiveLayer._behavioralCleanup = window.ActiveLayer._behavioralCleanup || [];
  function getCollectorForForm(form) {
    if (!form || !(form instanceof HTMLFormElement)) {
      return null;
    }
    if (!window.ActiveLayer._collectors.has(form)) {
      const collector = new BehavioralCollector();
      collector.init(form);
      window.ActiveLayer._collectors.set(form, collector);
    }
    return window.ActiveLayer._collectors.get(form);
  }
  function initFormCollector(form) {
    if (!form || !(form instanceof HTMLFormElement)) {
      return;
    }
    const input = form.querySelector("input.activelayer-behavioral-signals");
    if (!input) {
      return;
    }
    getCollectorForForm(form);
  }
  function initFormListeners() {
    const forms = document.querySelectorAll("form");
    forms.forEach(function(form) {
      initFormCollector(form);
    });
    var handleSubmit = function(event) {
      const form = event.target;
      if (!form || form.tagName !== "FORM") {
        return;
      }
      const input = form.querySelector("input.activelayer-behavioral-signals");
      if (input) {
        const collector = window.ActiveLayer._collectors.get(form);
        if (collector && collector._initialized) {
          input.value = JSON.stringify(collector.getFingerprint());
        }
      }
    };
    document.addEventListener("submit", handleSubmit, true);
    var handleClick = function(event) {
      const button = event.target.closest('button[type="submit"], input[type="submit"]');
      if (!button) {
        return;
      }
      const form = button.closest("form");
      if (!form) {
        return;
      }
      const input = form.querySelector("input.activelayer-behavioral-signals");
      if (input) {
        const collector = window.ActiveLayer._collectors.get(form);
        if (collector && collector._initialized) {
          input.value = JSON.stringify(collector.getFingerprint());
        }
      }
    };
    document.addEventListener("click", handleClick, true);
    window.ActiveLayer._behavioralCleanup.push(
      function() {
        document.removeEventListener("submit", handleSubmit, true);
      },
      function() {
        document.removeEventListener("click", handleClick, true);
      }
    );
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initFormListeners);
  } else {
    initFormListeners();
  }
  if (typeof jQuery !== "undefined") {
    jQuery(document).on("nfFormReady", function(e, layoutView) {
      if (layoutView && layoutView.el) {
        const form = jQuery(layoutView.el).closest("form")[0];
        if (form) {
          initFormCollector(form);
        }
      } else {
        const forms = document.querySelectorAll(".nf-form-cont form");
        forms.forEach(function(form) {
          initFormCollector(form);
        });
      }
    });
  }
  if (typeof MutationObserver !== "undefined") {
    const observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        if (mutation.addedNodes.length) {
          mutation.addedNodes.forEach(function(node) {
            if (node.nodeType === 1) {
              if (node.tagName === "FORM") {
                initFormCollector(node);
              }
              const forms = node.querySelectorAll ? node.querySelectorAll("form") : [];
              forms.forEach(function(form) {
                initFormCollector(form);
              });
              const inputs = node.querySelectorAll ? node.querySelectorAll("input.activelayer-behavioral-signals") : [];
              if (inputs.length > 0) {
                inputs.forEach(function(input) {
                  const form = input.closest("form");
                  if (form) {
                    initFormCollector(form);
                  }
                });
              }
            }
          });
        }
      });
    });
    observer.observe(document.body || document.documentElement, {
      childList: true,
      subtree: true
    });
    window.ActiveLayer._behavioralCleanup.push(function() {
      observer.disconnect();
    });
  }
  window.addEventListener("pagehide", function() {
    if (window.ActiveLayer && window.ActiveLayer._behavioralCleanup) {
      window.ActiveLayer._behavioralCleanup.forEach(function(fn) {
        fn();
      });
      window.ActiveLayer._behavioralCleanup = [];
    }
  });
})();
/**
 * KeypressTracker - Tracks keyboard input patterns.
 *
 * Monitors keydown/keyup events to measure typing patterns including
 * timing, modifier keys, and correction keys. These signals help
 * distinguish human typing from automated input.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * MouseTracker - Tracks mouse click and movement patterns.
 *
 * Monitors mouse events to measure click patterns, movement paths,
 * and path efficiency. These signals help distinguish human interaction
 * from automated mouse control.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * TouchTracker - Tracks touch interaction patterns.
 *
 * Monitors touch events to detect mobile devices and measure
 * touch interaction patterns. These signals help identify
 * device type and distinguish human touch from automated input.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * ScrollTracker - Tracks scroll interaction patterns.
 *
 * Monitors scroll events to detect user engagement and
 * distinguish from automated scrolling. Uses session-based
 * counting to measure scroll "actions" not individual events.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * FocusTracker - Tracks form field focus patterns.
 *
 * Monitors field focus events to measure form navigation patterns.
 * This is a CleanTalk-inspired signal that helps identify human
 * interaction with form fields.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * Behavioral Collector Utility Functions
 *
 * Common utility functions used across behavioral trackers.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * PageInteractionTracker - Tracks page-level interaction patterns.
 *
 * Monitors clicks outside the form and text selection events.
 * These CleanTalk-inspired signals help identify user engagement
 * with the broader page beyond just the form.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * DeviceInfoCollector - Collects static device/environment data.
 *
 * Gathers device information like screen dimensions, timezone,
 * and touch capability. This data helps identify device characteristics
 * and potential automation environments.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * HoneypotTracker - Tracks interactions with honeypot field.
 *
 * Monitors if the honeypot field receives any interaction (focus,
 * input, value changes). Any interaction indicates bot behavior.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * ActiveLayer Behavioral Collector
 *
 * Collects client-side behavioral signals (keystrokes, mouse movements, touch events,
 * scroll patterns, etc.) to help identify automated bots. Signals are sent to the API
 * for ML classification rather than making local bot decisions.
 *
 * Inspired by Akismet and CleanTalk behavioral analysis approaches.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * ActiveLayer Behavioral Collector Entry Point
 *
 * Exports BehavioralCollector to window.ActiveLayer and sets up form listeners.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
