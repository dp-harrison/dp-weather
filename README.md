# DP Weather

WordPress block that shows a multi-day weather forecast using the [Open-Meteo](https://open-meteo.com/) API.

## Features

- Gutenberg block: **DP Weather** (block names `dp/weather` and `dp-weather/weather`)
- Configurable location (name + lat/lon), units (°C / °F), and number of forecast days (1–14)
- Daily cards with weather emoji, high/low temps, and “Now” for today
- No API key required

## Installation

1. Upload the `dp-weather` folder to `wp-content/plugins/`.
2. Activate **DP Weather** in the WordPress admin.
3. Add the block via the block editor (search for “DP Weather”).

## Block settings (sidebar)

- **Location name** – Label shown above the forecast (e.g. “London, UK”).
- **Latitude / Longitude** – Coordinates for the forecast.
- **Units** – Metric (°C) or Imperial (°F).
- **Number of days** – 1–14 days (controls how many cards/columns are shown).

## Requirements

- WordPress 5.5+
- PHP 7.4+

## License

Proprietary – Dont Panic Projects.
