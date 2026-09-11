(() => {
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __commonJS = (cb, mod) => function __require() {
    return mod || (0, cb[__getOwnPropNames(cb)[0]])((mod = { exports: {} }).exports, mod), mod.exports;
  };

  // upstream/src/controllers/extender/extender.scss
  var require_extender = __commonJS({
    "upstream/src/controllers/extender/extender.scss"(exports, module) {
      module.exports = {};
    }
  });

  // upstream/src/controllers/option/option.scss
  var require_option = __commonJS({
    "upstream/src/controllers/option/option.scss"(exports, module) {
      module.exports = {};
    }
  });

  // upstream/src/controllers/selector/selector.scss
  var require_selector = __commonJS({
    "upstream/src/controllers/selector/selector.scss"(exports, module) {
      module.exports = {};
    }
  });

  // upstream/src/controllers/langselector/langselector.scss
  var require_langselector = __commonJS({
    "upstream/src/controllers/langselector/langselector.scss"(exports, module) {
      module.exports = {};
    }
  });

  // upstream/src/controllers/menu/menu.scss
  var require_menu = __commonJS({
    "upstream/src/controllers/menu/menu.scss"(exports, module) {
      module.exports = {};
    }
  });

  // upstream/src/controllers/lock/lock.scss
  var require_lock = __commonJS({
    "upstream/src/controllers/lock/lock.scss"(exports, module) {
      module.exports = {};
    }
  });

  // upstream/src/controllers/countdown/countdown.scss
  var require_countdown = __commonJS({
    "upstream/src/controllers/countdown/countdown.scss"(exports, module) {
      module.exports = {};
    }
  });

  // upstream/src/controllers/statusBar/statusBar.scss
  var require_statusBar = __commonJS({
    "upstream/src/controllers/statusBar/statusBar.scss"(exports, module) {
      module.exports = {};
    }
  });

  // upstream/src/controllers/history/history.scss
  var require_history = __commonJS({
    "upstream/src/controllers/history/history.scss"(exports, module) {
      module.exports = {};
    }
  });

  // upstream/src/controllers/summary/summary.scss
  var require_summary = __commonJS({
    "upstream/src/controllers/summary/summary.scss"(exports, module) {
      module.exports = {};
    }
  });

  // ../upstream/pattern.js
  var Pattern = class {
    /**
     * Set up a pattern with only
     * the length of dots to link
     * @param  {Number} dotLength Length of the pattern
     */
    constructor(dotLength) {
      this.dotLength = dotLength;
      this.suite = [];
    }
    /**
     * Fill the current instance with random values
     */
    fillRandomly() {
      while (!this.isComplete()) {
        this.addDot(Math.floor(Math.random() * 9));
      }
    }
    /**
     * Add point to the current pattern
     * @param {int} dotIndex Dot index to add
     * @return boolean True if successfully added
     */
    addDot(dotIndex) {
      if (this.isComplete() || ~this.suite.indexOf(dotIndex))
        return [];
      let lastDot = this.suite[this.suite.length - 1], medianDot = (lastDot + dotIndex) / 2;
      if (lastDot != void 0 && medianDot >> 0 === medianDot && lastDot % 3 - medianDot % 3 === medianDot % 3 - dotIndex % 3 && Math.floor(lastDot / 3) - Math.floor(medianDot / 3) === Math.floor(medianDot / 3) - Math.floor(dotIndex / 3)) {
        let addedPoints = this.addDot(medianDot);
        if (!this.isComplete()) {
          this.suite.push(dotIndex);
          addedPoints.push(dotIndex);
        }
        return addedPoints;
      }
      this.suite.push(dotIndex);
      return [dotIndex];
    }
    /**
     * Checks if the instance suite is complete
     * @return {Boolean}
     */
    isComplete() {
      return this.suite.length >= this.dotLength;
    }
    /**
     * Checks if a dot is already in the pattern
     * @param  {int} dotIndex Index to check
     * @return {boolean}
     */
    gotDot(dotIndex) {
      return ~this.suite.indexOf(dotIndex);
    }
    /**
     * Compare a pattern with the current instance.
     * The output will be an array of three values:
     * [0]: Number of dots in the right place in the pattern
     * [1]: Number of correct dots badly placed in the pattern
     * [2]: Number of wrong dots
     * @param  {Pattern} pattern Pattern to compare
     * @return {Array}
     */
    compare(pattern) {
      var goodPos = 0, wrongPos = 0;
      for (let i = 0; i < this.dotLength; i++) {
        if (this.suite[i] === pattern.suite[i])
          goodPos++;
        for (let j = 0; j < this.dotLength; j++) {
          if (this.suite[j] === pattern.suite[i])
            wrongPos++;
        }
      }
      return [goodPos, wrongPos - goodPos, this.dotLength - wrongPos];
    }
    /**
     * Reset the pattern by removing all the dots
     */
    reset() {
      this.suite = [];
    }
  };
  var pattern_default = Pattern;

  // ../round.js
  var UPSTREAM_REVISION = "a06fb28a3fa6072a089ca664c66a7bf08c0a3e99";
  var RESTORING = Symbol("restoring");
  var clone = (value) => JSON.parse(JSON.stringify(value));
  var requireValue = (condition, message) => {
    if (!condition) throw new TypeError(message);
  };
  var integer = (value, min, max) => Number.isSafeInteger(value) && value >= min && value <= max;
  var modeValid = (mode) => ["practice", "challenge", "countdown"].includes(mode);
  var delayValid = (delay) => delay === null || integer(delay, 0, 1e3);
  var BreakLockRound = class _BreakLockRound {
    #secret;
    #draft;
    #state = {
      schemaVersion: 1,
      upstreamRevision: UPSTREAM_REVISION,
      mode: null,
      dotLength: null,
      history: [],
      ended: "active",
      counterValue: null,
      timer: { remainingTicks: null, running: false, nextTickDelayMs: null },
      summary: { visible: false, success: null, attemptCount: null },
      statusDisplay: "counter",
      pendingGuessResetDelayMs: null
    };
    constructor(options = {}) {
      if (options !== RESTORING) this.start(options.mode ?? "practice", options.dotLength ?? 4);
    }
    get attempts() {
      return this.#state.history.filter((entry) => entry.type === "guess").length;
    }
    /** GameCtrl.start: deliberately retains existing summary/reset/clock lifecycle. */
    start(mode = this.#state.mode, dotLength = this.#state.dotLength) {
      requireValue(modeValid(mode) && [4, 5, 6].includes(dotLength), "Invalid mode or difficulty");
      const secret = new pattern_default(dotLength);
      secret.fillRandomly();
      this.#secret = secret;
      this.#draft = new pattern_default(dotLength);
      Object.assign(this.#state, { mode, dotLength, history: [], ended: "active" });
      if (mode === "countdown") {
        const timer = this.#state.timer;
        timer.remainingTicks = 60;
        if (!timer.running) timer.nextTickDelayMs = 1e3;
        timer.running = true;
        this.#state.statusDisplay = "countdown";
      } else {
        this.#state.counterValue = mode === "practice" ? 0 : 10;
        this.#state.statusDisplay = "counter";
      }
      return { type: "started" };
    }
    /** Summary NEW_GAME starts the same mode/difficulty, then toggles the overlay. */
    newGame() {
      this.start();
      this.#state.summary.visible = !this.#state.summary.visible;
      return { type: "started" };
    }
    /** Begin a gesture, cancel a draft, or deliver its pending one-second reset. */
    clearDraft() {
      this.#draft.reset();
      this.#state.pendingGuessResetDelayMs = null;
      return { type: "draft-reset" };
    }
    /** Releasing an incomplete gesture clears it; submitted feedback remains briefly. */
    endGesture() {
      if (this.#state.pendingGuessResetDelayMs === null) return this.clearDraft();
      return { type: "pending-reset" };
    }
    /** LockCtrl.updatePoint/triggerDot/checkPattern, with Pattern owning all mechanics. */
    select(position) {
      requireValue(integer(position, 0, 8), "Invalid position");
      if (this.#state.pendingGuessResetDelayMs !== null) {
        return { type: "selection", added: [], attempt: null, blocked: "pending-reset" };
      }
      const added = this.#draft.addDot(position);
      const attempt = added.length && this.#draft.isComplete() ? this.#submit() : null;
      return { type: "selection", added, attempt };
    }
    #submit() {
      const feedback = this.#secret.compare(this.#draft);
      const matched = feedback[0] === this.#secret.dotLength;
      const count = this.attempts + 1;
      if (this.#state.ended !== "active") {
        this.#state.counterValue++;
      } else if (matched) {
        if (this.#state.mode === "countdown") this.#stopClock();
        this.#showResult(true, count);
      } else if (this.#state.mode === "practice") {
        this.#state.counterValue++;
      } else if (this.#state.mode === "challenge") {
        if (--this.#state.counterValue === 0) this.#showResult(false, count);
      }
      this.#state.history.push({ type: "guess", sequence: [...this.#draft.suite] });
      this.#state.pendingGuessResetDelayMs = 1e3;
      return { matched, feedback, count, ended: this.#state.ended };
    }
    #showResult(success, count) {
      this.#state.ended = success ? "won" : "lost";
      this.#state.summary = { visible: true, success, attemptCount: count };
    }
    #stopClock() {
      this.#state.timer.running = false;
      this.#state.timer.nextTickDelayMs = null;
    }
    /** One delivered interval callback, even when another mode now owns the round. */
    tick() {
      const timer = this.#state.timer;
      if (!timer.running) return { type: "clock-stopped" };
      timer.remainingTicks = Math.max(0, timer.remainingTicks - 1);
      if (timer.remainingTicks === 0) {
        this.#stopClock();
        this.#showResult(false, this.attempts);
        return { type: "timeout" };
      }
      timer.nextTickDelayMs = 1e3;
      return { type: "tick", remainingTicks: timer.remainingTicks };
    }
    /** Summary SOLUTION: reveal only on loss, switch counter, toggle summary. */
    reveal() {
      requireValue(this.#state.ended !== "active", "No result to reveal");
      const revealed = this.#state.ended === "lost";
      if (revealed) this.#state.history.push({ type: "reveal" });
      this.#state.counterValue = this.attempts;
      this.#state.statusDisplay = "counter";
      this.#state.summary.visible = !this.#state.summary.visible;
      return { type: "solution", revealed };
    }
    /** Navigation is an effect only. The existing interval deliberately survives. */
    home({ fromSummary = false } = {}) {
      if (fromSummary) this.#state.summary.visible = !this.#state.summary.visible;
      return { type: "home" };
    }
    /** Derive presentation feedback using Pattern; reveal entries are not attempts. */
    history() {
      return this.#state.history.map((entry) => {
        const sequence = entry.type === "reveal" ? this.#secret.suite : entry.sequence;
        const pattern = new pattern_default(this.#state.dotLength);
        sequence.forEach((position) => pattern.addDot(position));
        return { type: entry.type, sequence: [...sequence], feedback: this.#secret.compare(pattern) };
      });
    }
    /** Scheduler may supply residual delays at suspension; no elapsed ticks are inferred. */
    snapshot(delays = {}) {
      const state = clone(this.#state);
      state.secret = [...this.#secret.suite];
      state.draft = [...this.#draft.suite];
      if ("nextTickDelayMs" in delays) {
        requireValue(state.timer.running && integer(delays.nextTickDelayMs, 0, 1e3), "Invalid clock delay");
        state.timer.nextTickDelayMs = delays.nextTickDelayMs;
      }
      if ("pendingGuessResetDelayMs" in delays) {
        requireValue(state.pendingGuessResetDelayMs !== null && integer(delays.pendingGuessResetDelayMs, 0, 1e3), "Invalid reset delay");
        state.pendingGuessResetDelayMs = delays.pendingGuessResetDelayMs;
      }
      return state;
    }
    /** Restore JSON state without generating a secret, executing callbacks or owning I/O. */
    static restore(input) {
      const s = clone(input);
      requireValue(s && s.schemaVersion === 1 && s.upstreamRevision === UPSTREAM_REVISION, "Unsupported snapshot");
      requireValue(modeValid(s.mode) && [4, 5, 6].includes(s.dotLength), "Invalid round");
      const pattern = (sequence, complete) => {
        requireValue(Array.isArray(sequence) && sequence.every((p) => integer(p, 0, 8)), "Invalid sequence");
        const result = new pattern_default(s.dotLength);
        sequence.forEach((p) => result.addDot(p));
        requireValue(JSON.stringify(result.suite) === JSON.stringify(sequence), "Noncanonical sequence");
        requireValue(!complete || result.isComplete(), "Incomplete pattern");
        return result;
      };
      const secret = pattern(s.secret, true), draft = pattern(s.draft, false);
      requireValue(Array.isArray(s.history), "Invalid history");
      s.history.forEach((entry) => {
        requireValue(entry && ["guess", "reveal"].includes(entry.type), "Invalid history entry");
        if (entry.type === "guess") pattern(entry.sequence, true);
      });
      requireValue(["active", "won", "lost"].includes(s.ended), "Invalid result");
      requireValue(s.counterValue === null || integer(s.counterValue, 0, Number.MAX_SAFE_INTEGER), "Invalid counter");
      requireValue(s.timer && typeof s.timer.running === "boolean" && delayValid(s.timer.nextTickDelayMs), "Invalid timer");
      requireValue(s.timer.remainingTicks === null || integer(s.timer.remainingTicks, 0, 60), "Invalid ticks");
      requireValue(s.timer.running ? s.timer.remainingTicks > 0 && s.timer.nextTickDelayMs !== null : s.timer.nextTickDelayMs === null, "Invalid timer lifecycle");
      requireValue(s.summary && typeof s.summary.visible === "boolean" && [null, true, false].includes(s.summary.success), "Invalid summary");
      requireValue(s.summary.attemptCount === null || integer(s.summary.attemptCount, 0, Number.MAX_SAFE_INTEGER), "Invalid summary count");
      requireValue(["counter", "countdown"].includes(s.statusDisplay) && delayValid(s.pendingGuessResetDelayMs), "Invalid presentation");
      requireValue(!draft.isComplete() || s.pendingGuessResetDelayMs !== null, "Completed draft requires pending reset");
      const round = new _BreakLockRound(RESTORING);
      for (const key of Object.keys(round.#state)) round.#state[key] = s[key];
      round.#secret = secret;
      round.#draft = draft;
      return round;
    }
  };

  // ../persistence/session.js
  var BreakLockSession = class {
    constructor(surface2, request, { onError = () => {
    }, intervalMs = 1e4, requestTimeoutMs = 8e3 } = {}) {
      this.surface = surface2;
      this.request = (body) => new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error("Storage timeout")), requestTimeoutMs);
        Promise.resolve().then(() => request(body)).then(resolve, reject).finally(() => clearTimeout(timeout));
      });
      this.onError = onError;
      this.intervalMs = intervalMs;
      this.tail = Promise.resolve();
      this.active = false;
      this.heartbeat = null;
      this.exiting = null;
      surface2.suspend();
    }
    async acquire() {
      const result = await this.request({ action: "acquire" });
      if (!result.success) throw new Error(result.reason || "acquire");
      this.envelope = result;
      this.active = true;
      try {
        if (this.exiting) throw new Error("Exit requested during acquisition");
        const state = result.data?.schemaVersion ? BreakLockRound.restore(result.data).snapshot() : null;
        if (state === null && Object.keys(result.data || {}).length) throw new Error("Invalid saved state");
        this.surface.restore(state);
        this.heartbeat = setInterval(() => this.checkpoint().catch(() => {
        }), this.intervalMs);
        this.heartbeat.unref?.();
        return state;
      } catch (error) {
        await this.request({ action: "release", owner_token: result.owner_token }).catch(() => {
        });
        this.fail(error);
        throw error;
      }
    }
    enqueue(operation) {
      const next = this.tail.then(async () => {
        if (!this.active) throw new Error("Writer inactive");
        return operation();
      });
      this.tail = next.catch((error) => {
        this.fail(error);
      });
      return next;
    }
    async send(action, extra = {}) {
      const result = await this.request({
        action,
        owner_token: this.envelope.owner_token,
        attempt_id: this.envelope.attempt_id,
        revision: this.envelope.revision,
        ...extra
      });
      if (!result.success) throw new Error(result.reason || "storage");
      this.envelope = { ...this.envelope, ...result };
      return result;
    }
    checkpoint() {
      return this.enqueue(() => {
        const data = this.surface.snapshot();
        return this.send(data ? "save" : "renew", data ? { data } : {});
      });
    }
    exit() {
      if (this.exiting) return this.exiting;
      clearInterval(this.heartbeat);
      const frozen = this.surface.suspend();
      this.exiting = this.enqueue(async () => {
        if (frozen) await this.send("save", { data: frozen });
        await this.send("release");
        this.active = false;
        return frozen;
      });
      return this.exiting;
    }
    fail(error) {
      clearInterval(this.heartbeat);
      this.active = false;
      this.surface.suspend();
      this.onError(error);
    }
  };
  function webTransport(endpoint, csrfToken) {
    return async (body) => {
      const response = await fetch(endpoint, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
        body: JSON.stringify(body)
      });
      if (!response.ok) throw new Error(`Storage HTTP ${response.status}`);
      return response.json();
    };
  }

  // ../persistence/web.js
  async function connectWeb(surface2, { endpoint, csrfToken, onExit = () => {
  }, onError = () => {
  } }) {
    const report = (error) => {
      let notice = document.getElementById("breaklock-storage-error");
      if (!notice) {
        notice = document.createElement("div");
        notice.id = "breaklock-storage-error";
        notice.setAttribute("role", "alert");
        notice.style.cssText = "position:fixed;top:0;left:0;right:0;z-index:10000;background:#222;color:white;padding:12px";
        document.body.appendChild(notice);
      }
      notice.textContent = "Progress storage stopped. Reload to reacquire your last checkpoint. " + error.message;
      onError(error);
    };
    const session = new BreakLockSession(surface2, webTransport(endpoint, csrfToken), { onError: report });
    try {
      await session.acquire();
    } catch (error) {
      report(error);
      throw error;
    }
    const exit = async () => {
      const state = await session.exit();
      onExit();
      return state;
    };
    return { checkpoint: () => session.checkpoint(), exit, session };
  }

  // ../persistence/scheduler.js
  var RoundScheduler = class {
    constructor(surface2, clock = globalThis) {
      this.surface = surface2;
      this.clock = clock;
      this.interval = this.first = this.reset = null;
      this.tickDue = this.resetDue = null;
    }
    now() {
      return this.clock.performance?.now() ?? performance.now();
    }
    sync() {
      const s = this.surface.round?.snapshot();
      if (!s) return;
      if (s.timer.running && this.interval === null && this.first === null) {
        const tick = () => {
          if (this.surface.closed || this.surface.suspended) return;
          this.tickDue = this.now() + 1e3;
          this.surface.round.tick();
          this.surface.refresh();
        };
        const delay = s.timer.nextTickDelayMs;
        this.tickDue = this.now() + delay;
        if (delay === 1e3) this.interval = this.clock.setInterval(tick, 1e3);
        else this.first = this.clock.setTimeout(() => {
          this.first = null;
          if (this.surface.closed || this.surface.suspended) return;
          this.interval = this.clock.setInterval(tick, 1e3);
          tick();
        }, delay);
      } else if (!s.timer.running) this.stopTick();
      if (s.pendingGuessResetDelayMs !== null && this.reset === null) {
        this.resetDue = this.now() + s.pendingGuessResetDelayMs;
        this.reset = this.clock.setTimeout(() => {
          this.reset = this.resetDue = null;
          if (this.surface.closed || this.surface.suspended) return;
          this.surface.round.clearDraft();
          this.surface.refresh();
        }, s.pendingGuessResetDelayMs);
      } else if (s.pendingGuessResetDelayMs === null && this.reset !== null) {
        this.clock.clearTimeout(this.reset);
        this.reset = this.resetDue = null;
      }
    }
    snapshot() {
      if (!this.surface.round) return null;
      const residual = (due) => Math.max(0, Math.min(1e3, Math.round(due - this.now())));
      const delays = {};
      if (this.tickDue !== null) delays.nextTickDelayMs = residual(this.tickDue);
      if (this.resetDue !== null) delays.pendingGuessResetDelayMs = residual(this.resetDue);
      return this.surface.round.snapshot(delays);
    }
    stopTick() {
      this.clock.clearInterval(this.interval);
      this.clock.clearTimeout(this.first);
      this.interval = this.first = this.tickDue = null;
    }
    stop() {
      this.stopTick();
      this.clock.clearTimeout(this.reset);
      this.reset = this.resetDue = null;
    }
  };

  // upstream/src/utils/dom.js
  var dom = {
    /**
     * Most advanced method (of this object) to
     * create a DOM element
     * @param  {String}        nodeName Node type to create
     * @param  {Object|String} props    Key/value of attributes to set
     * @param  {Array|String}  content  Text content if string or childnodes if array to include
     * @return {DOMElement}             Node generated
     */
    create: (nodeName, props = {}, content = null) => {
      var node;
      if (dom.SVG_ELEMENTS.indexOf(nodeName) === -1)
        node = document.createElement(nodeName);
      else
        node = document.createElementNS(dom.SVG_NAMESPACE, nodeName);
      if (props.constructor === String)
        node.setAttribute("class", props);
      else
        for (let propName in props)
          node.setAttribute(propName, props[propName]);
      if (content instanceof Array)
        for (let i = 0; i < content.length; i++) {
          node.appendChild(content[i]);
        }
      else
        node.textContent = content;
      return node;
    },
    /**
     * Generate SVG icon dom, using 'use' tags
     * and the definitions in the index.html
     * @param  {String} name Icon name (cf. definitions in index.html)
     * @return {SVGDOMElement}
     */
    icon: (name) => {
      let use = dom.create("use");
      use.setAttributeNS(dom.XLINK_NAMESPACE, "href", "#icon-" + name);
      return dom.create("svg", { class: "icon" }, [use]);
    },
    /**
     * Clear the content of an element
     * @param  {DOMElement} element Element to clear
     */
    clear: (element) => {
      for (let i = element.childNodes.length - 1; i >= 0; i--) {
        element.childNodes[i].remove();
      }
    },
    SVG_NAMESPACE: "http://www.w3.org/2000/svg",
    XLINK_NAMESPACE: "http://www.w3.org/1999/xlink",
    SVG_ELEMENTS: ["svg", "g", "circle", "line", "path", "use", "rect"]
  };
  var dom_default = dom;

  // upstream/src/controllers/extender/extender.ctrl.js
  require_extender();
  var ExtenderCtrl = class {
    /**
     * Every instance require a title which is the dropdown
     * button text, the content that can be a string or
     * a DOM element to display, and the initial state
     * of the extender.
     * @param  {String}  title      Button title
     * @param  {String}  content    Content text of DOM element to display
     * @param  {Boolean} isExpanded Initial state of the controller
     */
    constructor(title, content, isExpanded) {
      this.title = title;
      this.content = content;
      this.isExpanded = isExpanded;
      this.setupTemplate();
    }
    /**
     * Build template of the controller
     * @return {DOMElement}
     */
    setupTemplate() {
      let content = this.content instanceof String ? this.content : [this.content];
      this.buttonEl = dom_default.create("button", "extender-button", this.title);
      this.contentEl = dom_default.create("div", "extender-content", content);
      this.el = dom_default.create("div", "extender small-only", [
        this.buttonEl,
        this.contentEl
      ]);
      this.render();
      return this.el;
    }
    /**
     * Set up listeners
     */
    init() {
      this.buttonEl.addEventListener("click", this.toggle.bind(this));
    }
    /**
     * Show/hide the content
     * @param  {Boolean} force Force to show or hide if provided
     */
    toggle(force) {
      this.isExpanded = force instanceof Boolean ? force : !this.isExpanded;
      this.render();
    }
    /**
     * Render the DOM from the state of the controller
     *
     */
    render() {
      this.el.classList[this.isExpanded ? "add" : "remove"]("active");
    }
  };
  var extender_ctrl_default = ExtenderCtrl;

  // upstream/src/controllers/option/option.ctrl.js
  require_option();
  var OptionCtrl = class {
    /**
     * Setup the template and different options
     * See `setChoices` method to understand the
     * format of the following parameters
     * @param {Array} choiceList List of key/values to display
     */
    constructor(choiceList, defaultChoice) {
      this.setupTemplate();
      this.setChoices(choiceList);
    }
    /**
     * Build template of the controller
     * @return {DOMElement}
     */
    setupTemplate() {
      this.el = dom_default.create("div", "selectbox");
      return this.el;
    }
    /**
     * Set up the different available choices
     * Choice list format:
     * [
     *   { value: <int>, label: <string>, default: <boolean> },
     *   { value: 2, label: 'Easy'},
     *   { value: 3, label: 'Medium', default: true},
     *   { value: 4, label: 'Hard'},
     * ]
     * @param {Array} choiceList List of options to display
     */
    setChoices(choiceList) {
      let listener = this.selectListener.bind(this);
      choiceList.forEach((choice, index) => {
        let option = dom_default.create("span", {
          class: "selectbox-item",
          rel: choice.value
        }, choice.label);
        option.addEventListener("click", listener);
        option.addEventListener("touchstart", listener);
        this.el.appendChild(option);
        if (choice.default)
          this.selectFromTag(option);
        return option;
      });
      this.el.classList.add("selectbox-" + choiceList.length);
    }
    /**
     * Listener for click on items
     * @param  {Event} event Event catched
     */
    selectListener(e) {
      e.preventDefault();
      e.stopPropagation();
      this.selectFromTag(e.currentTarget);
    }
    /**
     * Update the selected value from the tag (:item)
     * provided in parameter. The call will apply the
     * class selected to the new tag (and remove it to
     * the previous one), then also update the selected
     * value of the instance.
     * @param  {DOMElement} tag Element tag selected
     */
    selectFromTag(tag) {
      if (this.selectedTag)
        this.selectedTag.classList.remove("active");
      this.selectedTag = tag;
      this.selectedTag.classList.add("active");
      this.selectedValue = window.parseInt(tag.getAttribute("rel"), 10);
    }
    /**
     * Return the current choice selected
     * @return {int}
     */
    getValue() {
      return this.selectedValue;
    }
  };
  var option_ctrl_default = OptionCtrl;

  // upstream/src/controllers/selector/selector.ctrl.js
  require_selector();
  var SelectorCtrl = class {
    /**
     * Setup the template and different options
     * See `setChoices` method to understand the
     * format of the following parameters
     * @param {Array} choiceList List of key/values to display
     */
    constructor(choiceList, defaultChoice) {
      this.selectionIndex = 0;
      this.setupTemplate();
      this.setChoices(choiceList);
    }
    /**
     * Build template of the controller
     * @return {DOMElement}
     */
    setupTemplate() {
      this.btnLeft = dom_default.create("span", "selectbox-item active selector-left", "<");
      this.btnRight = dom_default.create("span", "selectbox-item active selector-right", ">");
      this.labelEl = dom_default.create("span", "selectbox-item selector-label");
      this.el = dom_default.create("div", "selector selectbox", [
        this.btnLeft,
        this.btnRight,
        this.labelEl
      ]);
      return this.el;
    }
    /**
     * Set up listeners
     */
    init() {
      this.btnLeft.addEventListener("click", this.previous.bind(this));
      this.btnLeft.addEventListener("touchstart", this.previous.bind(this));
      this.btnRight.addEventListener("click", this.next.bind(this));
      this.btnRight.addEventListener("touchstart", this.next.bind(this));
    }
    /**
     * Set up the different available choices
     * Choice list format:
     * [
     *   { value: <int>, label: <string>, default: <boolean> },
     *   { value: 2, label: 'Easy'},
     *   { value: 3, label: 'Medium', default: true},
     *   { value: 4, label: 'Hard'},
     * ]
     * @param {Array} choiceList List of options to display
     */
    setChoices(choiceList) {
      this.choices = choiceList;
      for (let i = this.choices.length - 1; i >= 0; i--) {
        this.selectionIndex = this.choices[i].default ? i : this.selectionIndex;
      }
      this.selectionIndex = this.selectionIndex || 0;
      this.updateLabel();
    }
    /**
     * Update the counter DOM to the value
     * set in the instance
     * @return {int} The new value of the counter
     */
    updateLabel() {
      this.selectionIndex = (this.selectionIndex + this.choices.length) % this.choices.length;
      let choice = this.choices[this.selectionIndex];
      this.labelEl.textContent = choice.label;
      if (this.selectCallback)
        this.selectCallback(this.choices[this.selectionIndex]);
      return this.selectionIndex;
    }
    /**
     * Decrement the counter
     * @return {int} The new value of the counter
     */
    next(e) {
      e.preventDefault();
      e.stopPropagation();
      this.selectionIndex++;
      return this.updateLabel();
    }
    /**
     * Increment the counter
     * @return {int} The new value of the counter
     */
    previous(e) {
      e.preventDefault();
      e.stopPropagation();
      this.selectionIndex--;
      return this.updateLabel();
    }
    /**
     * Listener for when a new item is selected
     * The listener will be called with only one
     * parameter: the new selected value
     * @param  {function} listener Select listener to set
     */
    onSelect(listener) {
      this.selectCallback = listener;
      this.updateLabel();
    }
    /**
     * Return the current choice selected
     * @return {int}
     */
    getValue() {
      let choice = this.choices[this.selectionIndex];
      return choice && choice.value;
    }
  };
  var selector_ctrl_default = SelectorCtrl;

  // upstream/src/controllers/langselector/langselector.ctrl.js
  require_langselector();
  var LangSelector = class {
    constructor() {
      this.setupTemplate();
      this.setChoices([
        { value: "en", label: "English" },
        { value: "fa", label: "\u0641\u0627\u0631\u0633\u06CC" },
        { value: "zh", label: "\u7B80\u4F53\u4E2D\u6587" },
        { value: "ru", label: "\u0420\u0443\u0441\u0441\u043A\u0438\u0439" },
        { value: "fr", label: "Fran\xE7ais" },
        { value: "pt_br", label: "Portugu\xEAs Brasileiro" },
        { value: "it", label: "Italian" }
      ]);
    }
    /**
     * Build template of the controller
     * @return {DOMElement}
     */
    setupTemplate() {
      this.el = dom_default.create("div", "lang-selector disabled");
      this.el.onclick = () => this.el.classList.toggle("disabled");
      return this.el;
    }
    setChoices(choiceList) {
      let div = dom_default.create("div");
      choiceList.forEach((choice) => {
        let option = dom_default.create("a", {
          href: choice.value
        }, choice.label);
        div.appendChild(option);
        div.appendChild(dom_default.create("br"));
      });
      this.el.appendChild(div);
    }
  };
  var langselector_ctrl_default = LangSelector;

  // upstream/src/config.js
  var config = {
    GAME: {
      DIFFICULTY: {
        EASY: 4,
        MEDIUM: 5,
        HARD: 6
      },
      TYPE: {
        PRACTICE: 1,
        CHALLENGE: 2,
        COUNTDOWN: 3
      },
      ACTIONS: {
        SOLUTION: 1,
        NEW_GAME: 2,
        BACK_HOME: 3
      }
    },
    SOCIAL: {
      PLATFORMS: {
        FB: {
          NAME: "Facebook",
          ICON: "facebook",
          URL: (url) => `https://www.facebook.com/sharer/sharer.php?u=${encodeURI(url)}`
        },
        TWITTER: {
          NAME: "Twitter",
          ICON: "twitter",
          URL: (url, msg, tags) => {
            return `http://twitter.com/` + (url ? `share?` : `intent/tweet?`) + (msg ? `text=${encodeURI(msg)}&` : "") + (url ? `url=${encodeURI(url)}&` : "") + (tags ? `hashtags=${encodeURI(tags.join(","))}` : "");
          }
        }
      },
      MESSAGE: "I wasted my time on BreakLock, it's pointless, don't try it.",
      TAGS: ["breaklock"]
    },
    URL: "https://maxwellito.github.io/breaklock/",
    COLORS: {
      BRIGHT: "#ffffff",
      DARK: "#000000",
      SUCCESS: "#116699",
      ERROR: "#ff0000"
    },
    PATTERN: {
      HEX_COLOR_START: "66",
      HEX_COLOR_END: "FF"
    }
  };
  var config_default = config;

  // upstream/src/utils/airportText.js
  var animStack = [];
  var ratioFrameRate = 3;
  function airportText(element, text) {
    let existingAnim = popAnim(element);
    if (existingAnim)
      cancelFakeNextFrame(existingAnim.nextFrame);
    var newAnim = {
      el: element,
      counter: text.length * ratioFrameRate,
      originalLength: text.length,
      originalText: text,
      nextFrame: null
    };
    updateDisplay(newAnim);
    animStack.push(newAnim);
  }
  function popAnim(element) {
    for (let i = animStack.length - 1; i >= 0; i--) {
      if (animStack[i].el === element)
        return animStack.splice(i, 1)[0];
    }
  }
  function setNextFrame(anim) {
    anim.nextFrame = requestFakeNextFrame(function() {
      updateDisplay(anim);
    });
  }
  function updateDisplay(anim) {
    anim.counter -= 1;
    if (anim.counter <= 0) {
      anim.el.textContent = anim.originalText;
      popAnim(anim.el);
      return;
    }
    var randomLength = Math.floor(anim.originalLength - anim.counter / ratioFrameRate);
    anim.el.textContent = anim.originalText.substr(0, randomLength) + getRandomWord(Math.min(anim.originalLength - randomLength, 3));
    setNextFrame(anim);
  }
  function getRandomWord(pLength) {
    var toReturn = "";
    var charList = "abcdefghijklmnopqrstuvwxyz0123456789 _*%!?#/\\|@";
    if (pLength <= 0) {
      return toReturn;
    }
    for (let i = 0; i < pLength; i++) {
      toReturn += charList.charAt(Math.floor(Math.random() * charList.length));
    }
    return toReturn;
  }
  function requestFakeNextFrame(callback) {
    return window.setTimeout(callback, 60);
  }
  function cancelFakeNextFrame(id) {
    return window.clearTimeout(id);
  }
  var airportText_default = airportText;

  // upstream/src/controllers/menu/menu.ctrl.js
  require_menu();
  var MenuCtrl = class {
    /**
     * ¯\_(ツ)_/¯
     */
    constructor(onStart) {
      this.onStart = onStart;
      this.setupTemplate();
    }
    /**
     * Build template of the controller
     * @return {DOMElement}
     */
    setupTemplate() {
      let title = dom_default.create(
        "h1",
        "-wrap highlight unselectable",
        "BreakLock"
      ), intro = dom_default.create(
        "p",
        "menu-intro",
        "A hybrid of Mastermind and the Android pattern lock. A game you gonna love to hate."
      );
      this.title = title;
      this.typeHelpEl = dom_default.create("p", {}, "Future info about the challenge");
      this.btnStarlEl = dom_default.create("button", "action-btn", "START_");
      airportText_default(title, "BreakLock");
      let instructions = new extender_ctrl_default(
        "INSTRUCTIONS",
        document.getElementById("instructions-template")
      );
      instructions.init();
      this.difficultyOption = new option_ctrl_default([
        { value: config_default.GAME.DIFFICULTY.EASY, label: "Easy", default: true },
        { value: config_default.GAME.DIFFICULTY.MEDIUM, label: "Medium" },
        { value: config_default.GAME.DIFFICULTY.HARD, label: "Hard" }
      ]);
      this.typeSelector = new selector_ctrl_default([
        {
          value: config_default.GAME.TYPE.PRACTICE,
          label: "Practice",
          description: "No pressure, just discover and practice your game",
          default: true
        },
        {
          value: config_default.GAME.TYPE.CHALLENGE,
          label: "Challenge",
          description: "Challenge mode give you 10 attempts only to win"
        },
        {
          value: config_default.GAME.TYPE.COUNTDOWN,
          label: "Countdown",
          description: "Solve the game in one minute, without limit of attempts"
        }
      ]);
      const lang = (() => {
        const x = window.location.pathname.split("/");
        while (true) {
          const y = x.pop();
          if (y === void 0) return "EN";
          if (y.length === 2) return y.toUpperCase();
        }
      })();
      const langButton = dom_default.create("button", "lang-button", [dom_default.icon("lang")]);
      const langSelector = new langselector_ctrl_default();
      langButton.onclick = () => {
        langSelector.el.classList.toggle("disabled");
      };
      this.el = dom_default.create("div", "menu-layout view", [
        dom_default.create("div", "view-bloc menu-layout-instructions", [
          dom_default.create("div", "ui-row", [
            title,
            langButton
          ]),
          intro,
          langSelector.el,
          instructions.el
        ]),
        dom_default.create("div", "view-bloc menu-layout-form", [
          this.difficultyOption.el,
          this.typeSelector.el,
          this.typeHelpEl,
          this.btnStarlEl
        ])
      ]);
      return this.el;
    }
    /**
     * Set up listeners
     */
    init() {
      this.typeSelector.init();
      this.typeSelector.onSelect(this.typeChange.bind(this));
      this.btnStarlEl.addEventListener("click", this.start.bind(this));
    }
    /**
     * Start a new game by calling the callback
     * provided in the controller.
     */
    start() {
      this.onStart(
        this.typeSelector.getValue(),
        this.difficultyOption.getValue()
      );
    }
    /**
     * Selector for new type
     * @param  {object} type New selected type
     */
    typeChange(type) {
      this.typeHelpEl.textContent = type.description;
    }
  };
  var menu_ctrl_default = MenuCtrl;

  // upstream/src/utils/patternSVG.js
  var PatternSVG = class {
    /**
     * Setup the SVG base node
     *
     */
    constructor() {
      this.el = dom_default.create("svg", { "viewBox": "0 0 " + this.SVG_WIDTH + " " + this.SVG_WIDTH });
    }
    /**
     * Add an invisible rectangle as background.
     * This is only to help Safari to catch touch events
     * on the SVG lock.
     */
    addBackgroundLayer() {
      let rect = dom_default.create("rect", {
        "fill": "#fff",
        "fill-opacity": "0",
        "width": this.SVG_WIDTH,
        "height": this.SVG_WIDTH
      });
      this.el.appendChild(rect);
      return rect;
    }
    /**
     * Add pattern to the instance
     * @param {Pattern}      pattern   Pattern instance to get the points from
     * @param {int}          size      Thickness of the line
     * @param {string|array} color     List of colors to use for the pattern
     * @return PatternSVG
     */
    addPattern(pattern, size = 14, color2 = "#fff") {
      let lines = [];
      color2 = color2 instanceof Array ? color2 : [color2];
      for (let i = 1; i < pattern.suite.length; i++) {
        lines.push(dom_default.create("line", {
          "x1": pattern.suite[i - 1] % 3 * this.GRID_GUTTER + this.SVG_MARGIN,
          "y1": Math.floor(pattern.suite[i - 1] / 3) * this.GRID_GUTTER + this.SVG_MARGIN,
          "x2": pattern.suite[i] % 3 * this.GRID_GUTTER + this.SVG_MARGIN,
          "y2": Math.floor(pattern.suite[i] / 3) * this.GRID_GUTTER + this.SVG_MARGIN,
          "stroke": color2[Math.min(color2.length, i) - 1]
        }));
      }
      let lastDotIndex = pattern.suite[pattern.suite.length - 1];
      lines.push(dom_default.create("circle", {
        cx: lastDotIndex % 3 * this.GRID_GUTTER + this.SVG_MARGIN,
        cy: Math.floor(lastDotIndex / 3) * this.GRID_GUTTER + this.SVG_MARGIN,
        fill: color2[0],
        r: size / 4
      }));
      return this.addGroup({
        "stroke-width": size,
        "stroke-linecap": "round"
      }, lines);
    }
    /**
     * Add dots to the instance
     * @param {int}    size  Thickness of the line
     * @param {object} attr  List of attributes to set to the group
     * @return PatternSVG
     */
    addDots(size = 3, attr = {}) {
      let dots = [];
      attr.fill = attr.fill || "#fff";
      for (let i = 0; i < 9; i++) {
        dots.push(dom_default.create("circle", {
          cx: i % 3 * this.GRID_GUTTER + this.SVG_MARGIN,
          cy: Math.floor(i / 3) * this.GRID_GUTTER + this.SVG_MARGIN,
          rel: i,
          r: size
        }));
      }
      return this.addGroup(attr, dots);
    }
    /**
     * Add group to the instance
     * @param {object} attr    List of attributes to set to the group
     * @param {*}      content Items as content
     * @return SVGDOMElement
     */
    addGroup(attr, content) {
      let group = dom_default.create("g", attr, content);
      this.el.appendChild(group);
      return group;
    }
    /**
     * Add combinaison results
     * @param {int} goodDots      Amount of good dots
     * @param {int} badPlacedDots Amount of badly placed dots
     * @param {int} wrongDots     Amount of wrong dots
     */
    addCombinaison(goodDots, badPlacedDots, wrongDots) {
      let totalDots = goodDots + badPlacedDots + wrongDots, dot = Math.min(Math.floor(this.SVG_WIDTH / totalDots), this.SVG_COMB_EXP), dotWidth = Math.floor(dot * 0.75), dotGap = Math.floor(dot * 0.25), xGap = dotWidth + dotGap, xStart = Math.floor((this.SVG_WIDTH - (totalDots - 1) * xGap) / 2), yStart = this.SVG_WIDTH + Math.floor(this.SVG_COMB_EXP / 2);
      this.el.setAttribute("viewBox", "0 0 " + this.SVG_WIDTH + " " + (this.SVG_WIDTH + this.SVG_COMB_EXP));
      let dots = [];
      for (let i = 0; i < totalDots; i++) {
        dots.push(dom_default.create("circle", {
          "cx": xStart + i * xGap,
          "cy": yStart,
          "r": (dotWidth - this.DOT_BORDER) / 2,
          "stroke-width": this.DOT_BORDER,
          "fill": i < goodDots ? "#fff" : "#000",
          "stroke": i < goodDots + badPlacedDots ? "#fff" : "#000",
          "fill-opacity": i < goodDots ? "1" : ".25",
          "stroke-opacity": i < goodDots + badPlacedDots ? "1" : ".25"
        }));
      }
      return this.addGroup({}, dots);
    }
    /**
     * Get the SVG
     * @return SVGDOMElement
     */
    getSVG() {
      return this.el;
    }
  };
  PatternSVG.prototype.SVG_NAMESPACE = "http://www.w3.org/2000/svg";
  PatternSVG.prototype.SVG_WIDTH = 100;
  PatternSVG.prototype.SVG_COMB_EXP = 20;
  PatternSVG.prototype.SVG_MARGIN = 15;
  PatternSVG.prototype.GRID_GUTTER = 35;
  PatternSVG.prototype.DOT_BORDER = 2;
  PatternSVG.prototype.DOT_MAGNET = 6;
  var patternSVG_default = PatternSVG;

  // upstream/src/controllers/lock/lock.ctrl.js
  require_lock();
  var LockCtrl = class {
    /**
     * Make it ready
     * @param  {Function} callback  Callback to call on new pattern
     */
    constructor(callback) {
      this.currentLine = null;
      this.onNewPattern = callback;
      this.setupTemplate();
    }
    /**
     * Build template of the controller
     * @return {SVGDOMElement}
     */
    setupTemplate() {
      let myPatternSVG = new patternSVG_default();
      myPatternSVG.addBackgroundLayer();
      this.el = myPatternSVG.getSVG();
      this.el.setAttribute("class", "lock");
      this.patternEl = myPatternSVG.addGroup({
        "stroke-width": "2",
        "stroke": config_default.COLORS.BRIGHT,
        "stroke-linecap": "round"
      });
      this.bigDotsEl = myPatternSVG.addDots(9, { class: "lock-flashdots" });
      myPatternSVG.addDots(2);
      return this.el;
    }
    /**
     * Start listening to user input
     */
    init() {
      this.el.addEventListener("touchstart", this.touchStart.bind(this));
      this.el.addEventListener("touchmove", this.touchUpdate.bind(this));
      this.el.addEventListener("touchend", this.touchEnd.bind(this));
      this.el.addEventListener("mousedown", this.mouseStart.bind(this));
    }
    /**
     * Set the pattern length
     * @param {int} dotLength Number of dots in the pattern
     */
    setDotLength(dotLength) {
      this.dotLength = dotLength;
      this.pattern = new pattern_default(this.dotLength);
    }
    /**
     * Listeners
     */
    /* Mouse listeners *************************************/
    /**
     * Listener for mouse down on the lock.
     * It will start listening to mouse move and stop.
     * @param  {MouseEvent} t Mouse down event
     */
    mouseStart(t) {
      this.reset();
      this.mouseUpdateBind = this.mouseUpdate.bind(this);
      this.mouseEndBind = this.mouseEnd.bind(this);
      this.el.addEventListener("mousemove", this.mouseUpdateBind);
      window.addEventListener("mouseleave", this.mouseEndBind);
      window.addEventListener("mouseup", this.mouseEndBind);
      this.mouseUpdate(t);
    }
    /**
     * Listener for mouse move while drowing a pattern
     * with the mouse.
     * @param  {MouseEvent} t Mouse move event
     */
    mouseUpdate(t) {
      t.preventDefault();
      t.stopPropagation();
      let e = t.currentTarget.getBoundingClientRect(), x = Math.max(0, Math.min(patternSVG_default.prototype.SVG_WIDTH, Math.round(patternSVG_default.prototype.SVG_WIDTH / e.width * (t.pageX - e.left)))), y = Math.max(0, Math.min(patternSVG_default.prototype.SVG_WIDTH, Math.round(patternSVG_default.prototype.SVG_WIDTH / e.height * (t.pageY - e.top))));
      this.updatePoint(x, y);
    }
    /**
     * Method to end drawing with mouse.
     * It will remove useless listeners and reset
     * the current pattern.
     * @param  {MouseEvent} t Mouse event
     */
    mouseEnd(t) {
      if (!this.isPendingReset)
        this.reset();
      this.el.removeEventListener("mousemove", this.mouseUpdateBind);
      window.removeEventListener("mouseout", this.mouseEndBind);
      window.removeEventListener("mouseup", this.mouseEndBind);
    }
    /* Touch listeners *************************************/
    /**
     * Listener for startring drwaing a pattern
     * with touch events.
     * This will only reset the current pattern
     * and start drawing the new one.
     * @param  {Event} t Touch Start event
     */
    touchStart(t) {
      this.reset();
      this.touchUpdate(t);
    }
    /**
     * Listener for touch events
     * The method will calculate the position of the finger
     * on the lock to update the line and add dots to the
     * current pattern.
     * @param  {Event} t Touch event
     */
    touchUpdate(t) {
      t.preventDefault();
      t.stopPropagation();
      let e = t.currentTarget.getBoundingClientRect(), x = Math.max(0, Math.min(patternSVG_default.prototype.SVG_WIDTH, Math.round(patternSVG_default.prototype.SVG_WIDTH / e.width * (t.targetTouches[0].pageX - e.left)))), y = Math.max(0, Math.min(patternSVG_default.prototype.SVG_WIDTH, Math.round(patternSVG_default.prototype.SVG_WIDTH / e.height * (t.targetTouches[0].pageY - e.top))));
      this.updatePoint(x, y);
    }
    /**
     * Listener for end of pattern drawing
     * with touch events.
     */
    touchEnd() {
      if (!this.isPendingReset)
        this.reset();
    }
    /*
     * Drawing logic
     */
    /**
     * Update the current pattern by providing
     * the position of the new cursor/finger
     * in ordinates scaled to SVG size.
     * @param  {Number} x Position X of pointer/finger
     * @param  {Number} y Position X of pointer/finger
     */
    updatePoint(x, y) {
      if (this.isPendingReset)
        return;
      let iX, iY;
      for (let i = 0; i < 3; i++) {
        let rangeStart = patternSVG_default.prototype.GRID_GUTTER * i + patternSVG_default.prototype.SVG_MARGIN - patternSVG_default.prototype.DOT_MAGNET, rangeEnd = patternSVG_default.prototype.GRID_GUTTER * i + patternSVG_default.prototype.SVG_MARGIN + patternSVG_default.prototype.DOT_MAGNET;
        iX = rangeStart <= x && rangeEnd >= x ? i : iX;
        iY = rangeStart <= y && rangeEnd >= y ? i : iY;
      }
      let isEndOfPattern;
      if (iX !== void 0 && iY != void 0) {
        let dotIndex = iY * 3 + iX;
        isEndOfPattern = this.triggerDot(dotIndex);
      }
      if (!isEndOfPattern)
        this.updateLine(x, y);
      return true;
    }
    /**
     * Add a dot on the current pattern and the
     * intermediate ones if there's any.
     * @param {number} dotIndex Dot triggered index
     */
    triggerDot(dotIndex) {
      if (this.pattern.gotDot(dotIndex))
        return;
      var newDots = this.pattern.addDot(dotIndex);
      if (navigator.vibrate)
        navigator.vibrate(20);
      newDots.forEach((dot, index) => {
        let dotX = patternSVG_default.prototype.GRID_GUTTER * (dot % 3) + patternSVG_default.prototype.SVG_MARGIN, dotY = patternSVG_default.prototype.GRID_GUTTER * Math.floor(dot / 3) + patternSVG_default.prototype.SVG_MARGIN;
        this.closeLine(dotX, dotY);
        this.bigDotsEl.childNodes[dot].classList.add("active");
        if (index + 1 === newDots.length && this.pattern.isComplete())
          return this.checkPattern();
        else
          this.startLine(dotX, dotY);
      });
    }
    /**
     * Reset the lock
     */
    reset() {
      clearTimeout(this.isPendingReset);
      this.isPendingReset = null;
      this.pattern.reset();
      this.currentLine = null;
      for (let i = 0; i < 9; i++)
        this.bigDotsEl.childNodes[i].classList.remove("active");
      for (let i = this.patternEl.childNodes.length - 1; i >= 0; i--)
        this.patternEl.childNodes[i].remove();
      this.patternEl.setAttribute("stroke", config_default.COLORS.BRIGHT);
    }
    /**
     * Procedure for new patterns
     * @return {Boolean} True if the pattern tested is correct
     */
    checkPattern() {
      let itsAmatch = this.onNewPattern(this.pattern);
      this.isPendingReset = setTimeout(this.reset.bind(this), 1e3);
      this.patternEl.setAttribute("stroke", itsAmatch ? config_default.COLORS.SUCCESS : config_default.COLORS.ERROR);
      return itsAmatch;
    }
    /*
     * Drawn pattern
     */
    /**
     * Start a new 'current line'.
     * The current line is the one in progress of being
     * manipulated.
     * @param  {int} x Position X of the line starting point
     * @param  {int} y Position Y of the line starting point
     */
    startLine(x, y) {
      this.currentLine = dom_default.create("line", {
        x1: x,
        y1: y
      });
      this.patternEl.appendChild(this.currentLine);
    }
    /**
     * Update the line of the current move
     * @param  {int} x Position X on the finger on the SVG scale
     * @param  {int} y Position Y on the finger on the SVG scale
     */
    updateLine(x, y) {
      if (!this.currentLine)
        return;
      this.currentLine.setAttribute("x2", x);
      this.currentLine.setAttribute("y2", y);
    }
    /**
     * Update and close the line of the current move
     * @param  {int} x Position X of the line ending point
     * @param  {int} y Position Y of the line ending point
     */
    closeLine(x, y) {
      this.updateLine(x, y);
      this.currentLine = null;
    }
  };
  var lock_ctrl_default = LockCtrl;

  // upstream/src/utils/leftPadNum.js
  var leftPadNum = (num, len) => {
    let str = Math.abs(num) + "", isNegative = num < 0;
    for (let i = len - str.length; i > 0; i--) {
      str = "0" + str;
    }
    return (isNegative ? "-" : "") + str;
  };
  var leftPadNum_default = leftPadNum;

  // upstream/src/controllers/countdown/countdown.ctrl.js
  require_countdown();
  var CountdownCtrl = class {
    /**
     * Not much here... just buildin the template
     */
    constructor() {
      this.setupTemplate();
    }
    /**
     * Build template of the controller
     * @return {DOMElement}
     */
    setupTemplate() {
      this.counterEl = dom_default.create("span", "countdown-counter");
      this.barEl = dom_default.create("span", "countdown-content");
      let container = dom_default.create("span", "countdown-container", [
        this.barEl
      ]);
      this.el = dom_default.create("div", "countdown", [
        this.counterEl,
        container
      ]);
      return this.el;
    }
    /**
     * Set the countdown.
     * @param {int}      duration Duration in seconds
     * @param {function} callback Callback to call on end
     */
    setTimer(duration, callback) {
      this.duration = duration;
      this.remaining = duration;
      this.endCallback = callback;
      this.render();
    }
    /**
     * Starts the countdown
     *
     */
    start() {
      if (this.interval)
        return;
      this.interval = window.setInterval(this.decrement.bind(this), 1e3);
    }
    /**
     * Stops the countdown
     *
     */
    stop() {
      window.clearInterval(this.interval);
      this.interval = null;
    }
    /**
     * Decrement the counter by one second
     *
     */
    decrement() {
      this.remaining--;
      this.render();
    }
    /**
     * Render the component by using the values of
     * the instance. If the countdown is negative or
     * null, the end callback will be triggered
     */
    render() {
      this.remaining = this.remaining > 0 ? this.remaining : 0;
      this.el.classList[this.remaining > 10 ? "remove" : "add"]("alert");
      this.counterEl.textContent = leftPadNum_default(this.remaining, 3);
      this.barEl.style.width = this.remaining / this.duration * 100 + "%";
      if (this.remaining == 0) {
        this.stop();
        this.endCallback && this.endCallback();
      }
    }
  };
  var countdown_ctrl_default = CountdownCtrl;

  // upstream/src/controllers/statusBar/statusBar.ctrl.js
  require_statusBar();
  var StatusBarCtrl = class {
    /**
     * Constructor
     * The only callback provided is for cancelling
     * event from the user.
     * @param  {function} onCancel Cancel callback
     */
    constructor(onCancel) {
      this.cancelCallback = onCancel;
      this.counterVal = null;
      this.setupTemplate();
    }
    /**
     * Build template of the controller
     * @return {DOMElement}
     */
    setupTemplate() {
      this.cancelBtnEl = dom_default.create("button", "status-bar-cancel", "ABORT");
      this.counterEl = dom_default.create("span", "status-bar-info");
      this.countdown = new countdown_ctrl_default();
      this.countdownEl = this.countdown.el;
      this.countdownEl.setAttribute("class", "status-bar-info");
      this.el = dom_default.create("div", "status-bar ui-row", [
        this.cancelBtnEl,
        this.counterEl,
        this.countdownEl
      ]);
      return this.el;
    }
    /**
     * Set up listeners
     */
    init() {
      this.cancelBtnEl.addEventListener("click", () => {
        this.cancelCallback(0);
      });
    }
    /* Counter mode ******************************/
    /**
     * Set the counter mode to the status bar.
     * The count is the value displayed on the
     * counter.
     * @param {int} attemptCount Counter value to display
     */
    setCounter(count) {
      this.counterEl.style.display = "inherit";
      this.countdownEl.style.display = "none";
      this.counterVal = count;
      this.updateCounter();
    }
    /**
     * Update the counter DOM to the value
     * set in the instance
     * @return {int} The new value of the counter
     */
    updateCounter() {
      this.counterEl.textContent = leftPadNum_default(this.counterVal, 3);
      return this.counterVal;
    }
    /**
     * Decrement the counter
     * @return {int} The new value of the counter
     */
    decrementCounter() {
      this.counterVal--;
      return this.updateCounter();
    }
    /**
     * Increment the counter
     * @return {int} The new value of the counter
     */
    incrementCounter() {
      this.counterVal++;
      return this.updateCounter();
    }
    /* Countdown mode ****************************/
    /**
     * Set the countdown mode to the status bar
     * @param {int} duration Duration in seconds
     */
    setCountdown(duration) {
      this.counterEl.style.display = "none";
      this.countdownEl.style.display = "inherit";
      this.countdown.setTimer(duration, () => {
        this.cancelCallback(1);
      });
      this.countdown.start();
    }
    /**
     * Stops the countdown
     *
     */
    stopCountdown() {
      this.countdown.stop();
    }
  };
  var statusBar_ctrl_default = StatusBarCtrl;

  // upstream/src/controllers/history/history.ctrl.js
  require_history();
  var HistoryCtrl = class {
    /**
     * Set up instance
     */
    constructor() {
      this.lastPattern = null;
      this.setupTemplate();
    }
    /**
     * Build template of the controller
     * @return {SVGDOMElement}
     */
    setupTemplate() {
      this.containerEl = dom_default.create("div", "history-container", "");
      this.el = dom_default.create("div", "history scrollbarlesque", [this.containerEl]);
      return this.el;
    }
    /**
     * Add a new pattern in the container
     * @param {SVGDOM} pattern The try to stack
     */
    stackPattern(pattern) {
      if (this.lastPattern)
        this.containerEl.insertBefore(pattern, this.lastPattern);
      else this.containerEl.appendChild(pattern);
      this.lastPattern = pattern;
      this.scrollToStart();
    }
    /**
     * Loop animation to scroll smoothly
     * the history to the start.
     */
    scrollToStart() {
      let pos = this.el.scrollLeft;
      this.el.scrollLeft = (pos - Math.max(pos / 4, 4), 0);
      if (this.el.scrollLeft > 0) {
        window.requestAnimationFrame(this.scrollToStart.bind(this));
      }
    }
    /**
     * Clean the history
     *
     * @param  {String} helperText Helper text displayed when the history is empty
     * @return {[type]}
     */
    clear(helperText) {
      this.lastPattern = null;
      this.containerEl.remove();
      this.containerEl = dom_default.create("div", {
        class: "history-container",
        "data-helper": helperText
      });
      this.el.appendChild(this.containerEl);
    }
  };
  var history_ctrl_default = HistoryCtrl;

  // upstream/src/utils/pluralize.js
  var getLang = () => {
    return document.documentElement.lang;
  };
  var customRussianRule = (choice, choicesLength) => {
    if (choice === 0) {
      return 0;
    }
    const teen = choice > 10 && choice < 20;
    const endsWithOne = choice % 10 === 1;
    if (!teen && endsWithOne) {
      return 1;
    }
    if (!teen && choice % 10 >= 2 && choice % 10 <= 4) {
      return 2;
    }
    return choicesLength < 4 ? 2 : 3;
  };
  var pluralRules = {
    "ru": customRussianRule
  };
  var pluralize = (word, count) => {
    const variants = word.split(" | ");
    const code = getLang();
    if (variants.length === 1 || !pluralRules[code]) return word;
    const index = pluralRules[code](count, variants.length);
    return variants[index] || word;
  };

  // upstream/src/controllers/summary/summaryFeedback.js
  var SUCCESS_QUOTES_LIST = [
    { min: 1, max: 3, text: "That was pure luck, nothing else. Stop dreamin." },
    { min: 2, max: 4, text: "You got lucky, without staying up all night." },
    { min: 1, max: 2, text: "No merit. Absolutely none." },
    { min: 2, max: 5, text: "That was given on a golden plate." },
    { min: 1, max: 4, text: "Absolutely no synapse got used during that game." },
    { min: 2, max: 5, text: "Don\'t even dare to tweet your score." },
    { min: 8, max: 10, text: "Saperlipopette!! That was close." },
    { min: 4, max: 8, text: "Seems legit, with a bit of luck." },
    { min: 7, max: 10, text: "Pretty good!" },
    { min: 9, max: 10, text: "But you made it!" },
    { min: 11, max: 50, text: "Trying random patterns is not a strategy..." },
    { min: 11, max: 50, text: "That was looooooooong." },
    { min: 11, max: 50, text: "At least you made it." },
    { min: 11, max: 50, text: "You must hate this game by now." },
    { min: 11, max: 50, text: "I hope you didn\'t cheat." },
    { min: 41, max: 403, text: "Your dedication is impressive." },
    { min: 404, max: 404, text: "Logic not found." },
    { min: 405, max: 999, text: "No comment." }
  ];
  var FAIL_QUOTES_LIST = [
    "I believe there\'s some work to do.",
    "Do you understand the game? Don\'t take it personally, I struggle to explain it.",
    "One day you will make it...",
    "It\'s not funny for you, but it is for me ;)",
    "Don\'t stress, you will make it.",
    "If you want to avoid battles, stay out of the grassy areas!",
    "Even if you lose in battle, if you surpass what you\'ve done before, you have bested yourself.",
    "TILT! Insert coin and try again."
  ];
  function getQuote(wasSuccess, attemptsCount) {
    let feedback, matches;
    if (wasSuccess) {
      feedback = `Lock found in ${attemptsCount} ${pluralize("attempts.", attemptsCount)}`;
      matches = SUCCESS_QUOTES_LIST.filter((quote) => quote.min <= attemptsCount && quote.max >= attemptsCount).map((quote) => quote.text);
    } else {
      feedback = "Sorry, you didn\'t make it this time.";
      matches = FAIL_QUOTES_LIST;
    }
    return feedback + " " + matches[Math.floor(matches.length * Math.random())];
  }
  var summaryFeedback_default = getQuote;

  // upstream/src/utils/share.js
  function share(title, url, file) {
    const basicShare = {
      url,
      title
    };
    const fullShare = {
      ...basicShare,
      files: [file]
    };
    if (!navigator.canShare) {
      window.alert(`The sharing feature isn't available in your browser`);
    } else if (navigator.canShare(fullShare)) {
      navigator.share(fullShare);
    } else if (navigator.canShare(basicShare)) {
      navigator.share(basicShare);
    } else {
      window.alert(`The sharing feature isn't available in your browser`);
    }
  }

  // upstream/src/controllers/summary/summary.ctrl.js
  require_summary();
  var SummaryCtrl = class {
    actionLabels = {
      NEW_GAME: "NEW_GAME",
      SOLUTION: "SOLUTION",
      BACK_HOME: "BACK_HOME"
    };
    /**
     * Set up the template and init event.
     * The constructor take one parameter, the callback
     * for the following step.
     * @param  {function} onAction Action callback
     */
    constructor(onAction) {
      this.onAction = onAction;
      this.setupTemplate();
      this.init();
    }
    /**
     * Build template of the controller
     * @return {DOMElement}
     */
    setupTemplate() {
      this.actionButtons = [];
      for (let action in config_default.GAME.ACTIONS) {
        let btn = dom_default.create("button", {
          class: "summary-action-button",
          rel: config_default.GAME.ACTIONS[action]
        }, [
          dom_default.icon(action.toLowerCase()),
          dom_default.create("span", {}, this.actionLabels[action])
        ]);
        this.actionButtons.push(btn);
      }
      this.shareBtn = dom_default.create("button", {
        class: "summary-action-button"
      }, [
        dom_default.create("p", {}, "Share")
      ]);
      let feedbackEl = dom_default.create("div", "summary-feedback bloc", [
        dom_default.create("p", {}, [
          dom_default.create("span", {}, "Tweet me your feedback at "),
          dom_default.create("a", { href: config_default.SOCIAL.PLATFORMS.TWITTER.URL("", "@mxwllt", ["breaklock"]) }, "@mxwllt")
        ])
      ]);
      this.titleEl = dom_default.create("h1", "summary-title highlight");
      this.detailsEl = dom_default.create("p", "summary-details");
      this.revealEl = dom_default.create("p", "summary-reveal", "Please select an option.");
      this.actionsEl = dom_default.create("div", "summary-actions bloc", this.actionButtons);
      this.socialEl = dom_default.create("div", "summary-share bloc", [this.shareBtn]);
      this.el = dom_default.create("div", "summary view", [
        dom_default.create("div", "view-bloc", [this.titleEl, this.detailsEl, this.revealEl]),
        dom_default.create("div", "view-bloc", [this.actionsEl, this.socialEl, feedbackEl])
      ]);
      return this.el;
    }
    /**
     * Set up listeners
     */
    init() {
      this.actionButtons.forEach((btn) => btn.addEventListener("click", this.triggerAction.bind(this)));
      this.shareBtn.addEventListener("click", () => share(config_default.SOCIAL.MESSAGE, config_default.URL));
    }
    /**
     * Set new content.
     * This is independent from the constructor,
     * because an instance must be reused.
     * @param {Boolean}       isSuccess      Was the game a success?
     * @param {Number}        attemptsCount  Message to display
     */
    setContent(isSuccess, attemptsCount) {
      this.titleEl.classList.remove("fail");
      this.titleEl.classList.remove("success");
      this.titleEl.classList.add(isSuccess ? "success" : "fail");
      airportText_default(this.titleEl, isSuccess ? "Success!" : "Fail!");
      this.detailsEl.textContent = summaryFeedback_default(isSuccess, attemptsCount);
      this.revealEl.classList[isSuccess ? "add" : "remove"]("hide");
      this.toggle(true);
    }
    /**
     * Show/hide the controller
     * @param  {Boolean} force Force to show or hide if provided
     */
    toggle(force) {
      force = force != void 0 ? force : !this.el.classList.contains("active");
      this.el.classList[force ? "add" : "remove"]("active");
    }
    /**
     * Click listener for action buttons
     * @param  {Event} event Click event from action button
     */
    triggerAction(event) {
      let actionId = parseInt(event.currentTarget.getAttribute("rel") || 0, 10);
      this.onAction(actionId);
    }
  };
  var summary_ctrl_default = SummaryCtrl;

  // upstream/src/utils/color.js
  var color = {
    /**
     * Create gradients of grey
     * Specify the color to start and end with plus
     * the amount of intermediate steps.
     * The method will return an array of string
     * format hex string.
     *
     * Example:
     * greydient('99', 'FF', 2)
     * > ['#999999', '#bbbbbb', '#dddddd', '#ffffff']
     * @param  {Number|String} colorStart Start gradient color (: '99' or 153)
     * @param  {Number|String} colorEnd   End gradient color (: 'FF' or 255)
     * @param  {Number}        steps      Amount of intermediate colors
     * @return {Array}                    Color list
     */
    greydient: (colorStart, colorEnd, steps = 0) => {
      colorStart = typeof colorStart === "string" ? parseInt(colorStart, 16) : colorStart;
      colorEnd = typeof colorEnd === "string" ? parseInt(colorEnd, 16) : colorEnd;
      colorStart = Math.min(255, Math.max(0, colorStart));
      colorEnd = Math.min(255, Math.max(0, colorEnd));
      steps++;
      let output = [], gap = (colorEnd - colorStart) / steps;
      for (let i = 0; i <= steps; i++) {
        let grey = Math.round(colorStart + i * gap), greyHex = grey.toString(16);
        output.push("#" + greyHex + greyHex + greyHex);
      }
      return output;
    }
  };
  var color_default = color;

  // adapter.js
  var modes = { 1: "practice", 2: "challenge", 3: "countdown" };
  var SharedLock = class extends lock_ctrl_default {
    constructor(adapter) {
      super(() => {
      });
      this.adapter = adapter;
      this.init();
    }
    reset() {
      this.adapter.change(() => this.adapter.round.clearDraft());
    }
    triggerDot(position) {
      const event = this.adapter.change(() => this.adapter.round.select(position));
      if (event?.added?.length && navigator.vibrate) navigator.vibrate(20);
    }
    // Use viewport coordinates for the canonical SVG magnet geometry on scrolled pages.
    mouseUpdate(event) {
      event.preventDefault();
      const box = this.el.getBoundingClientRect();
      this.updatePoint(
        (event.clientX - box.left) * 100 / box.width,
        (event.clientY - box.top) * 100 / box.height
      );
    }
    touchUpdate(event) {
      event.preventDefault();
      const point = event.targetTouches[0];
      if (!point) return;
      const box = this.el.getBoundingClientRect();
      this.updatePoint(
        (point.clientX - box.left) * 100 / box.width,
        (point.clientY - box.top) * 100 / box.height
      );
    }
    mouseEnd(event) {
      super.mouseEnd(event);
      window.removeEventListener("mouseleave", this.mouseEndBind);
    }
    paint(state, matched) {
      this.currentLine = null;
      this.patternEl.replaceChildren();
      const svg = new patternSVG_default();
      const stroke = state.pendingGuessResetDelayMs === null ? config_default.COLORS.BRIGHT : matched ? config_default.COLORS.SUCCESS : config_default.COLORS.ERROR;
      if (state.draft.length) {
        this.patternEl.appendChild(svg.addPattern({ suite: state.draft }, 2, stroke));
      }
      this.bigDotsEl.childNodes.forEach((dot, i) => dot.classList.toggle("active", state.draft.includes(i)));
      this.isPendingReset = state.pendingGuessResetDelayMs !== null;
      if (state.draft.length && !this.isPendingReset) {
        const last = state.draft.at(-1);
        this.startLine(last % 3 * 35 + 15, Math.floor(last / 3) * 35 + 15);
      }
    }
  };
  var BreakLockWeb = class {
    constructor(container = document.body) {
      this.round = null;
      this.suspended = false;
      this.scheduler = new RoundScheduler(this);
      this.clock = null;
      this.resetTimer = null;
      this.clockDue = null;
      this.resetDue = null;
      this.menu = new menu_ctrl_default((type, length) => this.start(type, length));
      this.menu.init();
      this.menu.el.querySelector(".lang-button")?.remove();
      this.menu.el.querySelector(".lang-selector")?.remove();
      this.status = new statusBar_ctrl_default(() => this.home(false));
      this.status.init();
      this.history = new history_ctrl_default();
      this.summary = new summary_ctrl_default((action) => this.action(action));
      this.lock = new SharedLock(this);
      this.game = dom_default.create("div", "game-layout view", [
        dom_default.create("div", "view-bloc game-layout-dashboard", [
          this.status.el,
          dom_default.create("div", "history-wrap", [this.history.el])
        ]),
        dom_default.create("div", "view-bloc game-layout-lock", [this.lock.el]),
        this.summary.el
      ]);
      container.append(this.menu.el, this.game);
      this.game.style.display = "none";
    }
    start(type, length) {
      if (this.suspended) return;
      if (!this.round) this.round = new BreakLockRound({ mode: modes[type], dotLength: length });
      else this.round.start(modes[type], length);
      this.historyKey = null;
      this.renderedHistory = null;
      this.menu.el.style.display = "none";
      this.game.style.display = "";
      this.render();
      this.schedule();
    }
    change(action) {
      if (this.suspended) return;
      const result = action();
      if (result?.attempt) this.lastMatched = result.attempt.matched;
      this.render();
      this.schedule();
      return result;
    }
    home(fromSummary) {
      this.change(() => this.round.home({ fromSummary }));
      this.menu.el.style.display = "";
      this.game.style.display = "none";
    }
    action(action) {
      if (action === config_default.GAME.ACTIONS.BACK_HOME) return this.home(true);
      this.change(() => action === config_default.GAME.ACTIONS.NEW_GAME ? this.round.newGame() : this.round.reveal());
    }
    refresh() {
      if (!this.suspended) {
        this.render();
        this.schedule();
      }
    }
    schedule() {
      if (!this.suspended) this.scheduler.sync();
    }
    snapshot() {
      return this.scheduler.snapshot();
    }
    suspend() {
      const state = this.snapshot();
      this.suspended = true;
      this.scheduler.stop();
      this.menu.el.inert = this.game.inert = true;
      if (state) this.round = BreakLockRound.restore(state);
      return state;
    }
    restore(state) {
      this.scheduler.stop();
      this.round = state ? BreakLockRound.restore(state) : null;
      this.suspended = false;
      this.menu.el.inert = this.game.inert = false;
      this.historyKey = this.renderedHistory = this.summaryKey = null;
      this.menu.el.style.display = state ? "none" : "";
      this.game.style.display = state ? "" : "none";
      if (state) {
        const last = this.round.history().filter((entry) => entry.type === "guess").at(-1);
        this.lastMatched = last?.feedback[0] === state.dotLength;
        this.refresh();
      }
    }
    render() {
      const state = this.round.snapshot();
      this.status.counterEl.textContent = leftPadNum_default(state.counterValue);
      this.status.counterEl.style.display = state.statusDisplay === "counter" ? "inherit" : "none";
      this.status.countdownEl.style.display = state.statusDisplay === "countdown" ? "inherit" : "none";
      const countdown = this.status.countdown;
      countdown.counterEl.textContent = leftPadNum_default(state.timer.remainingTicks);
      countdown.barEl.style.width = `${state.timer.remainingTicks / 60 * 100}%`;
      countdown.el.classList.toggle("alert", state.timer.remainingTicks <= 10);
      const historyKey = JSON.stringify(state.history);
      if (historyKey !== this.historyKey) {
        if (!this.renderedHistory || JSON.stringify(state.history.slice(0, this.renderedHistory.length)) !== JSON.stringify(this.renderedHistory)) {
          this.history.clear(`Connect ${state.dotLength} dots`);
          this.renderedHistory = [];
        }
        for (const entry of this.round.history().slice(this.renderedHistory.length)) {
          const svg = new patternSVG_default();
          svg.addDots(1);
          svg.addPattern({ suite: entry.sequence }, 14, color_default.greydient(
            config_default.PATTERN.HEX_COLOR_START,
            config_default.PATTERN.HEX_COLOR_END,
            state.dotLength - 3
          ));
          svg.addCombinaison(...entry.feedback);
          svg.el.classList.toggle("success", entry.feedback[0] === state.dotLength);
          svg.el.dataset.entry = entry.type;
          this.history.stackPattern(svg.el);
        }
        this.historyKey = historyKey;
        this.renderedHistory = state.history;
      }
      const summaryKey = JSON.stringify([state.summary.success, state.summary.attemptCount]);
      if (state.summary.success !== null && summaryKey !== this.summaryKey) {
        this.summary.setContent(state.summary.success, state.summary.attemptCount);
        this.summaryKey = summaryKey;
      }
      this.summary.toggle(state.summary.visible);
      this.lock.paint(state, this.lastMatched);
    }
    dispose() {
      this.scheduler.stop();
      clearInterval(this.clock);
      clearTimeout(this.resetTimer);
      this.clock = this.resetTimer = null;
      this.clockDue = this.resetDue = null;
      this.lock.el.removeEventListener("mousemove", this.lock.mouseUpdateBind);
      window.removeEventListener("mouseup", this.lock.mouseEndBind);
      window.removeEventListener("mouseleave", this.lock.mouseEndBind);
      this.menu.el.remove();
      this.game.remove();
    }
  };

  // app.js
  document.getElementById("app-intro")?.remove();
  var surface = new BreakLockWeb();
  var persistence;
  var ready = window.breaklockStorage ? connectWeb(surface, window.breaklockStorage).then((value) => {
    persistence = value;
    const exit = document.createElement("button");
    exit.textContent = "Save & Return";
    exit.style.cssText = "position:fixed;bottom:8px;right:8px;z-index:9999";
    exit.onclick = () => {
      exit.disabled = true;
      value.exit().then(() => exit.remove()).catch((error) => {
        exit.textContent = "Save failed: " + error.message;
      });
    };
    document.body.appendChild(exit);
  }) : Promise.resolve();
  ready.catch(() => {
  });
  window.breaklock = Object.freeze({ snapshot: () => surface.snapshot(), ready, checkpoint: () => persistence?.checkpoint(), exit: () => persistence?.exit() });
  window.addEventListener("pagehide", (event) => {
    if (persistence) {
      persistence.session.fail(new Error("Page closed; resume last checkpoint"));
    }
    if (!event.persisted) surface.dispose();
  });
})();
