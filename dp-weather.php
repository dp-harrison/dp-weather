<?php
/**
 * Plugin Name: DP Weather
 * Description: Fetches weather data from a public API and displays it via a custom Gutenberg block.
 * Author: Dont Panic Projects
 * Version: 1.0.0
 */

namespace DontPanic\Weather;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the DP Weather block and its assets.
 * Uses block.json for metadata; render_callback is provided here for server-side output.
 * Also registers "dp-weather/weather" so saved content with that block name (e.g. {"locationLabel":"London"}) renders.
 */
function register_block() {
	$block_dir = plugin_dir_path( __FILE__ );

	register_block_type(
		$block_dir,
		array(
			'render_callback' => __NAMESPACE__ . '\\render_block',
		)
	);

	register_block_type( 'dp-weather/weather', array(
		'render_callback' => __NAMESPACE__ . '\\render_block',
		'attributes'      => array(
			'locationLabel'  => array( 'type' => 'string', 'default' => 'London' ),
			'locationName'   => array( 'type' => 'string', 'default' => 'London, UK' ),
			'latitude'       => array( 'type' => 'number', 'default' => 51.5072 ),
			'longitude'      => array( 'type' => 'number', 'default' => -0.1276 ),
			'units'          => array( 'type' => 'string', 'default' => 'metric' ),
			'numberOfDays'   => array( 'type' => 'number', 'default' => 7 ),
		),
	) );
}
add_action( 'init', __NAMESPACE__ . '\\register_block' );

add_action( 'enqueue_block_assets', function () {
	if ( is_admin() ) return;
	wp_enqueue_style(
		'dp-weather-block-style',
		plugins_url( 'assets/block-style.css', __FILE__ ),
		array(),
		'1.0.0'
	);
} );

/**
 * Get emoji for WMO weather code (Open-Meteo daily weathercode).
 *
 * @param int $code WMO code 0-99.
 * @return string Emoji character(s).
 */
function dp_weather_code_to_emoji( $code ) {
	$code = (int) $code;
	$map  = array(
		0   => '☀️',
		1   => '🌤️',
		2   => '⛅',
		3   => '☁️',
		45  => '🌫️',
		48  => '🌫️',
		51  => '🌧️',
		53  => '🌧️',
		55  => '🌧️',
		56  => '🌨️',
		57  => '🌨️',
		61  => '🌧️',
		63  => '🌧️',
		65  => '🌧️',
		66  => '🌨️',
		67  => '🌨️',
		71  => '❄️',
		73  => '❄️',
		75  => '❄️',
		77  => '🌨️',
		80  => '🌦️',
		81  => '🌦️',
		82  => '🌦️',
		85  => '🌨️',
		86  => '🌨️',
		95  => '⛈️',
		96  => '🌩️',
		99  => '🌩️',
	);
	if ( isset( $map[ $code ] ) ) {
		return $map[ $code ];
	}
	// Group by tens for fallback.
	if ( $code >= 0 && $code <= 3 ) {
		return '☀️';
	}
	if ( $code >= 45 && $code <= 48 ) {
		return '🌫️';
	}
	if ( $code >= 51 && $code <= 67 ) {
		return $code >= 56 ? '🌨️' : '🌧️';
	}
	if ( $code >= 71 && $code <= 77 ) {
		return '❄️';
	}
	if ( $code >= 80 && $code <= 82 ) {
		return '🌦️';
	}
	if ( $code >= 85 && $code <= 86 ) {
		return '🌨️';
	}
	if ( $code >= 95 && $code <= 99 ) {
		return '⛈️';
	}
	return '🌡️';
}

/**
 * Fetch current weather and daily forecast from Open-Meteo.
 *
 * @param float  $lat
 * @param float  $lon
 * @param string $units
 * @param int    $days Number of forecast days (1-16).
 * @return array{current: array|null, daily: array|null}
 */
function fetch_weather( $lat, $lon, $units, $days = 7 ) {
	$lat  = (float) $lat;
	$lon  = (float) $lon;
	$unit = $units === 'imperial' ? 'fahrenheit' : 'celsius';
	$days = max( 1, min( 16, (int) $days ) );

	$cache_key = sprintf( 'dp_weather_forecast_%s_%s_%s_%d', $lat, $lon, $unit, $days );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && isset( $cached['current'] ) ) {
		return $cached;
	}

	$url = add_query_arg(
		array(
			'latitude'          => $lat,
			'longitude'         => $lon,
			'current_weather'   => 'true',
			'daily'             => 'temperature_2m_max,temperature_2m_min,weathercode',
			'temperature_unit'  => $unit,
			'forecast_days'     => $days,
		),
		'https://api.open-meteo.com/v1/forecast'
	);

	$response = wp_remote_get( $url, array( 'timeout' => 8 ) );
	if ( is_wp_error( $response ) ) {
		return array( 'current' => null, 'daily' => null );
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return array( 'current' => null, 'daily' => null );
	}
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) ) {
		return array( 'current' => null, 'daily' => null );
	}

	$result = array(
		'current' => ! empty( $data['current_weather'] ) ? $data['current_weather'] : null,
		'daily'   => null,
	);
	if ( ! empty( $data['daily'] ) && isset( $data['daily']['time'], $data['daily']['temperature_2m_max'] ) ) {
		$result['daily'] = array(
			'time'               => $data['daily']['time'],
			'temperature_2m_max' => $data['daily']['temperature_2m_max'],
			'temperature_2m_min' => isset( $data['daily']['temperature_2m_min'] ) ? $data['daily']['temperature_2m_min'] : array(),
			'weathercode'        => isset( $data['daily']['weathercode'] ) ? $data['daily']['weathercode'] : array(),
		);
	}
	set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
	return $result;
}

