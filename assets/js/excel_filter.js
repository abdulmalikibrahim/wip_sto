/**
 * ExcelFilter — Excel-style AutoFilter dropdowns on DataTables headers.
 *
 * Each filterable header gets a ▼ button. It opens the same menu Excel
 * shows: Sort A to Z / Z to A, Clear Filter From "<column>", a Search box,
 * (Select All), a checkbox per value, (Blanks), and OK / Cancel. A filtered
 * column shows a funnel instead of the ▼, and a "Filtered by" bar above the
 * table lists every active filter with a Clear All.
 *
 * Like Excel, filters on different columns combine (AND), and a column's
 * list only offers the values left by the OTHER columns' filters.
 *
 *   ExcelFilter.attach(table, {
 *       mode: 'server',                        // or 'client'
 *       distinctUrl: BASE_URL + 'bom/distinct', // server mode only
 *       numeric: ['qty'],                      // "Sort Smallest to Largest"
 *       skip: ['no']                           // columns without a filter
 *   });
 *
 * server — for serverSide tables. Filters travel to the table's own ajax
 *          URL as `col_filters` (JSON); the list comes from distinctUrl.
 *          See application/libraries/Column_filter.php for the format.
 * client — for tables whose rows are all in the browser; filters run as a
 *          DataTables search function and the list is built from the rows.
 *
 * Filter state per column (the same shape the server parses):
 *   {mode: 'in',     values: [...]}  only these values (ticked)
 *   {mode: 'not_in', values: [...]}  all but these (unticked)
 *   {mode: 'like',   text: '...'}    contains text (Search + OK on a long list)
 * The value '' is "(Blanks)".
 */
