<?php
defined( 'ABSPATH' ) || exit;
$row = $args['row'];
echo ept_section_open( 'html', $args['s'] ); // phpcs:ignore
echo ept_html_section( (string) ( $row['code'] ?? '' ) ); // phpcs:ignore -- sanitised in helper.
echo ept_section_close(); // phpcs:ignore
