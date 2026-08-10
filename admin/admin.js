
(function(){
  function initColor(){
    if (window.jQuery && jQuery.fn && jQuery.fn.wpColorPicker){
      jQuery('.epayco-color-field').wpColorPicker();
    }
  }

  function qs(sel){ return document.querySelector(sel); }
  function qsa(sel){ return Array.from(document.querySelectorAll(sel)); }

  function setUsdDisabled(disabled){
    qsa('input[name*="[usd_min]"], input[name*="[usd_max]"]').forEach(function(inp){
      inp.disabled = disabled;
    });
    const hint = qs('#epayco-usd-hint');
    if (hint){
      hint.textContent = disabled
        ? 'USD automático: se calcula con la TRM del día (bloqueado).'
        : 'USD manual: puedes escribir tus propios valores (sin recargar).';
    }
    const pill = qs('#epayco-usd-pill');
    if (pill){
      pill.classList.toggle('epayco-sc-badge-ok', disabled);
      pill.classList.toggle('epayco-sc-badge-warn', !disabled);
      pill.textContent = disabled ? 'USD automático' : 'USD manual';
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    initColor();

    // Copiar shortcode al portapapeles
    const copyBtn = qs('#epayco-sc-copy-shortcode');
    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        const code = qs('#epayco-sc-shortcode');
        if (code) {
          navigator.clipboard.writeText(code.textContent).then(function () {
            const originalText = copyBtn.innerHTML;
            copyBtn.innerHTML = '<span class="dashicons dashicons-yes" style="line-height:1.3;"></span> ¡Copiado!';
            setTimeout(function () {
              copyBtn.innerHTML = originalText;
            }, 2000);
          }).catch(function (err) {
            console.error('No se pudo copiar: ', err);
          });
        }
      });
    }

    // Actualizar TRM manualmente
    const refreshBtn = qs('#epayco-sc-refresh-trm');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        refreshBtn.disabled = true;
        const icon = refreshBtn.querySelector('.dashicons-update');
        if (icon) {
          icon.style.transition = 'transform 1s linear';
          icon.style.transform = 'rotate(360deg)';
        }

        const data = new URLSearchParams();
        data.append('action', 'epayco_refresh_trm');

        fetch(ajaxurl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: data.toString()
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
          if (res.success) {
            window.location.reload();
          } else {
            alert(res.data.message || 'Error al actualizar la TRM');
            refreshBtn.disabled = false;
            if (icon) icon.style.transform = 'none';
          }
        })
        .catch(function(err) {
          console.error(err);
          alert('Error de red al actualizar la TRM');
          refreshBtn.disabled = false;
          if (icon) icon.style.transform = 'none';
        });
      });
    }

    const auto = qs('input[name*="[auto_usd_limits]"]');
    if (auto) {
      // initial state
      setUsdDisabled(!!auto.checked);

      auto.addEventListener('change', function(){
        setUsdDisabled(!!auto.checked);
      });
    }

    // Dynamic numeric formatting for limits inputs
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

    qsa('.epayco-sc-amount-input').forEach(function(inp){
      inp.addEventListener('input', function(){
        let cursorPosition = inp.selectionStart;
        let originalLength = inp.value.length;
        let val = inp.value;
        const cur = inp.dataset.currency || "COP";
        let formatted = formatAmountInput(val, cur);
        inp.value = formatted;
        let newLength = formatted.length;
        let diff = newLength - originalLength;
        inp.setSelectionRange(cursorPosition + diff, cursorPosition + diff);
      });
    });
  });
})();
