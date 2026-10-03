/* global PCIO_MEG */
/**
 * Gallery management list — front-end (/vis/galleries/manage/).
 * Styled like the member register: sortable, filterable REST-backed table.
 * All actions persist immediately via REST.
 */
( function () {
    'use strict';

    const CFG  = window.PCIO_MEG || {};
    const T    = CFG.i18n || {};
    const REST = ( CFG.rest || '' ).replace( /\/$/, '' );
    const NONCE = CFG.nonce;
    const VIS  = CFG.visibilities || {};

    let galleries = [];
    let sortCol   = 'created';
    let sortDir   = 'desc';

    // SVG icons (stroke-based, currentColor) — match the member list.
    const ICON_EDIT  = '<svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    const ICON_VIEW  = '<svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
    const ICON_DEL   = '<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>';
    const ICON_EMPTY = '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';

    const COLUMNS = [
        { key: 'id',          label: T.colId,         sortable: true,  numeric: true },
        { key: 'created',     label: T.colDate,       sortable: true },
        { key: 'title',       label: T.colTitle,      sortable: true },
        { key: 'event_title', label: T.colEvent,      sortable: true },
        { key: 'photo_count', label: T.colPhotos,     sortable: true,  numeric: true },
        { key: 'visibility',  label: T.colVisibility, sortable: true },
        { key: 'updated',     label: T.colUpdated,    sortable: true },
    ];

    // ── Helpers ──────────────────────────────────────────────────

    function escHtml( s ) {
        return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
        } );
    }
    function escAttr( s ) { return escHtml( s ); }

    function headers() {
        return { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE };
    }

    async function api( path, opts ) {
        const res = await fetch( REST + path, Object.assign( { headers: headers() }, opts || {} ) );
        if ( ! res.ok ) {
            let msg = 'HTTP ' + res.status;
            try { const j = await res.json(); if ( j.message ) { msg = j.message; } } catch ( e ) {}
            throw new Error( msg );
        }
        return res.status === 204 ? null : res.json();
    }

    function visLabel( key ) { return VIS[ key ] || key; }

    function fmtDate( s ) {
        if ( ! s ) { return '–'; }
        return String( s ).replace( 'T', ' ' ).substring( 0, 16 );
    }

    // ── Bootstrap ────────────────────────────────────────────────

    document.addEventListener( 'DOMContentLoaded', function () {
        // If we arrived from an event's "Create one" link, create a gallery
        // pre-linked to that event and jump straight to its edit page.
        const forEvent = Number( new URLSearchParams( window.location.search ).get( 'for_event' ) ) || 0;
        if ( forEvent ) {
            createForEvent( forEvent );
            return;
        }
        buildShell();
        loadGalleries();
        loadSettings();
    } );

    async function createForEvent( eventId ) {
        try {
            const g = await api( '/galleries', {
                method: 'POST',
                body: JSON.stringify( {
                    title: T.newGalleryTitle || 'Untitled gallery',
                    event_id: eventId,
                } ),
            } );
            window.location.href = CFG.editBase + g.id + '/';
        } catch ( e ) {
            // Fall back to the normal list view on failure.
            buildShell();
            loadGalleries();
            loadSettings();
        }
    }

    function buildShell() {
        const app = document.getElementById( 'pcio-me-app' );
        if ( ! app ) { return; }

        app.innerHTML =
            '<div class="me-page-header">' +
                '<h1 class="me-page-title">' + escHtml( T.pageTitle ) + '</h1>' +
                '<div class="me-header-right">' +
                    '<span class="me-member-count" id="meg-count"></span>' +
                    '<button class="me-btn me-btn-primary" id="meg-btn-new">' + escHtml( T.newGallery ) + '</button>' +
                '</div>' +
            '</div>' +

            '<div class="me-table-card">' +
                '<div class="me-toolbar">' +
                    '<span class="me-toolbar-info" id="meg-visible-count"></span>' +
                    '<label class="meg-autocreate" title="">' +
                        '<input type="checkbox" id="meg-autocreate"> ' +
                        '<span>' + escHtml( T.autocreateLbl ) + '</span>' +
                    '</label>' +
                    '<div class="me-search-box">' +
                        '<input type="search" id="meg-global-search" placeholder="' + escAttr( T.searchPh ) + '" autocomplete="off">' +
                    '</div>' +
                '</div>' +
                '<table id="me-table">' +
                    '<thead>' +
                        '<tr class="me-head-row" id="meg-head-row"></tr>' +
                        '<tr class="me-filter-row" id="meg-filter-row"></tr>' +
                    '</thead>' +
                    '<tbody id="meg-tbody"></tbody>' +
                '</table>' +
            '</div>';

        document.getElementById( 'meg-btn-new' ).addEventListener( 'click', createGallery );
        document.getElementById( 'meg-global-search' ).addEventListener( 'input', renderRows );
        document.getElementById( 'meg-autocreate' ).addEventListener( 'change', saveAutocreate );

        buildHeadAndFilterRow();
    }

    function buildHeadAndFilterRow() {
        const headRow   = document.getElementById( 'meg-head-row' );
        const filterRow = document.getElementById( 'meg-filter-row' );

        COLUMNS.forEach( function ( col ) {
            const th = document.createElement( 'th' );
            th.dataset.col = col.key;
            if ( col.numeric ) {
                th.classList.add( 'me-cell-center' );
            }
            if ( col.sortable ) {
                th.classList.add( 'sortable' );
                th.innerHTML = escHtml( col.label ) + '<span class="sort-icon" aria-hidden="true"></span>';
                th.addEventListener( 'click', function () { toggleSort( col.key ); } );
            } else {
                th.textContent = col.label;
            }
            headRow.appendChild( th );

            const td    = document.createElement( 'td' );
            if ( col.numeric ) {
                td.classList.add( 'me-cell-center' );
            }
            const input = document.createElement( 'input' );
            input.type         = 'text';
            input.classList.add( 'me-filter-input' );
            input.dataset.col  = col.key;
            input.placeholder  = T.filterWord + ' ' + String( col.label ).toLowerCase() + '…';
            input.autocomplete = 'off';
            input.addEventListener( 'input', renderRows );
            td.appendChild( input );
            filterRow.appendChild( td );
        } );

        headRow.appendChild( document.createElement( 'th' ) );
        filterRow.appendChild( document.createElement( 'td' ) );

        updateSortClasses();
    }

    function updateSortClasses() {
        document.querySelectorAll( '#meg-head-row th[data-col]' ).forEach( function ( th ) {
            th.classList.remove( 'sort-asc', 'sort-desc' );
            if ( th.dataset.col === sortCol ) {
                th.classList.add( sortDir === 'asc' ? 'sort-asc' : 'sort-desc' );
            }
        } );
    }

    function toggleSort( col ) {
        if ( sortCol === col ) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            sortCol = col;
            sortDir = 'asc';
        }
        updateSortClasses();
        renderRows();
    }

    // ── Data ─────────────────────────────────────────────────────

    async function loadGalleries() {
        try {
            galleries = await api( '/galleries' ) || [];
            updateCount();
            renderRows();
        } catch ( err ) {
            const tbody = document.getElementById( 'meg-tbody' );
            if ( tbody ) {
                tbody.innerHTML = '<tr><td colspan="' + ( COLUMNS.length + 1 ) + '">' +
                    '<div class="me-empty-state">' + ICON_EMPTY +
                    '<p>' + escHtml( T.loadErr ) + escHtml( err.message ) + '</p></div></td></tr>';
            }
        }
    }

    async function loadSettings() {
        try {
            const s = await api( '/gallery-settings' );
            const cb = document.getElementById( 'meg-autocreate' );
            if ( cb && s ) { cb.checked = !! s.autocreate; }
        } catch ( e ) {}
    }

    async function saveAutocreate() {
        const cb = document.getElementById( 'meg-autocreate' );
        if ( ! cb ) { return; }
        const wrap = cb.closest( '.meg-autocreate' );
        if ( wrap ) { wrap.classList.add( 'is-saving' ); }
        try {
            await api( '/gallery-settings', {
                method: 'POST',
                body: JSON.stringify( { autocreate: cb.checked } ),
            } );
        } catch ( e ) {
            cb.checked = ! cb.checked; // revert on failure
        } finally {
            if ( wrap ) { wrap.classList.remove( 'is-saving' ); }
        }
    }

    function updateCount() {
        const el = document.getElementById( 'meg-count' );
        if ( el ) { el.textContent = galleries.length + ' ' + T.galleryWord; }
    }

    // ── Rendering ────────────────────────────────────────────────

    function renderRows() {
        const q = ( document.getElementById( 'meg-global-search' ) || {} ).value || '';
        const query = q.toLowerCase().trim();

        const colFilters = {};
        document.querySelectorAll( '.me-filter-input' ).forEach( function ( input ) {
            const v = input.value.trim().toLowerCase();
            if ( v ) { colFilters[ input.dataset.col ] = v; }
        } );

        let rows = galleries.filter( function ( g ) {
            const decorated = Object.assign( {}, g, { visibility: visLabel( g.visibility ) } );
            if ( query && ! Object.values( decorated ).some( function ( v ) {
                return String( v == null ? '' : v ).toLowerCase().indexOf( query ) !== -1;
            } ) ) { return false; }
            for ( const col in colFilters ) {
                const cell = col === 'visibility' ? visLabel( g[ col ] ) : g[ col ];
                if ( String( cell == null ? '' : cell ).toLowerCase().indexOf( colFilters[ col ] ) === -1 ) {
                    return false;
                }
            }
            return true;
        } );

        const numeric = COLUMNS.find( function ( c ) { return c.key === sortCol; } );
        rows = rows.slice().sort( function ( a, b ) {
            const av = String( a[ sortCol ] == null ? '' : a[ sortCol ] );
            const bv = String( b[ sortCol ] == null ? '' : b[ sortCol ] );
            const cmp = av.localeCompare( bv, undefined, { numeric: !! ( numeric && numeric.numeric ), sensitivity: 'base' } );
            return sortDir === 'asc' ? cmp : -cmp;
        } );

        const tbody = document.getElementById( 'meg-tbody' );
        if ( ! tbody ) { return; }

        if ( rows.length === 0 ) {
            tbody.innerHTML = '<tr><td colspan="' + ( COLUMNS.length + 1 ) + '">' +
                '<div class="me-empty-state">' + ICON_EMPTY +
                '<p>' + ( galleries.length === 0 ? escHtml( T.empty ) : escHtml( T.emptyFiltered ) ) + '</p>' +
                '</div></td></tr>';
        } else {
            tbody.innerHTML = rows.map( buildRow ).join( '' );
            bindRowActions();
        }

        const vis = document.getElementById( 'meg-visible-count' );
        if ( vis ) {
            vis.textContent = rows.length < galleries.length
                ? T.showing + ' ' + rows.length + ' ' + T.of + ' ' + galleries.length + ' ' + T.galleryWord
                : galleries.length + ' ' + T.galleryWord;
        }
    }

    function buildRow( g ) {
        const editUrl = CFG.editBase + g.id + '/';
        const viewUrl = CFG.viewBase + g.id + '/';

        return '<tr>' +
            '<td class="me-cell-center">' + escHtml( g.id ) + '</td>' +
            '<td>' + escHtml( fmtDate( g.created ) ) + '</td>' +
            '<td class="me-cell-name">' +
                ( g.cover_url ? '<img class="meg-row-thumb" src="' + escAttr( g.cover_url ) + '" alt="" aria-hidden="true">' : '<span class="meg-row-thumb meg-row-thumb--empty" aria-hidden="true"></span>' ) +
                '<a href="' + escAttr( editUrl ) + '">' + escHtml( g.title || '–' ) + '</a>' +
            '</td>' +
            '<td>' + ( g.event_title ? escHtml( g.event_title ) : '<span style="color:#94a3b8">–</span>' ) + '</td>' +
            '<td class="me-cell-center">' + escHtml( g.photo_count != null ? g.photo_count : 0 ) + '</td>' +
            '<td><span class="me-role-badge meg-vis-' + escAttr( g.visibility.replace( /_/g, '-' ) ) + '">' + escHtml( visLabel( g.visibility ) ) + '</span></td>' +
            '<td>' + escHtml( fmtDate( g.updated ) ) + '</td>' +
            '<td><div class="me-row-actions">' +
                '<a class="me-icon-btn edit" href="' + escAttr( editUrl ) + '" title="' + escAttr( T.titleEdit ) + '">' + ICON_EDIT + '</a>' +
                '<a class="me-icon-btn" href="' + escAttr( viewUrl ) + '" title="' + escAttr( T.titleView ) + '" target="_blank" rel="noopener">' + ICON_VIEW + '</a>' +
                '<button class="me-icon-btn delete meg-del-btn" data-id="' + g.id + '" title="' + escAttr( T.titleDelete ) + '">' + ICON_DEL + '</button>' +
            '</div></td>' +
        '</tr>';
    }

    function bindRowActions() {
        document.querySelectorAll( '.meg-del-btn' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                deleteGallery( parseInt( btn.dataset.id, 10 ) );
            } );
        } );
    }

    // ── Actions ──────────────────────────────────────────────────

    async function createGallery() {
        const btn = document.getElementById( 'meg-btn-new' );
        if ( btn ) { btn.disabled = true; }
        try {
            const g = await api( '/galleries', {
                method: 'POST',
                body: JSON.stringify( { title: T.newGalleryTitle || 'Untitled gallery' } ),
            } );
            window.location.href = CFG.editBase + g.id + '/';
        } catch ( e ) {
            if ( btn ) { btn.disabled = false; }
            window.alert( ( T.loadErr || '' ) + e.message );
        }
    }

    async function deleteGallery( id ) {
        if ( ! window.confirm( T.confirmDel ) ) { return; }
        try {
            await api( '/galleries/' + id, { method: 'DELETE' } );
            galleries = galleries.filter( function ( g ) { return Number( g.id ) !== id; } );
            updateCount();
            renderRows();
        } catch ( e ) {
            window.alert( ( T.loadErr || '' ) + e.message );
        }
    }
} )();
