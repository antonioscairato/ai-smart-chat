( function ( wp ) {
	var el = wp.element.createElement;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var Placeholder = wp.components.Placeholder;

	// Textos traduzidos vindos do PHP (window.SAIC_BLOCK.i18n).
	var T = ( window.SAIC_BLOCK && window.SAIC_BLOCK.i18n ) || {};
	var templates = ( window.SAIC_BLOCK && window.SAIC_BLOCK.templates ) || [];
	var options = [ { value: '', label: T.defaultFromSettings || '' } ].concat( templates );

	wp.blocks.registerBlockType( 'saic/chat', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var blockProps = useBlockProps();

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Chat', initialOpen: true },
						el( SelectControl, {
							label: T.template,
							value: a.template,
							options: options,
							onChange: function ( v ) { set( { template: v } ); }
						} ),
						el( TextControl, {
							label: T.title,
							value: a.title,
							onChange: function ( v ) { set( { title: v } ); }
						} ),
						el( TextControl, {
							label: T.placeholder,
							value: a.placeholder,
							onChange: function ( v ) { set( { placeholder: v } ); }
						} ),
						el( TextareaControl, {
							label: T.welcome,
							value: a.welcome,
							onChange: function ( v ) { set( { welcome: v } ); }
						} ),
						el( TextControl, {
							label: T.height,
							value: a.height,
							onChange: function ( v ) { set( { height: v } ); }
						} )
					)
				),
				el( Placeholder, {
					icon: 'format-chat',
					label: 'Scairato AI Content Chat',
					instructions: T.help
				}, el( 'code', null, ( a.template || T.defaultTemplate ) + ' · ' + ( a.height || '520px' ) ) )
			);
		},
		save: function () {
			return null; // Renderizado no servidor.
		}
	} );
} )( window.wp );
