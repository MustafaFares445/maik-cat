(() => {
    document.documentElement.classList.add('js');

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const revealEverything = () => {
        document.querySelectorAll('.reveal').forEach((el) => el.classList.add('visible'));
    };

    window.addEventListener('DOMContentLoaded', () => {
        requestAnimationFrame(() => document.body.classList.add('ready'));

        if (reducedMotion || !('IntersectionObserver' in window)) {
            revealEverything();
            return;
        }

        const observer = new IntersectionObserver((entries, currentObserver) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('visible');
                currentObserver.unobserve(entry.target);
            });
        }, {
            threshold: 0.16,
            rootMargin: '0px 0px -10% 0px',
        });

        document.querySelectorAll('.reveal').forEach((el) => observer.observe(el));

        document.querySelectorAll('[data-tilt]').forEach((el) => {
            const strength = Number(el.dataset.strength || 4);

            el.addEventListener('pointermove', (event) => {
                if (event.pointerType === 'touch') return;

                const rect = el.getBoundingClientRect();
                const x = (event.clientX - rect.left) / rect.width - 0.5;
                const y = (event.clientY - rect.top) / rect.height - 0.5;

                el.style.setProperty('--ry', `${x * strength}deg`);
                el.style.setProperty('--rx', `${y * -strength}deg`);
            });

            el.addEventListener('pointerleave', () => {
                el.style.setProperty('--ry', '0deg');
                el.style.setProperty('--rx', '0deg');
            });
        });
    });
})();
