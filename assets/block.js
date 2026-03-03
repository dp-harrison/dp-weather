(function (wp) {
	// Basic guard: if Gutenberg APIs aren't present, do nothing.
	if (!wp || !wp.blocks || !wp.element || !wp.components || !wp.i18n) return;

	const { registerBlockType } = wp.blocks;
	const { __ } = wp.i18n;
	const { PanelBody, TextControl, SelectControl, RangeControl } = wp.components;

	const blockEditor = wp.blockEditor || wp.editor;
	if (!blockEditor) return;

	const { InspectorControls, useBlockProps } = blockEditor;
	const el = wp.element.createElement;

	// Resolve the preview image URL relative to THIS script, so it works without any PHP localization.
	// Assumes block-preview.png lives in the same folder as this block.js (assets/).
	const previewImageUrl = (function () {
		try {
			if (typeof document !== "undefined" && document.currentScript && document.currentScript.src) {
				return new URL("block-preview.png", document.currentScript.src).toString();
			}
		} catch (e) {}
		// Fallback (often still works if the script is served from the plugin's assets folder)
		return "block-preview.png";
	})();

	registerBlockType("dp/weather", {
		title: __("DP Weather", "dp-weather"),
		icon: "cloud",
		category: "widgets",

		attributes: {
			locationName: { type: "string", default: "London, UK" },
			latitude: { type: "number", default: 51.5072 },
			longitude: { type: "number", default: -0.1276 },
			units: { type: "string", default: "metric" },
			numberOfDays: { type: "number", default: 7 },
		},

		edit: function (props) {
			const { attributes, setAttributes, isSelected } = props;

			const blockProps = useBlockProps({
				className: "dp-weather-block-editor",
			});

			// Helper: clamp number inputs (prevents accidental nonsense).
			const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

			// When the block isn't selected, show a clean image preview.
			// IMPORTANT: wrap in a blockProps container so Gutenberg can select the block when clicked.
			if (!isSelected) {
				return el(
					"div",
					blockProps,
					el("img", {
						src: previewImageUrl,
						alt: __("DP Weather preview", "dp-weather"),
						style: { maxWidth: "100%", height: "auto", display: "block" },
					})
				);
			}

			return el(
				wp.element.Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __("Weather Settings", "dp-weather"), initialOpen: true },

						el(TextControl, {
							label: __("Location Name", "dp-weather"),
							value: attributes.locationName,
							onChange: function (value) {
								setAttributes({ locationName: value });
							},
							help: __("Label shown above the forecast (e.g. “London, UK”).", "dp-weather"),
						}),

						el(TextControl, {
							label: __("Latitude", "dp-weather"),
							value: attributes.latitude,
							type: "number",
							step: "0.0001",
							min: -90,
							max: 90,
							onChange: function (value) {
								const n = parseFloat(value);
								if (Number.isNaN(n)) return;
								setAttributes({ latitude: clamp(n, -90, 90) });
							},
						}),

						el(TextControl, {
							label: __("Longitude", "dp-weather"),
							value: attributes.longitude,
							type: "number",
							step: "0.0001",
							min: -180,
							max: 180,
							onChange: function (value) {
								const n = parseFloat(value);
								if (Number.isNaN(n)) return;
								setAttributes({ longitude: clamp(n, -180, 180) });
							},
						}),

						el(SelectControl, {
							label: __("Units", "dp-weather"),
							value: attributes.units,
							options: [
								{ label: __("Metric (°C)", "dp-weather"), value: "metric" },
								{ label: __("Imperial (°F)", "dp-weather"), value: "imperial" },
							],
							onChange: function (value) {
								setAttributes({ units: value });
							},
						}),

						el(RangeControl, {
							label: __("Number of days", "dp-weather"),
							value: attributes.numberOfDays || 7,
							onChange: function (value) {
								setAttributes({ numberOfDays: value });
							},
							min: 1,
							max: 14,
							help: __("Forecast days (cards shown).", "dp-weather"),
						})
					)
				),

				// When selected, keep the canvas minimal and direct editors to the sidebar.
				el(
					"div",
					blockProps,
					el("p", {}, __("Configure the weather block using the sidebar settings.", "dp-weather")),
					el(
						"p",
						{ style: { opacity: 0.7 } },
						__("The forecast renders on the front-end (dynamic block).", "dp-weather")
					)
				)
			);
		},

		save: function () {
			// Dynamic block: front-end markup is rendered in PHP.
			return null;
		},
	});

	// Legacy block name support (only keep if you enable legacy PHP registration too).
	// registerBlockType("dp-weather/weather", { ... });
})(window.wp);