const settings = window.hrcmOverallTripView || {};

function initOverallTripView() {
    const root = document.querySelector('.hrcm-overall-trip-view');
    if (!root) {
        return;
    }

    const wrapper = root.querySelector('.hrcm-table-wrapper');
    const table = root.querySelector('#hrcm-overall-trip-table');
    const tbody = table ? table.querySelector('tbody') : null;
    const searchInput = root.querySelector('#hrcm-overall-trip-search');
    const emptyState = root.querySelector('.hrcm-overall-trip-view__empty');
    const errorState = root.querySelector('.hrcm-overall-trip-view__error');
    const pagination = root.querySelector('.hrcm-overall-trip-view__pagination');
    const prevButton = pagination ? pagination.querySelector('[data-page="prev"]') : null;
    const nextButton = pagination ? pagination.querySelector('[data-page="next"]') : null;
    const summary = pagination ? pagination.querySelector('.hrcm-overall-trip-view__pagination-summary') : null;
    const modal = document.querySelector('.hrcm-overall-trip-view__modal');
    const modalClose = modal ? modal.querySelector('.hrcm-overall-trip-view__modal-close') : null;
    const modalBackdrop = modal ? modal.querySelector('.hrcm-overall-trip-view__modal-backdrop') : null;
    const modalContent = modal ? modal.querySelector('.hrcm-overall-trip-view__modal-json') : null;

    if (!settings.ajaxUrl) {
        return;
    }

    if (!table || !tbody || !pagination || !prevButton || !nextButton || !summary) {
        return;
    }

    const state = {
        page: 1,
        perPage: Number(root.getAttribute('data-per-page')) || Number(settings.perPage) || 25,
        orderby: 'title',
        order: 'asc',
        search: '',
        total: 0,
        totalPages: 1,
    };

    const rowsById = new Map();
    let requestToken = 0;
    let lastFocusedElement = null;

    function setLoading(isLoading) {
        root.setAttribute('aria-busy', String(isLoading));
        table.setAttribute('aria-busy', String(isLoading));
        if (wrapper) {
            wrapper.setAttribute('aria-busy', String(isLoading));
        }
        if (isLoading) {
            errorState.hidden = true;
            emptyState.hidden = true;
        }
    }

    function showSkeleton() {
        if (!tbody) {
            return;
        }
        tbody.innerHTML = '';
        const count = Math.min(5, state.perPage || 5);
        for (let i = 0; i < count; i++) {
            const tr = document.createElement('tr');
            tr.className = 'hrcm-skeleton-row';
            const cells = settings.debugEnabled ? 9 : 8;
            for (let j = 0; j < cells; j++) {
                const td = document.createElement('td');
                const span = document.createElement('span');
                span.className = 'hrcm-skeleton-text';
                td.appendChild(span);
                tr.appendChild(td);
            }
            tbody.appendChild(tr);
        }
    }

    function updateSortIndicators() {
        const headers = table.querySelectorAll('thead th[data-sort-key]');
        headers.forEach((th) => {
            const key = th.getAttribute('data-sort-key');
            const button = th.querySelector('.hrcm-sort-button');
            if (key === state.orderby) {
                th.setAttribute('aria-sort', state.order === 'asc' ? 'ascending' : 'descending');
                th.classList.add('sorted');
                if (button) {
                    button.setAttribute('aria-label', `${button.textContent.trim()} (${state.order === 'asc' ? 'ascending' : 'descending'})`);
                }
            } else {
                th.setAttribute('aria-sort', 'none');
                th.classList.remove('sorted');
                if (button) {
                    button.removeAttribute('aria-label');
                }
            }
        });
    }

    function formatCountry(row) {
        if (Array.isArray(row.countries) && row.countries.length > 0) {
            return row.countries.join(', ');
        }
        return settings.strings?.noNextDeparture || '—';
    }

    function parseDateInTimezone(dateString) {
        if (!dateString) {
            return null;
        }
        const timeZone = settings.timezone;
        if (!timeZone) {
            const utcDate = new Date(`${dateString}T00:00:00Z`);
            return Number.isNaN(utcDate.getTime()) ? null : utcDate;
        }
        const [year, month, day] = dateString.split('-').map((value) => Number(value));
        if (!year || !month || !day) {
            return null;
        }
        const formatter = new Intl.DateTimeFormat('en-US', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        });
        const dateUtc = new Date(Date.UTC(year, month - 1, day, 0, 0, 0));
        const parts = formatter.formatToParts(dateUtc);
        const values = {};
        for (const part of parts) {
            values[part.type] = part.value;
        }
        const parsed = Date.UTC(
            Number(values.year),
            Number(values.month) - 1,
            Number(values.day),
            Number(values.hour),
            Number(values.minute),
            Number(values.second)
        );
        return new Date(parsed);
    }

    function getNowInTimezone() {
        const timeZone = settings.timezone;
        if (!timeZone) {
            return new Date();
        }
        const formatter = new Intl.DateTimeFormat('en-US', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        });
        const parts = formatter.formatToParts(new Date());
        const values = {};
        for (const part of parts) {
            values[part.type] = part.value;
        }
        const parsed = Date.UTC(
            Number(values.year),
            Number(values.month) - 1,
            Number(values.day),
            Number(values.hour),
            Number(values.minute),
            Number(values.second)
        );
        return new Date(parsed);
    }

    function computeDaysToNext(row) {
        const nextDate = row.next_departure;
        if (!nextDate) {
            return null;
        }
        const next = parseDateInTimezone(nextDate);
        if (!next) {
            return null;
        }
        const now = getNowInTimezone();
        const diff = Math.floor((next.getTime() - now.getTime()) / 86400000);
        return diff >= 0 ? diff : null;
    }

    function formatDaysToNext(row) {
        const days = computeDaysToNext(row);
        if (days === null) {
            return settings.strings?.noNextDeparture || '—';
        }
        if (days === 0) {
            return settings.strings?.daysToNextToday || 'Today';
        }
        const template = days === 1 ? settings.strings?.daysToNextSingle : settings.strings?.daysToNext;
        if (!template) {
            return `${days}`;
        }
        return template.replace('%s', days);
    }

    function formatNextDeparture(row) {
        if (row.next_departure_display) {
            return row.next_departure_display;
        }
        if (row.next_departure) {
            return row.next_departure;
        }
        return settings.strings?.noNextDeparture || '—';
    }

    function createCell(className, content, options = {}) {
        const td = document.createElement('td');
        if (className) {
            td.className = className;
        }
        if (options.scope) {
            td.setAttribute('scope', options.scope);
        }
        if (options.isNumeric) {
            td.classList.add('has-text-right');
        }
        if (content instanceof Node) {
            td.appendChild(content);
        } else if (null === content || typeof content === 'undefined') {
            td.textContent = '';
        } else {
            td.textContent = content;
        }
        return td;
    }

    function renderRows(rows) {
        tbody.innerHTML = '';
        rowsById.clear();

        if (!rows || rows.length === 0) {
            emptyState.hidden = false;
            return;
        }

        emptyState.hidden = true;

        rows.forEach((row) => {
            rowsById.set(String(row.trip_id), row);
            const tr = document.createElement('tr');
            tr.dataset.tripId = row.trip_id;

            const codeCell = document.createElement('code');
            codeCell.textContent = row.trip_code || String(row.trip_id);
            tr.appendChild(createCell('column-trip-code', codeCell));

            const tripCell = document.createElement('td');
            tripCell.className = 'column-trip column-primary';
            tripCell.setAttribute('scope', 'row');
            tripCell.textContent = row.trip_title || settings.strings?.noNextDeparture || '—';
            tr.appendChild(tripCell);

            tr.appendChild(createCell('column-country', formatCountry(row)));
            tr.appendChild(createCell('column-departures', String(row.number_of_departures || 0)));
            tr.appendChild(createCell('column-next-date', formatNextDeparture(row)));
            tr.appendChild(createCell('column-days-to-next', formatDaysToNext(row)));
            tr.appendChild(createCell('column-total-pax', String(row.total_pax || 0)));
            tr.appendChild(createCell('column-pax-next', String(row.pax_on_next_departure || 0)));

            if (settings.debugEnabled && modal && modalContent) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button-link hrcm-overall-trip-view__debug-button';
                button.textContent = settings.strings?.viewData || 'View data';
                button.addEventListener('click', () => openDebugModal(row.trip_id));
                tr.appendChild(createCell('column-debug', button));
            }

            tbody.appendChild(tr);
        });
    }

    function updatePagination(paginationData, rowsLength) {
        state.total = Number(paginationData.total) || 0;
        state.perPage = Number(paginationData.per_page) || state.perPage;
        state.page = Number(paginationData.page) || state.page;
        state.totalPages = Number(paginationData.total_pages) || 1;

        const start = paginationData.range?.start || (state.total > 0 ? ((state.page - 1) * state.perPage) + 1 : 0);
        const end = paginationData.range?.end || (state.total > 0 ? start + rowsLength - 1 : 0);

        const summaryText = (() => {
            if (state.total === 0) {
                return settings.strings?.empty || '';
            }
            if (start === end) {
                const template = settings.strings?.showingSingle || 'Showing %1$s of %2$s trips';
                return template.replace('%1$s', start).replace('%2$s', state.total);
            }
            const template = settings.strings?.showing || 'Showing %1$s–%2$s of %3$s trips';
            return template
                .replace('%1$s', start)
                .replace('%2$s', end)
                .replace('%3$s', state.total);
        })();

        summary.textContent = summaryText;
        prevButton.disabled = state.page <= 1;
        nextButton.disabled = state.page >= state.totalPages;
    }

    function showError(message) {
        errorState.textContent = message;
        errorState.hidden = false;
        emptyState.hidden = true;
    }

    async function fetchRows() {
        const token = ++requestToken;
        setLoading(true);
        showSkeleton();

        const params = new URLSearchParams();
        params.append('action', 'hrcm_overall_trip_view');
        params.append('nonce', settings.nonce || '');
        params.append('page', String(state.page));
        params.append('per_page', String(state.perPage));
        params.append('orderby', state.orderby);
        params.append('order', state.order);
        if (state.search) {
            params.append('search', state.search);
        }

        try {
            const response = await fetch(settings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body: params.toString(),
            });

            const data = await response.json();
            if (token !== requestToken) {
                return;
            }

            if (!data || !data.success) {
                throw new Error(data?.data?.message || settings.strings?.error || 'Error');
            }

            const payload = data.data || {};
            const rows = Array.isArray(payload.rows) ? payload.rows : [];
            renderRows(rows);
            updatePagination(payload.pagination || {}, rows.length);
            errorState.hidden = true;
        } catch (error) {
            if (token !== requestToken) {
                return;
            }
            tbody.innerHTML = '';
            showError(error.message || settings.strings?.error || 'Error');
        } finally {
            if (token === requestToken) {
                setLoading(false);
            }
        }
    }

    function openDebugModal(tripId) {
        if (!settings.debugEnabled || !modal || !modalContent) {
            return;
        }
        const row = rowsById.get(String(tripId));
        if (!row) {
            return;
        }
        modalContent.textContent = JSON.stringify(row, null, 2);
        modal.hidden = false;
        modal.classList.add('is-open');
        lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        if (modalClose) {
            modalClose.focus();
        }
    }

    function closeDebugModal() {
        if (!modal) {
            return;
        }
        modal.hidden = true;
        modal.classList.remove('is-open');
        if (lastFocusedElement) {
            lastFocusedElement.focus();
        }
        lastFocusedElement = null;
    }

    function handleSort(event) {
        const button = event.currentTarget;
        const key = button.getAttribute('data-sort');
        if (!key) {
            return;
        }
        if (state.orderby === key) {
            state.order = state.order === 'asc' ? 'desc' : 'asc';
        } else {
            state.orderby = key;
            state.order = 'asc';
        }
        state.page = 1;
        updateSortIndicators();
        fetchRows();
    }

    function handleSearch(event) {
        state.search = event.target.value.trim();
        state.page = 1;
        fetchRows();
    }

    function goToPreviousPage() {
        if (state.page <= 1) {
            return;
        }
        state.page -= 1;
        fetchRows();
    }

    function goToNextPage() {
        if (state.page >= state.totalPages) {
            return;
        }
        state.page += 1;
        fetchRows();
    }

    const debouncedSearch = (function debounce(fn, delay) {
        let timer = null;
        return function (...args) {
            if (timer) {
                clearTimeout(timer);
            }
            timer = setTimeout(() => {
                fn.apply(null, args);
            }, delay);
        };
    })(handleSearch, 300);

    const sortButtons = table.querySelectorAll('.hrcm-sort-button');
    sortButtons.forEach((button) => {
        button.addEventListener('click', handleSort);
    });

    if (searchInput) {
        if (settings.strings?.searchPlaceholder) {
            searchInput.placeholder = settings.strings.searchPlaceholder;
        }
        searchInput.addEventListener('input', debouncedSearch);
    }

    prevButton.addEventListener('click', goToPreviousPage);
    nextButton.addEventListener('click', goToNextPage);

    if (modalClose) {
        modalClose.addEventListener('click', closeDebugModal);
    }

    if (modalBackdrop) {
        modalBackdrop.addEventListener('click', closeDebugModal);
    }

    if (modal) {
        modal.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeDebugModal();
            }
        });
    }

    updateSortIndicators();
    fetchRows();
}

if (document.readyState === 'complete' || document.readyState === 'interactive') {
    initOverallTripView();
} else {
    document.addEventListener('DOMContentLoaded', initOverallTripView);
}
