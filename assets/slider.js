(function () {
    function parseIds(value) {
        return String(value || '1')
            .split(',')
            .map(function (n) { return parseInt(n.trim(), 10); })
            .filter(function (n) { return !isNaN(n) && n > 0; })
            .slice(0, 2);
    }

    function debounce(fn, delay) {
        var timer;
        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(null, args);
            }, delay);
        };
    }

function initSlider(slider) {
    if (slider.dataset.inited === 'true') return;
    slider.dataset.inited = 'true';
    slider.classList.add('is-ready');

    var allSlides = Array.prototype.slice.call(slider.querySelectorAll('.tbx-hero-lite__slide'));
    var allDots = Array.prototype.slice.call(slider.querySelectorAll('.tbx-hero-lite__dot'));
    var prev = slider.querySelector('.tbx-hero-lite__prev');
    var next = slider.querySelector('.tbx-hero-lite__next');
    var nav = slider.querySelector('.tbx-hero-lite__nav');
    var dotsWrap = slider.querySelector('.tbx-hero-lite__dots');

    var autoplay = parseInt(slider.getAttribute('data-autoplay') || '0', 10);
    var breakpoint = parseInt(slider.getAttribute('data-breakpoint') || '767', 10);
    var mobileIds = parseIds(slider.getAttribute('data-mobile-ids'));

    var enabledSlides = [];
    var enabledDots = [];
    var current = 0;
    var timer = null;
    var currentMode = '';

    function isMobile() {
        return window.matchMedia('(max-width: ' + breakpoint + 'px)').matches;
    }

    function getEnabledIds() {
        if (isMobile()) {
            return mobileIds.length ? mobileIds : [1];
        }

        return allSlides.map(function (slide) {
            return parseInt(slide.getAttribute('data-index'), 10);
        });
    }

    function getSlideBg(slide) {
        return isMobile()
            ? (slide.getAttribute('data-bg-mobile') || slide.getAttribute('data-bg'))
            : slide.getAttribute('data-bg');
    }

    function loadBg(slide) {
        if (!slide) return;

        var src = getSlideBg(slide);
        if (!src) return;
        if (slide.dataset.loadedSrc === src) return;

        var currentBg = slide.style.backgroundImage || '';
        if (currentBg.indexOf(src) !== -1) {
            slide.dataset.loadedSrc = src;
            return;
        }

        slide.style.backgroundImage = 'url("' + src + '")';
        slide.dataset.loadedSrc = src;
    }

    function stop() {
        if (timer) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    function start() {
        if (!autoplay || enabledSlides.length < 2) return;
        stop();

        timer = window.setInterval(function () {
            goTo(current + 1);
        }, autoplay);
    }

    function applyCurrent() {
        allSlides.forEach(function (slide) {
            slide.classList.remove('is-active');
        });

        allDots.forEach(function (dot) {
            dot.classList.remove('is-active');
        });

        if (!enabledSlides.length) return;

        loadBg(enabledSlides[current]);

        var nextIndex = (current + 1) % enabledSlides.length;
        loadBg(enabledSlides[nextIndex]);

        enabledSlides[current].classList.add('is-active');

        if (enabledDots[current]) {
            enabledDots[current].classList.add('is-active');
        }
    }

    function build() {
        stop();

        var enabledIds = getEnabledIds();

        enabledSlides = allSlides.filter(function (slide) {
            return enabledIds.indexOf(parseInt(slide.getAttribute('data-index'), 10)) !== -1;
        });

        enabledDots = allDots.filter(function (dot) {
            return enabledIds.indexOf(parseInt(dot.getAttribute('data-index'), 10)) !== -1;
        });

        allSlides.forEach(function (slide) {
            var isEnabled = enabledSlides.indexOf(slide) !== -1;
            slide.classList.toggle('is-enabled', isEnabled);

            if (!isEnabled) {
                slide.classList.remove('is-active');
                slide.style.backgroundImage = '';
                slide.dataset.loadedSrc = '';
            }
        });

        allDots.forEach(function (dot) {
            var isEnabled = enabledDots.indexOf(dot) !== -1;
            dot.hidden = !isEnabled;
            dot.classList.remove('is-active');
        });

        if (current > enabledSlides.length - 1) {
            current = 0;
        }

        var hasControls = enabledSlides.length > 1;

        if (nav) nav.hidden = !hasControls;
        if (dotsWrap) dotsWrap.hidden = !hasControls;

        applyCurrent();
        start();

        currentMode = isMobile() ? 'mobile' : 'desktop';
    }

    function goTo(index) {
        if (!enabledSlides.length) return;
        current = (index + enabledSlides.length) % enabledSlides.length;
        applyCurrent();
    }

    if (prev) {
        prev.addEventListener('click', function () {
            goTo(current - 1);
            start();
        });
    }

    if (next) {
        next.addEventListener('click', function () {
            goTo(current + 1);
            start();
        });
    }

    allDots.forEach(function (dot) {
        dot.addEventListener('click', function () {
            var dotIndex = enabledDots.indexOf(dot);
            if (dotIndex !== -1) {
                goTo(dotIndex);
                start();
            }
        });
    });

    slider.addEventListener('mouseenter', stop);
    slider.addEventListener('mouseleave', start);

    var touchStartX = 0;

    slider.addEventListener('touchstart', function (e) {
        touchStartX = e.changedTouches[0].clientX;
    }, { passive: true });

    slider.addEventListener('touchend', function (e) {
        var touchEndX = e.changedTouches[0].clientX;
        var diff = touchEndX - touchStartX;

        if (Math.abs(diff) > 40) {
            if (diff < 0) {
                goTo(current + 1);
            } else {
                goTo(current - 1);
            }
            start();
        }
    }, { passive: true });

    build();

    window.addEventListener('resize', debounce(function () {
        var newMode = isMobile() ? 'mobile' : 'desktop';
        if (newMode !== currentMode) {
            current = 0;
            build();
        }
    }, 180));
}

    function initAll() {
        var sliders = document.querySelectorAll('.tbx-hero-lite');
        sliders.forEach(initSlider);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();