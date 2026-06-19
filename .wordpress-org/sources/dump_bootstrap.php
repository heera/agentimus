<?php
// Dump the exact AgentimusData the admin app boots with, from the active local plugin.
// Rewrite the local host to heera.it so screenshots show a real, clean domain.
$rewrite = function ( $url ) {
	return preg_replace( '#^https?://[^/]+#', 'https://heera.it', (string) $url );
};
add_filter( 'home_url', $rewrite, 99 );
add_filter( 'site_url', $rewrite, 99 );
add_filter( 'rest_url', $rewrite, 99 );

$settings = new \Agentimus\Settings();
$admin    = new \Agentimus\Admin( $settings );
$m = new ReflectionMethod( \Agentimus\Admin::class, 'bootstrap_data' );
$m->setAccessible( true );
$data = $m->invoke( $admin );

$data['onboarded'] = true; // force the main app (not the first-run wizard)

file_put_contents( '/tmp/agentimus-bootstrap.json', wp_json_encode( $data ) );
echo "OK bytes=" . filesize( '/tmp/agentimus-bootstrap.json' ) . "\n";
echo "endpoints: " . wp_json_encode( $data['endpoints'] ) . "\n";
