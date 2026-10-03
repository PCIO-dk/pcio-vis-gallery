/* global PCIO_MEG, Quill, jQuery, wp */
/**
 * Gallery edit page — front-end (/vis/galleries/manage/{id}/).
 * Styled like the event editor: General / Photos / Permissions tabs.
 *
 * All changes persist automatically:
 *   • text fields (title, caption)  → debounced 500ms
 *   • rich-text description (Quill) → debounced 800ms, stored as HTML
 *   • selects / checkboxes / cover / reorder / add / delete → immediately
 */
( function ( $ ) {
    'use strict';

    const CFG   = window.PCIO_MEG || {};
    const T     = CFG.i18n || {};
    const REST  = ( CFG.rest || '' ).replace( /\/$/, '' );
    const NONCE = CFG.nonce;
    const VIS   = CFG.visibilities || {};

    const galleryId = Number( CFG.galleryId || 0 );
    let gallery   = null;
    let photos    = [];
    let events    = [];
    let activeTab = 'general';

    let titleTimer = null;
    let descTimer  = null;
    let descQuill  = null;
    let saveStateTimer = null;

    const app = document.getElementById( 'pcio-meg-edit-app' );

    const TABS = [
        { id: 'general',     label: T.tabGeneral },
        { id: 'photos',      label: T.tabPhotos },
        { id: 'permissions', label: T.tabPermissions },
    ];

    const ICON_DEL = '<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>';
    const ICON_STAR = '<svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';

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

    // state: 'saving' | 'saved' | 'error'
    function showSaveState( state ) {
        const el = document.getElementById( 'meg-save-indicator' );
        if ( ! el ) { return; }
        if ( saveStateTimer ) { clearTimeout( saveStateTimer ); }
        el.className = 'me-save-indicator me-save-' + state;
        el.textContent = state === 'saving' ? T.stateSaving
                       : state === 'saved'  ? T.stateSaved
                       :                      T.stateError;
        if ( state !== 'saving' ) {
            saveStateTimer = setTimeout( function () { el.classList.add( 'me-hidden' ); }, 2500 );
        }
    }

    /** Persist a partial gallery update; merges the response back into state. */
    async function patchGallery( data ) {
        showSaveState( 'saving' );
        try {
            const updated = await api( '/galleries/' + galleryId, {
                method: 'PUT', body: JSON.stringify( data ),
            } );
            // Preserve photo list (PUT response includes it, but be safe).
            photos  = updated.photos || photos;
            gallery = updated;
            showSaveState( 'saved' );
            return updated;
        } catch ( e ) {
            showSaveState( 'error' );
            throw e;
        }
    }

    // ── Load ─────────────────────────────────────────────────────

    async function loadEvents() {
        try {
            const res = await fetch( CFG.eventsRest, { headers: { 'X-WP-Nonce': NONCE } } );
            events = res.ok ? await res.json() : [];
        } catch ( e ) { events = []; }
    }

    async function loadGallery() {
        gallery = await api( '/galleries/' + galleryId );
        photos  = gallery.photos || [];
    }

    // ── Render shell + tabs ──────────────────────────────────────

    function render() {
        const tabItems = TABS.map( function ( t ) {
            return '<button class="me-tab-btn' + ( t.id === activeTab ? ' active' : '' ) +
                '" data-tab="' + t.id + '" type="button">' + escHtml( t.label ) + '</button>';
        } ).join( '' );

        app.innerHTML =
            '<div class="me-page-header">' +
                '<div>' +
                    '<a class="me-back-link" href="' + escAttr( CFG.manageUrl ) + '">' +
                        '<svg viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"/></svg> ' +
                        escHtml( T.btnBack ) +
                    '</a>' +
                    '<h1 class="me-page-title" style="margin-top:6px"><span id="meg-heading">' +
                        escHtml( gallery.title || T.editGallery ) + '</span></h1>' +
                '</div>' +
                '<div class="me-header-right" style="align-items:flex-end;gap:10px">' +
                    '<span class="me-save-indicator me-hidden" id="meg-save-indicator">' + escHtml( T.stateSaving ) + '</span>' +
                '</div>' +
            '</div>' +

            '<div class="me-tab-bar" role="tablist">' + tabItems + '</div>' +
            '<div class="me-tab-content" id="meg-tab-content"></div>';

        app.querySelectorAll( '.me-tab-btn' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                activeTab = btn.getAttribute( 'data-tab' );
                app.querySelectorAll( '.me-tab-btn' ).forEach( function ( b ) { b.classList.remove( 'active' ); } );
                btn.classList.add( 'active' );
                renderTab();
            } );
        } );

        renderTab();
    }

    function renderTab() {
        const c = document.getElementById( 'meg-tab-content' );
        if ( activeTab === 'photos' )      { renderPhotosTab( c ); }
        else if ( activeTab === 'permissions' ) { renderPermissionsTab( c ); }
        else                               { renderGeneralTab( c ); }
    }

    // ── Tab: General ─────────────────────────────────────────────

    function renderGeneralTab( c ) {
        let eventOpts = '<option value="0">' + escHtml( T.noEvent ) + '</option>';
        events.forEach( function ( e ) {
            const sel = Number( gallery.event_id ) === Number( e.id ) ? ' selected' : '';
            eventOpts += '<option value="' + e.id + '"' + sel + '>' + escHtml( e.title ) + '</option>';
        } );

        const coverImg = gallery.cover_url
            ? '<img src="' + escAttr( gallery.cover_url ) + '" class="meg-cover-preview" alt="">'
            : '<span class="meg-cover-none">' + escHtml( T.fieldCover ) + '</span>';

        c.innerHTML =
            '<div class="me-tab-card">' +
                '<div class="me-form-row">' +
                    '<div class="me-form-group">' +
                        '<label class="me-form-label" for="meg-title">' + escHtml( T.fieldTitle ) + '</label>' +
                        '<input class="me-form-input" type="text" id="meg-title" maxlength="190" value="' + escAttr( gallery.title ) + '">' +
                        '<span class="me-field-error" id="meg-err-title"></span>' +
                    '</div>' +
                    '<div class="me-form-group">' +
                        '<label class="me-form-label" for="meg-event">' + escHtml( T.fieldEvent ) + '</label>' +
                        '<select class="me-form-input" id="meg-event">' + eventOpts + '</select>' +
                    '</div>' +
                '</div>' +
                '<div class="me-form-group" style="margin-top:14px">' +
                    '<label class="me-form-label">' + escHtml( T.fieldDesc ) + '</label>' +
                    '<div id="meg-quill"></div>' +
                '</div>' +
                '<div class="me-form-group" style="margin-top:14px">' +
                    '<label class="me-form-label">' + escHtml( T.fieldCover ) + '</label>' +
                    '<div class="meg-cover" id="meg-cover">' + coverImg + '</div>' +
                    '<p class="me-form-hint">' + escHtml( T.btnSetCover ) + ' — ' + escHtml( T.tabPhotos ) + '</p>' +
                '</div>' +
            '</div>';

        // Title autosave (debounced).
        document.getElementById( 'meg-title' ).addEventListener( 'input', function () {
            clearTimeout( titleTimer );
            titleTimer = setTimeout( saveTitle, 500 );
        } );

        // Event link saves immediately.
        document.getElementById( 'meg-event' ).addEventListener( 'change', function () {
            patchGallery( { event_id: Number( this.value ) || 0 } ).catch( function () {} );
        } );

        initQuill();
    }

    async function saveTitle() {
        titleTimer = null;
        const el  = document.getElementById( 'meg-title' );
        if ( ! el ) { return; }
        const errEl = document.getElementById( 'meg-err-title' );
        const title = el.value.trim();
        if ( ! title ) {
            if ( errEl ) { errEl.textContent = T.errTitleReq; }
            return;
        }
        if ( errEl ) { errEl.textContent = ''; }
        try {
            await patchGallery( { title: title } );
            const h = document.getElementById( 'meg-heading' );
            if ( h ) { h.textContent = title; }
        } catch ( e ) {}
    }

    function initQuill() {
        if ( typeof Quill === 'undefined' ) { return; }
        descQuill = new Quill( '#meg-quill', {
            theme: 'snow',
            modules: {
                toolbar: [
                    [ 'bold', 'italic', 'underline' ],
                    [ { list: 'ordered' }, { list: 'bullet' } ],
                    [ 'link' ],
                    [ 'clean' ],
                ],
            },
            placeholder: T.phDesc,
        } );

        if ( gallery.description ) {
            descQuill.clipboard.dangerouslyPasteHTML( gallery.description );
            descQuill.history.clear();
        }

        descQuill.on( 'text-change', function ( delta, oldDelta, source ) {
            if ( source !== 'user' ) { return; }
            clearTimeout( descTimer );
            descTimer = setTimeout( saveDescription, 800 );
        } );
    }

    async function saveDescription() {
        descTimer = null;
        if ( ! descQuill ) { return; }
        const raw  = descQuill.root.innerHTML;
        const html = raw === '<p><br></p>' ? '' : raw;
        try {
            await patchGallery( { description: html } );
        } catch ( e ) {}
    }

    // ── Tab: Permissions ─────────────────────────────────────────

    function renderPermissionsTab( c ) {
        let visOpts = '';
        Object.keys( VIS ).forEach( function ( key ) {
            const sel = gallery.visibility === key ? ' selected' : '';
            visOpts += '<option value="' + key + '"' + sel + '>' + escHtml( VIS[ key ] ) + '</option>';
        } );

        let roleChecks = '';
        ( CFG.visRoles || [] ).forEach( function ( r ) {
            const checked = ( gallery.visibility_roles || [] ).indexOf( r.slug ) !== -1 ? ' checked' : '';
            roleChecks += '<label class="meg-role"><input type="checkbox" value="' + escAttr( r.slug ) + '"' + checked + '> ' +
                escHtml( r.name ) + '</label>';
        } );

        c.innerHTML =
            '<div class="me-tab-card">' +
                '<div class="me-form-group">' +
                    '<label class="me-form-label" for="meg-visibility">' + escHtml( T.fieldVisibility ) + '</label>' +
                    '<select class="me-form-input" id="meg-visibility">' + visOpts + '</select>' +
                '</div>' +
                '<div class="me-form-group" id="meg-roles-row" style="margin-top:14px">' +
                    '<label class="me-form-label">' + escHtml( T.rolesLabel ) + '</label>' +
                    '<div class="meg-roles" id="meg-roles">' + ( roleChecks || '—' ) + '</div>' +
                '</div>' +
            '</div>';

        const vis = document.getElementById( 'meg-visibility' );
        const row = document.getElementById( 'meg-roles-row' );
        function toggleRoles() {
            row.style.display = vis.value === 'roles' ? '' : 'none';
        }
        toggleRoles();

        vis.addEventListener( 'change', function () {
            toggleRoles();
            patchGallery( { visibility: vis.value } ).catch( function () {} );
        } );

        document.querySelectorAll( '#meg-roles input[type=checkbox]' ).forEach( function ( cb ) {
            cb.addEventListener( 'change', function () {
                const roles = [];
                document.querySelectorAll( '#meg-roles input:checked' ).forEach( function ( x ) { roles.push( x.value ); } );
                patchGallery( { visibility_roles: roles } ).catch( function () {} );
            } );
        } );
    }

    // ── Tab: Photos ──────────────────────────────────────────────

    function renderPhotosTab( c ) {
        c.innerHTML =
            '<div class="me-tab-card">' +
                '<div class="meg-photos-toolbar">' +
                    '<button class="me-btn me-btn-primary" id="meg-add-photos" type="button">' + escHtml( T.btnAddPhotos ) + '</button>' +
                    '<span class="me-form-hint">' + escHtml( T.dragHint ) + '</span>' +
                '</div>' +
                '<div id="meg-photo-grid" class="meg-photo-grid"></div>' +
            '</div>';

        document.getElementById( 'meg-add-photos' ).addEventListener( 'click', openMedia );
        renderPhotos();
    }

    function renderPhotos() {
        const grid = document.getElementById( 'meg-photo-grid' );
        if ( ! grid ) { return; }
        if ( ! photos.length ) {
            grid.innerHTML = '<p class="me-form-hint">' + escHtml( T.noPhotos ) + '</p>';
            return;
        }
        grid.innerHTML = photos.map( function ( p ) {
            const isCover = Number( gallery.cover_attachment_id ) === Number( p.attachment_id );
            const isVideo = p.media_type === 'video';
            const pending = p.status === 'pending'
                ? '<span class="meg-pending">' + escHtml( T.statusPending ) + '</span>' : '';
            const thumb = p.thumb_url
                ? '<img src="' + escAttr( p.thumb_url ) + '" alt="">'
                : '<div class="meg-video-placeholder">&#9654;</div>';
            const posterBtn = isVideo
                ? '<button class="meg-poster-btn" type="button">' +
                    escHtml( Number( p.poster_attachment_id ) > 0 ? T.btnChangePoster : T.btnSetPoster ) +
                    '</button>'
                : '';
            return '<div class="meg-photo' + ( isVideo ? ' meg-photo--video' : '' ) + '" data-id="' + p.id + '" data-att="' + p.attachment_id + '">' +
                ( isCover ? '<span class="meg-cover-badge">' + escHtml( T.coverBadge ) + '</span>' : '' ) +
                pending +
                thumb +
                posterBtn +
                '<input type="text" class="meg-caption me-form-input" placeholder="' + escAttr( T.captionPh ) + '" value="' + escAttr( p.caption ) + '">' +
                '<div class="meg-photo-actions">' +
                    '<button class="me-icon-btn meg-cover-btn" type="button" title="' + escAttr( T.btnSetCover ) + '">' + ICON_STAR + '</button>' +
                    '<button class="me-icon-btn delete meg-del-photo" type="button" title="' + escAttr( T.btnRemove ) + '">' + ICON_DEL + '</button>' +
                '</div>' +
            '</div>';
        } ).join( '' );

        bindPhotoActions();
        makeSortable();
    }

    function bindPhotoActions() {
        const grid = document.getElementById( 'meg-photo-grid' );
        grid.querySelectorAll( '.meg-photo' ).forEach( function ( card ) {
            const id  = Number( card.getAttribute( 'data-id' ) );
            const att = Number( card.getAttribute( 'data-att' ) );
            let capTimer = null;

            card.querySelector( '.meg-caption' ).addEventListener( 'input', function () {
                const val = this.value;
                clearTimeout( capTimer );
                capTimer = setTimeout( function () {
                    showSaveState( 'saving' );
                    api( '/galleries/' + galleryId + '/photos/' + id, {
                        method: 'PUT', body: JSON.stringify( { caption: val } ),
                    } ).then( function () {
                        // Keep local state in sync so re-renders show the new caption.
                        const local = photos.find( function ( p ) { return Number( p.id ) === id; } );
                        if ( local ) { local.caption = val; }
                        showSaveState( 'saved' );
                    } )
                       .catch( function () { showSaveState( 'error' ); } );
                }, 500 );
            } );

            card.querySelector( '.meg-cover-btn' ).addEventListener( 'click', function () {
                patchGallery( { cover_attachment_id: att } ).then( function () {
                    renderPhotos();
                } ).catch( function () {} );
            } );

            card.querySelector( '.meg-del-photo' ).addEventListener( 'click', function () {
                showSaveState( 'saving' );
                api( '/galleries/' + galleryId + '/photos/' + id, { method: 'DELETE' } )
                    .then( function () {
                        photos = photos.filter( function ( p ) { return p.id !== id; } );
                        showSaveState( 'saved' );
                        renderPhotos();
                    } )
                    .catch( function () { showSaveState( 'error' ); } );
            } );

            const posterBtnEl = card.querySelector( '.meg-poster-btn' );
            if ( posterBtnEl ) {
                posterBtnEl.addEventListener( 'click', function () { openPosterPicker( id ); } );
            }
        } );
    }

    function makeSortable() {
        if ( ! $ || ! $.fn || ! $.fn.sortable ) { return; }
        $( '#meg-photo-grid' ).sortable( {
            items: '.meg-photo',
            tolerance: 'pointer',
            update: function () {
                const order = $( '#meg-photo-grid .meg-photo' ).map( function () {
                    return Number( $( this ).attr( 'data-id' ) );
                } ).get();
                showSaveState( 'saving' );
                api( '/galleries/' + galleryId + '/photos/reorder', {
                    method: 'POST', body: JSON.stringify( { order: order } ),
                } ).then( function ( updated ) {
                    if ( Array.isArray( updated ) ) { photos = updated; }
                    showSaveState( 'saved' );
                } ).catch( function () { showSaveState( 'error' ); } );
            },
        } );
    }

    function openPosterPicker( photoId ) {
        if ( ! window.wp || ! wp.media ) { return; }
        const frame = wp.media( {
            title:  T.posterTitle,
            button: { text: T.posterBtn },
            multiple: false,
            library: { type: 'image' },
        } );
        frame.on( 'select', async function () {
            const selection = frame.state().get( 'selection' );
            const item = selection ? selection.first() : null;
            if ( ! item ) { showSaveState( 'error' ); return; }
            const sel = item.toJSON();
            showSaveState( 'saving' );
            try {
                const updated = await api( '/galleries/' + galleryId + '/photos/' + photoId, {
                    method: 'PUT',
                    body: JSON.stringify( { poster_attachment_id: sel.id } ),
                } );
                const idx = photos.findIndex( function ( p ) { return Number( p.id ) === photoId; } );
                if ( idx !== -1 ) { photos[ idx ] = updated; }
                showSaveState( 'saved' );
                renderPhotos();
            } catch ( e ) {
                showSaveState( 'error' );
            }
        } );
        frame.open();
    }

    function openMedia() {
        if ( ! window.wp || ! wp.media ) { return; }
        const frame = wp.media( {
            title: T.mediaTitle,
            button: { text: T.mediaBtn },
            multiple: true,
            library: { type: [ 'image', 'video' ] },
        } );
        frame.on( 'select', async function () {
            const selection = frame.state().get( 'selection' ).toJSON();
            showSaveState( 'saving' );
            let ok = true;
            for ( let i = 0; i < selection.length; i++ ) {
                try {
                    const photo = await api( '/galleries/' + galleryId + '/photos', {
                        method: 'POST',
                        body: JSON.stringify( { attachment_id: selection[ i ].id } ),
                    } );
                    photos.push( photo );
                } catch ( e ) { ok = false; }
            }
            showSaveState( ok ? 'saved' : 'error' );
            renderPhotos();
        } );
        frame.open();
    }

    // ── Boot ─────────────────────────────────────────────────────

    async function boot() {
        try {
            await loadEvents();
            await loadGallery();
            render();
        } catch ( e ) {
            app.innerHTML = '<div class="me-tab-card"><p class="me-field-error">' +
                escHtml( T.loadFail || e.message ) + '</p></div>';
        }
    }

    if ( app && galleryId ) { boot(); }
} )( window.jQuery );