/**
 * Format a Y-m-d date for display (e.g. "Mon", "Tue" or "Today").
 *
 * @param string $date_ymd Date in Y-m-d.
 * @return string
 */
function dp_weather_format_day( $date_ymd ) {
	$today = gmdate( 'Y-m-d' );
	if ( $date_ymd === $today ) {
		return __( 'Today', 'dp-weather' );
	}
	$ts = strtotime( $date_ymd );
	return $ts ? wp_date( 'D', $ts ) : $date_ymd;
}

/**
 * Render callback for the DP Weather block.
 * Must match the signature used by WP_Block::render(): ( $attributes, $content, $block ).
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Inner block content (empty for this block).
 * @param WP_Block $block      Block instance.
 * @return string HTML output.
 */
function render_block( $attributes, $content, $block ) {
	$attributes   = is_array( $attributes ) ? $attributes : array();
	$location     = isset( $attributes['locationName'] ) ? sanitize_text_field( $attributes['locationName'] ) : ( isset( $attributes['locationLabel'] ) ? sanitize_text_field( $attributes['locationLabel'] ) : 'Unknown location' );
	$lat          = isset( $attributes['latitude'] ) ? (float) $attributes['latitude'] : 51.5072;
	$lon          = isset( $attributes['longitude'] ) ? (float) $attributes['longitude'] : -0.1276;
	$units        = isset( $attributes['units'] ) ? sanitize_text_field( $attributes['units'] ) : 'metric';
	$number_of_days = isset( $attributes['numberOfDays'] ) ? max( 1, min( 16, (int) $attributes['numberOfDays'] ) ) : 7;

	$weather   = fetch_weather( $lat, $lon, $units, $number_of_days );
	$current   = is_array( $weather ) && isset( $weather['current'] ) ? $weather['current'] : null;
	$daily     = is_array( $weather ) && ! empty( $weather['daily'] ) ? $weather['daily'] : null;
	$temp_unit = $units === 'imperial' ? 'F' : 'C';

	ob_start();
	?>
	<div class="wp-block-dp-weather dp-weather-block" style="--dp-weather-cols: <?php echo (int) $number_of_days; ?>">
		<div class="dp-weather-block__location"><?php echo esc_html( $location ); ?></div>
		<?php if ( $daily && ! empty( $daily['time'] ) ) : ?>
			<div class="dp-weather-block__forecast" role="list">
				<?php
				$times = $daily['time'];
				$maxes = isset( $daily['temperature_2m_max'] ) ? $daily['temperature_2m_max'] : array();
				$mins  = isset( $daily['temperature_2m_min'] ) ? $daily['temperature_2m_min'] : array();
				$codes = isset( $daily['weathercode'] ) ? $daily['weathercode'] : array();
				for ( $i = 0; $i < count( $times ); $i++ ) :
					$day_label = dp_weather_format_day( $times[ $i ] );
					$max       = isset( $maxes[ $i ] ) ? round( (float) $maxes[ $i ] ) : '';
					$min       = isset( $mins[ $i ] ) ? round( (float) $mins[ $i ] ) : '';
					$code      = isset( $codes[ $i ] ) ? (int) $codes[ $i ] : 0;
					$emoji     = dp_weather_code_to_emoji( $code );
					$is_today  = ( $times[ $i ] === gmdate( 'Y-m-d' ) );
				?>
				<div class="dp-weather-block__card" role="listitem">
					<span class="dp-weather-block__card-emoji" aria-hidden="true"><?php echo $emoji; ?></span>
					<span class="dp-weather-block__card-day"><?php echo esc_html( $day_label ); ?></span>
					<span class="dp-weather-block__card-temps"><?php echo esc_html( (string) $max ); ?>° / <?php echo esc_html( (string) $min ); ?>°<?php echo esc_html( $temp_unit ); ?></span>
					<?php if ( $is_today && $current && is_array( $current ) ) : ?>
						<span class="dp-weather-block__card-now"><?php esc_html_e( 'Now', 'dp-weather' ); ?> <?php echo esc_html( (string) round( (float) $current['temperature'] ) ); ?>°<?php echo esc_html( $temp_unit ); ?></span>
					<?php endif; ?>
				</div>
				<?php endfor; ?>
			</div>
		<?php elseif ( ! $current ) : ?>
			<div class="dp-weather-block__error">
				<?php esc_html_e( 'Unable to load weather data right now.', 'dp-weather' ); ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

