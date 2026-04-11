/* ══════════════════════════════════════════
   ERIKA MEDIA — Website JavaScript
   ══════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', function () {

    /* ── Navbar: scroll effect ─────────────── */
    var navbar = document.getElementById('navbar');
    window.addEventListener('scroll', function () {
        if (window.scrollY > 40) {
            navbar.style.background = 'rgba(13,27,62,0.99)';
        } else {
            navbar.style.background = 'rgba(13,27,62,0.97)';
        }
    });

    /* ── Mobile menu toggle ────────────────── */
    var toggle = document.getElementById('nav-toggle');
    var navLinks = document.getElementById('nav-links');

    toggle.addEventListener('click', function () {
        navLinks.classList.toggle('open');
        var bars = toggle.querySelectorAll('span');
        if (navLinks.classList.contains('open')) {
            bars[0].style.transform = 'translateY(7px) rotate(45deg)';
            bars[1].style.opacity   = '0';
            bars[2].style.transform = 'translateY(-7px) rotate(-45deg)';
        } else {
            bars[0].style.transform = '';
            bars[1].style.opacity   = '';
            bars[2].style.transform = '';
        }
    });

    navLinks.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            navLinks.classList.remove('open');
            var bars = toggle.querySelectorAll('span');
            bars[0].style.transform = '';
            bars[1].style.opacity   = '';
            bars[2].style.transform = '';
        });
    });

    /* ── Smooth scroll ─────────────────────── */
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                var offset = 70;
                var top = target.getBoundingClientRect().top + window.scrollY - offset;
                window.scrollTo({ top: top, behavior: 'smooth' });
            }
        });
    });

    /* ── Scroll animations ─────────────────── */
    var animateEls = document.querySelectorAll('[data-animate]');
    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                // Stagger children in the same parent grid
                var siblings = entry.target.parentElement.querySelectorAll('[data-animate]');
                var index = Array.from(siblings).indexOf(entry.target);
                entry.target.style.transitionDelay = (index * 0.1) + 's';
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });

    animateEls.forEach(function (el) { observer.observe(el); });

    /* ── Counter animation ─────────────────── */
    var counters = document.querySelectorAll('.stat-number');
    var countObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                var el     = entry.target;
                var target = parseInt(el.getAttribute('data-count'), 10);
                var start  = 0;
                var step   = Math.ceil(target / 60);
                var timer  = setInterval(function () {
                    start += step;
                    if (start >= target) { start = target; clearInterval(timer); }
                    el.textContent = start;
                }, 25);
                countObserver.unobserve(el);
            }
        });
    }, { threshold: 0.5 });

    counters.forEach(function (c) { countObserver.observe(c); });

    /* ── Services tab switching ────────────── */
    var tabBtns   = document.querySelectorAll('.tab-btn');
    var tabDigital   = document.getElementById('tab-digital');
    var tabRecruiting = document.getElementById('tab-recruiting');

    tabBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabBtns.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');

            if (btn.getAttribute('data-tab') === 'digital') {
                tabDigital.style.display   = 'grid';
                tabRecruiting.style.display = 'none';
            } else {
                tabDigital.style.display   = 'none';
                tabRecruiting.style.display = 'grid';
                // Re-trigger animations for newly visible cards
                tabRecruiting.querySelectorAll('[data-animate]').forEach(function (el) {
                    el.classList.add('visible');
                });
            }
        });
    });

    /* ── "Find Your Next Job" hero link ────── */
    var recruitingLink = document.querySelector('a[href="#recruiting"]');
    if (recruitingLink) {
        recruitingLink.addEventListener('click', function (e) {
            e.preventDefault();
            // Switch to recruiting tab
            tabBtns.forEach(function (b) { b.classList.remove('active'); });
            document.querySelector('[data-tab="recruiting"]').classList.add('active');
            tabDigital.style.display   = 'none';
            tabRecruiting.style.display = 'grid';
            tabRecruiting.querySelectorAll('[data-animate]').forEach(function (el) {
                el.classList.add('visible');
            });
            // Scroll to services
            var target = document.getElementById('services');
            if (target) {
                var top = target.getBoundingClientRect().top + window.scrollY - 70;
                window.scrollTo({ top: top, behavior: 'smooth' });
            }
        });
    }

    /* ── Process tab switching ─────────────── */
    var procTabs      = document.querySelectorAll('.proc-tab');
    var procDigital   = document.getElementById('digital-proc');
    var procRecruit   = document.getElementById('recruit-proc');

    procTabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            procTabs.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');

            if (btn.getAttribute('data-proc') === 'digital-proc') {
                procDigital.style.display = 'grid';
                procRecruit.style.display = 'none';
            } else {
                procDigital.style.display = 'none';
                procRecruit.style.display = 'grid';
                procRecruit.querySelectorAll('[data-animate]').forEach(function (el) {
                    el.classList.add('visible');
                });
            }
        });
    });

    /* ── Contact form ──────────────────────── */
    var form    = document.getElementById('contact-form');
    var success = document.getElementById('form-success');

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var btn = form.querySelector('button[type="submit"]');
            var originalText = btn.innerHTML;
            btn.innerHTML = 'Sending&hellip;';
            btn.disabled  = true;

            // Simulate send (replace with your backend / Formspree endpoint)
            setTimeout(function () {
                form.reset();
                btn.innerHTML = originalText;
                btn.disabled  = false;
                success.style.display = 'block';
                setTimeout(function () { success.style.display = 'none'; }, 5000);
            }, 1200);
        });
    }

    /* ── Active nav link on scroll ─────────── */
    var sections = document.querySelectorAll('section[id]');
    var navAnchors = document.querySelectorAll('.nav-links a');

    window.addEventListener('scroll', function () {
        var scrollPos = window.scrollY + 100;
        sections.forEach(function (sec) {
            if (sec.offsetTop <= scrollPos && (sec.offsetTop + sec.offsetHeight) > scrollPos) {
                navAnchors.forEach(function (a) {
                    a.style.color = '';
                    if (a.getAttribute('href') === '#' + sec.id) {
                        a.style.color = 'white';
                    }
                });
            }
        });
    });

});
