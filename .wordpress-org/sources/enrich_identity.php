<?php
// Temporarily enrich the local identity so screenshots look complete.
// Backs up the current option first; restore_identity.php puts it back.
$opt = get_option( 'agentimus_settings', array() );
file_put_contents( '/tmp/agentimus-settings-backup.json', wp_json_encode( $opt ) );

if ( ! isset( $opt['identity'] ) || ! is_array( $opt['identity'] ) ) {
	$opt['identity'] = array();
}
$opt['identity']['name']      = 'Sheikh Heera';
$opt['identity']['role']      = 'Software architect & CTO, Authlab';
$opt['identity']['about']     = 'Sheikh Heera is a software architect and CTO of Authlab, based in Sylhet, Bangladesh, with 16+ years building web applications and software systems. Outside engineering he is a dedicated fraghead who collects perfumes, with a personal collection of nearly 2,000 fragrances.';
$opt['identity']['expertise'] = array( 'Software Architecture', 'AI Engineering', 'Security', 'PHP', 'JavaScript', 'WordPress', 'Laravel', 'Vue', 'Fragrance' );
$opt['identity']['same_as']   = array( 'https://github.com/heera', 'https://heera.it' );

update_option( 'agentimus_settings', $opt );
echo "enriched (backup at /tmp/agentimus-settings-backup.json)\n";
