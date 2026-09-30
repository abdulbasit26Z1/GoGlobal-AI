(() => {
  const $ = (selector) => document.querySelector(selector);
  const state = { catalogue: {}, trip: null, sessions: JSON.parse(localStorage.getItem('gg_sessions') || '[]'), sessionId: crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) };
  const money = (value) => `PKR ${Number(value || 0).toLocaleString('en-PK')}`;
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const sessionTitle = (value) => value.replace(/^(show me|build me|i want|plan|search|explore)\s+/i, '').slice(0, 26);

  async function request(payload) {
    const response = await fetch('api.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || 'The concierge could not respond.');
    return data;
  }
  async function loadCatalogue() {
    const response = await fetch('api.php'); state.catalogue = (await response.json()).catalogue || {};
    $('#catalogCount').textContent = `${Object.values(state.catalogue).reduce((total, list) => total + list.length, 0)} items`;
    renderCatalog('all');
  }
  function appendMessage(text, role = 'ai', content = '') {
    const node = document.createElement('div'); node.className = `message ${role}`;
    node.innerHTML = role === 'user' ? `<div class="bubble">${escapeHtml(text)}</div>` : `<div class="avatar">GG</div><div class="bubble">${escapeHtml(text)}${content}</div>`;
    $('#messageStream').appendChild(node); $('#messageStream').scrollTop = $('#messageStream').scrollHeight;
  }
  function renderCatalog(filter) {
    const source = filter === 'all' ? [...(state.catalogue.tours || []), ...(state.catalogue.hotels || []), ...(state.catalogue.flights || [])] : state.catalogue[filter] || [];
    $('#catalogList').innerHTML = source.slice(0, 14).map((item) => {
      const type = item.title ? 'Tour' : item.name ? 'Stay' : 'Flight'; const title = item.title || item.name || `${item.airline} · ${item.from} → ${item.to}`;
      const detail = item.destination || item.city || `${item.duration} · ${item.stops ? `${item.stops} stop` : 'Direct'}`; const price = item.price;
      return `<article class="catalog-card"><div class="card-top"><span class="card-type">${type}</span><span class="card-stars">${item.stars ? '★'.repeat(Math.min(item.stars, 5)) : item.style || 'GoGlobal'}</span></div><h3>${escapeHtml(title)}</h3><p>${escapeHtml(detail)}${item.days ? ` · ${item.days} days` : ''}</p><div class="card-price"><span>${money(price)} <small>${type === 'Stay' ? '/ night' : 'from'}</small></span><button class="card-cta" data-card-prompt="${escapeHtml(title)}">View</button></div></article>`;
    }).join('');
  }
  function tripMarkup(trip) {
    const {tour, flight, hotel, meal, total, budget} = trip;
    const meter = budget ? `<div class="trip-line"><span>Budget headroom</span><strong>${money(Math.max(budget - total, 0))}</strong></div>` : '';
    return `<div class="trip-card"><h3>${escapeHtml(tour.title)}</h3><div class="trip-line"><span>✈ Flight · ${flight?.airline || 'Flexible'}</span><strong>${money(flight?.price)}</strong></div><div class="trip-line"><span>⌂ Stay · ${hotel?.name || 'Handpicked hotel'}</span><strong>${money(hotel?.price)} / night</strong></div><div class="trip-line"><span>◒ ${meal?.plan || 'Daily meals'}</span><strong>${money(meal?.price)}</strong></div>${meter}<div class="trip-total"><span>Trip total</span><span>${money(total)}</span></div><div class="trip-actions"><button data-trip-action="flight">Change flight</button><button data-trip-action="hotel">Change hotel</button><button class="secondary" data-trip-action="booking">Proceed to booking</button></div></div>`;
  }
  function alternativeMarkup(items) { return `<div class="choice-list">${items.map((item) => `<div class="choice-item"><div><h3>${escapeHtml(item.title)}</h3><p>${item.days} days · ${money(item.price)} · ${escapeHtml(item.style || 'Value')}</p></div><button data-alternative="${escapeHtml(item.id)}">Choose</button></div>`).join('')}</div>`; }
  async function sendMessage(message) {
    if (!message.trim()) return; $('#welcomeBlock').classList.add('hidden'); appendMessage(message, 'user'); $('#messageInput').value = '';
    const loading = document.createElement('div'); loading.className = 'message ai'; loading.innerHTML = '<div class="avatar">GG</div><div class="bubble">Finding the right combination <span class="loading-dots">···</span></div>'; $('#messageStream').appendChild(loading);
    try {
      const data = await request({action: 'chat', message, chat_id: state.sessionId}); loading.remove();
      const reply = data.reply || {}; appendMessage(reply.text || 'Here is what I found.');
      if (reply.type === 'trip') { state.trip = reply.trip; appendMessage('', 'ai', tripMarkup(state.trip)); }
      if (reply.type === 'alternatives') appendMessage('', 'ai', alternativeMarkup(reply.alternatives || []));
      saveSession(message);
    } catch (error) { loading.remove(); appendMessage(error.message); showToast(error.message); }
  }
  function saveSession(firstMessage) {
    const existing = state.sessions.find((session) => session.id === state.sessionId); if (existing) return;
    state.sessions.unshift({id: state.sessionId, title: sessionTitle(firstMessage), created: Date.now()}); localStorage.setItem('gg_sessions', JSON.stringify(state.sessions)); renderSessions();
  }
  function renderSessions() { $('#sessionList').innerHTML = state.sessions.slice(0, 8).map((session) => `<button class="session-link ${session.id === state.sessionId ? 'active' : ''}" data-session="${session.id}">${escapeHtml(session.title)}</button>`).join(''); }
  function showModal(id) { $(id).classList.remove('hidden'); }
  function closeModals() { document.querySelectorAll('.modal-backdrop').forEach((modal) => modal.classList.add('hidden')); }
  function showToast(message) { const toast = $('#toast'); toast.textContent = message; toast.classList.remove('hidden'); setTimeout(() => toast.classList.add('hidden'), 4500); }
  function openChoices(kind) {
    const isFlight = kind === 'flight'; const items = isFlight ? state.catalogue.flights : state.catalogue.hotels;
    $('#modalEyebrow').textContent = isFlight ? 'Flight alternatives' : 'Stay alternatives'; $('#modalTitle').textContent = isFlight ? 'Change your flight' : 'Change your hotel';
    $('#modalContent').innerHTML = `<div class="choice-list">${items.slice(0, 12).map((item) => `<div class="choice-item"><div><h3>${escapeHtml(isFlight ? `${item.airline} · ${item.from} → ${item.to}` : item.name)}</h3><p>${escapeHtml(isFlight ? `${item.duration} · ${item.stops ? `${item.stops} stop` : 'Direct'} · ${item.class}` : `${item.city} · ${item.stars}-star · ${item.mealPlan}`)}</p></div><button data-choice-kind="${kind}" data-choice-id="${item.id}">${money(item.price)}</button></div>`).join('')}</div>`; showModal('#choiceModal');
  }
  function openBooking() { $('#bookingSummary').innerHTML = `<div class="booking-summary"><strong>${escapeHtml(state.trip.tour.title)}</strong><br>${money(state.trip.total)} · ${state.trip.tour.days} days · ${escapeHtml(state.trip.hotel?.name || 'Selected hotel')}</div>`; showModal('#bookingModal'); }
  $('#chatForm').addEventListener('submit', (event) => { event.preventDefault(); sendMessage($('#messageInput').value); });
  document.addEventListener('click', (event) => {
    const prompt = event.target.closest('[data-prompt]'); if (prompt) sendMessage(prompt.dataset.prompt);
    const filter = event.target.closest('[data-filter]'); if (filter) { document.querySelectorAll('.filter-tab').forEach((tab) => tab.classList.remove('active')); filter.classList.add('active'); renderCatalog(filter.dataset.filter); }
    const action = event.target.closest('[data-trip-action]'); if (action) action.dataset.tripAction === 'booking' ? openBooking() : openChoices(action.dataset.tripAction);
    const choice = event.target.closest('[data-choice-kind]'); if (choice) { const kind = choice.dataset.choiceKind; const list = kind === 'flight' ? state.catalogue.flights : state.catalogue.hotels; const selected = list.find((item) => item.id === choice.dataset.choiceId); if (state.trip && selected) { const previous = state.trip[kind]; state.trip.total += Number(selected.price || 0) - Number(previous?.price || 0); state.trip[kind] = selected; } closeModals(); appendMessage(`${kind === 'flight' ? 'Flight' : 'Hotel'} updated to ${selected.airline || selected.name}.`, 'ai', state.trip ? tripMarkup(state.trip) : ''); }
    const alternative = event.target.closest('[data-alternative]'); if (alternative) { const selected = (state.catalogue.tours || []).find((item) => item.id === alternative.dataset.alternative); if (selected) { closeModals(); sendMessage(`Please build the ${selected.title} package.`); } }
    if (event.target.closest('[data-close-modal]') || event.target.classList.contains('modal-backdrop')) closeModals();
  });
  $('#newChat').addEventListener('click', () => { $('#messageStream').innerHTML = ''; $('#welcomeBlock').classList.remove('hidden'); state.sessionId = crypto.randomUUID ? crypto.randomUUID() : String(Date.now()); state.trip = null; renderSessions(); $('#sidebar').classList.remove('open'); });
  $('#resetConversation').addEventListener('click', () => $('#newChat').click()); $('#clearSessions').addEventListener('click', () => { state.sessions = []; localStorage.removeItem('gg_sessions'); renderSessions(); }); $('#mobileMenu').addEventListener('click', () => $('#sidebar').classList.toggle('open'));
  $('#voiceButton').addEventListener('click', () => showToast('Voice input is available when your browser grants microphone access.'));
  $('#bookingForm').addEventListener('submit', async (event) => { event.preventDefault(); const booking = Object.fromEntries(new FormData(event.currentTarget)); booking.trip = state.trip; try { const result = await request({action: 'booking', booking}); closeModals(); event.currentTarget.reset(); showToast(result.message); } catch (error) { showToast(error.message); } });
  loadCatalogue().catch(() => showToast('Catalogue could not be loaded.')); renderSessions();
})();
