document.documentElement.classList.add('js');

(() => {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const body = document.body;
    const revealItems = [...document.querySelectorAll('.reveal')];
    const heroVisual = document.querySelector('[data-parallax]');

    requestAnimationFrame(() => body.classList.add('ready'));

    if (!reducedMotion.matches && 'IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.16, rootMargin: '0px 0px -7% 0px' });

        revealItems.forEach((item) => observer.observe(item));
    } else {
        revealItems.forEach((item) => item.classList.add('visible'));
    }

    if (heroVisual && !reducedMotion.matches) {
        const reset = () => {
            heroVisual.style.setProperty('--px', '0px');
            heroVisual.style.setProperty('--py', '0px');
        };

        heroVisual.addEventListener('pointermove', (event) => {
            if (event.pointerType === 'touch') return;
            const rect = heroVisual.getBoundingClientRect();
            const x = ((event.clientX - rect.left) / rect.width - 0.5) * 8;
            const y = ((event.clientY - rect.top) / rect.height - 0.5) * 8;
            heroVisual.style.setProperty('--px', `${x}px`);
            heroVisual.style.setProperty('--py', `${y}px`);
        });
        heroVisual.addEventListener('pointerleave', reset);
    }

    document.addEventListener('visibilitychange', () => {
        body.classList.toggle('animation-paused', document.hidden);
    });
})();
