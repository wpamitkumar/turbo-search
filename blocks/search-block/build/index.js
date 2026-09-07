/**
 * Turbo Search: Gutenberg Block Editor Script
 *
 * Registered manually in PHP with correct wp-blocks, wp-element,
 * wp-block-editor, wp-components, wp-i18n dependencies.
 * No build step required.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	if ( ! blocks || ! blocks.registerBlockType ) {
		console.warn( 'Turbo Search: wp.blocks not available.' );
		return;
	}

	var registerBlockType  = blocks.registerBlockType;
	var el                 = element.createElement;
	var Fragment           = element.Fragment;
	var useBlockProps      = blockEditor.useBlockProps;
	var InspectorControls  = blockEditor.InspectorControls;
	var PanelBody          = components.PanelBody;
	var TextControl        = components.TextControl;
	var SelectControl      = components.SelectControl;
	var RangeControl       = components.RangeControl;
	var ToggleControl      = components.ToggleControl;
	var CheckboxControl    = components.CheckboxControl;
	var ColorPicker        = components.ColorPicker;
	var __                 = i18n.__;

	/* Theme presets */
	var THEMES = {
		light:   { primary:'#2563eb', bg:'#ffffff', text:'#1e293b', border:'#e2e8f0', highlight:'#fef08a' },
		dark:    { primary:'#60a5fa', bg:'#1e293b', text:'#f1f5f9', border:'#334155', highlight:'#854d0e' },
		minimal: { primary:'#000000', bg:'#ffffff', text:'#000000', border:'#000000', highlight:'#d1d5db' },
		glass:   { primary:'#3b82f6', bg:'rgba(255,255,255,0.85)', text:'#0f172a', border:'rgba(255,255,255,0.4)', highlight:'#fef08a' },
	};

	/* Color value helper (ColorPicker returns {hex} in some WP versions) */
	function colorVal( v ) {
		if ( ! v ) return '';
		if ( typeof v === 'object' && v.hex ) return v.hex;
		return String( v );
	}

	/* Edit component */
	function Edit( props ) {
		var a   = props.attributes;
		var set = props.setAttributes;

		/* Apply a theme preset */
		function applyTheme( t ) {
			if ( t === 'custom' ) { set( { theme: t } ); return; }
			var p = THEMES[ t ];
			if ( ! p ) return;
			set( { theme: t, primaryColor: p.primary, backgroundColor: p.bg,
				textColor: p.text, borderColor: p.border, highlightColor: p.highlight } );
		}

		var sizeH      = { small: '42px', medium: '52px', large: '62px' }[ a.inputSize ] || '52px';
		var blockProps = useBlockProps( { style: { maxWidth: ( a.maxWidth || 640 ) + 'px' } } );

		return el( Fragment, null,

			/* Inspector sidebar panels */
			el( InspectorControls, null,

				/* Search Behaviour */
				el( PanelBody, { title: __( '🔎 Search Behaviour & Features', 'turbo-search' ), initialOpen: true },
					el( TextControl, {
						label: __( 'Placeholder Text', 'turbo-search' ), value: a.placeholder,
						onChange: function ( v ) { set( { placeholder: v } ); },
					} ),
					el( 'div', { className: 'wpts-block-post-types', style: { marginBottom: 16 } },
						el( 'label', { className: 'components-base-control__label', style: { display: 'block', marginBottom: 6, fontWeight: 600 } },
							__( 'Filter by Post Types (Multiple)', 'turbo-search' )
						),
						( function () {
							var types = ( window.wptsBlock && window.wptsBlock.postTypes && window.wptsBlock.postTypes.length ) ? window.wptsBlock.postTypes : [
								{ slug: 'post', label: 'Posts' },
								{ slug: 'page', label: 'Pages' },
								{ slug: 'product', label: 'Products' }
							];

							var currentList = [];
							if ( Array.isArray( a.postType ) ) {
								currentList = a.postType.slice();
							} else if ( typeof a.postType === 'string' && a.postType.length > 0 ) {
								currentList = a.postType.split( ',' ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
							}

							var toggleType = function ( slug, isChecked ) {
								var list = currentList.slice();
								if ( isChecked ) {
									if ( list.indexOf( slug ) === -1 ) list.push( slug );
								} else {
									list = list.filter( function ( s ) { return s !== slug; } );
								}
								set( { postType: list.join( ',' ) } );
							};

							var isAll = currentList.length === 0;

							return el( 'div', { style: { display: 'flex', flexDirection: 'column', gap: 6, background: '#f8fafc', padding: '10px 12px', borderRadius: 8, border: '1px solid #e2e8f0' } },
								el( CheckboxControl, {
									label: __( '🌐 All Indexed Types (Default)', 'turbo-search' ),
									checked: isAll,
									onChange: function () { set( { postType: '' } ); }
								} ),
								el( 'div', { style: { display: 'flex', flexDirection: 'column', gap: 4, paddingLeft: 4 } },
									types.map( function ( pt ) {
										var isChecked = currentList.indexOf( pt.slug ) !== -1;
										return el( CheckboxControl, {
											key: 'cb-' + pt.slug,
											label: pt.label + ' (' + pt.slug + ')',
											checked: isChecked,
											onChange: function ( checked ) { toggleType( pt.slug, checked ); }
										} );
									} )
								),
								el( 'div', { style: { display: 'flex', flexWrap: 'wrap', gap: 4, marginTop: 6, paddingTop: 6, borderTop: '1px dashed #cbd5e1' } },
									types.map( function ( pt ) {
										var isChecked = currentList.indexOf( pt.slug ) !== -1;
										return el( 'button', {
											key: 'pill-' + pt.slug,
											type: 'button',
											style: {
												padding: '2px 8px',
												borderRadius: 99,
												fontSize: 11,
												fontWeight: 600,
												cursor: 'pointer',
												border: isChecked ? '1px solid #2563eb' : '1px solid #cbd5e1',
												background: isChecked ? '#2563eb' : '#ffffff',
												color: isChecked ? '#ffffff' : '#475569',
												lineHeight: '1.4'
											},
											onClick: function () { toggleType( pt.slug, ! isChecked ); }
										}, ( isChecked ? '✓ ' : '+ ' ) + pt.label );
									} )
								)
							);
						} )()
					),
					el( SelectControl, {
						label: __( 'Results Layout', 'turbo-search' ),
						value: a.layout || '',
						options: [
							{ label: __( '🌐 Default (Inherit Global Settings)', 'turbo-search' ), value: '' },
							{ label: __( '📄 List View', 'turbo-search' ),                        value: 'list' },
							{ label: __( '⊞ Grid Cards', 'turbo-search' ),                       value: 'grid' },
							{ label: __( '🗂️ Compact Card', 'turbo-search' ),                     value: 'card' },
						],
						onChange: function ( v ) { set( { layout: v } ); },
						help: __( 'Choose between List View, Grid Cards, or Compact layout.', 'turbo-search' )
					} ),
					el( ToggleControl, {
						label: __( 'Show Voice Search Button', 'turbo-search' ),
						help: __( 'Allows visitors to speak their query. Works with HTTPS connection only (or localhost).', 'turbo-search' ),
						checked: a.showVoice,
						onChange: function ( v ) { set( { showVoice: v } ); },
					} ),
					el( ToggleControl, {
						label: __( 'Show Command + K Spotlight Hint', 'turbo-search' ),
						checked: a.enableCommandK,
						onChange: function ( v ) { set( { enableCommandK: v } ); },
					} ),
					el( RangeControl, {
						label: __( 'Results per Page', 'turbo-search' ), value: a.perPage,
						onChange: function ( v ) { set( { perPage: v } ); },
						min: 1, max: 20, step: 1,
					} ),
					el( RangeControl, {
						label: __( 'Min Characters to Search', 'turbo-search' ), value: a.minChars,
						onChange: function ( v ) { set( { minChars: v } ); },
						min: 1, max: 5, step: 1,
						help: __( 'Search fires after this many characters are typed.', 'turbo-search' ),
					} ),
					el( ToggleControl, {
						label: __( 'Open results in new tab', 'turbo-search' ),
						checked: a.openInNewTab,
						onChange: function ( v ) { set( { openInNewTab: v } ); },
					} )
				),

				/* Performance */
				el( PanelBody, { title: __( '⚡ Performance', 'turbo-search' ), initialOpen: false },
					el( RangeControl, {
						label: __( 'Debounce (ms)', 'turbo-search' ), value: a.debounce,
						onChange: function ( v ) { set( { debounce: v } ); },
						min: 0, max: 1000, step: 50,
						help: __( 'Wait after user stops typing before searching (recommended: 150–300ms).', 'turbo-search' ),
					} ),
					el( RangeControl, {
						label: __( 'Throttle (ms)', 'turbo-search' ), value: a.throttle,
						onChange: function ( v ) { set( { throttle: v } ); },
						min: 0, max: 2000, step: 100,
						help: __( 'Max fire frequency while typing. 0 = disabled. Use throttle OR debounce, not both.', 'turbo-search' ),
					} )
				),

				/* Results Display */
				el( PanelBody, { title: __( '📋 Results Display & Commerce', 'turbo-search' ), initialOpen: true },
					el( ToggleControl, {
						label: __( 'Categorized Multi-Tabs in Dropdown', 'turbo-search' ),
						checked: a.categoryTabs,
						onChange: function ( v ) { set( { categoryTabs: v } ); },
					} ),
					el( ToggleControl, {
						label: __( 'WooCommerce Instant 1-Click Add-to-Cart', 'turbo-search' ),
						checked: a.quickAddToCart,
						onChange: function ( v ) { set( { quickAddToCart: v } ); },
					} ),
					el( ToggleControl, {
						label: __( 'Show post type badge', 'turbo-search' ),
						checked: a.showPostType,
						onChange: function ( v ) { set( { showPostType: v } ); },
					} ),
					el( ToggleControl, {
						label: __( 'Show excerpt', 'turbo-search' ),
						checked: a.showExcerpt,
						onChange: function ( v ) { set( { showExcerpt: v } ); },
					} ),
					el( RangeControl, {
						label: __( 'Dropdown max height (px)', 'turbo-search' ), value: a.dropdownMaxHeight,
						onChange: function ( v ) { set( { dropdownMaxHeight: v } ); },
						min: 200, max: 800, step: 20,
					} )
				),

				/* Styling */
				el( PanelBody, { title: __( '🎨 Styling', 'turbo-search' ), initialOpen: false },
					el( SelectControl, {
						label: __( 'Theme Preset', 'turbo-search' ), value: a.theme,
						options: [
							{ label: __( 'Light (default)', 'turbo-search' ), value: 'light'   },
							{ label: __( 'Dark',            'turbo-search' ), value: 'dark'    },
							{ label: __( 'Minimal',         'turbo-search' ), value: 'minimal' },
							{ label: __( 'Glassmorphism',   'turbo-search' ), value: 'glass'   },
							{ label: __( 'Custom',          'turbo-search' ), value: 'custom'  },
						],
						onChange: applyTheme,
					} ),
					el( SelectControl, {
						label: __( 'Input Size', 'turbo-search' ), value: a.inputSize,
						options: [
							{ label: __( 'Small (42px)',  'turbo-search' ), value: 'small'  },
							{ label: __( 'Medium (52px)', 'turbo-search' ), value: 'medium' },
							{ label: __( 'Large (62px)',  'turbo-search' ), value: 'large'  },
						],
						onChange: function ( v ) { set( { inputSize: v } ); },
					} ),
					el( RangeControl, {
						label: __( 'Max Width (px)', 'turbo-search' ), value: a.maxWidth,
						onChange: function ( v ) { set( { maxWidth: v } ); },
						min: 200, max: 1200, step: 10,
					} ),
					el( RangeControl, {
						label: __( 'Border Radius (px)', 'turbo-search' ), value: a.borderRadius,
						onChange: function ( v ) { set( { borderRadius: v } ); },
						min: 0, max: 40, step: 1,
					} ),

					el( 'p', { style: { fontWeight: 600, marginTop: 12, marginBottom: 4 } }, __( 'Primary Color', 'turbo-search' ) ),
					el( ColorPicker, { color: a.primaryColor, enableAlpha: false,
						onChange: function ( v ) { set( { primaryColor: colorVal( v ), theme: 'custom' } ); } } ),

					el( 'p', { style: { fontWeight: 600, marginTop: 12, marginBottom: 4 } }, __( 'Background Color', 'turbo-search' ) ),
					el( ColorPicker, { color: a.backgroundColor, enableAlpha: false,
						onChange: function ( v ) { set( { backgroundColor: colorVal( v ), theme: 'custom' } ); } } ),

					el( 'p', { style: { fontWeight: 600, marginTop: 12, marginBottom: 4 } }, __( 'Text Color', 'turbo-search' ) ),
					el( ColorPicker, { color: a.textColor, enableAlpha: false,
						onChange: function ( v ) { set( { textColor: colorVal( v ), theme: 'custom' } ); } } ),

					el( 'p', { style: { fontWeight: 600, marginTop: 12, marginBottom: 4 } }, __( 'Border Color', 'turbo-search' ) ),
					el( ColorPicker, { color: a.borderColor, enableAlpha: false,
						onChange: function ( v ) { set( { borderColor: colorVal( v ), theme: 'custom' } ); } } ),

					el( 'p', { style: { fontWeight: 600, marginTop: 12, marginBottom: 4 } }, __( 'Highlight Color', 'turbo-search' ) ),
					el( ColorPicker, { color: a.highlightColor, enableAlpha: false,
						onChange: function ( v ) { set( { highlightColor: colorVal( v ), theme: 'custom' } ); } } )
				)
			),

			/* Editor preview */
			el( 'div', blockProps,
				el( 'div', {
					className: 'wpts-editor-preview',
					style: {
						'--wpts-primary':   a.primaryColor,
						'--wpts-bg':        a.backgroundColor,
						'--wpts-text':      a.textColor,
						'--wpts-border':    a.borderColor,
						'--wpts-radius':    ( a.borderRadius || 10 ) + 'px',
						'--wpts-highlight': a.highlightColor,
					},
				},
					el( 'div', { className: 'wpts-editor-preview__bar', style: { height: sizeH } },
						el( 'span', { className: 'wpts-editor-preview__icon' },
							el( 'svg', { viewBox: '0 0 24 24', width: '18', height: '18',
										fill: 'none', stroke: 'currentColor', strokeWidth: '2' },
								el( 'circle', { cx: '11', cy: '11', r: '8' } ),
								el( 'line', { x1: '21', y1: '21', x2: '16.65', y2: '16.65' } )
							)
						),
						el( 'span', { className: 'wpts-editor-preview__placeholder' }, a.placeholder || 'Search\u2026' ),
						el( 'span', { className: 'wpts-editor-preview__meta' },
							a.debounce > 0  && el( 'span', null, '\u23f1 ' + a.debounce + 'ms debounce' ),
							a.throttle > 0  && el( 'span', null, '\ud83d\udea6 ' + a.throttle + 'ms throttle' ),
							a.postType      && el( 'span', null, '📁 ' + a.postType.split( ',' ).join( ' + ' ) ),
							a.layout        && el( 'span', null, '📐 ' + a.layout ),
							el( 'span', null, a.perPage + ' results' ),
							el( 'span', null, a.inputSize + ' size' )
						)
					),
					el( 'p', { className: 'wpts-editor-preview__hint' },
						'\ud83d\udd0d Turbo Search \u2014 live on the front end'
					)
				)
			)
		);
	}

	/* Register block */
	registerBlockType( 'wpts/search-bar', {
		title:       __( 'Turbo Search Bar', 'turbo-search' ),
		description: __( 'Instant search with debounce, throttle, and full styling controls.', 'turbo-search' ),
		category:    'widgets',
		icon:        'search',
		keywords:    [ 'search', 'instant', 'turbo', 'filter', 'ajax' ],
		attributes: {
			placeholder:       { type: 'string',  default: 'Search\u2026' },
			postType:          { type: 'string',  default: '' },
			perPage:           { type: 'number',  default: 8 },
			debounce:          { type: 'number',  default: 200 },
			throttle:          { type: 'number',  default: 0 },
			minChars:          { type: 'number',  default: 2 },
			showPostType:      { type: 'boolean', default: true },
			showExcerpt:       { type: 'boolean', default: true },
			layout:            { type: 'string',  default: '' },
			openInNewTab:      { type: 'boolean', default: false },
			primaryColor:      { type: 'string',  default: '#2563eb' },
			backgroundColor:   { type: 'string',  default: '#ffffff' },
			textColor:         { type: 'string',  default: '#1e293b' },
			borderColor:       { type: 'string',  default: '#e2e8f0' },
			borderRadius:      { type: 'number',  default: 10 },
			inputSize:         { type: 'string',  default: 'medium' },
			maxWidth:          { type: 'number',  default: 640 },
			dropdownMaxHeight: { type: 'number',  default: 400 },
			highlightColor:    { type: 'string',  default: '#fef08a' },
			showVoice:         { type: 'boolean', default: true },
			enableCommandK:    { type: 'boolean', default: true },
			categoryTabs:      { type: 'boolean', default: true },
			quickAddToCart:    { type: 'boolean', default: true },
			theme:             { type: 'string',  default: 'light' },
		},
		edit: Edit,
		save: function () { return null; }, // dynamic block - PHP renders front end
	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
);
