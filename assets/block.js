( function( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element ) return;
	const { registerBlockType } = wp.blocks;
	const { __ } = wp.i18n;
	const { PanelBody, TextControl, SelectControl, RangeControl } = wp.components;
	const blockEditor = wp.blockEditor || wp.editor;
	const InspectorControls = blockEditor ? blockEditor.InspectorControls : null;
	const useBlockProps = blockEditor && blockEditor.useBlockProps ? blockEditor.useBlockProps : function( opts ) { return opts || {}; };
	const el = wp.element.createElement;
	if ( ! InspectorControls ) return;

	registerBlockType( 'dp/weather', {
		title: __( 'DP Weather', 'dp-weather' ),
		icon: 'cloud',
		category: 'widgets',
		attributes: {
			locationName: {
				type: 'string',
				default: 'London, UK',
			},
			latitude: {
				type: 'number',
				default: 51.5072,
			},
			longitude: {
				type: 'number',
				default: -0.1276,
			},
			units: {
				type: 'string',
				default: 'metric',
			},
			numberOfDays: {
				type: 'number',
				default: 7,
			},
		},
		edit: function( props ) {
			const { attributes, setAttributes } = props;
			const blockProps = useBlockProps ? useBlockProps( { className: 'dp-weather-block-editor' } ) : { className: ( props.className || '' ) + ' dp-weather-block-editor' };

			return el(
				wp.element.Fragment,
				{},
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{
							title: __( 'Weather Settings', 'dp-weather' ),
							initialOpen: true,
						},
						el( TextControl, {
							label: __( 'Location Name', 'dp-weather' ),
							value: attributes.locationName,
							onChange: function( value ) {
								setAttributes( { locationName: value } );
							},
							help: __( 'Label shown above the weather (e.g. “London, UK”).', 'dp-weather' ),
						} ),
						el( TextControl, {
							label: __( 'Latitude', 'dp-weather' ),
							value: attributes.latitude,
							type: 'number',
							onChange: function( value ) {
								setAttributes( { latitude: parseFloat( value ) || 0 } );
							},
						} ),
						el( TextControl, {
							label: __( 'Longitude', 'dp-weather' ),
							value: attributes.longitude,
							type: 'number',
							onChange: function( value ) {
								setAttributes( { longitude: parseFloat( value ) || 0 } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Units', 'dp-weather' ),
							value: attributes.units,
							options: [
								{ label: __( 'Metric (°C)', 'dp-weather' ), value: 'metric' },
								{ label: __( 'Imperial (°F)', 'dp-weather' ), value: 'imperial' },
							],
							onChange: function( value ) {
								setAttributes( { units: value } );
							},
						} ),
						el( RangeControl, {
							label: __( 'Number of days', 'dp-weather' ),
							value: attributes.numberOfDays || 7,
							onChange: function( value ) {
								setAttributes( { numberOfDays: value } );
							},
							min: 1,
							max: 14,
							help: __( 'Forecast days (columns/cards in a row).', 'dp-weather' ),
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( 'p', {}, __( 'DP Weather preview', 'dp-weather' ) ),
					el(
						'p',
						{},
						attributes.locationName +
							' (' +
							attributes.latitude +
							', ' +
							attributes.longitude +
							') – ' +
							( attributes.units === 'imperial' ? '°F' : '°C' )
					),
					el(
						'p',
						{ style: { opacity: 0.7 } },
						__( 'Actual values will be loaded from the weather API on the front-end.', 'dp-weather' )
					)
				)
			);
		},
		save: function() {
			// Dynamic block – rendered in PHP.
			return null;
		},
	} );

	// Support saved content that uses block name "dp-weather/weather" (e.g. locationLabel attribute).
	registerBlockType( 'dp-weather/weather', {
		title: __( 'DP Weather', 'dp-weather' ),
		icon: 'cloud',
		category: 'widgets',
		attributes: {
			locationLabel: { type: 'string', default: 'London' },
			locationName: { type: 'string', default: 'London, UK' },
			latitude: { type: 'number', default: 51.5072 },
			longitude: { type: 'number', default: -0.1276 },
			units: { type: 'string', default: 'metric' },
			numberOfDays: { type: 'number', default: 7 },
		},
		edit: function( props ) {
			const { attributes, setAttributes } = props;
			const label = attributes.locationLabel || attributes.locationName || 'London, UK';
			const days = attributes.numberOfDays || 7;
			const blockProps = useBlockProps ? useBlockProps( { className: 'dp-weather-block-editor' } ) : { className: ( props.className || '' ) + ' dp-weather-block-editor' };
			const setLabel = function( value ) {
				setAttributes( { locationLabel: value, locationName: value } );
			};
			return el(
				wp.element.Fragment,
				{},
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Weather Settings', 'dp-weather' ), initialOpen: true },
						el( TextControl, { label: __( 'Location Name', 'dp-weather' ), value: label, onChange: setLabel } ),
						el( TextControl, { label: __( 'Latitude', 'dp-weather' ), value: attributes.latitude, type: 'number', onChange: function( v ) { setAttributes( { latitude: parseFloat( v ) || 0 } ); } } ),
						el( TextControl, { label: __( 'Longitude', 'dp-weather' ), value: attributes.longitude, type: 'number', onChange: function( v ) { setAttributes( { longitude: parseFloat( v ) || 0 } ); } } ),
						el( SelectControl, { label: __( 'Units', 'dp-weather' ), value: attributes.units, options: [ { label: __( 'Metric (°C)', 'dp-weather' ), value: 'metric' }, { label: __( 'Imperial (°F)', 'dp-weather' ), value: 'imperial' } ], onChange: function( v ) { setAttributes( { units: v } ); } } ),
						el( RangeControl, { label: __( 'Number of days', 'dp-weather' ), value: days, onChange: function( v ) { setAttributes( { numberOfDays: v } ); }, min: 1, max: 14 } )
					)
				),
				el( 'div', blockProps, el( 'p', {}, __( 'DP Weather preview', 'dp-weather' ) ), el( 'p', {}, label + ' – ' + days + ' ' + __( 'days', 'dp-weather' ) ) )
			);
		},
		save: function() { return null; },
	} );
} )( window.wp );

