<?php
$bak = json_decode( file_get_contents( '/tmp/agentimus-settings-backup.json' ), true );
if ( is_array( $bak ) ) { update_option( 'agentimus_settings', $bak ); echo "local identity restored\n"; }
else { echo "WARN: backup missing/invalid — not restored\n"; }
