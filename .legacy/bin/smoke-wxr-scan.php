#!/usr/bin/env php
<?php
/**
 * Standalone WXR preflight smoke test (no WordPress bootstrap).
 *
 * Usage: php bin/smoke-wxr-scan.php path/to/export.xml [batch_size]
 *
 * @package WordPress_Importer_v2
 * @since 3.0.7
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only.\n" );
	exit( 1 );
}

$file = isset( $argv[1] ) ? $argv[1] : '';
$batch_size = isset( $argv[2] ) ? max( 1, (int) $argv[2] ) : 10;

if ( '' === $file || ! is_readable( $file ) ) {
	fwrite( STDERR, "Usage: php bin/smoke-wxr-scan.php /path/to/export.xml [batch_size]\n" );
	exit( 1 );
}

$start = microtime( true );
$reader = new XMLReader();
if ( ! $reader->open( $file ) ) {
	fwrite( STDERR, "Could not open XML file.\n" );
	exit( 1 );
}

$counts = array(
	'author' => 0,
	'term'   => 0,
	'item'   => 0,
);
$manifest = array();

while ( $reader->read() ) {
	if ( XMLReader::ELEMENT !== $reader->nodeType ) {
		continue;
	}

	switch ( $reader->name ) {
		case 'wp:author':
			++$counts['author'];
			$manifest[] = 'author';
			$reader->next( 'wp:author' );
			break;
		case 'wp:category':
		case 'wp:tag':
		case 'wp:term':
			++$counts['term'];
			$manifest[] = 'term';
			$reader->next( $reader->name );
			break;
		case 'item':
			++$counts['item'];
			$manifest[] = 'item';
			$reader->next( 'item' );
			break;
	}
}

$reader->close();
$scan_time = microtime( true ) - $start;
$total = count( $manifest );

echo "File: {$file}\n";
echo 'Size: ' . number_format( filesize( $file ) / 1048576, 1 ) . " MB\n";
echo "Entities: {$total} (authors {$counts['author']}, terms {$counts['term']}, items {$counts['item']})\n";
echo 'Scan time: ' . number_format( $scan_time, 2 ) . "s\n";

// Simulate sequential skip to batch boundary (worst case per batch).
$skip_start = microtime( true );
$reader = new XMLReader();
$reader->open( $file );
$index = 0;
$target = min( $batch_size, $total );

while ( $reader->read() && $index < $target ) {
	if ( XMLReader::ELEMENT !== $reader->nodeType ) {
		continue;
	}
	if ( in_array( $reader->name, array( 'wp:author', 'wp:category', 'wp:tag', 'wp:term', 'item' ), true ) ) {
		++$index;
		if ( 'item' === $reader->name ) {
			$reader->next( 'item' );
		} elseif ( 'wp:author' === $reader->name ) {
			$reader->next( 'wp:author' );
		} else {
			$reader->next( $reader->name );
		}
	}
}
$reader->close();

$skip_time = microtime( true ) - $skip_start;
echo "Sequential read to entity {$target}: " . number_format( $skip_time, 3 ) . "s\n";

$recommended = 50;
if ( $total > 5000 ) {
	$recommended = 10;
} elseif ( $total > 1000 ) {
	$recommended = 20;
}
echo "Recommended web batch size: {$recommended}\n";

exit( 0 );
