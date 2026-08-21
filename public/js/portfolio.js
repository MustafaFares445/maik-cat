document.documentElement.classList.add('js');

function revealSections() {
    const sections = document.querySelectorAll('.reveal');

    if (!('IntersectionObserver' in window)) {
        sections.forEach((section) => section.classList.add('visible'));
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });

    sections.forEach((section) => observer.observe(section));
}

requestAnimationFrame(() => {
    document.body.classList.add('ready');
    revealSections();
});
