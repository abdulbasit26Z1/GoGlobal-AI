<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0B1B3D">
    <title>GoGlobal AI Travel Assistant</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { colors: { navy: '#0B1B3D', crimson: '#E50914', gold: '#F59E0B' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand-lockup"><span class="brand-mark">GG</span><span>GoGlobal</span><small>TRAVEL AI</small></div>
        <button class="new-chat" id="newChat"><span>＋</span> New trip plan</button>
        <div class="sidebar-section"><p class="section-label">Popular escapes</p>
            <button class="destination-link" data-prompt="Build me a Dubai Elevated Escape"><span>✦</span> Dubai</button>
            <button class="destination-link" data-prompt="Show me Baku packages"><span>✦</span> Baku</button>
            <button class="destination-link" data-prompt="I want the 21 Days Economy Umrah"><span>✦</span> Umrah 21 Days</button>
            <button class="destination-link" data-prompt="Plan the Thailand Emerald Trio"><span>✦</span> Thailand Emerald Trio</button>
            <button class="destination-link" data-prompt="Show Turkey Highlights"><span>✦</span> Turkey Highlights</button>
        </div>
        <div class="sidebar-section history-section"><p class="section-label">Your sessions</p><div id="sessionList"></div></div>
        <div class="sidebar-footer"><span class="status-dot"></span><span>Concierge online</span><button id="clearSessions" title="Clear saved sessions">⌫</button></div>
    </aside>
    <main class="main-panel">
        <header class="topbar">
            <button class="mobile-menu" id="mobileMenu" aria-label="Open menu">☰</button>
            <div><p class="topbar-kicker">GoGlobal / Intelligent planning desk</p><h1>Travel, made personal.</h1></div>
            <div class="topbar-actions">
                <button class="capabilities-button" id="openCapabilities">✦ What AI Can Do</button>
                <span class="secure-pill">● Local catalogue</span>
                <button class="icon-button" id="resetConversation" title="Start a new conversation">↻</button>
            </div>
        </header>
        <section class="chat-canvas" id="chatCanvas">
            <div class="welcome-block" id="welcomeBlock">
                <div class="ai-orbit"><span>GG</span><i></i></div>
                <p class="eyebrow">Your GoGlobal concierge</p>
                <h2>Where will your<br><em>next story</em> begin?</h2>
                <p class="welcome-copy">Tell me what you have in mind. I’ll match flights, stays, meals and moments to your budget.</p>
            </div>
            <div class="message-stream" id="messageStream"></div>
            <div class="composer-area">
                <div class="prompt-chips" id="promptChips">
                    <button data-prompt="Show me Umrah packages">🕋 Umrah packages</button>
                    <button data-prompt="Search flights">✈ Search flights</button>
                    <button data-prompt="Explore hotels">⌂ Explore hotels</button>
                    <button data-prompt="Show tour packages">✦ Tour packages</button>
                </div>
                <form class="composer" id="chatForm">
                    <button class="composer-tool" type="button" id="voiceButton" title="Voice input">◉</button>
                    <input id="messageInput" autocomplete="off" placeholder="Ask anything about your next journey..." aria-label="Message GoGlobal AI">
                    <button class="send-button" type="submit" aria-label="Send message">➜</button>
                </form>
                <p class="composer-note">GoGlobal AI works from our curated local catalogue. Prices are per traveller unless noted.</p>
            </div>
        </section>
    </main>
    <aside class="insight-panel">
        <div class="insight-head"><div><p class="eyebrow">Live catalogue</p><h2>Trip building blocks</h2></div><span class="catalog-count" id="catalogCount">200+</span></div>
        <div class="filter-strip"><button class="filter-tab active" data-filter="all">All</button><button class="filter-tab" data-filter="flights">Flights</button><button class="filter-tab" data-filter="hotels">Hotels</button><button class="filter-tab" data-filter="tours">Tours</button></div>
        <div class="catalog-list" id="catalogList"></div>
    </aside>
</div>

<!-- Modals -->
<div class="modal-backdrop hidden" id="capabilitiesModal">
    <div class="modal-sheet capabilities-sheet">
        <div class="modal-heading">
            <div>
                <p class="eyebrow">GoGlobal AI Capabilities</p>
                <h2>What GoGlobal AI Can Do</h2>
            </div>
            <button class="modal-close" data-close-modal>×</button>
        </div>
        <div class="capabilities-grid">
            <div class="capability-card" data-prompt="Find flights from Lahore to Dubai">
                <div class="cap-icon">✈</div>
                <h3>Flight Deals & Search</h3>
                <p>Search direct and connecting flight options across top airlines with exact pricing in PKR.</p>
                <span class="cap-try">Try: "Find flights to Dubai" ➔</span>
            </div>
            <div class="capability-card" data-prompt="5 star hotels in Makkah near Haram">
                <div class="cap-icon">⌂</div>
                <h3>Hotel & Stay Search</h3>
                <p>Filter hotels by star rating, Haram view, city, amenities, and meal plans.</p>
                <span class="cap-try">Try: "5-star hotels in Makkah" ➔</span>
            </div>
            <div class="capability-card" data-prompt="Show me 21 Days Economy Umrah packages">
                <div class="cap-icon">🕋</div>
                <h3>Umrah & Spiritual Tours</h3>
                <p>Explore 7 to 21-day economy and luxury packages for Makkah & Madinah.</p>
                <span class="cap-try">Try: "Umrah 21 Days package" ➔</span>
            </div>
            <div class="capability-card" data-prompt="Plan a 6 day trip to Dubai with 150000 PKR budget">
                <div class="cap-icon">🧳</div>
                <h3>Smart Itinerary Builder</h3>
                <p>Pairs flight + hotel + meal plan tailored to your budget with live headroom calculations.</p>
                <span class="cap-try">Try: "Plan Dubai trip 150k" ➔</span>
            </div>
            <div class="capability-card" data-prompt="Book a trip to Phuket">
                <div class="cap-icon">📝</div>
                <h3>Instant In-Chat Booking</h3>
                <p>Submit booking requests directly inside chat or connect via WhatsApp.</p>
                <span class="cap-try">Try: "Book a trip to Phuket" ➔</span>
            </div>
            <div class="capability-card">
                <div class="cap-icon">🎙</div>
                <h3>Voice Query Assistant</h3>
                <p>Speak your travel requests naturally using native voice speech recognition.</p>
                <span class="cap-try">Click ◉ microphone button below</span>
            </div>
        </div>
    </div>
</div>

<div class="modal-backdrop hidden" id="choiceModal">
    <div class="modal-sheet">
        <div class="modal-heading">
            <div><p class="eyebrow" id="modalEyebrow">GoGlobal choices</p><h2 id="modalTitle">Choose an option</h2></div>
            <button class="modal-close" data-close-modal>×</button>
        </div>
        <div id="modalContent"></div>
    </div>
</div>

<div class="modal-backdrop hidden" id="bookingModal">
    <div class="modal-sheet booking-sheet">
        <div class="modal-heading">
            <div><p class="eyebrow">One step from takeoff</p><h2>Confirm your trip</h2></div>
            <button class="modal-close" data-close-modal>×</button>
        </div>
        <div id="bookingSummary"></div>
        <form id="bookingForm" class="booking-form">
            <label>Full name<input name="name" required placeholder="Your full name"></label>
            <label>WhatsApp number<input name="whatsapp" required placeholder="+92 300 0000000"></label>
            <label>Departure date<input name="date" type="date" required></label>
            <label>Special instructions<textarea name="notes" rows="3" placeholder="Room preference, airport assistance..."></textarea></label>
            <button class="primary-action" type="submit">Send booking request <span>→</span></button>
        </form>
    </div>
</div>

<div class="toast hidden" id="toast"></div>
<script src="/assets/js/app.js"></script>
</body>
</html>
