(() => {
  const badge = document.getElementById('new-ticket-badge');
  const baseTitle = document.title;
  let audioContext;

  function beep() {
    try {
      audioContext = audioContext || new (window.AudioContext || window.webkitAudioContext)();
      const oscillator = audioContext.createOscillator();
      const gain = audioContext.createGain();
      oscillator.type = 'sine';
      oscillator.frequency.value = 880;
      gain.gain.value = 0.08;
      oscillator.connect(gain);
      gain.connect(audioContext.destination);
      oscillator.start();
      oscillator.stop(audioContext.currentTime + 0.18);
    } catch (error) {
      console.warn('Không thể phát âm báo ticket mới.', error);
    }
  }

  function notify(ticket) {
    let box = document.getElementById('ticket-notifications');
    if (!box) {
      box = document.createElement('aside');
      box.id = 'ticket-notifications';
      box.className = 'notification';
      box.setAttribute('role', 'status');
      document.body.appendChild(box);
    }
    const link = document.createElement('a');
    link.href = ticket.url;
    link.textContent = `${ticket.code}: ${ticket.title}`;
    const item = document.createElement('div');
    item.append('Có ticket mới — ', link);
    box.replaceChildren(item);
    window.setTimeout(() => {
      if (box.isConnected) box.remove();
    }, 9000);
  }

  async function poll() {
    try {
      const response = await fetch('/ticket/admin/api_new.php', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      if (!response.ok) return;
      const data = await response.json();
      if (!data.ok) return;
      if (badge) {
        badge.textContent = String(data.count);
        badge.hidden = data.count < 1;
      }
      document.title = data.count > 0 ? `(${data.count}) ${baseTitle}` : baseTitle;
      if (data.newTickets.length > 0) {
        data.newTickets.forEach(notify);
        beep();
      }
    } catch (error) {
      console.warn('Không thể tải thông báo ticket.', error);
    }
  }

  poll();
  window.setInterval(poll, 10000);
})();
