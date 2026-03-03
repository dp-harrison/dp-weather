<?php
/**
 * Plugin Name: DP Weather
 * Description: Gutenberg block that displays a multi-day weather forecast via Open-Meteo (no API key).
 * Version: 1.0.0
 * Author: Dont Panic Projects
 * Text Domain: dp-weather
 *
 * Internal DP notes:
 * - Dynamic block (server-rendered).
 * - Forecast responses are cached using transients.
 */

namespace DontPanic\Weather;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the block from block.json, attaching our PHP render callback.
 */
function register_block() : void {
	register_block_type(
		__DIR__,
		[
			'render_callback' => __NAMESPACE__ . '\\render_block',
		]
	);

	// If you *actually* need legacy support, set this to true and keep the legacy block.js registration too.
	// define( 'DP_WEATHER_ENABLE_LEGACY_BLOCK', true );
	if ( defined( 'DP_WEATHER_ENABLE_LEGACY_BLOCK' ) && DP_WEATHER_ENABLE_LEGACY_BLOCK ) {
		register_block_type(
			'dp-weather/weather',
			[
				'render_callback' => __NAMESPACE__ . '\\render_block',
				'attributes'      => [
					// Legacy attribute kept for backwards compatibility only.
					'locationLabel' => [ 'type' => 'string', 'default' => 'London' ],
					'locationName'  => [ 'type' => 'string', 'default' => 'London, UK' ],
					'latitude'      => [ 'type' => 'number', 'default' => 51.5072 ],
					'longitude'     => [ 'type' => 'number', 'default' => -0.1276 ],
					'units'         => [ 'type' => 'string', 'default' => 'metric' ],
					'numberOfDays'  => [ 'type' => 'number', 'default' => 7 ],
				],
			]
		);
	}
}
add_action( 'init', __NAMESPACE__ . '\\register_block' );

/**
 * Convert WMO/Open-Meteo weather codes to a lightweight emoji icon.
 * Open-Meteo uses WMO codes for daily "weathercode".
 */
function code_to_emoji( int $code ) : string {
	$map = [
		0  => '☀️', // Clear sky
		1  => '🌤️', // Mainly clear
		2  => '⛅',  // Partly cloudy
		3  => '☁️', // Overcast
		45 => '🌫️', // Fog
		48 => '🌫️', // Depositing rime fog
		51 => '🌦️', // Drizzle (light)
		53 => '🌦️', // Drizzle (moderate)
		55 => '🌦️', // Drizzle (dense)
		56 => '🌧️', // Freezing drizzle (light)
		57 => '🌧️', // Freezing drizzle (dense)
		61 => '🌧️', // Rain (slight)
		63 => '🌧️', // Rain (moderate)
		65 => '🌧️', // Rain (heavy)
		66 => '🌧️', // Freezing rain (light)
		67 => '🌧️', // Freezing rain (heavy)
		71 => '❄️', // Snow (slight)
		73 => '❄️', // Snow (moderate)
		75 => '❄️', // Snow (heavy)
		77 => '❄️', // Snow grains
		80 => '🌧️', // Rain showers (slight)
		81 => '🌧️', // Rain showers (moderate)
		82 => '🌧️', // Rain showers (violent)
		85 => '🌨️', // Snow showers (slight)
		86 => '🌨️', // Snow showers (heavy)
		95 => '⛈️', // Thunderstorm
		96 => '⛈️', // Thunderstorm w/ slight hail
		99 => '⛈️', // Thunderstorm w/ heavy hail
	];

	if ( isset( $map[ $code ] ) ) {
		return $map[ $code ];
	}

	// Conservative fallback for unknown values.
	return '🌡️';
}

/**
 * Clamp helper.
 */
function clamp_float( float $value, float $min, float $max ) : float {
	return max( $min, min( $max, $value ) );
}

/**
 * Fetch weather data from Open-Meteo and cache it using transients.
 *
 * Returns a small, stable structure to keep rendering simple:
 * [
 *   'current' => array|null,
 *   'daily'   => array|null,
 * ]
 */
function fetch_weather( float $lat, float $lon, string $units, int $days ) : array {
	// Input hygiene (prevents accidental nonsense values).
	$lat  = clamp_float( $lat, -90.0, 90.0 );
	$lon  = clamp_float( $lon, -180.0, 180.0 );
	$days = max( 1, min( 14, $days ) );

	$temp_unit = ( $units === 'imperial' ) ? 'fahrenheit' : 'celsius';

	// Cache key: hash keeps it short and avoids float formatting quirks.
	$key_material = wp_json_encode( [ $lat, $lon, $temp_unit, $days ] );
	$cache_key    = 'dp_weather_' . md5( (string) $key_material );

	$cached = get_transient( $cache_key );
	if ( is_array( $cached ) && array_key_exists( 'current', $cached ) ) {
		return $cached;
	}

	// Allow internal sites to control freshness without editing the plugin.
	$ttl = (int) apply_filters( 'dp_weather_cache_ttl', 10 * MINUTE_IN_SECONDS, $lat, $lon, $units, $days );
	if ( $ttl < 0 ) {
		$ttl = 0;
	}

	$url = add_query_arg(
		[
			'latitude'          => $lat,
			'longitude'         => $lon,
			'current_weather'   => 'true',
			'daily'             => 'temperature_2m_max,temperature_2m_min,weathercode',
			'temperature_unit'  => $temp_unit,
			'forecast_days'     => $days,
			'timezone'          => 'auto',
		],
		'https://api.open-meteo.com/v1/forecast'
	);

	$response = wp_remote_get(
		$url,
		[
			'timeout' => 8,
		]
	);

	if ( is_wp_error( $response ) ) {
		return [ 'current' => null, 'daily' => null ];
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return [ 'current' => null, 'daily' => null ];
	}

	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $decoded ) ) {
		return [ 'current' => null, 'daily' => null ];
	}

	$result = [
		'current' => ! empty( $decoded['current_weather'] ) ? $decoded['current_weather'] : null,
		'daily'   => null,
	];

	// Only keep what we use.
	if (
		! empty( $decoded['daily'] ) &&
		is_array( $decoded['daily'] ) &&
		isset( $decoded['daily']['time'], $decoded['daily']['temperature_2m_max'] )
	) {
		$result['daily'] = [
			'time'               => (array) $decoded['daily']['time'],
			'temperature_2m_max'  => (array) $decoded['daily']['temperature_2m_max'],
			'temperature_2m_min'  => isset( $decoded['daily']['temperature_2m_min'] ) ? (array) $decoded['daily']['temperature_2m_min'] : [],
			'weathercode'         => isset( $decoded['daily']['weathercode'] ) ? (array) $decoded['daily']['weathercode'] : [],
		];
	}

	if ( $ttl > 0 ) {
		set_transient( $cache_key, $result, $ttl );
	}

	return $result;
}

