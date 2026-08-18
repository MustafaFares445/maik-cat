(() => {
    document.documentElement.classList.add('js');

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    const revealAll = () => {
        document.querySelectorAll('.reveal').forEach((element) => element.classList.add('visible'));
    };

    const boot = () => {
        requestAnimationFrame(() => document.body.classList.add('ready'));

        if (prefersReducedMotion.matches || !('IntersectionObserver' in window)) {
            revealAll();
            return;
        }

        const observer = new IntersectionObserver((entries, currentObserver) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('visible');
                currentObserver.unobserve(entry.target);
            });
        }, {
            threshold: 0.12,
            rootMargin: '0px 0px -6% 0px',
        });

        document.querySelectorAll('.reveal').forEach((element) => observer.observe(element));
    };

    document.addEventListener('visibilitychange', () => {
        document.body.classList.toggle('animation-paused', document.hidden);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
