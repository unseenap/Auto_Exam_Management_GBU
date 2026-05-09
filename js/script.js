// Exam Management System — Shared JavaScript
// Light theme only. No dark mode. Clean, modular, role-based chatbot.

// ── DOMContentLoaded bootstrap ───────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    // Auto-hide alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function () { alert.style.display = 'none'; }, 500);
        }, 5000);
    });

    initTooltips();
    initThemePreference();

    // Mark active nav item
    const currentPath = window.location.pathname;
    document.querySelectorAll('.nav-item').forEach(function (link) {
        if (link.getAttribute('href') && currentPath.includes(link.getAttribute('href'))) {
            link.classList.add('active');
        }
    });

    // Boot chatbot on pages that don't do their own auth check.
    // Pages that do their own auth check (dashboard.html, faculty pages, student_portal.html)
    // call addChatbotWidget(role) directly after auth, so we skip it here for those pages.
    var skipChatbotPages = ['dashboard.html', 'faculty', 'student_portal.html',
                            'student_datesheet.html', 'student_seating_slip.html', 'student_admit_card.html'];
    var currentPage = window.location.pathname.split('/').pop() || '';
    var skipChatbot = skipChatbotPages.some(function (p) { return currentPage === p; });

    if (!document.body.classList.contains('login-page') && !skipChatbot) {
        addChatbotWidget();
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// FORM UTILITIES
// ═══════════════════════════════════════════════════════════════════════════

function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return true;
    let isValid = true;
    form.querySelectorAll('input[required], select[required], textarea[required]').forEach(function (input) {
        if (!input.value.trim()) {
            input.style.borderColor = 'red';
            isValid = false;
        } else {
            input.style.borderColor = '';
        }
    });
    return isValid;
}

function confirmDelete(message) {
    return confirm(message || 'Are you sure you want to delete this item?');
}

function printPage() { window.print(); }

function resetForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    form.reset();
    form.querySelectorAll('input, select, textarea').forEach(function (el) {
        el.style.borderColor = '';
    });
}

