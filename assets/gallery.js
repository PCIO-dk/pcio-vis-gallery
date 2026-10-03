/* global PCIO_VIS_GALLERY */
/**
 * Frontend gallery behaviour:
 *   • Builds the /vis/galleries/{id} single-gallery grid (when galleryId set).
 *   • Upgrades any server-rendered .pcio-me-gallery grid into a lightbox.
 *
 * The /vis/galleries index and the shortcode grid are server-rendered in PHP;
 * this script only adds the interactive lightbox + keyboard navigation.
 */
( function () {
	'use strict';

	const CFG  = window.PCIO_VIS_GALLERY || {};
	const T    = CFG.i18n || {};
	const REST = ( CFG.rest || '' ).replace( /\/$/, '' );

	function escAttr( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	// ── Single gallery page: fetch + render grid ─────────────────

	async function renderSingle() {
		const mount = document.getElementById( 'pcio-me-gallery-single' );
		if ( ! mount || ! CFG.galleryId ) { return; }
		try {
			const res = await fetch( REST + '/galleries/' + CFG.galleryId, {
				headers: { 'X-WP-Nonce': CFG.nonce },
			} );
			if ( ! res.ok ) { throw new Error( 'HTTP ' + res.status ); }
			const g = await res.json();
			const photos = ( g.photos || [] ).filter( function ( p ) { return p.status === 'approved'; } );

			let html = '<a class="pcio-me-gallery__back" href="' + escAttr( CFG.baseUrl ) + '">' + escAttr( T.back ) + '</a>';
			html += '<h1 class="pcio-me-gallery__title">' + escAttr( g.title ) + '</h1>';
			if ( g.description ) { html += '<div class="pcio-me-gallery__desc">' + g.description + '</div>'; }

			if ( ! photos.length ) {
				html += '<p class="pcio-me-gallery__empty">' + escAttr( T.emptyG ) + '</p>';
			} else {
				html += '<div class="pcio-me-gallery__grid">';
				photos.forEach( function ( p, i ) {
				const isVideo = p.media_type === 'video';
				html += '<a class="pcio-me-gallery__thumb' + ( isVideo ? ' pcio-me-gallery__thumb--video' : '' ) +
					'" href="' + escAttr( p.full_url ) +
					'" data-index="' + i +
					'" data-type="' + escAttr( p.media_type || 'image' ) +
					'" data-full="' + escAttr( p.full_url ) +				'" data-poster="' + escAttr( isVideo ? ( p.thumb_url || '' ) : '' ) +					'" data-caption="' + escAttr( p.caption ) + '">' +
					( p.thumb_url ? '<img src="' + escAttr( p.thumb_url ) + '" alt="' + escAttr( p.caption ) + '" loading="lazy">' : '' ) +
					( isVideo ? '<span class="pcio-me-gallery__play" aria-hidden="true"></span>' : '' ) +
						( p.caption ? '<span class="pcio-me-gallery__caption">' + escAttr( p.caption ) + '</span>' : '' ) +
						'</a>';
				} );
				html += '</div>';
			}
			mount.innerHTML = html;
		} catch ( e ) {
			mount.innerHTML = '<p class="pcio-me-gallery__empty">' + escAttr( T.loadErr ) + '</p>';
		}
	}

	// ── Lightbox ─────────────────────────────────────────────────

	let lbItems = [];
	let lbIndex = 0;
	let lbEl    = null;

	function buildLightbox() {
		lbEl = document.createElement( 'div' );
		lbEl.className = 'pcio-me-lightbox';
		lbEl.hidden = true;
		lbEl.innerHTML =
			'<button class="pcio-me-lightbox__close" aria-label="' + escAttr( T.close ) + '">×</button>' +
			'<button class="pcio-me-lightbox__nav prev" aria-label="' + escAttr( T.prev ) + '">‹</button>' +
			'<figure class="pcio-me-lightbox__fig">' +
			'<img class="pcio-me-lightbox__img" src="" alt="">' +		'<video class="pcio-me-lightbox__video" controls preload="none" style="display:none"></video>' +			'<figcaption class="pcio-me-lightbox__cap"></figcaption>' +
			'</figure>' +
			'<button class="pcio-me-lightbox__nav next" aria-label="' + escAttr( T.next ) + '">›</button>';
		document.body.appendChild( lbEl );

		lbEl.querySelector( '.pcio-me-lightbox__close' ).addEventListener( 'click', closeLb );
		lbEl.querySelector( '.prev' ).addEventListener( 'click', function () { step( -1 ); } );
		lbEl.querySelector( '.next' ).addEventListener( 'click', function () { step( 1 ); } );
		lbEl.addEventListener( 'click', function ( e ) { if ( e.target === lbEl ) { closeLb(); } } );
		document.addEventListener( 'keydown', function ( e ) {
			if ( lbEl.hidden ) { return; }
			if ( e.key === 'Escape' ) { closeLb(); }
			if ( e.key === 'ArrowLeft' ) { step( -1 ); }
			if ( e.key === 'ArrowRight' ) { step( 1 ); }
		} );
	}

	function showLb( index ) {
		lbIndex = index;
		const item = lbItems[ lbIndex ];
		if ( ! item ) { return; }
		const img = lbEl.querySelector( '.pcio-me-lightbox__img' );
		const vid = lbEl.querySelector( '.pcio-me-lightbox__video' );
		vid.pause();
		vid.removeAttribute( 'src' );
		vid.load();
		if ( item.type === 'video' ) {
			img.style.display = 'none';
			img.src = '';
			vid.style.display = '';
			vid.poster = item.poster || '';
			vid.src = item.full;
			vid.load();
		} else {
			vid.style.display = 'none';
			img.style.display = '';
			img.src = item.full;
			img.alt = item.caption || '';
		}
		lbEl.querySelector( '.pcio-me-lightbox__cap' ).textContent = item.caption || '';
		lbEl.hidden = false;
		document.body.style.overflow = 'hidden';
	}

	function step( dir ) {
		showLb( ( lbIndex + dir + lbItems.length ) % lbItems.length );
	}

	function closeLb() {
		const vid = lbEl.querySelector( '.pcio-me-lightbox__video' );
		vid.pause();
		vid.removeAttribute( 'src' );
		vid.load();
		lbEl.hidden = true;
		document.body.style.overflow = '';
	}

	// ── Wire up any grid on the page ─────────────────────────────

	function attachGrids() {
		const thumbs = document.querySelectorAll( '.pcio-me-gallery__thumb' );
		if ( ! thumbs.length ) { return; }
		if ( ! lbEl ) { buildLightbox(); }

		lbItems = Array.prototype.map.call( thumbs, function ( a ) {
			return {
				full:    a.getAttribute( 'data-full' ),
				caption: a.getAttribute( 'data-caption' ),
				type:    a.getAttribute( 'data-type' ) || 'image',
				poster:  a.getAttribute( 'data-poster' ) || '',
			};
		} );

		thumbs.forEach( function ( a, i ) {
			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				showLb( i );
			} );
		} );
	}

	async function init() {
		await renderSingle();
		attachGrids();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
