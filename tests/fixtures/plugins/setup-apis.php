<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

namespace RankMath\Admin {

	/**
	 * Records Rank Math's static wizard calls without loading the plugin.
	 */
	class Setup_Wizard {

		/**
		 * Supplies native-shaped defaults for the requested wizard step.
		 *
		 * @param string $step The requested wizard step.
		 * @return array Defaults that the setup helper must preserve.
		 */
		public static function get_localized_data( string $step ): array {
			return [
				'sitemap'          => true,
				'preserve_default' => 'yes',
				'step'             => $step,
			];
		}

		/**
		 * Records a save and simulates completion of the final wizard step.
		 *
		 * @param string $step   The wizard step being saved.
		 * @param array  $values The settings submitted by the setup helper.
		 * @return bool Whether the simulated native save succeeds.
		 */
		public static function save_data( string $step, array $values ): bool {

			$GLOBALS['tsfb_setup_test']['rank_steps'][ $step ] = $values;

			if ( 'ready' === $step )
				$GLOBALS['tsfb_setup_test']['configured'] = true;

			return empty( $GLOBALS['tsfb_setup_test']['rank_fail'] );
		}
	}
}

namespace RankMath {

	/**
	 * Exposes the module and wizard-completion state required by setup.
	 */
	class Helper {

		/**
		 * Checks the test's active module set without changing it.
		 *
		 * @param string $name The requested module name.
		 * @return bool Whether the named module belongs to the active set.
		 */
		public static function is_module_active( string $name ): bool {
			return \in_array(
				$name,
				$GLOBALS['tsfb_setup_test']['options']['rank_math_modules'] ?? [],
				true,
			);
		}

		/**
		 * Reads the simulated wizard's completion status.
		 *
		 * @return bool Whether the wizard reached its completion step.
		 */
		public static function is_configured(): bool {
			return $GLOBALS['tsfb_setup_test']['configured'] ?? false;
		}
	}
}

namespace The_SEO_Framework\Data {

	/**
	 * Supplies configurable TSF option values for default-feature verification.
	 */
	class Plugin {

		/**
		 * Reads an explicit test override or TSF's enabled feature default.
		 *
		 * @param string $key The option requested by the setup helper.
		 * @return mixed The override or the enabled default value.
		 */
		public static function get_option( string $key ): mixed {
			return $GLOBALS['tsfb_setup_test']['tsf_features'][ $key ] ?? 1;
		}
	}
}

namespace {

	/**
	 * Supplies Yoast options while preserving its public static call signature.
	 */
	class WPSEO_Options {

		/**
		 * Reads a simulated Yoast option without applying additional defaults.
		 *
		 * @param string $key     The option name.
		 * @param mixed  $default The caller's missing-value default.
		 * @return mixed The test override or supplied default.
		 */
		public static function get( string $key, mixed $default = null ): mixed {
			return $GLOBALS['tsfb_setup_test']['yoast_features'][ $key ] ?? $default;
		}
	}

	/**
	 * Captures local REST request parameters without bootstrapping WordPress.
	 */
	class WP_REST_Request {

		/**
		 * JSON request body supplied by the setup helper.
		 *
		 * @var string
		 */
		public string $body = '';

		/**
		 * Request headers retained for assertions; no HTTP request is made.
		 *
		 * @var array
		 */
		public array $headers = [];

		/**
		 * Stores the HTTP method and local route supplied by the setup helper.
		 *
		 * @param string $method The request method.
		 * @param string $route  The internal REST route.
		 */
		public function __construct(
			public string $method,
			public string $route
		) {}

		/**
		 * Stores one request header for later assertions.
		 *
		 * @param string $key   The header name.
		 * @param string $value The header value.
		 * @return void
		 */
		public function set_header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}

