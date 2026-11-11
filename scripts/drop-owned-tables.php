<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Removes only tables bearing the parent's verified run-owned prefix.
 *
 * A fresh process avoids loading the tested plugins during cleanup. Database
 * errors stay generic so credentials and server details never reach stdout.
 */

if ( PHP_SAPI !== 'cli' )
	exit( 1 );

try {
	$input  = json_decode(
		file_get_contents( $argv[1] ?? '' ),
		true,
		32,
		JSON_THROW_ON_ERROR,
	);
	$prefix = $input['prefix'];
	$db     = $input['database'];

	if ( ! preg_match( '/\Atsfb[a-f0-9]{12}_p[1-9][0-9]*_a[0-9]+_\z/', $prefix ) )
		throw new RuntimeException( 'Invalid owned prefix.' );

	$host   = $db['host'];
	$port   = null;
	$socket = null;

	// WordPress permits host:port:/socket as well as host:/socket.
	$socket_position = strpos( $host, ':/' );

	if ( false !== $socket_position ) {
		$socket = substr( $host, $socket_position + 1 );
		$host   = substr( $host, 0, $socket_position );
	}

	if ( str_starts_with( $host, '[' ) ) {
		if ( ! preg_match( '/\A\[([0-9a-f:]+)\](?::([0-9]+))?\z/i', $host, $parts ) )
			throw new RuntimeException( 'Invalid database host.' );

		$host = $parts[1];
		$port = isset( $parts[2] ) ? (int) $parts[2] : null;
	} elseif ( 1 === substr_count( $host, ':' ) ) {
		[ $host, $suffix ] = explode( ':', $host, 2 );

		if ( ! ctype_digit( $suffix ) )
			throw new RuntimeException( 'Invalid database port.' );

		$port = (int) $suffix;
	}

	if ( null !== $port && ( 1 > $port || 65535 < $port ) )
		throw new RuntimeException( 'Invalid database port.' );

	if ( str_contains( $host, ':' ) ) {
		if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) )
			throw new RuntimeException( 'Invalid IPv6 database host.' );

		// Match wpdb: mysqlnd needs brackets; libmysqlclient needs the bare address.
		if ( extension_loaded( 'mysqlnd' ) )
			$host = "[$host]";
	}

	$mysqli  = new mysqli(
		$host,
		$db['user'],
		$db['password'],
		$db['name'],
		$port,
		$socket,
	);
	$removed = [];
	$tables  = $mysqli->query( 'SHOW TABLES' );

	if ( false === $tables )
		throw new RuntimeException( 'Cannot inspect owned tables.' );

	foreach ( $tables->fetch_all() as [ $table ] ) {
		if ( ! str_starts_with( $table, $prefix ) ) continue;

		if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) )
			throw new RuntimeException( 'Unexpected table identifier.' );

		if ( false === $mysqli->query( "DROP TABLE `$table`" ) )
			throw new RuntimeException( 'Cannot remove an owned table.' );

		$removed[] = $table;
	}

	$tables = $mysqli->query( 'SHOW TABLES' );

	if ( false === $tables )
		throw new RuntimeException( 'Cannot verify owned table cleanup.' );

	foreach ( $tables->fetch_all() as [ $table ] ) {
		if ( str_starts_with( $table, $prefix ) )
			throw new RuntimeException( 'Owned table remains.' );
	}

	echo json_encode(
		[
			'removed'   => $removed,
			'remaining' => 0,
		],
		JSON_THROW_ON_ERROR,
	);
} catch ( Throwable $error ) {
	fwrite( STDERR, "Owned table cleanup failed.\n" );
	exit( 1 );
}