(function ($) {
    'use strict';

    var BLANK = '';
    var openMenu = null; // only one menu open at a time, like Excel

    function esc(v) {
        return $('<div>').text(v == null ? '' : String(v)).html();
    }

    function norm(v) {
        return v == null ? '' : String(v).trim();
    }

    // Excel shows 1 for a stored 1.000.
    function formatNumber(v) {
        if (v === '' || isNaN(Number(v))) return v;
        return String(Number(v));
    }

    function valueMatches(f, value) {
        value = norm(value);
        if (f.mode === 'like') return value.toLowerCase().indexOf(f.text.toLowerCase()) !== -1;
        if (f.mode === 'in') return f.values.indexOf(value) !== -1;
        return f.values.indexOf(value) === -1; // not_in
    }

    function describe(f) {
        if (f.mode === 'like') return 'contains "' + f.text + '"';
        var label = function (v) { return v === BLANK ? '(Blanks)' : v; };
        var shown = f.values.slice(0, 3).map(label).join(', ');
        var more = f.values.length > 3 ? ' +' + (f.values.length - 3) : '';
        return (f.mode === 'in' ? '' : 'not ') + shown + more;
    }

    function attach(table, opts) {
        opts = $.extend({ mode: 'client', numeric: [], skip: [] }, opts || {});
        var filters = {};        // column data name => filter
        var colIndex = {};       // column data name => DataTables index
        var $table = $(table.table().node());
        var tableNode = table.table().node();

        // ---- which columns get a ▼ ----------------------------------------
        table.columns().every(function (idx) {
            var src = this.dataSrc();
            var setting = table.settings()[0].aoColumns[idx];
            if (typeof src !== 'string' || opts.skip.indexOf(src) !== -1) return;
            if (setting.bSearchable === false) return;
            colIndex[src] = idx;
        });

        function title(name) {
            return $(table.column(colIndex[name]).header()).find('.dt-column-title').text().trim() ||
                $(table.column(colIndex[name]).header()).text().trim();
        }

        // ---- header buttons ------------------------------------------------
        $.each(colIndex, function (name, idx) {
            // Like Excel, the header itself no longer sorts — Sort A to Z /
            // Z to A live in the ▼ menu. data-dt-order="disable" is
            // DataTables' own switch for that (click and Enter); the
            // ▲▼ arrows are hidden in CSS and the sort direction shows on
            // the ▼ button instead (see paintButtons).
            var $th = $(table.column(idx).header())
                .addClass('xf-th')
                .attr('data-dt-order', 'disable')
                .removeAttr('tabindex');
            var $btn = $('<button type="button" class="xf-btn" aria-haspopup="true"></button>')
                .attr('title', 'Filter ' + title(name))
                .attr('data-xf-col', name)
                .html('<i class="bi bi-caret-down-fill"></i>');
            $th.append($btn);
            // Bound on the button itself so the event stops here and never
            // reaches the header's own handlers (a click or Enter on the
            // header sorts the column). Enter/Space on a <button> already
            // fire `click`, so only the click opens the menu.
            $btn.on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (openMenu && openMenu.name === name && openMenu.table === tableNode) {
                    closeMenu();
                } else {
                    openFor(name, $btn);
                }
            });
            $btn.on('mousedown keydown keypress keyup', function (e) { e.stopPropagation(); });
        });

        // The column the table is sorted by, as {idx, dir} (DT2 hands the
        // order back either as [[idx, dir]] or as a flat [idx, dir]).
        function currentOrder() {
            var o = table.order();
            var first = Array.isArray(o[0]) ? o[0] : o;
            return first && first.length ? { idx: first[0], dir: first[1] } : null;
        }

        // ▼ = nothing; funnel = filtered; plus a small ↑/↓ on the column the
        // table is sorted by — Excel's button shows both the same way.
        function paintButtons() {
            var ord = currentOrder();
            $.each(colIndex, function (name, idx) {
                var on = !!filters[name];
                var dir = ord && ord.idx === idx ? ord.dir : null;
                var tip = on ? title(name) + ': ' + describe(filters[name]) : 'Filter ' + title(name);
                if (dir) tip += dir === 'asc' ? ' — sorted A to Z' : ' — sorted Z to A';
                $(table.column(idx).header()).find('.xf-btn')
                    .toggleClass('xf-active', on)
                    .toggleClass('xf-sorted', !!dir)
                    .attr('title', tip)
                    .html((on ? '<i class="bi bi-funnel-fill"></i>' : '<i class="bi bi-caret-down-fill"></i>') +
                        (dir ? '<i class="bi ' + (dir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down') + ' xf-sort-mark"></i>' : ''));
            });
            paintBar();
        }

        table.on('order.dt', paintButtons);

        // ---- "Filtered by" bar ---------------------------------------------
        var $bar = $('<div class="xf-bar d-none"></div>');
        $table.closest('.dt-container').before($bar);
        if (!$bar.parent().length) $table.before($bar);

        function paintBar() {
            var names = Object.keys(filters);
            $bar.toggleClass('d-none', names.length === 0).empty();
            if (!names.length) return;
            $bar.append('<span class="xf-bar-label"><i class="bi bi-funnel-fill"></i> Filtered by:</span>');
            names.forEach(function (name) {
                $('<span class="xf-chip"></span>')
                    .append($('<strong></strong>').text(title(name) + ': '))
                    .append($('<span></span>').text(describe(filters[name])))
                    .append($('<button type="button" class="xf-chip-x" title="Clear this filter">&times;</button>')
                        .on('click', function () { setFilter(name, null); }))
                    .appendTo($bar);
            });
            $('<button type="button" class="btn btn-link btn-sm xf-clear-all">Clear All</button>')
                .on('click', function () { filters = {}; changed(); })
                .appendTo($bar);
        }

        function setFilter(name, f) {
            if (f) filters[name] = f; else delete filters[name];
            changed();
        }

        function changed() {
            paintButtons();
            if (opts.mode === 'server') {
                table.ajax.reload(); // back to page 1, like Excel re-filtering
            } else {
                table.draw();
            }
            $table.trigger('xf:change', [$.extend({}, filters)]);
        }

        // ---- applying the filters ------------------------------------------
        if (opts.mode === 'server') {
            table.on('preXhr.dt', function (e, settings, data) {
                data.col_filters = JSON.stringify(filters);
            });
        } else {
            $.fn.dataTable.ext.search.push(function (settings, searchData) {
                if (settings.nTable !== tableNode) return true;
                for (var name in filters) {
                    if (!valueMatches(filters[name], searchData[colIndex[name]])) return false;
                }
                return true;
            });
        }

        // ---- the dropdown's value list -------------------------------------
        // Resolves with {values: [...], truncated: bool}.
        function loadValues(name, q) {
            if (opts.mode === 'server') {
                var params = $.extend({}, table.ajax.params() || {});
                var others = $.extend({}, filters);
                delete others[name];
                params.col_filters = JSON.stringify(others);
                params.column = name;
                params.q = q || '';
                // Not needed for a list — keeps the request small.
                delete params.columns;
                return $.ajax({ url: opts.distinctUrl, type: 'POST', data: params, dataType: 'json' })
                    .then(function (resp) {
                        if (resp.status !== 'success') return $.Deferred().reject(resp.message).promise();
                        // Kept exactly as stored (not trimmed): the server
                        // matches them with IN (...), so "ABC " must stay
                        // "ABC " to find its rows.
                        return {
                            values: resp.values.map(function (v) { return v == null ? '' : String(v); }),
                            truncated: !!resp.truncated
                        };
                    });
            }

            // client: values from rows that pass every OTHER column filter.
            var seen = {};
            var values = [];
            var idx = colIndex[name];
            table.rows().every(function (rowIdx) {
                for (var other in filters) {
                    if (other === name) continue;
                    if (!valueMatches(filters[other], table.cell(rowIdx, colIndex[other]).render('filter'))) return;
                }
                var v = norm(table.cell(rowIdx, idx).render('filter'));
                if (q && v.toLowerCase().indexOf(q.toLowerCase()) === -1) return;
                if (!seen.hasOwnProperty(v)) {
                    seen[v] = true;
                    values.push(v);
                }
            });
            var numeric = opts.numeric.indexOf(name) !== -1;
            values.sort(function (a, b) {
                if (numeric) return Number(a) - Number(b);
                return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
            });
            return $.Deferred().resolve({ values: values, truncated: false }).promise();
        }

        // ---- the menu --------------------------------------------------------
        function openFor(name, $btn) {
            closeMenu();

            var numeric = opts.numeric.indexOf(name) !== -1;
            var current = filters[name] || null;
            var checked = {};     // value => bool, for every value loaded so far
            var list = { values: [], truncated: false };
            var query = '';
            var reqSeq = 0;

            var $menu = $(
                '<div class="dropdown-menu show xf-menu" role="dialog">' +
                '  <button type="button" class="dropdown-item xf-sort" data-dir="asc"><i class="bi bi-sort-alpha-down"></i> <span></span></button>' +
                '  <button type="button" class="dropdown-item xf-sort" data-dir="desc"><i class="bi bi-sort-alpha-down-alt"></i> <span></span></button>' +
                '  <div class="dropdown-divider"></div>' +
                '  <button type="button" class="dropdown-item xf-clear"><i class="bi bi-funnel"></i> <span></span></button>' +
                '  <div class="dropdown-divider"></div>' +
                '  <div class="px-2">' +
                '    <div class="input-group input-group-sm mb-2">' +
                '      <input type="search" class="form-control xf-search" placeholder="Search" autocomplete="off">' +
                '      <span class="input-group-text"><i class="bi bi-search"></i></span>' +
                '    </div>' +
                '    <div class="xf-list border rounded"></div>' +
                '    <div class="xf-note small text-secondary mt-1"></div>' +
                '  </div>' +
                '  <div class="d-flex justify-content-end gap-2 px-2 pt-2">' +
                '    <button type="button" class="btn btn-primary btn-sm xf-ok">OK</button>' +
                '    <button type="button" class="btn btn-secondary btn-sm xf-cancel">Cancel</button>' +
                '  </div>' +
                '</div>'
            );

            $menu.find('.xf-sort[data-dir="asc"] span').text(numeric ? 'Sort Smallest to Largest' : 'Sort A to Z');
            $menu.find('.xf-sort[data-dir="desc"] span').text(numeric ? 'Sort Largest to Smallest' : 'Sort Z to A');
            if (numeric) {
                $menu.find('.xf-sort[data-dir="asc"] i').attr('class', 'bi bi-sort-numeric-down');
                $menu.find('.xf-sort[data-dir="desc"] i').attr('class', 'bi bi-sort-numeric-down-alt');
            }
            $menu.find('.xf-clear span').text('Clear Filter From "' + title(name) + '"');
            $menu.find('.xf-clear').prop('disabled', !current).toggleClass('disabled', !current);

            var orderable = table.settings()[0].aoColumns[colIndex[name]].bSortable !== false;
            $menu.find('.xf-sort').prop('disabled', !orderable).toggleClass('disabled', !orderable);

            // A value's tick when first shown comes from the column's filter.
            function initialTick(v) {
                return current ? valueMatches(current, v) : true;
            }

            function label(v) {
                if (v === BLANK) return '<em>(Blanks)</em>';
                return esc(numeric ? formatNumber(v) : v);
            }

            function visibleValues() {
                return list.values;
            }

            function paintList() {
                var $list = $menu.find('.xf-list').empty();
                var vals = visibleValues();
                // Excel lists (Blanks) last.
                vals = vals.filter(function (v) { return v !== BLANK; })
                    .concat(vals.indexOf(BLANK) !== -1 ? [BLANK] : []);

                if (!vals.length) {
                    $list.append('<div class="xf-empty small text-secondary p-2">No matches</div>');
                } else {
                    $list.append(
                        '<label class="xf-item xf-all"><input type="checkbox" class="form-check-input"> ' +
                        (query ? '(Select All Search Results)' : '(Select All)') + '</label>'
                    );
                    var html = '';
                    vals.forEach(function (v, i) {
                        if (!checked.hasOwnProperty(v)) checked[v] = query ? true : initialTick(v);
                        html += '<label class="xf-item"><input type="checkbox" class="form-check-input" data-i="' + i + '"' +
                            (checked[v] ? ' checked' : '') + '> ' + label(v) + '</label>';
                    });
                    $list.append(html);
                    $list.data('vals', vals);
                }

                $menu.find('.xf-note').text(list.truncated
                    ? 'Showing the first ' + vals.length.toLocaleString() + ' items — type in Search to find more.'
                    : '');
                syncAll();
            }

            function syncAll() {
                var vals = $menu.find('.xf-list').data('vals') || [];
                var n = vals.filter(function (v) { return checked[v]; }).length;
                var $all = $menu.find('.xf-all input');
                $all.prop('checked', vals.length > 0 && n === vals.length);
                $all.prop('indeterminate', n > 0 && n < vals.length);
                // Excel greys out OK when nothing is ticked.
                $menu.find('.xf-ok').prop('disabled', n === 0);
            }

            function load() {
                var seq = ++reqSeq;
                $menu.find('.xf-list').html('<div class="small text-secondary p-2"><span class="spinner-border spinner-border-sm me-1"></span>Loading…</div>');
                $menu.find('.xf-ok').prop('disabled', true);
                loadValues(name, query).then(function (res) {
                    if (seq !== reqSeq) return; // a newer search already went out
                    list = res;
                    if (query) {
                        // Searching starts from everything ticked, like Excel.
                        list.values.forEach(function (v) { checked[v] = true; });
                    } else {
                        // Search cleared: back to the ticks the column's
                        // filter implies, not the all-ticked search state.
                        checked = {};
                    }
                    paintList();
                }, function (msg) {
                    if (seq !== reqSeq) return;
                    $menu.find('.xf-list').html('<div class="small text-danger p-2"></div>').find('div').text(msg || 'Failed to load values.');
                });
            }

            // What OK means, in Excel's terms.
            function buildFilter() {
                var vals = $menu.find('.xf-list').data('vals') || [];
                var ticked = vals.filter(function (v) { return checked[v]; });
                var unticked = vals.filter(function (v) { return !checked[v]; });

                if (query) {
                    // Search + OK = only the ticked search results. If the
                    // list was cut short and every shown match is ticked,
                    // mean "everything containing the text", not just the
                    // first page of it.
                    if (list.truncated && unticked.length === 0) return { mode: 'like', text: query };
                    return { mode: 'in', values: ticked };
                }

                if (!list.truncated) {
                    if (unticked.length === 0) return null; // everything ticked = no filter
                    return ticked.length <= unticked.length
                        ? { mode: 'in', values: ticked }
                        : { mode: 'not_in', values: unticked };
                }

                // Truncated list: values beyond the first page keep whatever
                // the previous filter said about them.
                if (current && current.mode === 'in') {
                    var keep = current.values.filter(function (v) { return vals.indexOf(v) === -1; });
                    return { mode: 'in', values: ticked.concat(keep) };
                }
                if (unticked.length === 0) {
                    return current && current.mode === 'like' ? current : (current && current.mode === 'not_in'
                        ? { mode: 'not_in', values: current.values.filter(function (v) { return vals.indexOf(v) === -1; }) }
                        : null);
                }
                var prior = current && current.mode === 'not_in'
                    ? current.values.filter(function (v) { return vals.indexOf(v) === -1; })
                    : [];
                return { mode: 'not_in', values: unticked.concat(prior) };
            }

            // ---- events ----
            $menu.on('click', function (e) { e.stopPropagation(); });

            $menu.find('.xf-sort').on('click', function () {
                table.order([colIndex[name], $(this).data('dir')]).draw();
                closeMenu();
            });

            $menu.find('.xf-clear').on('click', function () {
                closeMenu();
                setFilter(name, null);
            });

            var searchTimer = null;
            $menu.find('.xf-search').on('input', function () {
                var v = $(this).val().trim();
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    query = v;
                    load();
                }, opts.mode === 'server' ? 300 : 0);
            }).on('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (!$menu.find('.xf-ok').prop('disabled')) $menu.find('.xf-ok').trigger('click');
                }
            });

            $menu.on('change', '.xf-item:not(.xf-all) input', function () {
                var vals = $menu.find('.xf-list').data('vals') || [];
                checked[vals[$(this).data('i')]] = this.checked;
                syncAll();
            });

            $menu.on('change', '.xf-all input', function () {
                var on = this.checked;
                var vals = $menu.find('.xf-list').data('vals') || [];
                vals.forEach(function (v) { checked[v] = on; });
                $menu.find('.xf-item:not(.xf-all) input').prop('checked', on);
                syncAll();
            });

            $menu.find('.xf-ok').on('click', function () {
                var f = buildFilter();
                closeMenu();
                setFilter(name, f);
            });

            $menu.find('.xf-cancel').on('click', closeMenu);

            // ---- place it under the ▼, kept on screen ----
            $menu.appendTo('body');
            var r = $btn[0].getBoundingClientRect();
            var w = $menu.outerWidth();
            var left = Math.min(r.left + window.scrollX, window.scrollX + document.documentElement.clientWidth - w - 8);
            $menu.css({
                position: 'absolute',
                top: r.bottom + window.scrollY + 4,
                left: Math.max(window.scrollX + 8, left)
            });

            openMenu = { name: name, table: tableNode, $menu: $menu };
            $btn.addClass('xf-open');
            load();
            setTimeout(function () { $menu.find('.xf-search').trigger('focus'); }, 0);
        }

        paintButtons();

        return {
            filters: function () { return $.extend({}, filters); },
            clear: function () { filters = {}; changed(); },
            set: setFilter
        };
    }

    function closeMenu() {
        if (!openMenu) return;
        openMenu.$menu.remove();
        $('.xf-btn.xf-open').removeClass('xf-open');
        openMenu = null;
    }

    // Excel closes the menu on a click elsewhere or Esc.
    $(document).on('click', function (e) {
        if (openMenu && !$(e.target).closest('.xf-menu, .xf-btn').length) closeMenu();
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') closeMenu();
    });
    $(window).on('resize', closeMenu);

    window.ExcelFilter = { attach: attach, close: closeMenu };
})(jQuery);