		/**
		 * Stores JSON for the internal REST dispatcher stub.
		 *
		 * @param string $body The serialized request body.
		 * @return void
		 */
		public function set_body( string $body ): void {
			$this->body = $body;
		}
	}

	/**
	 * Exposes the response methods consumed by the setup REST helper.
	 */
	class TSFB_Setup_REST_Response {

		/**
		 * Captures the injected response status and data.
		 *
		 * @param int   $status The synthetic HTTP response status.
		 * @param mixed $data   The response body before REST serialization.
		 */
		public function __construct(
			private int $status,
			private mixed $data
		) {}

		/**
		 * Reads the status checked by the setup helper.
		 *
		 * @return int The injected status.
		 */
		public function get_status(): int {
			return $this->status;
		}

		/**
		 * Preserves arrays, objects, and invalid scalar response test cases.
		 *
		 * @return mixed The injected data, unchanged.
		 */
		public function get_data(): mixed {
			return $this->data;
		}
	}

	/**
	 * Supplies SEOPress module state without rewriting native feature settings.
	 */
	class TSFB_Setup_SEOPress_Options {

		/**
		 * Reads a module value from the current test state.
		 *
		 * @param string $key The native module option key.
		 * @return mixed The configured value or null when absent.
		 */
		public function searchOptionByKey( string $key ): mixed {
			return $GLOBALS['tsfb_setup_test']['seopress_features'][ $key ] ?? null;
		}
	}

	/**
	 * Supplies Slim SEO's enabled default feature state.
	 */
	class TSFB_Setup_Slim_Settings {

		/**
		 * Preserves the former fixture's enabled state for every requested feature.
		 *
		 * @param string $feature The feature requested by the setup helper.
		 * @return bool The simulated enabled default.
		 */
		public function is_feature_active( string $feature ): bool {
			return true;
		}
	}

	/**
	 * Supplies the service lookup used by Slim SEO's setup validation.
	 */
	class TSFB_Setup_Slim_Container {

		/**
		 * Creates the feature-state service without loading Slim SEO.
		 *
		 * @param string $name The service name passed by the setup helper.
		 * @return object A fresh settings service, matching the former fixture.
		 */
		public function get_service( string $name ): object {
			return new TSFB_Setup_Slim_Settings();
		}
	}

	/**
	 * Answers the setup helper's administrator capability check.
	 *
	 * @param string $capability The WordPress capability being checked.
	 * @return bool Whether this test grants fixture-administrator privileges.
	 */
	function current_user_can( string $capability ): bool {
		return $GLOBALS['tsfb_setup_test']['administrator'] ?? true;
	}

	/**
	 * Reads a WordPress option from the current isolated test state.
	 *
	 * @param string $key     The option name.
	 * @param mixed  $default The caller's missing-value default.
	 * @return mixed The stored option or supplied default.
	 */
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['tsfb_setup_test']['options'][ $key ] ?? $default;
	}

	/**
	 * Stores an in-memory option without touching a database.
	 *
	 * @param string $key   The option name.
	 * @param mixed  $value The replacement value.
	 * @return bool A simulated successful WordPress option write.
	 */
	function update_option( string $key, mixed $value ): bool {

		$GLOBALS['tsfb_setup_test']['options'][ $key ] = $value;

		return true;
	}

	/**
	 * Records a request and dispatches only to the test's injected callback.
	 *
	 * @param WP_REST_Request $request The local request under test.
	 * @return object A synthetic response preserving the injected data type.
	 */
	function rest_do_request( WP_REST_Request $request ): object {

		$body                                     = json_decode(
			$request->body,
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		$GLOBALS['tsfb_setup_test']['requests'][] = [
			'route'  => $request->route,
			'method' => $request->method,
			'body'   => $body,
		];

		if ( '/yoast/v1/configuration/enable_tracking' === $request->route )
			$GLOBALS['tsfb_setup_test']['yoast_features']['tracking'] = $body['tracking'];

		$result = isset( $GLOBALS['tsfb_setup_test']['response'] )
			? ( $GLOBALS['tsfb_setup_test']['response'] )( $request, $body )
			: [
				200,
				[
					'success'  => true,
					'next_url' => null,
				],
			];

		return new TSFB_Setup_REST_Response( $result[0], $result[1] );
	}

	/**
	 * Exposes the mutable AIOSEO option tree used by the setup helper.
	 *
	 * @return object The current test's nested AIOSEO options.
	 */
	function aioseo(): object {
		return $GLOBALS['tsfb_setup_test']['aioseo'];
	}

	/**
	 * Supplies the SEOPress module-option service expected during setup.
	 *
	 * @param string $name The requested native service name.
	 * @return object A service backed by the current test state.
	 */
	function seopress_get_service( string $name ): object {
		return new TSFB_Setup_SEOPress_Options();
	}

	if ( ! defined( 'SEOPRESS_VERSION' ) )
		define( 'SEOPRESS_VERSION', 'test' );
}