/**
 * Format "Y-m-d" as "Today" or short weekday (Mon/Tue/...).
 */
function format_day_label( string $ymd ) : string {
	if ( $ymd === gmdate( 'Y-m-d' ) ) {
		return __( 'Today', 'dp-weather' );
	}

	$ts = strtotime( $ymd );
	return $ts ? wp_date( 'D', $ts ) : $ymd;
}

/**
 * Dynamic block render callback.
 *
 * Signature matches WP dynamic blocks: ($attributes, $content, $block).
 */
function render_block( $attributes, $content, $block ) : string {
	$attributes = is_array( $attributes ) ? $attributes : [];

	// Backwards compat: locationLabel was used in older block name.
	$location = $attributes['locationName']
		?? $attributes['locationLabel']
		?? __( 'Unknown location', 'dp-weather' );

	$location = sanitize_text_field( (string) $location );

	$lat   = isset( $attributes['latitude'] ) ? (float) $attributes['latitude'] : 51.5072;
	$lon   = isset( $attributes['longitude'] ) ? (float) $attributes['longitude'] : -0.1276;
	$units = isset( $attributes['units'] ) ? sanitize_key( (string) $attributes['units'] ) : 'metric';

	$units = ( $units === 'imperial' ) ? 'imperial' : 'metric';

	$days = isset( $attributes['numberOfDays'] ) ? (int) $attributes['numberOfDays'] : 7;
	$days = max( 1, min( 14, $days ) );

	$weather = fetch_weather( $lat, $lon, $units, $days );
	$current = is_array( $weather ) ? ( $weather['current'] ?? null ) : null;
	$daily   = is_array( $weather ) ? ( $weather['daily'] ?? null ) : null;

	$temp_unit = ( $units === 'imperial' ) ? 'F' : 'C';

	// If we have nothing useful, render a simple, non-breaking message.
	if ( ! is_array( $daily ) || empty( $daily['time'] ) ) {
		return sprintf(
			'<div class="wp-block-dp-weather dp-weather-block"><p>%s</p></div>',
			esc_html__( 'Weather data unavailable right now.', 'dp-weather' )
		);
	}

	ob_start();
	?>
	<div class="wp-block-dp-weather dp-weather-block">
		<div class="dp-weather-header">
			<h3 class="dp-weather-location"><?php echo esc_html( $location ); ?></h3>
			<?php if ( is_array( $current ) && isset( $current['temperature'] ) ) : ?>
				<div class="dp-weather-now">
					<span class="dp-weather-now-label"><?php echo esc_html__( 'Now', 'dp-weather' ); ?></span>
					<span class="dp-weather-now-temp">
						<?php echo esc_html( round( (float) $current['temperature'] ) . '°' . $temp_unit ); ?>
					</span>
				</div>
			<?php endif; ?>
		</div>

		<div class="dp-weather-grid" style="--dp-weather-cols: <?php echo esc_attr( (string) $days ); ?>;">
			<?php
			$times = (array) ( $daily['time'] ?? [] );

			foreach ( $times as $i => $date_ymd ) :
				$max = isset( $daily['temperature_2m_max'][ $i ] ) ? round( (float) $daily['temperature_2m_max'][ $i ] ) : null;
				$min = isset( $daily['temperature_2m_min'][ $i ] ) ? round( (float) $daily['temperature_2m_min'][ $i ] ) : null;

				$code  = isset( $daily['weathercode'][ $i ] ) ? (int) $daily['weathercode'][ $i ] : 0;
				$emoji = code_to_emoji( $code );

				$label = format_day_label( (string) $date_ymd );
				?>
				<div class="dp-weather-card">
					<div class="dp-weather-day"><?php echo esc_html( $label ); ?></div>
					<div class="dp-weather-emoji" aria-hidden="true"><?php echo esc_html( $emoji ); ?></div>

					<div class="dp-weather-temps">
						<?php if ( null !== $max ) : ?>
							<span class="dp-weather-max"><?php echo esc_html( $max . '°' ); ?></span>
						<?php endif; ?>
						<?php if ( null !== $min ) : ?>
							<span class="dp-weather-min"><?php echo esc_html( $min . '°' ); ?></span>
						<?php endif; ?>
						<span class="dp-weather-unit" aria-label="<?php echo esc_attr( $temp_unit ); ?>"><?php echo esc_html( $temp_unit ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}