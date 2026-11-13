<?php
/**
 * WordPress error double for the isolated filter harness.
 *
 * @package TSF_WP_Benchmark
 */

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

/** WordPress-compatible error value used by the real guard. */
class WP_Error {

	/** @var string Error code. */
	private $code;

	/** @var string Human-readable explanation. */
	private $message;

	/**
	 * @param string $code Error code.
	 * @param string $message Human-readable explanation.
	 */
	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/** @return string Error code. */
	public function get_error_code() {
		return $this->code;
	}

	/** @return string Human-readable explanation. */
	public function get_error_message() {
		return $this->message;
	}
}
