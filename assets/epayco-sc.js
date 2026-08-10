(function () {

  // Global UX guards (delegated):
  // - Prevent arrow keys / PgUp PgDn from changing numeric-like inputs for "amount"
  // - Prevent mouse wheel from changing "amount" while focused
  // Uses capture + delegation so it works even if the field is re-rendered.
  if (!window.__epayco_sc_amount_guards) {
    window.__epayco_sc_amount_guards = true;

    document.addEventListener(
      "keydown",
      (e) => {
        const t = e.target;
        if (!t || !(t instanceof Element)) return;
        if (!t.matches('input[name="amount"]')) return;

        const keys = ["ArrowUp", "ArrowDown", "PageUp", "PageDown", "Up", "Down"];
        if (keys.includes(e.key)) {
          e.preventDefault();
          e.stopPropagation();
          return false;
        }
      },
      true
    );

    document.addEventListener(
      "wheel",
      (e) => {
        const t = e.target;
        if (!t || !(t instanceof Element)) return;
        if (!t.matches('input[name="amount"]')) return;
        // Only if focused
        if (document.activeElement !== t) return;

        const dy = typeof e.deltaY === "number" ? e.deltaY : 0;
        e.preventDefault();
        // Keep page scroll instead of value change
        t.blur();
        if (dy) window.scrollBy(0, dy);
        return false;
      },
      { passive: false, capture: true }
    );
  }

  function ensureCheckoutLoaded(timeoutMs) {
    timeoutMs = timeoutMs || 8000;

    function isReady() {
      return !!(
        window.ePayco &&
        window.ePayco.checkout &&
        typeof window.ePayco.checkout.configure === "function"
      );
    }

    // Cache across multiple forms
    if (window.__EPAYCO_SC_CHECKOUT_PROMISE) return window.__EPAYCO_SC_CHECKOUT_PROMISE;

    window.__EPAYCO_SC_CHECKOUT_PROMISE = new Promise((resolve) => {
      if (isReady()) return resolve(true);

      // If the script tag isn't present (or got stripped), inject it
      const SRC = "https://checkout.epayco.co/checkout-v2.js";
      const existing =
        document.querySelector('script[src="' + SRC + '"]') ||
        document.getElementById("epayco-checkout-v2-js");

      if (!existing) {
        const s = document.createElement("script");
        s.id = "epayco-checkout-v2-js";
        s.src = SRC;
        s.async = true;
        s.onload = () => resolve(isReady());
        s.onerror = () => resolve(false);
        document.head.appendChild(s);
      }

      const start = Date.now();
      (function tick() {
        if (isReady()) return resolve(true);
        if (Date.now() - start >= timeoutMs) return resolve(false);
        setTimeout(tick, 120);
      })();
    });

    return window.__EPAYCO_SC_CHECKOUT_PROMISE;
  }

  function boot() {
    document.querySelectorAll('form[data-epayco-sc="1"]').forEach((form) => {
      if (form.dataset.epaycoInit) return;
      form.dataset.epaycoInit = "1";
      initForm(form);
    });
  }

  function initForm(form) {
    const msg = form.querySelector(".epayco-sc-msg");
    const btn = form.querySelector(".epayco-sc-btn");
    const amount = form.querySelector('input[name="amount"]');
    const currency = form.querySelector('[name="currency"]');
    const rangeHint = form.querySelector(".epayco-sc-range-hint");

    function getLimitsFor(cur) {
      const limits =
        window.EPAYCO_SC && window.EPAYCO_SC.limits
          ? window.EPAYCO_SC.limits
          : { COP: { min: 5000, max: 5000000 }, USD: { min: 1, max: 999999 } };
      const useCur = String(cur || "COP").toUpperCase();
      return limits[useCur] || limits["COP"] || { min: 0, max: Number.MAX_SAFE_INTEGER };
    }

    function updateRangeHint() {
      if (!rangeHint) return;
      const cur = currency && currency.value ? String(currency.value).toUpperCase() : (window.EPAYCO_SC.defaultCurrency || "COP");
      const lim = getLimitsFor(cur);
      rangeHint.textContent = `Rango permitido: ${fmt(lim.min, cur)} - ${fmt(lim.max, cur)}`;
    }

    function validateAmountInline() {
      if (!amount) return;
      const rawVal = amount.value.trim();
      const val = parseLocaleFloat(amount.value);
      const cur = currency && currency.value ? String(currency.value).toUpperCase() : (window.EPAYCO_SC.defaultCurrency || "COP");
      const lim = getLimitsFor(cur);

      if (rawVal === "" || val === 0) {
        amount.classList.remove("is-invalid", "is-valid");
        if (rangeHint) {
          rangeHint.classList.remove("is-invalid", "is-valid");
          rangeHint.textContent = `Rango permitido: ${fmt(lim.min, cur)} - ${fmt(lim.max, cur)}`;
        }
        if (btn) btn.disabled = true;
        return;
      }

      if (val < lim.min || val > lim.max) {
        amount.classList.remove("is-valid");
        amount.classList.add("is-invalid");
        if (rangeHint) {
          rangeHint.classList.remove("is-valid");
          rangeHint.classList.add("is-invalid");
          if (val < lim.min) {
            rangeHint.textContent = `Monto mínimo: ${fmt(lim.min, cur)}`;
          } else {
            rangeHint.textContent = `Monto máximo: ${fmt(lim.max, cur)}`;
          }
        }
        if (btn) btn.disabled = true;
      } else {
        amount.classList.remove("is-invalid");
        amount.classList.add("is-valid");
        if (rangeHint) {
          rangeHint.classList.remove("is-invalid");
          rangeHint.classList.add("is-valid");
          rangeHint.textContent = `Monto válido: ${fmt(val, cur)}`;
        }
        if (btn) btn.disabled = false;
      }
    }


    // Prefill from URL params, e.g. /pagos/?amount=1&currency=COP
    try {
      const params = new URLSearchParams(window.location.search || "");
      let currentCurrency = currency ? currency.value : "COP";
      if (currency && params.has("currency")) {
        let c = String(params.get("currency") || "").trim().toUpperCase();
        if (c === "US$") c = "USD";
        if (c === "COP" || c === "USD") {
          currency.value = c;
          currentCurrency = c;
        }
      }
      if (amount) {
        amount.dataset.lastCurrency = currentCurrency;
        if (params.has("amount")) {
          const raw = String(params.get("amount") || "").trim();
          if (raw) {
            amount.value = formatAmountInput(raw, currentCurrency);
          }
        }
      }
    } catch (e) { }


    let isLoading = false;

    const setMsg = (text, kind) => {
      if (!msg) return;
      msg.className = "epayco-sc-msg" + (kind ? " is-" + kind : "");
      msg.textContent = text || "";
    };

    const setLoading = (loading) => {
      isLoading = !!loading;
      if (btn) btn.disabled = !!loading;

      // lock inputs to avoid accidental changes mid-request
      if (amount) {
        amount.readOnly = !!loading;
        amount.disabled = !!loading;
        if (loading) amount.blur();
      }
      if (currency) {
        currency.disabled = !!loading;
        if (loading) currency.blur();
      }
    };

    const preventWhileLoading = (e) => {
      if (!isLoading) return;
      e.preventDefault();
      e.stopPropagation();
      return false;
    };

    // UX: evita que el monto cambie accidentalmente con la rueda del mouse / trackpad.
    // Mantiene el scroll de la página: desenfoca el input y desplaza la ventana.
    const preventWheelValueChange = (e) => {
      if (!amount) return;
      if (document.activeElement !== amount) return;
      const dy = typeof e.deltaY === "number" ? e.deltaY : 0;
      e.preventDefault();
      amount.blur();
      if (dy) window.scrollBy(0, dy);
      return false;
    };

    function parseLocaleFloat(val, cur) {
      if (!val) return 0;
      const useCur = cur || (currency && currency.value ? String(currency.value).toUpperCase() : "COP");
      if (useCur === "USD") {
        let clean = val.replace(/,/g, "");
        return parseFloat(clean) || 0;
      } else {
        let clean = val.replace(/\./g, "");
        clean = clean.replace(/,/g, ".");
        return parseFloat(clean) || 0;
      }
    }

    function formatAmountInput(val, cur) {
      if (!val) return "";
      const currencyCode = (cur || "").toUpperCase();
      if (currencyCode === "COP") {
        let clean = val.replace(/\D/g, "");
        if (!clean) return "";
        return clean.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
      } else {
        let normalized = val.replace(/,/g, ".");
        let dotCount = (normalized.match(/\./g) || []).length;
        if (dotCount > 1) {
          let parts = normalized.split(".");
          normalized = parts[0] + "." + parts.slice(1).join("");
        }
        let parts = normalized.split(".");
        let integerPart = parts[0].replace(/\D/g, "");
        let decimalPart = parts.length > 1 ? parts[1].replace(/\D/g, "").substring(0, 2) : null;
        let formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        if (decimalPart !== null) {
          return formattedInteger + "." + decimalPart;
        } else {
          return formattedInteger;
        }
      }
    }

    if (amount) {
      amount.dataset.lastCurrency = currency ? currency.value : (window.EPAYCO_SC.defaultCurrency || "COP");

      // UX: evita que las flechas (↑ ↓ / PgUp PgDn) incrementen/decrementen el número cuando el input está enfocado.
      // Esto quita el comportamiento típico de <input type="number"> que a veces cambia el monto sin querer.
      amount.addEventListener("keydown", (e) => {
        const keys = ["ArrowUp", "ArrowDown", "PageUp", "PageDown"];
        if (keys.includes(e.key)) {
          e.preventDefault();
          e.stopPropagation();
          return false;
        }
      });

      amount.addEventListener("wheel", preventWheelValueChange, { passive: false });
      amount.addEventListener("wheel", preventWhileLoading, { passive: false });

      // Automatically format amount with thousands separator as the user types
      amount.addEventListener("input", (e) => {
        let cursorPosition = amount.selectionStart;
        let originalLength = amount.value.length;
        let val = amount.value;
        const cur = currency && currency.value ? String(currency.value).toUpperCase() : "COP";
        let formatted = formatAmountInput(val, cur);
        amount.value = formatted;
        let newLength = formatted.length;
        let diff = newLength - originalLength;
        amount.setSelectionRange(cursorPosition + diff, cursorPosition + diff);
        validateAmountInline();
      });

    }
    if (currency) {
      currency.addEventListener("change", (e) => {
        preventWhileLoading(e);
        if (amount && amount.value) {
          const lastCur = amount.dataset.lastCurrency || "COP";
          const parsed = parseLocaleFloat(amount.value, lastCur);
          amount.dataset.lastCurrency = currency.value;
          amount.value = formatAmountInput(parsed.toString(), currency.value);
        }
        updateRangeHint();
        validateAmountInline();
      });
      currency.addEventListener("keydown", (e) => {
        if (!isLoading) return;
        const keys = ["ArrowUp", "ArrowDown", "PageUp", "PageDown"];
        if (keys.includes(e.key)) preventWhileLoading(e);
      });
    }

    function fmt(n, cur) {
      // Formato final: "$<valor> <DIVISA>"
      // Ejemplos: "$10.000 COP" | "$1.290,41 USD"
      try {
        const currency = (cur || "").toUpperCase();
        const digits = currency === "COP" ? 0 : 2;
        const num = new Intl.NumberFormat("es-CO", {
          minimumFractionDigits: digits,
          maximumFractionDigits: digits,
        }).format(Number(n));
        // $ pegado al número, divisa al final (si existe)
        return "$" + num + (currency ? " " + currency : "");
      } catch (e) {
        const v = String(n ?? "");
        const currency = (cur || "").toUpperCase();
        return "$" + v + (currency ? " " + currency : "");
      }
    }


    const payBtn = form.querySelector('[data-epayco-sc-pay="1"]');
    if (payBtn) {
      payBtn.addEventListener("click", (e) => {
        e.preventDefault();
        // Trigger the same flow as submit without reloading the page
        form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
      });
    }

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      setMsg("", "");

      const value = amount ? parseLocaleFloat(amount.value) : 0;
      if (!value || value <= 0) {
        setMsg("Pon un monto válido 👀", "error");
        amount && amount.focus();
        return;
      }

      const limits =
        window.EPAYCO_SC && window.EPAYCO_SC.limits
          ? window.EPAYCO_SC.limits
          : { COP: { min: 5000, max: 5000000 }, USD: { min: 1, max: 999999 } };

      const cur = currency && currency.value ? String(currency.value).toUpperCase() : "COP";
      const lim = limits[cur] || limits["COP"] || { min: 0, max: Number.MAX_SAFE_INTEGER };

      const min = Number(lim.min ?? 0);
      const max = Number(lim.max ?? Number.MAX_SAFE_INTEGER);

      if (value < min) {
        setMsg("El monto mínimo por transacción es " + fmt(min, cur) + ".", "error");
        amount && amount.focus();
        return;
      }
      if (value > max) {
        setMsg("El monto máximo por transacción es " + fmt(max, cur) + ".", "error");
        amount && amount.focus();
        return;
      }

      if (!window.EPAYCO_SC) {
        setMsg("Falta configuración EPAYCO_SC. Revisa el shortcode.", "error");
        return;
      }
      if (!window.EPAYCO_SC.publicKey) {
        setMsg("Falta la Public Key. Configúrala en Ajustes del plugin.", "error");
        return;
      }

      const fd = new FormData(form);
      if (amount) {
        fd.set("amount", parseLocaleFloat(amount.value));
      }
      fd.append("action", "epayco_create_session");
      fd.append("nonce", window.EPAYCO_SC.nonce);
      fd.append("page_title", document.title || "");
      fd.append("url_response", window.location.href);

      setLoading(true);
      setMsg("Procesando...", "info");

      try {
        const res = await fetch(window.EPAYCO_SC.ajaxUrl, { method: "POST", body: fd });
        const data = await res.json();

        if (!data || !data.success) {
          const m = data && data.data && data.data.message ? data.data.message : "No se pudo iniciar el pago";
          setMsg(m, "error");
          setLoading(false);
          return;
        }

        const sessionId = data.data.sessionId;

        const ok = await ensureCheckoutLoaded(9000);
        if (!ok) {
          setMsg("No cargó checkout-v2.js. Revisa bloqueadores o cache.", "error");
          setLoading(false);
          return;
        }

        const isMobile = window.matchMedia && window.matchMedia("(max-width: 768px)").matches;
        const forcedMobile = !!window.EPAYCO_SC.mobileForceStandard;
        const mode = forcedMobile && isMobile ? "standard" : (window.EPAYCO_SC.mode || "onpage");

        const isTest = String(window.EPAYCO_SC.test) === "1";

        console.log("ePayco SC Config:", { sessionId, mode, test: isTest });

        // Freeze & null-prototype: prevents any prototype pollution from affecting the object
        const config = Object.freeze(Object.assign(Object.create(null), {
          sessionId: sessionId,
          type: mode,
          test: isTest,
        }));

        const checkout = window.ePayco.checkout.configure(config);

        checkout.onErrors((errors) => {
          console.error("ePayco errors:", errors);
          setMsg("Error abriendo el checkout 😵", "error");
          setLoading(false);
        });

        checkout.onClosed(() => {
          // when it closes, enable inputs again
          setLoading(false);
          setMsg("", "");
        });

        checkout.open();

        // Requirement: when onpage opens, clear lower messages
        if (mode === "onpage") {
          setMsg("", "");
        } else {
          setMsg("Abriendo checkout...", "ok");
        }
      } catch (err) {
        console.error(err);
        setMsg("Error iniciando el pago. Intenta otra vez.", "error");
        setLoading(false);
      }
    });

    updateRangeHint();
    validateAmountInline();
  }

  document.addEventListener("DOMContentLoaded", boot);

  // Elementor live render support
  document.addEventListener("elementor/frontend/init", () => {
    try {
      if (window.elementorFrontend) {
        window.elementorFrontend.hooks.addAction("frontend/element_ready/global", function () {
          boot();
        });
      }
    } catch (e) { }
  });
})();
