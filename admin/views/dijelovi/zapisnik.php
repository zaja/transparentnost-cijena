<?php
/**
 * Zapisnik jednog posla.
 *
 * Izdvojen jer ga trazi i otvorena i sazeta kartica. Ista tablica na dva mjesta
 * razisla bi se prvom izmjenom.
 *
 * @var CJTR\Poslovi\Stanje $s
 * @package CJTR
 */

use CJTR\Config;

defined( 'ABSPATH' ) || exit;

$log = $s->log( 20 );

if ( empty( $log ) ) {
	return;
}
?>
<details class="<?php echo esc_attr( Config::css( 'log' ) ); ?>">
	<summary><?php esc_html_e( 'Zapisnik', Config::TEXT_DOMAIN ); ?></summary>
	<table class="<?php echo esc_attr( Config::css( 'stavke' ) ); ?>">
		<tbody>
			<?php foreach ( $log as $redak ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $redak->zapisano ); ?></th>
					<td>
						<?php if ( 'greska' === $redak->razina ) : ?>
							<strong class="<?php echo esc_attr( Config::css( 'gresaka' ) ); ?>"><?php echo esc_html( $redak->poruka ); ?></strong>
						<?php elseif ( 'upozorenje' === $redak->razina ) : ?>
							<strong class="<?php echo esc_attr( Config::css( 'upozorenje' ) ); ?>"><?php echo esc_html( $redak->poruka ); ?></strong>
						<?php else : ?>
							<?php echo esc_html( $redak->poruka ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</details>
