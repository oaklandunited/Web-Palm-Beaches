/* === WEB PALM BEACHES — SHARED JS === */

const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Nav scroll effect — IntersectionObserver sentinel (no per-frame scroll handler)
const nav = document.getElementById('nav');
if (nav) {
  const sentinel = document.createElement('div');
  sentinel.setAttribute('aria-hidden', 'true');
  sentinel.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:50px;pointer-events:none';
  document.body.prepend(sentinel);
  new IntersectionObserver(([entry]) => {
    nav.classList.toggle('scrolled', !entry.isIntersecting);
  }, { threshold: 0 }).observe(sentinel);
}

// Mobile menu toggle
const mobileToggle = document.querySelector('.mobile-toggle');
if (mobileToggle) {
  mobileToggle.addEventListener('click', () => {
    document.querySelector('.nav-links').classList.toggle('show');
  });
}

// Close mobile menu on link click
document.querySelectorAll('.nav-links a').forEach(a => {
  a.addEventListener('click', () => {
    document.querySelector('.nav-links').classList.remove('show');
  });
});

// Scroll reveal (supports .reveal, .reveal-left, .reveal-right, .reveal-scale)
// js-reveal flag tells CSS it is safe to hide-then-animate; without JS, content stays visible.
document.documentElement.classList.add('js-reveal');
const revealEls = document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .reveal-scale');
if (prefersReducedMotion) {
  revealEls.forEach(el => el.classList.add('visible'));
} else {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
  revealEls.forEach(el => observer.observe(el));
}

// Smooth scroll for same-page anchor links
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', (e) => {
    const href = a.getAttribute('href');
    if (href === '#') return;
    e.preventDefault();
    const target = document.querySelector(href);
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

// Tab system
function initTabs() {
  const tabBtns = document.querySelectorAll('.tab-btn');
  const tabPanels = document.querySelectorAll('.tab-panel');
  if (!tabBtns.length) return;

  tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.tab;
      tabBtns.forEach(b => b.classList.remove('active'));
      tabPanels.forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById(target).classList.add('active');
    });
  });
}
initTabs();

// Accordion system
function initAccordions() {
  const headers = document.querySelectorAll('.accordion-header');
  if (!headers.length) return;

  headers.forEach(header => {
    header.addEventListener('click', () => {
      const item = header.parentElement;
      const wasOpen = item.classList.contains('open');

      // Close all in same group
      item.parentElement.querySelectorAll('.accordion-item').forEach(i => {
        i.classList.remove('open');
      });

      if (!wasOpen) item.classList.add('open');
    });
  });
}
initAccordions();

// Counter animation
function animateCounters() {
  const counters = document.querySelectorAll('[data-count]');
  counters.forEach(el => {
    const target = parseInt(el.dataset.count);
    const suffix = el.dataset.suffix || '';
    const prefix = el.dataset.prefix || '';
    const duration = 1500;
    const steps = 50;
    const stepTime = duration / steps;
    let current = 0;
    const increment = target / steps;

    const timer = setInterval(() => {
      current += increment;
      if (current >= target) {
        current = target;
        clearInterval(timer);
      }
      el.textContent = prefix + Math.round(current) + suffix;
    }, stepTime);
  });
}

const counterObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      animateCounters();
      counterObserver.disconnect();
    }
  });
}, { threshold: 0.3 });

const counterSection = document.querySelector('.counters-row');
if (counterSection) counterObserver.observe(counterSection);

// Hero stat counter (homepage .hero-stats .stat-number, e.g. "20+", "100+")
function animateHeroStats() {
  document.querySelectorAll('.hero-stats .stat-number').forEach(el => {
    const text = el.textContent;
    const match = text.match(/(\d+)/);
    if (!match) return;
    const target = parseInt(match[0], 10);
    const suffix = text.slice(match.index + match[0].length);
    const prefix = text.slice(0, match.index);
    const increment = Math.max(1, Math.ceil(target / 40));
    let current = 0;
    const timer = setInterval(() => {
      current += increment;
      if (current >= target) {
        current = target;
        clearInterval(timer);
      }
      el.textContent = prefix + current + suffix;
    }, 30);
  });
}

