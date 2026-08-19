(() => {
  'use strict';
  const timer = document.querySelector('[data-assessment-countdown]');
  if (!timer) return;
  let remaining = Math.max(0, Number(timer.dataset.assessmentCountdown || 0));
  const format = (seconds) => {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
  };
  const render = () => { timer.textContent = format(remaining); };
  render();
  const interval = window.setInterval(() => {
    remaining = Math.max(0, remaining - 1);
    render();
    if (remaining === 0) {
      window.clearInterval(interval);
      const form = document.getElementById(timer.dataset.expiryForm || '');
      if (form instanceof HTMLFormElement) form.submit();
    }
  }, 1000);
})();