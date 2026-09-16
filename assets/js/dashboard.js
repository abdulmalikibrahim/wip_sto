// ============================================================
// Dashboard — "Alur Data -> WIP Summary" guide: the auto-playing step
// walkthrough, the Cutoff VIN conveyor demo and the rule tile reveal.
// ============================================================
(function ($) {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ------------------------------------------------------------
    // Step walkthrough: moves to the next step every DURATION ms while
    // playing; clicking a step jumps there and pauses so it can be read.
    // ------------------------------------------------------------
    var $guide = $('#flowGuide');
    if ($guide.length) {
        var DURATION = 5500;
        var $steps = $guide.find('.flow-step');
        var $panels = $guide.find('.flow-panel');
        var $bar = $guide.find('.flow-timer-bar');
        var $play = $('#flowPlay');
        var track = $guide.find('.flow-track')[0];
        var total = $steps.length;
        var current = 0;
        var timer = null;
        var playing = !reduceMotion;

        $guide[0].style.setProperty('--flow-dur', DURATION + 'ms');

        var restartBar = function () {
            $bar.removeClass('run');
            void $bar[0].offsetWidth; // reflow, so the CSS animation starts over
            if (playing) $bar.addClass('run');
        };

        var show = function (i) {
            current = (i + total) % total;
            $steps.each(function (idx) {
                $(this)
                    .toggleClass('is-active', idx === current)
                    .toggleClass('is-done', idx < current)
                    .attr('aria-current', idx === current ? 'step' : null);
            });
            $panels.each(function (idx) { this.hidden = idx !== current; });
            track.style.setProperty('--p', total > 1 ? current / (total - 1) : 0);
            restartBar();
        };

        var schedule = function () {
            clearTimeout(timer);
            if (!playing) return;
            timer = setTimeout(function () {
                show(current + 1);
                schedule();
            }, DURATION);
        };

        var setPlaying = function (on) {
            playing = on;
            $guide.toggleClass('is-playing', on);
            $play
                .attr('aria-pressed', on ? 'true' : 'false')
                .html(on ? '<i class="bi bi-pause-fill"></i> Pause' : '<i class="bi bi-play-fill"></i> Play');
            restartBar();
            schedule();
        };

        $steps.on('click', function () {
            show($steps.index(this));
            setPlaying(false);
        });
        $play.on('click', function () { setPlaying(!playing); });

        show(0);
        setPlaying(playing);
    }

    // ------------------------------------------------------------
    // Cutoff VIN conveyor: units are laid out by SEQUENCE, descending to the
    // right — the highest sequence sits at the left, like the Master WIP list
    // showing the highest Sequence at the top (sequence is the only order the
    // WIP data has; there is no scan date). The flagged unit is the cutoff
    // VIN; it and every HIGHER sequence to its left are counted
    // (`id >= boundary`, the PDF's "dari cut off itu sampai atas"), the lower
    // sequences to its right are not. Loops after NEW_UNITS arrive.
    // ------------------------------------------------------------
    var $units = $('#conveyorUnits');
    if ($units.length) {
        var QTY = 2;
        var NEW_UNITS = 5;
        var MAX_VISIBLE = 9;
        var TICK_MS = 1300;
        var $count = $('#convCount');
        var $net = $('#convNet');
        var counted = 0;
        var serial = 0;
        var tickNo = 0;

        // The label under each unit is its SEQUENCE number, not a VIN.
        var makeUnit = function (state, seq, animate) {
            return $('<div class="c-unit ' + state + (animate ? ' enter' : '') + '">' +
                '<div class="c-body"><i class="bi bi-car-front-fill"></i></div>' +
                '<div class="c-vin">' + seq + '</div></div>');
        };

        var bump = function ($el) {
            $el.removeClass('bump');
            void $el[0].offsetWidth;
            $el.addClass('bump');
        };

        var setCount = function (n) {
            counted = n;
            $count.text(n);
            $net.text(n * QTY);
            bump($count);
            bump($net);
        };

        // Sequences below the cutoff sit at the right, the cutoff unit in front of them.
        var reset = function (animate) {
            $units.empty();
            for (var v = 101; v <= 104; v++) {
                $units.prepend(makeUnit('old', v, animate));
            }
            $units.prepend(makeUnit('cut', 105, animate));
            serial = 105;
            tickNo = 0;
            setCount(1);
        };

        var addUnit = function (animate) {
            serial++;
            $units.prepend(makeUnit('new', serial, animate));
            setCount(counted + 1);

            var $inLine = $units.children(':not(.leave)');
            if ($inLine.length > MAX_VISIBLE) {
                var $lowest = $inLine.last(); // the lowest sequence leaves at the right
                if (animate) {
                    $lowest.addClass('leave');
                    setTimeout(function () { $lowest.remove(); }, 500);
                } else {
                    $lowest.remove();
                }
            }
        };

        if (reduceMotion) {
            // No animation: just show the finished picture.
            reset(false);
            for (var n = 0; n < NEW_UNITS; n++) addUnit(false);
        } else {
            reset(true);
            setInterval(function () {
                tickNo++;
                if (tickNo <= NEW_UNITS) {
                    addUnit(true);
                } else if (tickNo === NEW_UNITS + 2) { // hold the final total one tick, then replay
                    reset(true);
                }
            }, TICK_MS);
        }
    }

    // ------------------------------------------------------------
    // Rule tiles fade up once as they scroll into view.
    // ------------------------------------------------------------
    var reveals = document.querySelectorAll('.reveal');
    if (reveals.length && !reduceMotion && 'IntersectionObserver' in window) {
        document.documentElement.classList.add('reveal-ready');
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });
        Array.prototype.forEach.call(reveals, function (el) { io.observe(el); });
    }
})(jQuery);