const heroStats = document.querySelector('.hero-stats');
if (heroStats) {
  if (prefersReducedMotion) {
    // leave the final values in place
  } else {
    const heroStatsObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          animateHeroStats();
          heroStatsObserver.disconnect();
        }
      });
    }, { threshold: 0.5 });
    heroStatsObserver.observe(heroStats);
  }
}

// ============================================================
// SITE-WIDE SEARCH
// ============================================================
(function() {
  // Inject search button — inside .nav-links (before the phone link) on desktop,
  // and as a standalone icon sibling for mobile (before .mobile-toggle)
  const navLinks = document.querySelector('.nav-links');
  const mobileBtn = document.querySelector('.mobile-toggle');

  const makeBtn = (cls) => {
    const btn = document.createElement('button');
    btn.className = cls;
    btn.setAttribute('aria-label', 'Open search');
    btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>';
    btn.addEventListener('click', openSearch);
    return btn;
  };

  // Desktop: inject inside nav-links, before the phone number link
  if (navLinks) {
    const phoneLink = navLinks.querySelector('.nav-phone');
    const desktopBtn = makeBtn('search-toggle search-toggle-desktop');
    navLinks.insertBefore(desktopBtn, phoneLink || navLinks.firstChild);
  }

  // Mobile: inject as nav sibling before hamburger (visible only on mobile via CSS)
  if (mobileBtn) {
    const mobileSearchBtn = makeBtn('search-toggle search-toggle-mobile');
    nav.insertBefore(mobileSearchBtn, mobileBtn);
  }

  // Inject overlay HTML into body
  const overlay = document.createElement('div');
  overlay.id = 'search-overlay';
  overlay.className = 'search-overlay';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-label', 'Site search');
  overlay.innerHTML = `
    <div class="search-backdrop"></div>
    <div class="search-modal">
      <div class="search-box">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <input type="search" id="search-input" placeholder="Search pages, guides, and blog posts…" autocomplete="off" spellcheck="false">
        <button class="search-close" aria-label="Close search">&#10005;</button>
      </div>
      <div class="search-hint">
        <span>&#9166; to visit &nbsp;·&nbsp; &#8593;&#8595; to navigate &nbsp;·&nbsp; esc to close</span>
      </div>
      <div id="search-results" class="search-results" role="listbox"></div>
    </div>`;
  document.body.appendChild(overlay);

  const input = overlay.querySelector('#search-input');
  const results = overlay.querySelector('#search-results');
  const closeBtn = overlay.querySelector('.search-close');
  const backdrop = overlay.querySelector('.search-backdrop');

  let searchIndex = null;
  let activeIdx = -1;

  // Load index (fetch once, cache)
  function loadIndex() {
    if (searchIndex) return Promise.resolve(searchIndex);
    // Resolve path relative to site root regardless of current page depth
    const base = document.querySelector('base') ? document.querySelector('base').href : window.location.origin + '/';
    return fetch('search-index.json')
      .then(r => r.json())
      .then(data => { searchIndex = data; return data; });
  }

  function openSearch() {
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    input.value = '';
    results.innerHTML = '';
    activeIdx = -1;
    // Pre-load index silently
    loadIndex();
    // Small delay so transition completes before focus
    setTimeout(() => input.focus(), 50);
  }

  function closeSearch() {
    overlay.classList.remove('open');
    document.body.style.overflow = '';
    input.value = '';
    results.innerHTML = '';
    activeIdx = -1;
  }

  closeBtn.addEventListener('click', closeSearch);
  backdrop.addEventListener('click', closeSearch);

  // Keyboard: Escape, arrows, Enter
  document.addEventListener('keydown', e => {
    if (e.key === '/' && !overlay.classList.contains('open') &&
        !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) {
      e.preventDefault();
      openSearch();
      return;
    }
    if (!overlay.classList.contains('open')) return;
    if (e.key === 'Escape') { closeSearch(); return; }
    const items = results.querySelectorAll('.search-result');
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      activeIdx = Math.min(activeIdx + 1, items.length - 1);
      updateActive(items);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      activeIdx = Math.max(activeIdx - 1, 0);
      updateActive(items);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const active = items[activeIdx] || items[0];
      if (active) window.location.href = active.dataset.url;
    }
  });

  function updateActive(items) {
    items.forEach((el, i) => el.classList.toggle('active', i === activeIdx));
    if (items[activeIdx]) items[activeIdx].scrollIntoView({ block: 'nearest' });
  }

  // Search logic
  function highlight(text, terms) {
    if (!text) return '';
    let safe = text.replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));
    terms.forEach(t => {
      const escaped = t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      safe = safe.replace(new RegExp(`(${escaped})`, 'gi'), '<mark>$1</mark>');
    });
    return safe;
  }

  function score(entry, terms) {
    let s = 0;
    const lTitle = entry.title.toLowerCase();
    const lDesc = entry.description.toLowerCase();
    const lHeadings = (entry.headings || []).join(' ').toLowerCase();
    const lBody = (entry.body || '').toLowerCase();
    terms.forEach(t => {
      const tl = t.toLowerCase();
      if (lTitle.includes(tl)) s += 20;
      if (lDesc.includes(tl)) s += 10;
      if (lHeadings.includes(tl)) s += 8;
      if (lBody.includes(tl)) s += 3;
    });
    return s;
  }

  function getSnippet(entry, terms) {
    const text = entry.description || entry.body || '';
    if (!text) return '';
    // Find best position
    const lText = text.toLowerCase();
    let bestPos = 0;
    terms.forEach(t => {
      const pos = lText.indexOf(t.toLowerCase());
      if (pos > -1) bestPos = Math.max(0, pos - 40);
    });
    let snippet = text.substring(bestPos, bestPos + 140);
    if (bestPos > 0) snippet = '…' + snippet;
    if (bestPos + 140 < text.length) snippet += '…';
    return snippet;
  }

  const sectionIcons = {
    'Home': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'Services': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
    'AI Solutions': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/></svg>',
    'Blog': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
    'Resources': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>',
    'Industries': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg>',
    'Locations': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
    'default': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
  };

  input.addEventListener('input', () => {
    const query = input.value.trim();
    activeIdx = -1;

    if (query.length < 2) {
      results.innerHTML = '';
      return;
    }

    loadIndex().then(data => {
      const terms = query.toLowerCase().split(/\s+/).filter(t => t.length > 1);
      if (!terms.length) { results.innerHTML = ''; return; }

      const scored = data
        .map(entry => ({ entry, s: score(entry, terms) }))
        .filter(x => x.s > 0)
        .sort((a, b) => b.s - a.s)
        .slice(0, 8);

      if (!scored.length) {
        results.innerHTML = `<div class="search-empty"><strong>No results found</strong>Try different keywords or browse the <a href="blog.html" style="color:var(--accent)">blog</a> or <a href="resources.html" style="color:var(--accent)">resources</a>.</div>`;
        return;
      }

      results.innerHTML = scored.map(({ entry }) => {
        const icon = sectionIcons[entry.section] || sectionIcons['default'];
        const snippet = getSnippet(entry, terms);
        return `<a class="search-result" href="${entry.url}" data-url="${entry.url}" role="option">
          <div class="search-result-icon">${icon}</div>
          <div class="search-result-body">
            <div class="search-result-title">${highlight(entry.title, terms)}</div>
            <div class="search-result-desc">${highlight(snippet, terms)}</div>
          </div>
          <div class="search-result-tag">${entry.section}</div>
        </a>`;
      }).join('');
    });
  });
})();
