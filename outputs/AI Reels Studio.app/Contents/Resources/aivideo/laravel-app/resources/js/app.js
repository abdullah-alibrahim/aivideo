import '@fontsource/cairo/400.css';
import '@fontsource/cairo/600.css';
import '@fontsource/cairo/700.css';
import '@fontsource/cairo/800.css';
import '@fontsource/cairo/900.css';
import '@fontsource/plus-jakarta-sans/400.css';
import '@fontsource/plus-jakarta-sans/600.css';
import '@fontsource/plus-jakarta-sans/700.css';
import '@fontsource/plus-jakarta-sans/800.css';
import '@fortawesome/fontawesome-free/css/all.min.css';

document.addEventListener('DOMContentLoaded', () => {
    const picker = document.querySelector('[data-background-picker]');
    if (!picker) return;

    const cards = Array.from(picker.querySelectorAll('[data-background-option]'));
    const searchInput = picker.querySelector('[data-background-search]');
    const typeButtons = Array.from(picker.querySelectorAll('[data-background-type]'));
    const categoryButtons = Array.from(picker.querySelectorAll('[data-background-category]'));
    const favoritesFilter = picker.querySelector('[data-background-favorites]');
    const resultStatus = picker.querySelector('[data-background-results]');
    const emptyState = picker.querySelector('[data-background-empty]');
    const isArabic = document.documentElement.lang === 'ar';
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const favoritesKey = 'aivideo.background-favorites.v1';
    let selectedCategory = 'all';
    let favoritesOnly = false;
    let favorites = new Set();

    try {
        const saved = JSON.parse(window.localStorage.getItem(favoritesKey) || '[]');
        favorites = new Set(Array.isArray(saved) ? saved.filter((id) => typeof id === 'string') : []);
    } catch {
        favorites = new Set();
    }

    const normalize = (value) => value.trim().toLocaleLowerCase(isArabic ? 'ar' : 'en');
    let selectedType = 'all';

    function renderResults() {
        const term = normalize(searchInput?.value || '');
        let visibleCount = 0;

        cards.forEach((card) => {
            const radio = card.querySelector('input[name="bg_preset"]');
            const matchesTerm = !term || normalize(card.dataset.search || '').includes(term);
            const matchesCategory = selectedCategory === 'all' || card.dataset.category === selectedCategory;
            const matchesType = selectedType === 'all' || card.dataset.type === selectedType;
            const matchesFavorite = !favoritesOnly || favorites.has(radio?.value || '');
            const isVisible = matchesTerm && matchesCategory && matchesType && matchesFavorite;
            card.hidden = !isVisible;
            if (isVisible) visibleCount += 1;
        });

        if (emptyState) emptyState.classList.toggle('hidden', visibleCount !== 0);
        if (resultStatus) {
            resultStatus.textContent = isArabic
                ? `عدد الخلفيات المعروضة: ${visibleCount}`
                : `${visibleCount} backgrounds shown`;
        }
    }

    function saveFavorites() {
        try {
            window.localStorage.setItem(favoritesKey, JSON.stringify(Array.from(favorites)));
        } catch {
            // Favorites still work for the current page if browser storage is unavailable.
        }
    }

    function updateFavoriteButtons() {
        picker.querySelectorAll('[data-background-favorite]').forEach((button) => {
            const card = button.closest('[data-background-option]');
            const id = card?.querySelector('input[name="bg_preset"]')?.value;
            const active = Boolean(id && favorites.has(id));
            button.setAttribute('aria-pressed', String(active));
            button.setAttribute('aria-label', active ? button.dataset.labelRemove : button.dataset.labelAdd);
            const icon = button.querySelector('i');
            icon?.classList.toggle('fa-solid', active);
            icon?.classList.toggle('fa-regular', !active);
            icon?.classList.toggle('text-amber-300', active);
        });
    }

    function playPreview(card) {
        if (reducedMotion) return;
        const video = card.querySelector('[data-background-video-preview]');
        if (!video) return;

        if (!video.src) video.src = video.dataset.src;
        video.classList.remove('opacity-0');
        video.classList.add('opacity-100');
        const playback = video.play();
        if (playback && typeof playback.catch === 'function') playback.catch(() => {});
    }

    function pausePreview(card) {
        const video = card.querySelector('[data-background-video-preview]');
        if (!video) return;

        const isSelected = card.querySelector('input[name="bg_preset"]')?.checked;
        if (isSelected && !reducedMotion) return;
        video.pause();
        if (video.readyState > 0) video.currentTime = 0;
        video.classList.add('opacity-0');
        video.classList.remove('opacity-100');
    }

    typeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            selectedType = button.dataset.backgroundType || 'all';
            typeButtons.forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
            renderResults();
        });
    });

    categoryButtons.forEach((button) => {
        button.addEventListener('click', () => {
            selectedCategory = button.dataset.backgroundCategory || 'all';
            categoryButtons.forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
            renderResults();
        });
    });

    searchInput?.addEventListener('input', renderResults);

    favoritesFilter?.addEventListener('click', () => {
        favoritesOnly = !favoritesOnly;
        favoritesFilter.setAttribute('aria-pressed', String(favoritesOnly));
        renderResults();
    });

    picker.querySelectorAll('[data-background-favorite]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const id = button.closest('[data-background-option]')?.querySelector('input[name="bg_preset"]')?.value;
            if (!id) return;

            if (favorites.has(id)) favorites.delete(id);
            else favorites.add(id);

            saveFavorites();
            updateFavoriteButtons();
            renderResults();
        });
    });

    cards.forEach((card) => {
        card.addEventListener('pointerenter', () => playPreview(card));
        card.addEventListener('pointerleave', () => pausePreview(card));
        card.addEventListener('focusin', () => playPreview(card));
        card.addEventListener('focusout', () => pausePreview(card));
        card.querySelector('input[name="bg_preset"]')?.addEventListener('change', () => {
            cards.forEach((item) => {
                if (item !== card) pausePreview(item);
            });
            playPreview(card);
        });
    });

    updateFavoriteButtons();
    renderResults();
    const initiallySelected = cards.find((card) => card.querySelector('input[name="bg_preset"]')?.checked);
    if (initiallySelected) playPreview(initiallySelected);
});
