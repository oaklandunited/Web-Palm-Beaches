/* === WEB PALM BEACHES — SHARED JS === */
(function() {
  'use strict';

  // Cache DOM elements
  const nav = document.getElementById('nav');
  const navLinks = document.querySelector('.nav-links');
  const mobileToggle = document.querySelector('.mobile-toggle');

  // Throttle helper for scroll events
  let ticking = false;
  function throttleScroll(callback) {
    if (!ticking) {
      requestAnimationFrame(() => {
        callback();
        ticking = false;
      });
      ticking = true;
    }
  }

  // Nav scroll effect (throttled)
  if (nav) {
    window.addEventListener('scroll', () => {
      throttleScroll(() => {
        nav.classList.toggle('scrolled', window.scrollY > 50);
      });
    }, { passive: true });
  }

  // Mobile menu toggle with event delegation and WCAG ARIA state
  if (mobileToggle && navLinks) {
    if (!navLinks.id) navLinks.id = 'primary-nav-links';
    mobileToggle.setAttribute('aria-controls', navLinks.id);
    mobileToggle.setAttribute('aria-expanded', 'false');

    const setMenuOpen = (open) => {
      navLinks.classList.toggle('show', open);
      mobileToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    mobileToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      setMenuOpen(!navLinks.classList.contains('show'));
    });

    // Close mobile menu on link click (event delegation)
    navLinks.addEventListener('click', (e) => {
      if (e.target.closest('a')) {
        setMenuOpen(false);
      }
    });

    // Close on Escape or outside click
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && navLinks.classList.contains('show')) {
        setMenuOpen(false);
        mobileToggle.focus();
      }
    });
    document.addEventListener('click', (e) => {
      if (navLinks.classList.contains('show') && nav && !nav.contains(e.target)) {
        setMenuOpen(false);
      }
    });
    window.addEventListener('resize', () => {
      if (window.innerWidth > 1200 && navLinks.classList.contains('show')) {
        setMenuOpen(false);
      }
    });
  }

  // Scroll reveal with optimized observer
  const revealEls = document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .reveal-scale');
  if (revealEls.length) {
    const revealObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          revealObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
    revealEls.forEach(el => revealObserver.observe(el));
  }

  // Smooth scroll with event delegation
  document.addEventListener('click', (e) => {
    const link = e.target.closest('a[href^="#"]');
    if (!link) return;
    const href = link.getAttribute('href');
    if (href === '#') return;
    const target = document.querySelector(href);
    if (target) {
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });
})();

// Tab system with event delegation and ARIA keyboard support
(function() {
  'use strict';
  const tabsContainer = document.querySelector('.tabs');
  if (!tabsContainer) return;

  const tabBtns = Array.from(tabsContainer.querySelectorAll('.tab-btn'));
  const tabPanels = document.querySelectorAll('.tab-panel');

  tabsContainer.setAttribute('role', 'tablist');
  tabBtns.forEach((btn) => {
    const isActive = btn.classList.contains('active');
    btn.setAttribute('role', 'tab');
    btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    btn.setAttribute('tabindex', isActive ? '0' : '-1');
    if (btn.dataset.tab) {
      btn.setAttribute('aria-controls', btn.dataset.tab);
      const panel = document.getElementById(btn.dataset.tab);
      if (panel) panel.setAttribute('role', 'tabpanel');
    }
  });

  function activateTab(btn, focusBtn) {
    const target = btn.dataset.tab;
    tabBtns.forEach(b => {
      b.classList.remove('active');
      b.setAttribute('aria-selected', 'false');
      b.setAttribute('tabindex', '-1');
    });
    tabPanels.forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    btn.setAttribute('aria-selected', 'true');
    btn.setAttribute('tabindex', '0');
    if (focusBtn) btn.focus();

    const panel = document.getElementById(target);
    if (panel) panel.classList.add('active');
  }

  tabsContainer.addEventListener('click', (e) => {
    const btn = e.target.closest('.tab-btn');
    if (!btn) return;
    activateTab(btn, false);
  });

  tabsContainer.addEventListener('keydown', (e) => {
    const currentIdx = tabBtns.indexOf(document.activeElement);
    if (currentIdx === -1) return;
    let nextIdx = -1;
    if (e.key === 'ArrowRight') nextIdx = (currentIdx + 1) % tabBtns.length;
    else if (e.key === 'ArrowLeft') nextIdx = (currentIdx - 1 + tabBtns.length) % tabBtns.length;
    else if (e.key === 'Home') nextIdx = 0;
    else if (e.key === 'End') nextIdx = tabBtns.length - 1;
    if (nextIdx > -1) {
      e.preventDefault();
      activateTab(tabBtns[nextIdx], true);
    }
  });
})();

