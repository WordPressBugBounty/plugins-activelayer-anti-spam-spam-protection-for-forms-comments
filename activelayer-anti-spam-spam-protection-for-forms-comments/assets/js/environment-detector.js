(() => {
  var __defProp = Object.defineProperty;
  var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
  var __publicField = (obj, key, value) => __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value);
  var __async = (__this, __arguments, generator) => {
    return new Promise((resolve, reject) => {
      var fulfilled = (value) => {
        try {
          step(generator.next(value));
        } catch (e) {
          reject(e);
        }
      };
      var rejected = (value) => {
        try {
          step(generator.throw(value));
        } catch (e) {
          reject(e);
        }
      };
      var step = (x) => x.done ? resolve(x.value) : Promise.resolve(x.value).then(fulfilled, rejected);
      step((generator = generator.apply(__this, __arguments)).next());
    });
  };

  // assets/js/src/environment/detectors/WebdriverDetector.js
  var WebdriverDetector = class {
    /**
     * Detect navigator.webdriver property.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if webdriver is detected.
     */
    detect() {
      return navigator.webdriver === true;
    }
  };

  // assets/js/src/environment/detectors/AutomationFrameworkDetector.js
  var AutomationFrameworkDetector = class {
    /**
     * Detect automation framework globals.
     *
     * @since 1.1.0
     *
     * @return {string|null} Framework name or null if not detected.
     */
    detect() {
      try {
        const cdcKeys = Object.keys(window).filter((k) => k.startsWith("cdc_"));
        if (cdcKeys.length > 0) {
          return "chromedriver";
        }
      } catch (e) {
      }
      if (window._selenium || window.callSelenium || window.calledSelenium || window.__webdriver_evaluate || window.__webdriver_unwrapped || window.__webdriver_script_fn || window.__webdriver_script_function || window.__webdriver_script_func || window.__driver_evaluate || window.__driver_unwrapped || window.__fxdriver_evaluate || window.__fxdriver_unwrapped || window._Selenium_IDE_Recorder || window.$chrome_asyncScriptInfo || document.$cdc_asdjflasutopfhvcZLmcfl_ || document.$chrome_asyncScriptInfo) {
        return "selenium";
      }
      if (window.__playwright || window.__playwright_pages || window.__pw_manual) {
        return "playwright";
      }
      if (window.__puppeteer_evaluation_script__) {
        return "puppeteer";
      }
      if (window._phantom || window.callPhantom || window.__phantomas) {
        return "phantom";
      }
      if (window.__nightmare) {
        return "nightmare";
      }
      if (window.Cypress) {
        return "cypress";
      }
      return null;
    }
  };

  // assets/js/src/environment/detectors/UserAgentDetector.js
  var _UserAgentDetector = class _UserAgentDetector {
    /**
     * Detect headless browser indicators in user agent.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if headless UA detected.
     */
    detect() {
      const ua = navigator.userAgent;
      return _UserAgentDetector.HEADLESS_PATTERNS.some((pattern) => pattern.test(ua));
    }
  };
  /**
   * Headless browser patterns.
   *
   * @type {RegExp[]}
   * @private
   */
  __publicField(_UserAgentDetector, "HEADLESS_PATTERNS", [
    /HeadlessChrome/i,
    /PhantomJS/i,
    /\bHeadless\b/i,
    /SlimerJS/i,
    /headless/i,
    /selenium/i,
    /webdriver/i,
    /puppeteer/i,
    /playwright/i,
    /cypress/i,
    /nodriver/i,
    // 2025: new anti-detect framework
    /undetected/i,
    // 2025: undetected-chromedriver
    /bot/i,
    /crawl/i,
    /spider/i
  ]);
  var UserAgentDetector = _UserAgentDetector;

  // assets/js/src/environment/detectors/BrowserFeaturesDetector.js
  var BrowserFeaturesDetector = class {
    /**
     * Detect missing browser plugins.
     *
     * Real browsers typically have at least some plugins (PDF viewer, etc.).
     * Headless browsers often report zero plugins.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if no plugins detected.
     */
    detectNoPlugins() {
      if (!navigator.plugins) {
        return true;
      }
      return navigator.plugins.length === 0;
    }
    /**
     * Detect missing navigator.languages.
     *
     * Real browsers typically report at least one language preference.
     * Some headless configurations have empty or missing languages array.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if no languages detected.
     */
    detectNoLanguages() {
      if (!navigator.languages) {
        return true;
      }
      return navigator.languages.length === 0;
    }
    /**
     * Detect missing chrome object in Chrome browser.
     *
     * When user agent indicates Chrome but window.chrome is missing,
     * this suggests a headless or modified browser environment.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if Chrome object is missing in Chrome browser.
     */
    detectChromeRuntimeMissing() {
      const ua = navigator.userAgent || "";
      const isChrome = /Chrome/.test(ua) && !/Chromium|Edg/.test(ua);
      if (!isChrome) {
        return false;
      }
      if (!window.chrome) {
        return true;
      }
      const hasLoadTimes = typeof window.chrome.loadTimes === "function";
      const hasCsi = typeof window.chrome.csi === "function";
      return !hasLoadTimes && !hasCsi;
    }
    /**
     * Detect suspicious screen properties.
     *
     * Headless browsers may have unusual screen values like
     * colorDepth of 0 or very low devicePixelRatio.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if screen properties are suspicious.
     */
    detectSuspiciousScreen() {
      try {
        const colorDepth = screen.colorDepth;
        const pixelDepth = screen.pixelDepth;
        if (colorDepth === 0 || colorDepth < 8) {
          return true;
        }
        if (pixelDepth === 0 || pixelDepth < 8) {
          return true;
        }
        if (window.devicePixelRatio === 0) {
          return true;
        }
        return false;
      } catch (e) {
        return false;
      }
    }
    /**
     * Detect missing Battery API.
     *
     * Most real browsers support the Battery API.
     * Headless browsers often lack this API.
     *
     * Note: Battery API is deprecated and only available in Chromium-based
     * browsers. Firefox removed support, Safari never implemented it.
     * This signal will return true for all Firefox/Safari users.
     * Use as MEDIUM confidence signal with low weight.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if Battery API is missing.
     */
    detectNoBatteryAPI() {
      return typeof navigator.getBattery !== "function";
    }
    /**
     * Detect missing Connection API.
     *
     * Network Information API is often unavailable in headless environments.
     *
     * Note: Network Information API (navigator.connection) is only available
     * in Chromium-based browsers. Firefox and Safari do not support it.
     * This signal will return true for all Firefox/Safari users.
     * Use as MEDIUM confidence signal with low weight.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if Connection API is missing.
     */
    detectNoConnectionAPI() {
      return !navigator.connection;
    }
  };

  // assets/js/src/environment/detectors/WebGLDetector.js
  var _WebGLDetector = class _WebGLDetector {
    /**
     * Detect WebGL rendering anomalies.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if WebGL anomaly detected.
     */
    detect() {
      const vendor = this.getVendor();
      const renderer = this.getRenderer();
      if (!vendor || !renderer) {
        return false;
      }
      const rendererLower = renderer.toLowerCase();
      return _WebGLDetector.SUSPICIOUS_RENDERERS.some(
        (suspicious) => rendererLower.includes(suspicious)
      );
    }
    /**
     * Perform WebGL rendering test to verify GPU consistency.
     *
     * Renders a 3D scene and analyzes the output for noise patterns.
     * Software renderers and spoofed GPUs often produce inconsistent output.
     *
     * @since 1.1.0
     *
     * @return {Object} Rendering test result with hash and suspicious flag.
     */
    performRenderingTest() {
      let gl = null;
      try {
        const canvas = document.createElement("canvas");
        canvas.width = 150;
        canvas.height = 150;
        gl = canvas.getContext("webgl") || canvas.getContext("experimental-webgl");
        if (!gl) {
          return {
            webgl_rendering_hash: null,
            webgl_rendering_noise: null,
            webgl_rendering_suspicious: false
          };
        }
        const renderer = this.getRenderer() || "";
        const vertices = new Float32Array([
          -1,
          -1,
          1,
          1,
          -1,
          1,
          1,
          1,
          1,
          -1,
          1,
          1,
          // Front face.
          -1,
          -1,
          -1,
          -1,
          1,
          -1,
          1,
          1,
          -1,
          1,
          -1,
          -1
          // Back face.
        ]);
        const buffer = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
        gl.bufferData(gl.ARRAY_BUFFER, vertices, gl.STATIC_DRAW);
        const vertexShader = gl.createShader(gl.VERTEX_SHADER);
        gl.shaderSource(vertexShader, `
				attribute vec3 position;
				void main() {
					gl_Position = vec4(position * 0.5, 1.0);
				}
			`);
        gl.compileShader(vertexShader);
        const fragmentShader = gl.createShader(gl.FRAGMENT_SHADER);
        gl.shaderSource(fragmentShader, `
				precision mediump float;
				void main() {
					gl_FragColor = vec4(0.2, 0.5, 0.8, 1.0);
				}
			`);
        gl.compileShader(fragmentShader);
        const program = gl.createProgram();
        gl.attachShader(program, vertexShader);
        gl.attachShader(program, fragmentShader);
        gl.linkProgram(program);
        gl.useProgram(program);
        const position = gl.getAttribLocation(program, "position");
        gl.enableVertexAttribArray(position);
        gl.vertexAttribPointer(position, 3, gl.FLOAT, false, 0, 0);
        gl.clearColor(0, 0, 0, 1);
        gl.clear(gl.COLOR_BUFFER_BIT);
        gl.drawArrays(gl.TRIANGLES, 0, 6);
        const pixels = new Uint8Array(gl.drawingBufferWidth * gl.drawingBufferHeight * 4);
        gl.readPixels(0, 0, gl.drawingBufferWidth, gl.drawingBufferHeight, gl.RGBA, gl.UNSIGNED_BYTE, pixels);
        const hash = this._hashPixels(pixels.slice(0, 1e3));
        const noiseRatio = this._calculateNoiseRatio(pixels);
        const isHighEndGPU = _WebGLDetector.HIGH_END_GPU_PATTERN.test(renderer);
        const hasHighNoise = noiseRatio > _WebGLDetector.NOISE_THRESHOLD;
        const suspicious = isHighEndGPU && hasHighNoise;
        gl.deleteBuffer(buffer);
        gl.deleteShader(vertexShader);
        gl.deleteShader(fragmentShader);
        gl.deleteProgram(program);
        return {
          webgl_rendering_hash: hash,
          webgl_rendering_noise: parseFloat(noiseRatio.toFixed(4)),
          webgl_rendering_suspicious: suspicious
        };
      } catch (e) {
        return {
          webgl_rendering_hash: null,
          webgl_rendering_noise: null,
          webgl_rendering_suspicious: false
        };
      } finally {
        this._loseContext(gl);
      }
    }
    /**
     * Calculate noise ratio from pixel data.
     *
     * @since 1.1.0
     *
     * @param {Uint8Array} pixels The pixel data.
     * @return {number} Noise ratio between 0 and 1.
     * @private
     */
    _calculateNoiseRatio(pixels) {
      let noiseLevel = 0;
      for (let i = 0; i < pixels.length - 4; i += 4) {
        const diff = Math.abs(pixels[i] - pixels[i + 4]);
        if (diff > 5) {
          noiseLevel++;
        }
      }
      return noiseLevel / (pixels.length / 4);
    }
    /**
     * Generate hash from pixel data.
     *
     * @since 1.1.0
     *
     * @param {Uint8Array} pixels The pixel data to hash.
     * @return {string} Hash string.
     * @private
     */
    _hashPixels(pixels) {
      let hash = 0;
      for (let i = 0; i < pixels.length; i++) {
        hash = (hash << 5) - hash + pixels[i];
        hash = hash | 0;
      }
      return hash.toString(36);
    }
    /**
     * Release a WebGL context to free GPU resources.
     *
     * Browsers limit the number of active WebGL contexts (typically 8-16).
     * Failing to release contexts can exhaust this limit.
     *
     * @since 1.1.0
     *
     * @param {WebGLRenderingContext|null} gl The WebGL context to release.
     * @private
     */
    _loseContext(gl) {
      if (!gl) {
        return;
      }
      try {
        const ext = gl.getExtension("WEBGL_lose_context");
        if (ext) {
          ext.loseContext();
        }
      } catch (e) {
      }
      try {
        if (gl.canvas) {
          gl.canvas.width = 0;
          gl.canvas.height = 0;
        }
      } catch (e) {
      }
    }
    /**
     * Get WebGL vendor string.
     *
     * @since 1.1.0
     *
     * @return {string|null} WebGL vendor or null if unavailable.
     */
    getVendor() {
      let gl = null;
      try {
        const canvas = document.createElement("canvas");
        gl = canvas.getContext("webgl") || canvas.getContext("experimental-webgl");
        if (!gl) {
          return null;
        }
        const debugInfo = gl.getExtension("WEBGL_debug_renderer_info");
        if (!debugInfo) {
          return "unknown";
        }
        return gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL);
      } catch (e) {
        return null;
      } finally {
        this._loseContext(gl);
      }
    }
    /**
     * Get WebGL renderer string.
     *
     * @since 1.1.0
     *
     * @return {string|null} WebGL renderer or null if unavailable.
     */
    getRenderer() {
      let gl = null;
      try {
        const canvas = document.createElement("canvas");
        gl = canvas.getContext("webgl") || canvas.getContext("experimental-webgl");
        if (!gl) {
          return null;
        }
        const debugInfo = gl.getExtension("WEBGL_debug_renderer_info");
        if (!debugInfo) {
          return "unknown";
        }
        return gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL);
      } catch (e) {
        return null;
      } finally {
        this._loseContext(gl);
      }
    }
  };
  /**
   * Software renderers and VM indicators that suggest headless/automated environment.
   *
   * @since 1.1.0
   *
   * @type {string[]}
   */
  __publicField(_WebGLDetector, "SUSPICIOUS_RENDERERS", [
    "swiftshader",
    "llvmpipe",
    "softpipe",
    "mesa",
    "software rasterizer",
    "virtualbox",
    "vmware"
  ]);
  /**
   * High-end GPU patterns that should produce clean rendering output.
   *
   * @since 1.1.0
   *
   * @type {RegExp}
   */
  __publicField(_WebGLDetector, "HIGH_END_GPU_PATTERN", /nvidia|geforce|rtx|gtx|radeon|rx\s/i);
  /**
   * Noise ratio threshold for suspicious rendering.
   *
   * @since 1.1.0
   *
   * @type {number}
   */
  __publicField(_WebGLDetector, "NOISE_THRESHOLD", 0.1);
  var WebGLDetector = _WebGLDetector;

  // assets/js/src/environment/detectors/PermissionsDetector.js
  var PermissionsDetector = class {
    /**
     * Check for permissions API inconsistencies.
     *
     * @since 1.1.0
     *
     * @return {Promise<boolean>} True if permissions inconsistency detected.
     */
    detect() {
      return __async(this, null, function* () {
        if (!navigator.permissions) {
          return false;
        }
        try {
          const status = yield navigator.permissions.query({ name: "notifications" });
          if (!["granted", "denied", "prompt"].includes(status.state)) {
            return true;
          }
          return false;
        } catch (e) {
          return true;
        }
      });
    }
  };

  // assets/js/src/environment/detectors/CDPLeakDetector.js
  var CDPLeakDetector = class {
    /**
     * Detect all CDP leaks.
     *
     * @since 1.1.0
     *
     * @return {Object} Detection results with boolean signals.
     */
    detect() {
      return {
        cdp_stack_trace_leak: this.detectStackTraceLeak(),
        cdp_console_debug_leak: this.detectConsoleDebugLeak()
      };
    }
    /**
     * Detect CDP via stack trace property access.
     *
     * When CDP Runtime.enable is active, accessing the stack property
     * of an Error object through console.debug triggers the getter.
     * Normal browsers do not access this property.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if CDP stack trace leak detected.
     */
    detectStackTraceLeak() {
      try {
        let detected = false;
        const err = new Error();
        Object.defineProperty(err, "stack", {
          configurable: false,
          enumerable: false,
          get() {
            detected = true;
            return "";
          }
        });
        console.debug(err);
        return detected;
      } catch (e) {
        return false;
      }
    }
    /**
     * Detect CDP via console.debug property access count.
     *
     * When CDP Runtime.enable is active, object properties are accessed
     * multiple times during console.debug serialization. Normal browsers
     * typically access properties only once.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if CDP console debug leak detected.
     */
    detectConsoleDebugLeak() {
      try {
        let accessCount = 0;
        const testObj = {};
        Object.defineProperty(testObj, "id", {
          get() {
            accessCount++;
            return "test";
          }
        });
        console.debug(testObj);
        return accessCount > 1;
      } catch (e) {
        return false;
      }
    }
  };

  // assets/js/src/environment/detectors/WindowDimensionsDetector.js
  var WindowDimensionsDetector = class {
    /**
     * Detect all window dimension anomalies.
     *
     * @since 1.1.0
     *
     * @return {Object} Detection results with no_outer_dimensions and inner_equals_outer.
     */
    detect() {
      return {
        no_outer_dimensions: this.detectNoOuterDimensions(),
        inner_equals_outer: this.detectInnerEqualsOuter()
      };
    }
    /**
     * Detect if outer dimensions are missing.
     *
     * Headless browsers often report outerWidth or outerHeight as 0
     * since there is no actual browser window chrome.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if outerWidth OR outerHeight is 0.
     */
    detectNoOuterDimensions() {
      try {
        const outerWidth = window.outerWidth;
        const outerHeight = window.outerHeight;
        if (typeof outerWidth !== "number" || typeof outerHeight !== "number") {
          return false;
        }
        return outerWidth === 0 || outerHeight === 0;
      } catch (e) {
        return false;
      }
    }
    /**
     * Detect if inner dimensions equal outer dimensions.
     *
     * In a real browser, outer dimensions include browser chrome (toolbars, borders)
     * so they should be larger than inner dimensions. When they are exactly equal,
     * it may indicate a headless environment or automation.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if inner dimensions exactly equal outer dimensions.
     */
    detectInnerEqualsOuter() {
      try {
        const outerWidth = window.outerWidth;
        const outerHeight = window.outerHeight;
        const innerWidth = window.innerWidth;
        const innerHeight = window.innerHeight;
        if (typeof outerWidth !== "number" || typeof outerHeight !== "number" || typeof innerWidth !== "number" || typeof innerHeight !== "number") {
          return false;
        }
        if (outerWidth === 0 || outerHeight === 0) {
          return false;
        }
        return innerWidth === outerWidth && innerHeight === outerHeight;
      } catch (e) {
        return false;
      }
    }
  };

  // assets/js/src/environment/detectors/ClientHintsDetector.js
  var ClientHintsDetector = class {
    /**
     * Detect headless browser via Client Hints API.
     *
     * Checks navigator.userAgentData.brands for any brand containing
     * "Headless" (case-insensitive), which indicates HeadlessChrome
     * or similar headless browser.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if headless brand detected in Client Hints.
     */
    detect() {
      try {
        if (!navigator.userAgentData || !navigator.userAgentData.brands) {
          return false;
        }
        const brands = navigator.userAgentData.brands;
        return brands.some((brandInfo) => {
          if (!brandInfo || !brandInfo.brand) {
            return false;
          }
          return brandInfo.brand.toLowerCase().includes("headless");
        });
      } catch (e) {
        return false;
      }
    }
  };

  // assets/js/src/environment/detectors/CanvasFingerprintDetector.js
  var _CanvasFingerprintDetector = class _CanvasFingerprintDetector {
    /**
     * Detect canvas fingerprint anomalies.
     *
     * @since 1.1.0
     *
     * @return {Object} Detection result with hash and suspicious flag.
     */
    detect() {
      try {
        const canvas = document.createElement("canvas");
        canvas.width = 200;
        canvas.height = 50;
        const ctx = canvas.getContext("2d");
        if (!ctx) {
          return {
            canvas_hash: null,
            canvas_suspicious: true
          };
        }
        ctx.textBaseline = "top";
        ctx.font = "14px Arial";
        ctx.fillStyle = "#f60";
        ctx.fillRect(125, 1, 62, 20);
        ctx.fillStyle = "#069";
        ctx.fillText("HeadlessTest", 2, 15);
        ctx.fillStyle = "rgba(102, 204, 0, 0.7)";
        ctx.fillText("HeadlessTest", 4, 17);
        const dataUrl = canvas.toDataURL();
        const hash = this.generateHash(dataUrl);
        const suspicious = dataUrl.length < _CanvasFingerprintDetector.MIN_DATA_LENGTH;
        return {
          canvas_hash: hash,
          canvas_suspicious: suspicious
        };
      } catch (e) {
        return {
          canvas_hash: null,
          canvas_suspicious: true
        };
      }
    }
    /**
     * Generate a hash from canvas data URL.
     *
     * Uses a simple string hashing algorithm to create a fingerprint.
     *
     * @since 1.1.0
     *
     * @param {string} dataUrl The canvas data URL to hash.
     * @return {string} Hash in hexadecimal format.
     */
    generateHash(dataUrl) {
      let hash = 0;
      for (let i = 0; i < dataUrl.length; i++) {
        hash = (hash << 5) - hash + dataUrl.charCodeAt(i);
        hash = hash | 0;
      }
      return hash.toString(16);
    }
  };
  /**
   * Minimum expected data URL length for legitimate canvas output.
   *
   * @since 1.1.0
   *
   * @type {number}
   */
  __publicField(_CanvasFingerprintDetector, "MIN_DATA_LENGTH", 100);
  var CanvasFingerprintDetector = _CanvasFingerprintDetector;

  // assets/js/src/environment/detectors/AudioContextDetector.js
  var _AudioContextDetector = class _AudioContextDetector {
    /**
     * Detect suspicious AudioContext behavior.
     *
     * @since 1.1.0
     *
     * @return {Object} Detection result with sample rate and suspicious flag.
     */
    detect() {
      const sampleRate = this.getSampleRate();
      const suspicious = this.isSuspicious(sampleRate);
      return {
        audio_sample_rate: sampleRate,
        audio_suspicious: suspicious
      };
    }
    /**
     * Get the AudioContext sample rate.
     *
     * @since 1.1.0
     *
     * @return {number|null} Sample rate or null if unavailable.
     */
    getSampleRate() {
      let audioCtx = null;
      try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) {
          return null;
        }
        audioCtx = new AudioContextClass();
        const sampleRate = audioCtx.sampleRate;
        return sampleRate;
      } catch (e) {
        return null;
      } finally {
        if (audioCtx) {
          try {
            audioCtx.close();
          } catch (e) {
          }
        }
      }
    }
    /**
     * Determine if the sample rate is suspicious.
     *
     * @since 1.1.0
     *
     * @param {number|null} sampleRate The audio sample rate to check.
     * @return {boolean} True if suspicious, false otherwise.
     */
    isSuspicious(sampleRate) {
      if (sampleRate === null) {
        return true;
      }
      return !_AudioContextDetector.STANDARD_SAMPLE_RATES.includes(sampleRate);
    }
  };
  /**
   * Standard audio sample rates used by real browsers.
   *
   * Includes common rates and 96000 Hz for professional audio equipment.
   *
   * @since 1.1.0
   *
   * @type {number[]}
   */
  __publicField(_AudioContextDetector, "STANDARD_SAMPLE_RATES", [44100, 48e3, 96e3]);
  var AudioContextDetector = _AudioContextDetector;

  // assets/js/src/environment/detectors/FontDetector.js
  var _FontDetector = class _FontDetector {
    /**
     * Detect available fonts and flag suspicious low counts.
     *
     * @since 1.1.0
     *
     * @return {Object} Detection result with fonts_detected_count and fonts_suspicious.
     */
    detect() {
      try {
        const canvas = document.createElement("canvas");
        const context = canvas.getContext("2d");
        if (!context) {
          return {
            fonts_detected_count: 0,
            fonts_suspicious: true
          };
        }
        const baseFontWidths = this.measureBaseFonts(context);
        const detectedCount = this.countDetectedFonts(context, baseFontWidths);
        return {
          fonts_detected_count: detectedCount,
          fonts_suspicious: detectedCount < _FontDetector.MIN_FONTS_THRESHOLD
        };
      } catch (e) {
        return {
          fonts_detected_count: 0,
          fonts_suspicious: true
        };
      }
    }
    /**
     * Measure the width of the test string using base fonts.
     *
     * @since 1.1.0
     *
     * @param {CanvasRenderingContext2D} context Canvas 2D context.
     * @return {Object} Object mapping base font names to their measured widths.
     */
    measureBaseFonts(context) {
      const widths = {};
      _FontDetector.BASE_FONTS.forEach((font) => {
        context.font = `${_FontDetector.TEST_SIZE} ${font}`;
        widths[font] = context.measureText(_FontDetector.TEST_STRING).width;
      });
      return widths;
    }
    /**
     * Count the number of test fonts that are detected as available.
     *
     * A font is considered available if its measured width differs
     * from all base font widths (indicating it was actually used).
     *
     * @since 1.1.0
     *
     * @param {CanvasRenderingContext2D} context        Canvas 2D context.
     * @param {Object}                   baseFontWidths Base font width measurements.
     * @return {number} Number of detected fonts.
     */
    countDetectedFonts(context, baseFontWidths) {
      let detectedCount = 0;
      _FontDetector.TEST_FONTS.forEach((font) => {
        const isDetected = _FontDetector.BASE_FONTS.some((baseFont) => {
          context.font = `${_FontDetector.TEST_SIZE} '${font}', ${baseFont}`;
          const measuredWidth = context.measureText(_FontDetector.TEST_STRING).width;
          return measuredWidth !== baseFontWidths[baseFont];
        });
        if (isDetected) {
          detectedCount++;
        }
      });
      return detectedCount;
    }
  };
  /**
   * Base fonts used as fallbacks for comparison.
   *
   * @since 1.1.0
   *
   * @type {string[]}
   */
  __publicField(_FontDetector, "BASE_FONTS", ["monospace", "sans-serif", "serif"]);
  /**
   * Common fonts to test for availability.
   *
   * @since 1.1.0
   *
   * @type {string[]}
   */
  __publicField(_FontDetector, "TEST_FONTS", [
    "Arial",
    "Verdana",
    "Times New Roman",
    "Courier New",
    "Georgia",
    "Comic Sans MS",
    "Trebuchet MS",
    "Impact"
  ]);
  /**
   * Test string used for font measurement.
   *
   * @since 1.1.0
   *
   * @type {string}
   */
  __publicField(_FontDetector, "TEST_STRING", "mmmmmmmmmmlli");
  /**
   * Font size used for measurement.
   *
   * @since 1.1.0
   *
   * @type {string}
   */
  __publicField(_FontDetector, "TEST_SIZE", "72px");
  /**
   * Minimum number of fonts expected on a normal system.
   *
   * @since 1.1.0
   *
   * @type {number}
   */
  __publicField(_FontDetector, "MIN_FONTS_THRESHOLD", 3);
  var FontDetector = _FontDetector;

  // assets/js/src/environment/detectors/MediaDevicesDetector.js
  var MediaDevicesDetector = class {
    /**
     * Detect MediaDevices and WebRTC availability.
     *
     * @since 1.1.0
     *
     * @return {Object} Detection result with media_devices_available and webrtc_available flags.
     */
    detect() {
      return {
        media_devices_available: this.detectMediaDevicesAvailable(),
        webrtc_available: this.detectWebRTCAvailable()
      };
    }
    /**
     * Detect if navigator.mediaDevices exists.
     *
     * Real browsers typically have the mediaDevices API available.
     * Headless browsers may lack this API entirely.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if navigator.mediaDevices exists.
     */
    detectMediaDevicesAvailable() {
      try {
        if (!navigator.mediaDevices) {
          return false;
        }
        const hasEnumerateDevices = typeof navigator.mediaDevices.enumerateDevices === "function";
        const hasGetUserMedia = typeof navigator.mediaDevices.getUserMedia === "function";
        return hasEnumerateDevices || hasGetUserMedia;
      } catch (e) {
        return false;
      }
    }
    /**
     * Detect if WebRTC (RTCPeerConnection) works.
     *
     * Attempts to create an RTCPeerConnection instance.
     * Headless browsers often disable or artificially block WebRTC.
     *
     * @since 1.1.0
     *
     * @return {boolean} True if RTCPeerConnection is functional.
     */
    detectWebRTCAvailable() {
      let connection = null;
      try {
        const RTCPeerConnectionClass = window.RTCPeerConnection || window.webkitRTCPeerConnection || window.mozRTCPeerConnection;
        if (!RTCPeerConnectionClass) {
          return false;
        }
        connection = new RTCPeerConnectionClass();
        return true;
      } catch (e) {
        return false;
      } finally {
        if (connection) {
          try {
            connection.close();
          } catch (e) {
          }
        }
      }
    }
  };

  // assets/js/src/environment/detectors/WorkerUADetector.js
  var _WorkerUADetector = class _WorkerUADetector {
    /**
     * Detect User-Agent mismatch between main thread and Worker.
     *
     * @since 1.1.0
     *
     * @return {Promise<Object>} Detection result with mismatch flags.
     */
    detect() {
      return __async(this, null, function* () {
        if (typeof Worker === "undefined" || typeof Blob === "undefined") {
          return {
            worker_ua_available: false,
            worker_ua_mismatch: false,
            worker_platform_mismatch: false
          };
        }
        try {
          return yield this._runWorkerCheck();
        } catch (e) {
          return {
            worker_ua_available: false,
            worker_ua_mismatch: false,
            worker_platform_mismatch: false
          };
        }
      });
    }
    /**
     * Run the Worker-based UA check.
     *
     * @since 1.1.0
     *
     * @return {Promise<Object>} Detection result.
     * @private
     */
    _runWorkerCheck() {
      return new Promise((resolve) => {
        try {
          const workerCode = `
					self.onmessage = function() {
						self.postMessage({
							userAgent: navigator.userAgent,
							platform: navigator.platform
						});
					};
				`;
          const blob = new Blob([workerCode], { type: "application/javascript" });
          const blobUrl = URL.createObjectURL(blob);
          const worker = new Worker(blobUrl);
          const timeout = setTimeout(() => {
            worker.terminate();
            URL.revokeObjectURL(blobUrl);
            resolve({
              worker_ua_available: false,
              worker_ua_mismatch: false,
              worker_platform_mismatch: false
            });
          }, _WorkerUADetector.WORKER_TIMEOUT);
          worker.onmessage = (e) => {
            clearTimeout(timeout);
            worker.terminate();
            URL.revokeObjectURL(blobUrl);
            const workerUA = e.data.userAgent;
            const mainUA = navigator.userAgent;
            const workerPlatform = e.data.platform;
            const mainPlatform = navigator.platform;
            resolve({
              worker_ua_available: true,
              worker_ua_mismatch: workerUA !== mainUA,
              worker_platform_mismatch: workerPlatform !== mainPlatform
            });
          };
          worker.onerror = () => {
            clearTimeout(timeout);
            worker.terminate();
            URL.revokeObjectURL(blobUrl);
            resolve({
              worker_ua_available: false,
              worker_ua_mismatch: false,
              worker_platform_mismatch: false
            });
          };
          worker.postMessage({});
        } catch (e) {
          resolve({
            worker_ua_available: false,
            worker_ua_mismatch: false,
            worker_platform_mismatch: false
          });
        }
      });
    }
  };
  /**
   * Worker timeout in milliseconds.
   *
   * @since 1.1.0
   *
   * @type {number}
   */
  __publicField(_WorkerUADetector, "WORKER_TIMEOUT", 1e3);
  var WorkerUADetector = _WorkerUADetector;

  // assets/js/src/environment/detectors/EmojiRenderingDetector.js
  var _EmojiRenderingDetector = class _EmojiRenderingDetector {
    /**
     * Detect emoji rendering anomalies.
     *
     * @since 1.1.0
     *
     * @return {Object} Detection result with rendering flags.
     */
    detect() {
      try {
        const canvas = document.createElement("canvas");
        canvas.width = _EmojiRenderingDetector.CANVAS_SIZE;
        canvas.height = _EmojiRenderingDetector.CANVAS_SIZE;
        const ctx = canvas.getContext("2d");
        if (!ctx) {
          return {
            emoji_rendered: false,
            emoji_os_mismatch: false,
            emoji_hash: null
          };
        }
        ctx.font = "48px Arial";
        ctx.fillText(_EmojiRenderingDetector.TEST_EMOJI, 10, 50);
        const imageData = ctx.getImageData(
          0,
          0,
          _EmojiRenderingDetector.CANVAS_SIZE,
          _EmojiRenderingDetector.CANVAS_SIZE
        );
        const hash = this._hashImageData(imageData.data);
        const rendered = this._isEmojiRendered(imageData.data);
        return {
          emoji_rendered: rendered,
          emoji_os_mismatch: !rendered,
          // If not rendered, it's suspicious.
          emoji_hash: hash
        };
      } catch (e) {
        return {
          emoji_rendered: false,
          emoji_os_mismatch: false,
          emoji_hash: null
        };
      }
    }
    /**
     * Check if emoji was actually rendered (not all pixels same).
     *
     * @since 1.1.0
     *
     * @param {Uint8ClampedArray} pixels The image data pixels.
     * @return {boolean} True if emoji was rendered.
     * @private
     */
    _isEmojiRendered(pixels) {
      const firstPixel = pixels[0];
      for (let i = 4; i < pixels.length; i += 4) {
        if (pixels[i] !== firstPixel) {
          return true;
        }
      }
      return false;
    }
    /**
     * Generate hash from image data.
     *
     * @since 1.1.0
     *
     * @param {Uint8ClampedArray} pixels The image data pixels.
     * @return {string} Hash string.
     * @private
     */
    _hashImageData(pixels) {
      let hash = 0;
      for (let i = 0; i < pixels.length; i += 100) {
        hash = (hash << 5) - hash + pixels[i];
        hash = hash | 0;
      }
      return hash.toString(36);
    }
  };
  /**
   * Test emoji string for rendering check.
   *
   * @since 1.1.0
   *
   * @type {string}
   */
  __publicField(_EmojiRenderingDetector, "TEST_EMOJI", "\u{1F600}\u{1F3A8}\u{1F30D}");
  // 😀🎨🌍
  /**
   * Canvas dimensions for emoji rendering.
   *
   * @since 1.1.0
   *
   * @type {number}
   */
  __publicField(_EmojiRenderingDetector, "CANVAS_SIZE", 100);
  var EmojiRenderingDetector = _EmojiRenderingDetector;

  // assets/js/src/environment/EnvironmentDetector.js
  var EnvironmentDetector = class {
    /**
     * Constructor.
     *
     * @since 1.1.0
     */
    constructor() {
      this._cachedResult = null;
      this._detectors = {
        webdriver: new WebdriverDetector(),
        automationFramework: new AutomationFrameworkDetector(),
        userAgent: new UserAgentDetector(),
        browserFeatures: new BrowserFeaturesDetector(),
        webgl: new WebGLDetector(),
        permissions: new PermissionsDetector(),
        cdpLeak: new CDPLeakDetector(),
        windowDimensions: new WindowDimensionsDetector(),
        clientHints: new ClientHintsDetector(),
        canvasFingerprint: new CanvasFingerprintDetector(),
        audioContext: new AudioContextDetector(),
        font: new FontDetector(),
        mediaDevices: new MediaDevicesDetector(),
        workerUA: new WorkerUADetector(),
        emojiRendering: new EmojiRenderingDetector()
      };
    }
    /**
     * Run all environment detection checks.
     *
     * Returns a fingerprint object containing raw detection signals.
     * All scoring is performed server-side by the API.
     * Results are cached for performance.
     *
     * @since 1.1.0
     *
     * @return {Promise<EnvironmentSignals>} Detection result with all signals.
     */
    detect() {
      return __async(this, null, function* () {
        if (this._cachedResult) {
          return this._cachedResult;
        }
        const cdpSignals = this._detectors.cdpLeak.detect();
        const windowDimSignals = this._detectors.windowDimensions.detect();
        const canvasSignals = this._detectors.canvasFingerprint.detect();
        const audioSignals = this._detectors.audioContext.detect();
        const fontSignals = this._detectors.font.detect();
        const mediaSignals = this._detectors.mediaDevices.detect();
        const emojiSignals = this._detectors.emojiRendering.detect();
        const webglRenderSignals = this._detectors.webgl.performRenderingTest();
        const [permissionsResult, workerUASignals] = yield Promise.all([
          this._detectors.permissions.detect(),
          this._detectors.workerUA.detect()
        ]);
        const signals = {
          // High confidence signals.
          webdriver: this._detectors.webdriver.detect(),
          automation_framework: this._detectors.automationFramework.detect(),
          headless_ua: this._detectors.userAgent.detect(),
          // Medium confidence signals.
          no_plugins: this._detectors.browserFeatures.detectNoPlugins(),
          no_languages: this._detectors.browserFeatures.detectNoLanguages(),
          chrome_runtime_missing: this._detectors.browserFeatures.detectChromeRuntimeMissing(),
          webgl_anomaly: this._detectors.webgl.detect(),
          // Async checks.
          permissions_inconsistent: permissionsResult,
          // CDP leak detection signals.
          cdp_stack_trace_leak: cdpSignals.cdp_stack_trace_leak,
          cdp_console_debug_leak: cdpSignals.cdp_console_debug_leak,
          // Window dimensions signals.
          no_outer_dimensions: windowDimSignals.no_outer_dimensions,
          inner_equals_outer: windowDimSignals.inner_equals_outer,
          // Client hints headless detection.
          client_hints_headless: this._detectors.clientHints.detect(),
          // Canvas fingerprint signals.
          canvas_hash: canvasSignals.canvas_hash,
          canvas_suspicious: canvasSignals.canvas_suspicious,
          // Audio context signals.
          audio_sample_rate: audioSignals.audio_sample_rate,
          audio_suspicious: audioSignals.audio_suspicious,
          // Font detection signals.
          fonts_detected_count: fontSignals.fonts_detected_count,
          fonts_suspicious: fontSignals.fonts_suspicious,
          // Media devices signals.
          media_devices_available: mediaSignals.media_devices_available,
          webrtc_available: mediaSignals.webrtc_available,
          // Additional browser feature signals.
          screen_suspicious: this._detectors.browserFeatures.detectSuspiciousScreen(),
          no_battery_api: this._detectors.browserFeatures.detectNoBatteryAPI(),
          no_connection_api: this._detectors.browserFeatures.detectNoConnectionAPI(),
          // Worker UA mismatch signals (2026).
          worker_ua_available: workerUASignals.worker_ua_available,
          worker_ua_mismatch: workerUASignals.worker_ua_mismatch,
          worker_platform_mismatch: workerUASignals.worker_platform_mismatch,
          // Emoji rendering signals (2026).
          emoji_rendered: emojiSignals.emoji_rendered,
          emoji_os_mismatch: emojiSignals.emoji_os_mismatch,
          emoji_hash: emojiSignals.emoji_hash,
          // WebGL rendering test signals (2026).
          webgl_rendering_hash: webglRenderSignals.webgl_rendering_hash,
          webgl_rendering_noise: webglRenderSignals.webgl_rendering_noise,
          webgl_rendering_suspicious: webglRenderSignals.webgl_rendering_suspicious,
          // Metadata.
          check_timestamp: Date.now()
        };
        this._cachedResult = signals;
        return signals;
      });
    }
    /**
     * Clear cached detection result.
     *
     * Call this if you need to re-run detection (e.g., after page state changes).
     *
     * @since 1.1.0
     *
     * @return {void}
     */
    clearCache() {
      this._cachedResult = null;
    }
    /**
     * Get a simplified result for API transmission.
     *
     * Returns only the essential fields needed by the API, reducing payload size.
     * All scoring is performed server-side.
     *
     * @since 1.1.0
     *
     * @return {Promise<Object>} Simplified detection result.
     */
    getSimplifiedResult() {
      return __async(this, null, function* () {
        const signals = yield this.detect();
        return {
          webdriver: signals.webdriver,
          automation_framework: signals.automation_framework,
          headless_ua: signals.headless_ua,
          no_plugins: signals.no_plugins,
          no_languages: signals.no_languages,
          chrome_runtime_missing: signals.chrome_runtime_missing,
          webgl_anomaly: signals.webgl_anomaly,
          permissions_inconsistent: signals.permissions_inconsistent,
          cdp_stack_trace_leak: signals.cdp_stack_trace_leak,
          cdp_console_debug_leak: signals.cdp_console_debug_leak,
          no_outer_dimensions: signals.no_outer_dimensions,
          inner_equals_outer: signals.inner_equals_outer,
          client_hints_headless: signals.client_hints_headless,
          canvas_hash: signals.canvas_hash,
          canvas_suspicious: signals.canvas_suspicious,
          audio_sample_rate: signals.audio_sample_rate,
          audio_suspicious: signals.audio_suspicious,
          fonts_detected_count: signals.fonts_detected_count,
          fonts_suspicious: signals.fonts_suspicious,
          media_devices_available: signals.media_devices_available,
          webrtc_available: signals.webrtc_available,
          screen_suspicious: signals.screen_suspicious,
          no_battery_api: signals.no_battery_api,
          no_connection_api: signals.no_connection_api,
          worker_ua_available: signals.worker_ua_available,
          worker_ua_mismatch: signals.worker_ua_mismatch,
          worker_platform_mismatch: signals.worker_platform_mismatch,
          emoji_rendered: signals.emoji_rendered,
          emoji_os_mismatch: signals.emoji_os_mismatch,
          emoji_hash: signals.emoji_hash,
          webgl_rendering_hash: signals.webgl_rendering_hash,
          webgl_rendering_noise: signals.webgl_rendering_noise,
          webgl_rendering_suspicious: signals.webgl_rendering_suspicious,
          check_timestamp: signals.check_timestamp
        };
      });
    }
    /**
     * Get WebGL vendor string (delegated to WebGLDetector).
     *
     * @since 1.1.0
     *
     * @return {string|null} WebGL vendor or null if unavailable.
     */
    getWebGLVendor() {
      return this._detectors.webgl.getVendor();
    }
    /**
     * Get WebGL renderer string (delegated to WebGLDetector).
     *
     * @since 1.1.0
     *
     * @return {string|null} WebGL renderer or null if unavailable.
     */
    getWebGLRenderer() {
      return this._detectors.webgl.getRenderer();
    }
  };

  // assets/js/src/environment/index.js
  if (typeof window.ActiveLayer === "undefined") {
    window.ActiveLayer = {};
  }
  window.ActiveLayer.EnvironmentDetector = EnvironmentDetector;
  window.ActiveLayer.environmentDetector = window.ActiveLayer.environmentDetector || new EnvironmentDetector();
  window.ActiveLayer._environmentCleanup = window.ActiveLayer._environmentCleanup || [];
  function populateHiddenFields() {
    return __async(this, null, function* () {
      const detector = window.ActiveLayer.environmentDetector;
      const signals = yield detector.getSimplifiedResult();
      const jsonSignals = JSON.stringify(signals);
      const inputs = document.querySelectorAll("input.activelayer-env-signals");
      inputs.forEach(function(input) {
        input.value = jsonSignals;
      });
    });
  }
  function writeSignalsToInput(input) {
    if (!input) {
      return;
    }
    const detector = window.ActiveLayer.environmentDetector;
    if (detector && detector._cachedResult) {
      input.value = JSON.stringify({
        webdriver: detector._cachedResult.webdriver,
        automation_framework: detector._cachedResult.automation_framework,
        headless_ua: detector._cachedResult.headless_ua,
        no_plugins: detector._cachedResult.no_plugins,
        no_languages: detector._cachedResult.no_languages,
        chrome_runtime_missing: detector._cachedResult.chrome_runtime_missing,
        webgl_anomaly: detector._cachedResult.webgl_anomaly,
        permissions_inconsistent: detector._cachedResult.permissions_inconsistent,
        check_timestamp: detector._cachedResult.check_timestamp
      });
      return;
    }
    input.value = JSON.stringify({ check_timestamp: Date.now() });
  }
  function initFormListeners() {
    populateHiddenFields();
    var handleSubmit = function(event) {
      const form = event.target;
      if (!form || form.tagName !== "FORM") {
        return;
      }
      writeSignalsToInput(form.querySelector("input.activelayer-env-signals"));
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
      writeSignalsToInput(form.querySelector("input.activelayer-env-signals"));
    };
    document.addEventListener("click", handleClick, true);
    window.ActiveLayer._environmentCleanup.push(
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
    jQuery(document).on("nfFormReady", function() {
      populateHiddenFields();
    });
  }
  if (typeof MutationObserver !== "undefined") {
    const observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        if (mutation.addedNodes.length) {
          mutation.addedNodes.forEach(function(node) {
            if (node.nodeType === 1) {
              const inputs = node.querySelectorAll ? node.querySelectorAll("input.activelayer-env-signals") : [];
              if (inputs.length > 0) {
                populateHiddenFields();
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
    window.ActiveLayer._environmentCleanup.push(function() {
      observer.disconnect();
    });
  }
  window.addEventListener("pagehide", function() {
    if (window.ActiveLayer && window.ActiveLayer._environmentCleanup) {
      window.ActiveLayer._environmentCleanup.forEach(function(fn) {
        fn();
      });
      window.ActiveLayer._environmentCleanup = [];
    }
  });
})();
/**
 * WebdriverDetector - Detects navigator.webdriver property.
 *
 * The webdriver property is set to true when the browser is controlled
 * by automation tools like Selenium, Puppeteer, or Playwright.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * AutomationFrameworkDetector - Detects automation framework globals.
 *
 * Checks for global variables and properties that automation frameworks
 * inject into the page: Selenium, Puppeteer, Playwright, PhantomJS, Cypress.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * UserAgentDetector - Detects headless browser indicators in user agent.
 *
 * Checks for known headless browser signatures like "HeadlessChrome"
 * or "PhantomJS" in the user agent string.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * BrowserFeaturesDetector - Detects missing/suspicious browser features.
 *
 * Checks for plugins, languages, and Chrome-specific features
 * that are often missing or anomalous in headless browsers.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * WebGLDetector - Detects WebGL rendering anomalies.
 *
 * Headless Chrome often uses SwiftShader for software rendering,
 * which can be detected via WebGL vendor/renderer strings.
 * Also performs complex rendering tests to verify GPU consistency.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * PermissionsDetector - Checks for permissions API inconsistencies.
 *
 * Headless browsers sometimes throw errors or return inconsistent
 * results when querying the Permissions API.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * CDPLeakDetector - Detects Chrome DevTools Protocol (CDP) leaks.
 *
 * When CDP's Runtime.enable is active (common in headless automation),
 * certain JavaScript operations behave differently, creating detectable
 * side effects that can identify automated browsers.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * WindowDimensionsDetector - Detects suspicious window dimension patterns.
 *
 * Headless browsers often have no outer dimensions (outerWidth/outerHeight = 0)
 * or have inner dimensions that exactly equal outer dimensions (no browser chrome).
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * ClientHintsDetector - Detects headless browsers via Client Hints API.
 *
 * The User-Agent Client Hints API provides information about the browser
 * in a more structured way. Headless Chrome exposes "HeadlessChrome" in
 * the brands array, which can be used for detection.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * CanvasFingerprintDetector - Generates canvas fingerprints and detects suspicious output.
 *
 * Headless browsers and bots often produce identical or minimal canvas output,
 * which can be detected by generating a canvas fingerprint and checking for anomalies.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * AudioContextDetector - Detects suspicious AudioContext behavior.
 *
 * Headless browsers and bots may report non-standard audio sample rates,
 * which can indicate automated/scripted environments.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * FontDetector - Detects available fonts using canvas measureText.
 *
 * Headless browsers typically have very few fonts installed,
 * which can be detected by measuring text width differences.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * MediaDevicesDetector - Detects MediaDevices and WebRTC availability.
 *
 * Headless browsers often lack or disable MediaDevices and WebRTC APIs,
 * which can indicate automated/scripted environments.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * WorkerUADetector - Detects User-Agent mismatch between main thread and Web Worker.
 *
 * Automation tools often fail to patch navigator.userAgent in Worker context,
 * allowing detection via comparison. Based on Chrome bug fix (2026).
 *
 * Reference: https://chromiumdash.appspot.com/commit/4e9b82be3e9feed8952c81eedde553dfeb746ff3
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * EmojiRenderingDetector - Detects emoji rendering anomalies and OS consistency.
 *
 * Different operating systems render emoji differently. Headless browsers
 * often fail to render emoji at all, or render them inconsistently with
 * the claimed OS in User-Agent.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * ActiveLayer Environment Detector
 *
 * Detects headless browsers and automation frameworks (Selenium, Puppeteer,
 * Playwright, PhantomJS) to identify sophisticated bot attacks.
 *
 * Signals are passed to the API for final classification rather than blocking
 * locally. This is one signal among many used for spam detection.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
/**
 * ActiveLayer Environment Detector Entry Point
 *
 * Exports EnvironmentDetector to window.ActiveLayer and sets up form listeners.
 *
 * @since 1.1.0
 * @license GPL-2.0-or-later
 */
