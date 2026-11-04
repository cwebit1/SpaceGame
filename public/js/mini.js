/**
 * SpaceGame Mini - Minimal frontend per Task 8b
 * Login → elenco pianeti → dettaglio pianeta con code costruzione/ricerca
 */

(function() {
    'use strict';

    // ========== State ==========
    let state = {
        user: null,
        csrfToken: null,
        currentGameId: null,
        bodies: [],
        selectedBody: null,
    };

    // ========== DOM Elements ==========
    const elements = {
        // Login
        loginSection: document.getElementById('login-section'),
        loginForm: document.getElementById('login-form'),
        loginUsername: document.getElementById('login-username'),
        loginPassword: document.getElementById('login-password'),
        loginError: document.getElementById('login-error'),

        // Games
        gamesSection: document.getElementById('games-section'),
        gamesList: document.getElementById('games-list'),
        gamesError: document.getElementById('games-error'),

        // Bodies
        bodiesSection: document.getElementById('bodies-section'),
        bodiesGameName: document.getElementById('bodies-game-name'),
        bodiesList: document.getElementById('bodies-list'),
        bodiesError: document.getElementById('bodies-error'),

        // Body Detail
        detailSection: document.getElementById('detail-section'),
        detailBackBtn: document.getElementById('detail-back-btn'),
        detailBodyName: document.getElementById('detail-body-name'),
        detailBodyCoords: document.getElementById('detail-body-coords'),
        detailFields: document.getElementById('detail-fields'),
        detailResources: document.getElementById('detail-resources'),
        detailEnergy: document.getElementById('detail-energy'),
        detailBuildings: document.getElementById('detail-buildings'),
        detailBuildQueue: document.getElementById('detail-build-queue'),
        detailResearchQueue: document.getElementById('detail-research-queue'),
        detailBuildable: document.getElementById('detail-buildable'),
        detailResearchable: document.getElementById('detail-researchable'),
        detailError: document.getElementById('detail-error'),
    };

    // ========== API Helpers ==========
    async function api(path, options = {}) {
        const defaults = {
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include',
        };

        if (state.csrfToken && ['POST', 'DELETE'].includes(options.method || 'GET')) {
            defaults.headers['X-CSRF-Token'] = state.csrfToken;
        }

        const response = await fetch(path, { ...defaults, ...options });

        let data;
        try {
            data = await response.json();
        } catch {
            data = { ok: false, error: 'Risposta non valida' };
        }

        if (!response.ok) {
            throw new Error(data.error || `HTTP ${response.status}`);
        }

        if (!data.ok) {
            throw new Error(data.error || 'Errore sconosciuto');
        }

        return data.data;
    }

    // ========== Auth ==========
    async function login(username, password) {
        const data = await api('/api/login', {
            method: 'POST',
            body: JSON.stringify({ username, password }),
        });
        state.user = data.user_id;
        state.csrfToken = data.csrf_token;
        return data;
    }

    async function checkAuth() {
        try {
            const data = await api('/api/auth/status');
            if (data.authenticated) {
                state.user = data.user_id;
                state.csrfToken = data.csrf_token;
                return true;
            }
        } catch {
            // Ignore
        }
        return false;
    }

    // ========== Games ==========
    async function loadGames() {
        const games = await api('/api/games');
        renderGames(games);
    }

    function renderGames(games) {
        elements.gamesList.innerHTML = '';

        if (games.length === 0) {
            elements.gamesList.innerHTML = '<p class="empty">Nessuna partita. Crea una nuova partita dal pannello admin.</p>';
            return;
        }

        games.forEach(game => {
            const div = document.createElement('div');
            div.className = 'game-card';
            div.innerHTML = `
                <h3>${escapeHtml(game.name)}</h3>
                <p>Speed: ${game.speed}x | Creata: ${formatDate(game.created_at)}</p>
                <button class="btn btn-primary" data-game-id="${game.id}">Seleziona</button>
            `;
            div.querySelector('button').addEventListener('click', () => selectGame(game.id, game.name));
            elements.gamesList.appendChild(div);
        });
    }

    function selectGame(gameId, gameName) {
        state.currentGameId = gameId;
        showSection('bodies');
        elements.bodiesGameName.textContent = gameName;
        loadBodies();
    }

    // ========== Bodies ==========
    async function loadBodies() {
        try {
            const bodies = await api(`/api/games/${state.currentGameId}/bodies`);
            state.bodies = bodies;
            renderBodies(bodies);
        } catch (e) {
            elements.bodiesError.textContent = e.message;
        }
    }

    function renderBodies(bodies) {
        elements.bodiesList.innerHTML = '';

        if (bodies.length === 0) {
            elements.bodiesList.innerHTML = '<p class="empty">Nessun pianeta trovato.</p>';
            return;
        }

        bodies.forEach(body => {
            const div = document.createElement('div');
            div.className = 'body-card';
            const queueCount = (body.build_queue?.length || 0) + (body.research_queue?.length || 0);
            div.innerHTML = `
                <h3>${body.type === 'standard' ? 'Pianeta' : body.type} ${body.g}:${body.s}:${body.p}</h3>
                <p>Campi: ${body.fields_used}/${body.fields_total} | Code: ${queueCount}</p>
                <p>M: ${formatNumber(body.resources.m)} | C: ${formatNumber(body.resources.c)} | D: ${formatNumber(body.resources.d)}</p>
                <button class="btn btn-secondary" data-body-id="${body.id}">Dettaglio</button>
            `;
            div.querySelector('button').addEventListener('click', () => selectBody(body.id));
            elements.bodiesList.appendChild(div);
        });
    }

    function selectBody(bodyId) {
        state.selectedBody = state.bodies.find(b => b.id === bodyId);
        showSection('detail');
        loadBodyDetail(bodyId);
    }

    // ========== Body Detail ==========
    async function loadBodyDetail(bodyId) {
        try {
            const body = await api(`/api/games/${state.currentGameId}/bodies/${bodyId}`);
            renderBodyDetail(body);
        } catch (e) {
            elements.detailError.textContent = e.message;
        }
    }

    function renderBodyDetail(body) {
        elements.detailBodyName.textContent = `${body.type === 'standard' ? 'Pianeta' : body.type} ${body.g}:${body.s}:${body.p}`;
        elements.detailBodyCoords.textContent = `Galassia ${body.g}, Sistema ${body.s}, Posizione ${body.p}`;

        // Fields
        elements.detailFields.innerHTML = `
            <div class="stat">
                <span class="stat-value">${body.fields_used}</span>
                <span class="stat-label">Usati</span>
            </div>
            <div class="stat">
                <span class="stat-value">${body.fields_free}</span>
                <span class="stat-label">Liberi</span>
            </div>
            <div class="stat">
                <span class="stat-value">${body.fields_total}</span>
                <span class="stat-label">Totali</span>
            </div>
        `;

        // Resources
        elements.detailResources.innerHTML = `
            <div class="resource">
                <span class="resource-icon">🔩</span>
                <span class="resource-value">${formatNumber(body.resources.m)}</span>
                <span class="resource-label">Metallo</span>
                <span class="resource-rate">+${formatNumber(body.production_rates?.m || 0)}/h</span>
            </div>
            <div class="resource">
                <span class="resource-icon">💎</span>
                <span class="resource-value">${formatNumber(body.resources.c)}</span>
                <span class="resource-label">Cristallo</span>
                <span class="resource-rate">+${formatNumber(body.production_rates?.c || 0)}/h</span>
            </div>
            <div class="resource">
                <span class="resource-icon">⛽</span>
                <span class="resource-value">${formatNumber(body.resources.d)}</span>
                <span class="resource-label">Deuterio</span>
                <span class="resource-rate">+${formatNumber(body.production_rates?.d || 0)}/h</span>
            </div>
            <div class="resource">
                <span class="resource-icon">🌙</span>
                <span class="resource-value">${formatNumber(body.resources.lu)}</span>
                <span class="resource-label">Lunarite</span>
                <span class="resource-rate">+${formatNumber(body.production_rates?.lu || 0)}/h</span>
            </div>
        `;

        // Energy
        elements.detailEnergy.innerHTML = `
            <div class="energy-bar">
                <div class="energy-fill" style="width: ${body.energy_store > 0 ? 100 : 0}%"></div>
            </div>
            <span>${body.energy_store > 0 ? 'Attivo' : 'Inattivo'}</span>
        `;

        // Buildings
        renderBuildings(body.buildings);

        // Build Queue
        renderQueue('build', body.build_queue);

        // Research Queue
        renderQueue('research', body.research_queue);

        // Buildable
        renderBuildable(body.buildable, body);

        // Researchable
        renderResearchable(body.researchable, body);
    }

    function renderBuildings(buildings) {
        if (Object.keys(buildings).length === 0) {
            elements.detailBuildings.innerHTML = '<p class="empty">Nessun edificio costruito.</p>';
            return;
        }

        let html = '<table class="table"><thead><tr><th>Edificio</th><th>Livello</th></tr></thead><tbody>';
        Object.entries(buildings).forEach(([code, info]) => {
            html += `<tr><td>${escapeHtml(code)}</td><td>${info.level}</td></tr>`;
        });
        html += '</tbody></table>';
        elements.detailBuildings.innerHTML = html;
    }

    function renderQueue(type, queue) {
        const container = type === 'build' ? elements.detailBuildQueue : elements.detailResearchQueue;

        if (!queue || queue.length === 0) {
            container.innerHTML = '<p class="empty">Nessuna coda.</p>';
            return;
        }

        let html = '<table class="table"><thead><tr><th>#</th><th>Tipo</th><th>Stato</th><th>Progresso</th><th>ETA</th><th>Slot</th></tr></thead><tbody>';
        queue.forEach(item => {
            const progress = item.progress ? `${Math.round(item.progress * 100)}%` : '-';
            const eta = item.eta ? formatTime(item.eta - Date.now() / 1000) : '-';
            html += `<tr>
                <td>${item.id}</td>
                <td>${escapeHtml(item.code)}</td>
                <td><span class="status status-${item.status}">${item.status}</span></td>
                <td>${progress}</td>
                <td>${eta}</td>
                <td>${item.slot}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function renderBuildable(buildable, body) {
        if (!buildable || Object.keys(buildable).length === 0) {
            elements.detailBuildable.innerHTML = '<p class="empty">Nessun edificio costruibile.</p>';
            return;
        }

        let html = '<table class="table"><thead><tr><th>Edificio</th><th>Livello</th><th>Costo (M/C/D)</th><th>Durata</th><th>Azione</th></tr></thead><tbody>';
        Object.entries(buildable).forEach(([code, info]) => {
            const cost = info.cost || { m: 0, c: 0, d: 0 };
            const canBuild = info.can_build;
            const duration = info.duration_hours ? `${info.duration_hours.toFixed(2)}h` : '-';
            html += `<tr>
                <td>${escapeHtml(info.name || code)}</td>
                <td>${info.current_level} → ${info.next_level}</td>
                <td>${formatNumber(cost.m)} / ${formatNumber(cost.c)} / ${formatNumber(cost.d)}</td>
                <td>${duration}</td>
                <td>
                    <button class="btn btn-${canBuild ? 'primary' : 'disabled'}"
                            data-action="build"
                            data-code="${code}"
                            ${!canBuild ? 'disabled' : ''}>
                        ${canBuild ? 'Costruisci' : 'Non disponibile'}
                    </button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        elements.detailBuildable.innerHTML = html;

        // Add click handlers
        elements.detailBuildable.querySelectorAll('button[data-action="build"]').forEach(btn => {
            btn.addEventListener('click', () => queueBuild(btn.dataset.code));
        });
    }

    function renderResearchable(researchable, body) {
        if (!researchable || Object.keys(researchable).length === 0) {
            elements.detailResearchable.innerHTML = '<p class="empty">Nessuna ricerca disponibile.</p>';
            return;
        }

        let html = '<table class="table"><thead><tr><th>Ricerca</th><th>Livello</th><th>Costo (M/C)</th><th>Durata</th><th>Lab</th><th>Azione</th></tr></thead><tbody>';
        Object.entries(researchable).forEach(([code, info]) => {
            const cost = info.cost || { m: 0, c: 0 };
            const canResearch = info.can_research;
            const duration = info.duration_hours ? `${info.duration_hours.toFixed(2)}h` : '-';
            const labLevel = info.lab_level || 0;
            html += `<tr>
                <td>${escapeHtml(info.name || code)}</td>
                <td>${info.current_level} → ${info.next_level}</td>
                <td>${formatNumber(cost.m)} / ${formatNumber(cost.c)}</td>
                <td>${duration}</td>
                <td>${labLevel}</td>
                <td>
                    <button class="btn btn-${canResearch ? 'primary' : 'disabled'}"
                            data-action="research"
                            data-code="${code}"
                            ${!canResearch ? 'disabled' : ''}>
                        ${canResearch ? 'Ricercare' : labLevel === 0 ? 'Lab richiesto' : 'Non disponibile'}
                    </button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        elements.detailResearchable.innerHTML = html;

        // Add click handlers
        elements.detailResearchable.querySelectorAll('button[data-action="research"]').forEach(btn => {
            btn.addEventListener('click', () => queueResearch(btn.dataset.code));
        });
    }

    // ========== Actions ==========
    async function queueBuild(code) {
        try {
            const result = await api(`/api/games/${state.currentGameId}/build`, {
                method: 'POST',
                body: JSON.stringify({ body_id: state.selectedBody.id, code }),
            });
            alert(`Costruzione accodata! Queue ID: ${result.queue_id}, Status: ${result.status}`);
            loadBodyDetail(state.selectedBody.id);
        } catch (e) {
            alert('Errore: ' + e.message);
        }
    }

    async function queueResearch(code) {
        // TODO: implement research queue endpoint
        alert('Ricerca non ancora implementata nel frontend');
    }

    // ========== UI Helpers ==========
    function showSection(section) {
        // Hide all
        elements.loginSection.hidden = true;
        elements.gamesSection.hidden = true;
        elements.bodiesSection.hidden = true;
        elements.detailSection.hidden = true;

        // Show requested
        switch (section) {
            case 'login':
                elements.loginSection.hidden = false;
                break;
            case 'games':
                elements.gamesSection.hidden = false;
                break;
            case 'bodies':
                elements.bodiesSection.hidden = false;
                break;
            case 'detail':
                elements.detailSection.hidden = false;
                break;
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatNumber(num) {
        if (num >= 1e9) return (num / 1e9).toFixed(1) + 'B';
        if (num >= 1e6) return (num / 1e6).toFixed(1) + 'M';
        if (num >= 1e3) return (num / 1e3).toFixed(1) + 'K';
        return num.toString();
    }

    function formatDate(isoString) {
        const date = new Date(isoString);
        return date.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function formatTime(seconds) {
        if (seconds <= 0) return 'Ora';
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        if (h > 0) return `${h}h ${m}m`;
        if (m > 0) return `${m}m ${s}s`;
        return `${s}s`;
    }

    // ========== Event Listeners ==========
    function initEventListeners() {
        // Login form
        elements.loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            elements.loginError.textContent = '';
            try {
                await login(elements.loginUsername.value, elements.loginPassword.value);
                await loadGames();
                showSection('games');
            } catch (e) {
                elements.loginError.textContent = e.message;
            }
        });

        // Back button
        elements.detailBackBtn.addEventListener('click', () => {
            showSection('bodies');
        });
    }

    // ========== Init ==========
    async function init() {
        initEventListeners();

        // Check if already logged in
        const authenticated = await checkAuth();
        if (authenticated) {
            await loadGames();
            showSection('games');
        } else {
            showSection('login');
        }
    }

    // Start when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();