// Accordion system with event delegation and WCAG keyboard support
(function() {
  'use strict';
  const accordionContainers = document.querySelectorAll('.accordion');
  if (!accordionContainers.length) return;

  accordionContainers.forEach((accordionContainer) => {
    const headers = accordionContainer.querySelectorAll('.accordion-header');
    headers.forEach((header) => {
      const item = header.parentElement;
      const isOpen = item.classList.contains('open');
      if (header.tagName !== 'BUTTON') {
        header.setAttribute('role', 'button');
        header.setAttribute('tabindex', '0');
      }
      header.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    function toggleHeader(header) {
      const item = header.parentElement;
      const wasOpen = item.classList.contains('open');

      const siblings = item.parentElement.querySelectorAll('.accordion-item.open');
      siblings.forEach(i => {
        i.classList.remove('open');
        const h = i.querySelector('.accordion-header');
        if (h) h.setAttribute('aria-expanded', 'false');
      });

      if (!wasOpen) {
        item.classList.add('open');
        header.setAttribute('aria-expanded', 'true');
      } else {
        header.setAttribute('aria-expanded', 'false');
      }
    }

    accordionContainer.addEventListener('click', (e) => {
      const header = e.target.closest('.accordion-header');
      if (!header) return;
      toggleHeader(header);
    });

    accordionContainer.addEventListener('keydown', (e) => {
      const header = e.target.closest('.accordion-header');
      if (!header) return;
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        toggleHeader(header);
      }
    });
  });
})();

// Counter animation with requestAnimationFrame
(function() {
  'use strict';
  function animateCounters() {
    const counters = document.querySelectorAll('[data-count]');
    counters.forEach(el => {
      const target = parseInt(el.dataset.count);
      const suffix = el.dataset.suffix || '';
      const prefix = el.dataset.prefix || '';
      const duration = 1500;
      const startTime = performance.now();

      function update(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        const current = target * progress;

        el.textContent = prefix + Math.round(current) + suffix;

        if (progress < 1) {
          requestAnimationFrame(update);
        }
      }

      requestAnimationFrame(update);
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
})();

// Parallax effect with throttling
(function() {
  'use strict';
  const pageHero = document.querySelector('.page-hero');
  if (!pageHero) return;

  let rafId = null;
  window.addEventListener('scroll', () => {
    if (rafId) return;
    rafId = requestAnimationFrame(() => {
      const scrolled = window.scrollY;
      if (scrolled < 600) {
        pageHero.style.backgroundPositionY = scrolled * 0.3 + 'px';
      }
      rafId = null;
    });
  }, { passive: true });
})();

// ============================================================
// SITE-WIDE SEARCH
// ============================================================
(function() {
  'use strict';
  // Inject search button — inside .nav-links (before the phone link) on desktop,
  // and as a standalone icon sibling for mobile (before .mobile-toggle)
  const navLinks = document.querySelector('.nav-links');
  const mobileBtn = document.querySelector('.mobile-toggle');
  const nav = document.getElementById('nav');

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
  if (mobileBtn && nav) {
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
  let debounceTimer = null;
  let lastFocusedEl = null;

  // Load index (fetch once, cache)
  function loadIndex() {
    if (searchIndex) return Promise.resolve(searchIndex);
    return fetch('search-index.json')
      .then(r => r.json())
      .then(data => { searchIndex = data; return data; });
  }

  function openSearch() {
    lastFocusedEl = document.activeElement;
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    input.value = '';
    results.innerHTML = '';
    activeIdx = -1;
    loadIndex();
    setTimeout(() => input.focus(), 50);
  }

  function closeSearch() {
    overlay.classList.remove('open');
    document.body.style.overflow = '';
    input.value = '';
    results.innerHTML = '';
    activeIdx = -1;
    if (lastFocusedEl && typeof lastFocusedEl.focus === 'function') {
      lastFocusedEl.focus();
    }
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
    items.forEach((el, i) => {
      const isActive = i === activeIdx;
      el.classList.toggle('active', isActive);
      el.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    if (items[activeIdx]) items[activeIdx].scrollIntoView({ block: 'nearest' });
  }

  // Search logic with memoization
  const highlightCache = new Map();
  function highlight(text, terms) {
    if (!text) return '';
    const cacheKey = text + '|' + terms.join('|');
    if (highlightCache.has(cacheKey)) return highlightCache.get(cacheKey);

    let safe = text.replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));
    terms.forEach(t => {
      const escaped = t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      safe = safe.replace(new RegExp('(' + escaped + ')', 'gi'), '<mark>$1</mark>');
    });

    highlightCache.set(cacheKey, safe);
    return safe;
  }

  function score(entry, terms) {
    let s = 0;
    const lTitle = entry.title.toLowerCase();
    const lDesc = entry.description.toLowerCase();
    const lHeadings = (entry.headings || []).join(' ').toLowerCase();
    const lBody = (entry.body || '').toLowerCase();
    for (let i = 0; i < terms.length; i++) {
      const tl = terms[i].toLowerCase();
      if (lTitle.includes(tl)) s += 20;
      else if (lDesc.includes(tl)) s += 10;
      else if (lHeadings.includes(tl)) s += 8;
      else if (lBody.includes(tl)) s += 3;
    }
    return s;
  }

  function getSnippet(entry, terms) {
    const text = entry.description || entry.body || '';
    if (!text) return '';
    const lText = text.toLowerCase();
    let bestPos = 0;
    for (let i = 0; i < terms.length; i++) {
      const pos = lText.indexOf(terms[i].toLowerCase());
      if (pos > -1) bestPos = Math.max(0, pos - 40);
    }
    let snippet = text.substring(bestPos, bestPos + 140);
    if (bestPos > 0) snippet = '\u2026' + snippet;
    if (bestPos + 140 < text.length) snippet += '\u2026';
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

    // Debounce search
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      loadIndex().then(data => {
        const terms = query.toLowerCase().split(/\s+/).filter(t => t.length > 1);
        if (!terms.length) { results.innerHTML = ''; return; }

        const scored = data
          .map(entry => ({ entry, s: score(entry, terms) }))
          .filter(x => x.s > 0)
          .sort((a, b) => b.s - a.s)
          .slice(0, 8);

        if (!scored.length) {
          results.innerHTML = '<div class="search-empty"><strong>No results found</strong>Try different keywords or browse the <a href="blog.html" style="color:var(--accent-ink)">blog</a> or <a href="resources.html" style="color:var(--accent-ink)">resources</a>.</div>';
          return;
        }

        results.innerHTML = scored.map(({ entry }) => {
          const icon = sectionIcons[entry.section] || sectionIcons['default'];
          const snippet = getSnippet(entry, terms);
          return '<a class="search-result" href="' + entry.url + '" data-url="' + entry.url + '" role="option">' +
            '<div class="search-result-icon">' + icon + '</div>' +
            '<div class="search-result-body">' +
            '<div class="search-result-title">' + highlight(entry.title, terms) + '</div>' +
            '<div class="search-result-desc">' + highlight(snippet, terms) + '</div>' +
            '</div>' +
            '<div class="search-result-tag">' + entry.section + '</div>' +
            '</a>';
        }).join('');
      });
    }, 150);
  });
})();

// === LIGHT / DARK THEME TOGGLE ===
// The <head> snippet has already set data-theme before first paint. This adds
// the buttons next to the search icon (desktop inside .nav-links, mobile beside
// the hamburger, same pattern as search), syncs labels, and saves the choice.
(function() {
  'use strict';
  const root = document.documentElement;
  const KEY = 'wpb-theme';
  const nav = document.getElementById('nav');
  if (!nav) return;
  const navLinks = nav.querySelector('.nav-links');
  const mobileBtn = nav.querySelector('.mobile-toggle');
  const ICONS =
    '<svg class="i-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>' +
    '<svg class="i-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4.2"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
  const buttons = [];
  const current = () => root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';

  const sync = () => {
    const next = current() === 'dark' ? 'light' : 'dark';
    buttons.forEach(b => { b.setAttribute('aria-label', 'Switch to ' + next + ' theme'); b.title = 'Switch to ' + next + ' theme'; });
  };
  const setTheme = (t, persist) => {
    root.classList.add('theme-anim');
    root.setAttribute('data-theme', t);
    sync();
    if (persist) { try { localStorage.setItem(KEY, t); } catch (e) {} }
    setTimeout(() => root.classList.remove('theme-anim'), 400);
  };
  const makeBtn = (cls) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'theme-toggle ' + cls;
    b.innerHTML = ICONS;
    b.addEventListener('click', () => setTheme(current() === 'dark' ? 'light' : 'dark', true));
    buttons.push(b);
    return b;
  };

  if (navLinks) {
    const phone = navLinks.querySelector('.nav-phone');
    navLinks.insertBefore(makeBtn('theme-toggle-desktop'), phone || null);
  }
  if (mobileBtn) nav.insertBefore(makeBtn('theme-toggle-mobile'), mobileBtn);

  // Follow OS changes live, but only until the visitor picks a theme themselves
  if (window.matchMedia) {
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = (e) => {
      let saved = null; try { saved = localStorage.getItem(KEY); } catch (err) {}
      if (!saved) setTheme(e.matches ? 'dark' : 'light', false);
    };
    if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
  }
  sync();
})();