function toggleElement(elementId) {
    const el = document.getElementById(elementId);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// ═══════════════════════════════════════════════════════════════════════════
// TABLE UTILITIES
// ═══════════════════════════════════════════════════════════════════════════

function exportTableToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const csv = [];
    table.querySelectorAll('tr').forEach(function (row) {
        const cols = [];
        row.querySelectorAll('td, th').forEach(function (col) {
            cols.push('"' + col.innerText.replace(/"/g, '""') + '"');
        });
        csv.push(cols.join(','));
    });
    downloadCSV(csv.join('\n'), filename);
}

function downloadCSV(csv, filename) {
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = window.URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = filename || 'export.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

function filterTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if (!input || !table) return;
    input.addEventListener('keyup', function () {
        const filter = this.value.toLowerCase();
        Array.from(table.getElementsByTagName('tr')).slice(1).forEach(function (row) {
            const text = Array.from(row.getElementsByTagName('td')).map(function (c) { return c.innerText; }).join(' ').toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
}

function filterTableFn(tableId, query) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const q = query.toLowerCase().trim();
    table.querySelectorAll('tbody tr').forEach(function (row) {
        row.style.display = (!q || row.innerText.toLowerCase().includes(q)) ? '' : 'none';
    });
}

function sortTable(tableId, columnIndex) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const tbody = table.getElementsByTagName('tbody')[0];
    const rows  = Array.from(tbody.getElementsByTagName('tr'));
    const asc   = table.dataset.sortOrder !== 'asc' || table.dataset.sortColumn != columnIndex;
    rows.sort(function (a, b) {
        const av = (a.getElementsByTagName('td')[columnIndex] || {}).innerText || '';
        const bv = (b.getElementsByTagName('td')[columnIndex] || {}).innerText || '';
        if (!isNaN(av) && !isNaN(bv)) return asc ? av - bv : bv - av;
        return asc ? av.localeCompare(bv) : bv.localeCompare(av);
    });
    tbody.innerHTML = '';
    rows.forEach(function (r) { tbody.appendChild(r); });
    table.dataset.sortColumn = columnIndex;
    table.dataset.sortOrder  = asc ? 'asc' : 'desc';
}

function sortTableFn(tableId, colIndex) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    const th    = table.querySelectorAll('th')[colIndex];
    const asc   = th.dataset.sort !== 'asc';
    th.dataset.sort = asc ? 'asc' : 'desc';
    rows.sort(function (a, b) {
        const av = (a.cells[colIndex] ? a.cells[colIndex].innerText.trim() : '');
        const bv = (b.cells[colIndex] ? b.cells[colIndex].innerText.trim() : '');
        const an = parseFloat(av), bn = parseFloat(bv);
        if (!isNaN(an) && !isNaN(bn)) return asc ? an - bn : bn - an;
        return asc ? av.localeCompare(bv) : bv.localeCompare(av);
    });
    rows.forEach(function (r) { tbody.appendChild(r); });
}

// ═══════════════════════════════════════════════════════════════════════════
// LOADING OVERLAY
// ═══════════════════════════════════════════════════════════════════════════

function showLoading() {
    const overlay = document.createElement('div');
    overlay.id = 'loadingOverlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;z-index:9999;';
    overlay.innerHTML = '<div style="color:#fff;font-size:20px;font-weight:600;">Loading…</div>';
    document.body.appendChild(overlay);
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.remove();
}

// ═══════════════════════════════════════════════════════════════════════════
// TOAST NOTIFICATIONS
// ═══════════════════════════════════════════════════════════════════════════

let _toastTimer = null;
function showToast(message, type) {
    type = type || 'info';
    let toast = document.getElementById('toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'toast';
        document.body.appendChild(toast);
    }
    toast.className = 'toast ' + type;
    toast.textContent = message;
    void toast.offsetWidth;
    toast.classList.add('show');
    if (_toastTimer) clearTimeout(_toastTimer);
    _toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 3200);
}

// ═══════════════════════════════════════════════════════════════════════════
// THEME + CHATBOT
// ═══════════════════════════════════════════════════════════════════════════

const THEME_STORAGE_KEY = 'exam-management-theme';

function normalizeRole(role) {
    const value = String(role || '').toLowerCase().trim();
    if (value === 'exam_cell') return 'admin';
    if (value === 'faculty' || value === 'student') return value;
    return 'admin';
}

function getBasePath() {
    const isModule = window.location.pathname.toLowerCase().includes('/modules/');
    return isModule ? '../' : '';
}

function applyTheme(theme) {
    const value = theme === 'dark' ? 'dark' : 'light';
    document.body.classList.toggle('theme-dark', value === 'dark');
    document.documentElement.dataset.theme = value;
    document.documentElement.style.colorScheme = value;
}

function refreshThemeToggleLabel() {
    const button = document.getElementById('theme-toggle-fab');
    if (!button) return;
    const isDark = document.body.classList.contains('theme-dark');
    button.innerHTML = isDark ? '☀' : '🌙';
    button.title = isDark ? 'Switch to light mode' : 'Switch to dark mode';
    button.setAttribute('aria-label', button.title);
}

function initThemePreference() {
    let saved = 'light';
    try {
        saved = localStorage.getItem(THEME_STORAGE_KEY) === 'dark' ? 'dark' : 'light';
    } catch (err) {
        saved = 'light';
    }

    applyTheme(saved);

    if (!document.getElementById('theme-toggle-fab')) {
        const button = document.createElement('button');
        button.id = 'theme-toggle-fab';
        button.type = 'button';
        button.className = 'theme-toggle-fab';
        button.addEventListener('click', function () {
            const nextTheme = document.body.classList.contains('theme-dark') ? 'light' : 'dark';
            try {
                localStorage.setItem(THEME_STORAGE_KEY, nextTheme);
            } catch (err) {}
            applyTheme(nextTheme);
            refreshThemeToggleLabel();
        });
        document.body.appendChild(button);
    }

    refreshThemeToggleLabel();
}

function getChatbotUiConfig(role) {
    if (role === 'student') {
        return {
            title: 'Student Assistant',
            prompt: 'Ask about seating, room number, admit card, datesheet, or attendance.',
            chips: ['my seat', 'exam schedule', 'admit card', 'attendance info']
        };
    }
    if (role === 'faculty') {
        return {
            title: 'Faculty Assistant',
            prompt: 'Ask about duty details, attendance, replacement, or room assignment.',
            chips: ['my duty', 'mark attendance', 'request replacement', 'room assignment']
        };
    }
    return {
        title: 'Admin Assistant',
        prompt: 'Ask about seating generation, room allocation, invigilation, reports, or scheduling.',
        chips: ['generate seating', 'room allocation', 'invigilation', 'attendance reports']
    };
}

function getChatbotReplies(role) {
    const replies = {
        admin: [
            { keywords: ['generate seating', 'seating plan', 'seat allocation', 'allocate seat'], response: 'Opening Seating Plan.', action_url: 'modules/seating.html' },
            { keywords: ['room allocation', 'room plan', 'room capacity'], response: 'Opening Rooms.', action_url: 'modules/rooms.html' },
            { keywords: ['invigilation', 'invigilator', 'duty assignment'], response: 'Opening Invigilation.', action_url: 'modules/invigilation.html' },
            { keywords: ['attendance report', 'attendance', 'reports', 'analytics'], response: 'Opening Reports.', action_url: 'modules/reports.html' },
            { keywords: ['replacement', 'replace faculty', 'replacement request'], response: 'Opening Replacement.', action_url: 'modules/replacement.html' },
            { keywords: ['exam schedule', 'datesheet', 'schedule'], response: 'Opening Schedule.', action_url: 'modules/schedule.html' },
            { keywords: ['help', 'what can you do', 'commands', 'options'], response: 'Available commands: seating generation, room allocation, invigilation, attendance reports, replacement requests, and exam scheduling.', action_url: null },
        ],
        faculty: [
            { keywords: ['my duty', 'duty details', 'invigilation duty', 'assigned room'], response: 'Opening your Invigilation Duties.', action_url: 'faculty/duties.php' },
            { keywords: ['mark attendance', 'attendance', 'present', 'absent', 'late'], response: 'Opening Attendance Marking.', action_url: 'faculty/attendance.php' },
            { keywords: ['request replacement', 'replacement', 'substitute', 'replace'], response: 'Opening Replacement Requests.', action_url: 'faculty/replacement.php' },
            { keywords: ['room assignment', 'assigned room', 'room number'], response: 'Opening your room assignment details.', action_url: 'faculty/duties.php' },
            { keywords: ['help', 'what can you do', 'commands', 'options'], response: 'Available commands: my duty, mark attendance, request replacement, and room assignment.', action_url: null },
        ],
        student: [
            { keywords: ['my seat', 'seating', 'seat number', 'room', 'where do i sit', 'seating slip'], response: 'Opening your Seating Slip.', action_url: 'modules/student_seating_slip.html' },
            { keywords: ['exam schedule', 'datesheet', 'timetable', 'schedule', 'exam date'], response: 'Opening your Datesheet.', action_url: 'modules/student_datesheet.html' },
            { keywords: ['admit card', 'hall ticket', 'admit'], response: 'Opening your Admit Card.', action_url: 'modules/student_admit_card.html' },
            { keywords: ['attendance', 'present', 'absent'], response: 'Attendance is view-only for students.', action_url: null },
            { keywords: ['help', 'what can you do', 'commands', 'options'], response: 'Available commands: my seat, exam schedule, admit card, and attendance info.', action_url: null },
        ],
    };

    return replies[role] || replies.admin;
}

function resolveChatbotReply(role, message) {
    const text = String(message || '').toLowerCase().trim();
    const rules = getChatbotReplies(normalizeRole(role));

    for (let i = 0; i < rules.length; i++) {
        const rule = rules[i];
        for (let j = 0; j < rule.keywords.length; j++) {
            if (text.includes(rule.keywords[j])) {
                return rule;
            }
        }
    }

    const fallback = {
        admin: 'Try: seating generation, room allocation, invigilation, attendance reports, replacement requests, or exam scheduling.',
        faculty: 'Try: my duty, mark attendance, request replacement, or room assignment.',
        student: 'Try: my seat, exam schedule, admit card, or attendance info.',
    };

    return {
        response: fallback[normalizeRole(role)],
        action_url: null,
    };
}

function addChatbotWidget(knownRole) {
    if (document.getElementById('chatbot-fab')) return;

    const base = getBasePath();
    const role = normalizeRole(knownRole || 'admin');
    const config = getChatbotUiConfig(role);

    const fab = document.createElement('button');
    fab.id = 'chatbot-fab';
    fab.className = 'chatbot-fab';
    fab.type = 'button';
    fab.title = 'Open Help Assistant';
    fab.setAttribute('aria-label', 'Open Help Assistant');
    fab.innerHTML = '💬';

    const panel = document.createElement('div');
    panel.id = 'chatbot-panel';
    panel.className = 'chatbot-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Help Assistant');

    panel.innerHTML =
        '<div class="chatbot-head">' +
        '  <div class="chatbot-head-info">' +
        '    <span class="chatbot-icon">🤖</span>' +
        '    <div>' +
        '      <strong>' + config.title + '</strong>' +
        '      <p>' + config.prompt + '</p>' +
        '    </div>' +
        '  </div>' +
        '  <button type="button" class="chatbot-close" id="chatbot-close" aria-label="Close">✕</button>' +
        '</div>' +
        '<div class="chatbot-body" id="chatbot-body"></div>' +
        '<div class="chatbot-chips" id="chatbot-chips"></div>' +
        '<form class="chatbot-input-row" id="chatbot-form">' +
        '  <input id="chatbot-text" type="text" placeholder="Type a command" autocomplete="off" aria-label="Chat input">' +
        '  <button type="submit" aria-label="Send">Send</button>' +
        '</form>';

    document.body.appendChild(fab);
    document.body.appendChild(panel);

    const chatBody  = document.getElementById('chatbot-body');
    const chatForm  = document.getElementById('chatbot-form');
    const chatInput = document.getElementById('chatbot-text');
    const closeBtn  = document.getElementById('chatbot-close');
    const chipsWrap = document.getElementById('chatbot-chips');

    function pushMsg(text, kind) {
        const bubble = document.createElement('div');
        bubble.className = 'chat-msg ' + kind;
        bubble.textContent = text;
        chatBody.appendChild(bubble);
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    function toAbsoluteAction(path) {
        if (!path) return '';
        if (/^https?:\/\//i.test(path)) return path;
        return base + path.replace(/^\/+/, '');
    }

    function handleInput(message) {
        if (!message.trim()) return;
        pushMsg(message, 'user');

        const reply = resolveChatbotReply(role, message);
        setTimeout(function () {
            pushMsg(String(reply.response || ''), 'bot');
            if (reply.action_url) {
                setTimeout(function () {
                    window.location.href = toAbsoluteAction(String(reply.action_url));
                }, 500);
            }
        }, 180);
    }

    config.chips.forEach(function (label) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'chatbot-chip';
        chip.textContent = label;
        chip.addEventListener('click', function () { handleInput(label); });
        chipsWrap.appendChild(chip);
    });

    pushMsg('Hello! ' + config.prompt, 'bot');

    fab.addEventListener('click', function () {
        panel.classList.toggle('open');
        if (panel.classList.contains('open')) {
            chatInput.focus();
        }
    });
    closeBtn.addEventListener('click', function () { panel.classList.remove('open'); });
    chatForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var msg = chatInput.value.trim();
        chatInput.value = '';
        handleInput(msg);
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// DATE / TIME / NUMBER FORMATTING
// ═══════════════════════════════════════════════════════════════════════════

function formatDate(dateString) {
    const d = new Date(dateString);
    return String(d.getDate()).padStart(2, '0') + '-' +
           String(d.getMonth() + 1).padStart(2, '0') + '-' +
           d.getFullYear();
}

function formatTime(timeString) {
    const parts = timeString.split(':');
    const hour  = parseInt(parts[0], 10);
    const ampm  = hour >= 12 ? 'PM' : 'AM';
    return (hour % 12 || 12) + ':' + parts[1] + ' ' + ampm;
}

function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}

// ═══════════════════════════════════════════════════════════════════════════
// MISC UTILITIES
// ═══════════════════════════════════════════════════════════════════════════

function debounce(func, wait) {
    let timeout;
    return function () {
        const args = arguments;
        clearTimeout(timeout);
        timeout = setTimeout(function () { func.apply(this, args); }, wait);
    };
}

function throttle(func, limit) {
    let inThrottle;
    return function () {
        if (!inThrottle) {
            func.apply(this, arguments);
            inThrottle = true;
            setTimeout(function () { inThrottle = false; }, limit);
        }
    };
}

function isInViewport(element) {
    const rect = element.getBoundingClientRect();
    return rect.top >= 0 && rect.left >= 0 &&
           rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
           rect.right  <= (window.innerWidth  || document.documentElement.clientWidth);
}

function scrollToElement(elementId) {
    const el = document.getElementById(elementId);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function copyToClipboard(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0;';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    showToast('Copied to clipboard!', 'success');
}

function generateId(prefix) {
    prefix = prefix || 'id';
    return prefix + '_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

function initTooltips() {
    document.querySelectorAll('[data-tooltip]').forEach(function (el) {
        el.addEventListener('mouseenter', function () {
            const tip = document.createElement('div');
            tip.className = 'tooltip';
            tip.innerText = this.dataset.tooltip;
            tip.style.cssText = 'position:absolute;background:#333;color:#fff;padding:4px 8px;border-radius:4px;font-size:12px;z-index:9999;pointer-events:none;';
            document.body.appendChild(tip);
            const rect = this.getBoundingClientRect();
            tip.style.top  = (rect.top  - tip.offsetHeight - 5) + 'px';
            tip.style.left = (rect.left + rect.width / 2 - tip.offsetWidth / 2) + 'px';
            this.addEventListener('mouseleave', function () { tip.remove(); }, { once: true });
        });
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// NOTIFICATION AUTO-SCROLL (always-on, no stop on hover)
// Call after rendering notification items into a container.
// The container must have overflow:hidden and a fixed height.
// Items are duplicated inside so the scroll loops seamlessly.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * startAutoScroll(container, speed)
 * container — the overflow:hidden wrapper element
 * speed     — pixels per animation frame (default 0.5 — smooth, decent pace)
 *
 * Expects container to already have its items rendered.
 * Duplicates the inner content for seamless looping.
 */
function startAutoScroll(container, speed) {
    if (!container) return;
    speed = speed || 0.5;

    // Wrap existing children in a scrolling inner div if not already done
    var inner = container.querySelector('.auto-scroll-inner');
    if (!inner) {
        inner = document.createElement('div');
        inner.className = 'auto-scroll-inner';
        inner.style.cssText = 'display:flex;flex-direction:column;';
        // Move all children into inner
        while (container.firstChild) {
            inner.appendChild(container.firstChild);
        }
        container.appendChild(inner);
    }

    // Only scroll if content overflows
    if (inner.scrollHeight <= container.clientHeight) return;

    // Duplicate content for seamless loop
    var clone = inner.cloneNode(true);
    clone.setAttribute('aria-hidden', 'true');
    container.appendChild(clone);

    var pos = 0;
    var halfH = inner.scrollHeight;

    function tick() {
        pos += speed;
        if (pos >= halfH) pos = 0;
        inner.style.transform = 'translateY(-' + pos + 'px)';
        clone.style.transform = 'translateY(-' + pos + 'px)';
        requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
}

// Legacy alias — replaces the old hover-triggered version used in student_portal.html
// and faculty_portal.html. Now always scrolls, never stops.
function setupNotificationAutoScroll(element) {
    if (!element) return;
    // Only start if there's content to scroll
    if (element.scrollHeight <= element.clientHeight) return;

    var pos = 0;
    var speed = 0.5;

    function tick() {
        pos += speed;
        if (pos >= element.scrollHeight - element.clientHeight) {
            pos = 0; // jump back to top seamlessly
        }
        element.scrollTop = pos;
        requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
}

// ── CommonJS export (for Node.js scripts) ────────────────────────────────────
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        validateForm, confirmDelete, printPage, exportTableToCSV,
        filterTable, sortTable, resetForm, toggleElement,
        showLoading, hideLoading, showToast,
        formatDate, formatTime, formatNumber,
        debounce, throttle, isInViewport, scrollToElement, copyToClipboard, generateId,
        startAutoScroll, setupNotificationAutoScroll
    };
}
