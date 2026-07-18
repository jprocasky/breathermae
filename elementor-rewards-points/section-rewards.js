window.addEventListener('load', () => {

  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {

      // Re-observe after Elementor layout settles
      setTimeout(() => {
        document
          .querySelectorAll('[data-reward-points][data-reward-key], [data-event-key]')
          .forEach(el => observer.observe(el));
      }, 500);

      if (!entry.isIntersecting) return;

      const el = entry.target;

      // -----------------------------
      // ✅ REWARDS LOGIC
      // -----------------------------
      const points = parseInt(
        String(el.dataset.rewardPoints || '').replace(/"/g, ''),
        10
      );

      const rewardKey = String(el.dataset.rewardKey || '').replace(/"/g, '');

      if (points && rewardKey && !el.dataset.rewarded) {

        el.dataset.rewarded = '1';

        fetch(ERP.ajax_url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: 'erp_award_points',
            nonce: ERP.nonce,
            reward_key: rewardKey,
            points: points
          })
        });
      }

      // -----------------------------
      // ✅ EVENT LOGGING (NEW)
      // -----------------------------
      const eventKey = String(el.dataset.eventKey || '').replace(/"/g, '');
      const eventVal = String(el.dataset.eventValue || '').replace(/"/g, '');

      if (eventKey && !el.dataset.eventLogged) {

        el.dataset.eventLogged = '1';

        fetch(ERP.ajax_url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: 'uls_record_event',
            nonce: ERP.nonce,
            event_key: eventKey,
            event_value: eventVal
          })
        });
      }

    });
  }, { threshold: 0.5 });

  // ✅ Observe BOTH reward + event elements
  document
    .querySelectorAll('[data-reward-points][data-reward-key], [data-event-key]')
    .forEach(el => observer.observe(el));

});