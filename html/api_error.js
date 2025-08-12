(() => {
  const originalFetch = window.fetch.bind(window);

  function toUrl(input) {
    try { return (input && input.url) || (typeof input === 'string' ? input : ''); } catch { return ''; }
  }

  function toMethod(input, init) {
    return (init && init.method) || (input && input.method) || 'GET';
  }

  function tryStringify(body) {
    try {
      if (typeof body === 'string') return body;
      if (body instanceof FormData) {
        const o = {}; for (const [k, v] of body.entries()) o[k] = v; return JSON.stringify(o, null, 2);
      }
      if (body instanceof URLSearchParams) return body.toString();
      if (body && typeof body === 'object') return JSON.stringify(body, null, 2);
      return String(body);
    } catch { return '[unserializable body]'; }
  }

  function showModal(summary, details) {
    const modalEl = document.getElementById('apiErrorModal');
    if (!modalEl) return;
    const summaryEl = document.getElementById('apiErrorSummary');
    const detailsEl = document.getElementById('apiErrorDetails');
    if (summaryEl) summaryEl.textContent = summary;
    if (detailsEl) detailsEl.textContent = details;
    try {
      const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      modal.show();
    } catch (e) {
      // eslint-disable-next-line no-console
      console.error('Unable to show API error modal', e);
    }
  }

  window.fetch = async function(input, init) {
    try {
      const response = await originalFetch(input, init);
      if (!response.ok) {
        let bodyText = '';
        try { bodyText = await response.clone().text(); } catch {}
        const method = toMethod(input, init);
        const url = toUrl(input);
        const headerSummary = `${method} ${url} — ${response.status} ${response.statusText}`;
        const details = [
          `Request: ${method} ${url}`,
          `Status: ${response.status} ${response.statusText}`,
          bodyText && '--- Response Body ---',
          bodyText
        ].filter(Boolean).join('\n');
        showModal(headerSummary, details);
      }
      return response;
    } catch (err) {
      const method = toMethod(input, init);
      const url = toUrl(input);
      const headerSummary = `${method} ${url} — Network error`;
      const details = [
        `Request: ${method} ${url}`,
        `Error: ${err && err.message ? err.message : String(err)}`
      ].join('\n');
      showModal(headerSummary, details);
      throw err;
    }
  };
})();

