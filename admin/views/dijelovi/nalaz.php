<?php
/**
 * Jedan nalaz.
 *
 * @var CJTR\Nalazi\Nalaz $nalaz
 * @package CJTR
 */

use CJTR\Config;
use CJTR\Nalazi\Nalaz;

defined( 'ABSPATH' ) || exit;

$oznake = array(
	Nalaz::ZAPREKA => __( 'zaustavlja', Config::TEXT_DOMAIN ),
	Nalaz::VAZNO   => __( 'vazno', Config::TEXT_DOMAIN ),
	Nalaz::SAVJET  => __( 'savjet', Config::TEXT_DOMAIN ),
);
?>
<div class="<?php echo esc_attr( Config::css( 'nalaz' ) . ' ' . Config::css( 'nalaz-' . $nalaz->vaznost ) ); ?>">

	<p class="<?php echo esc_attr( Config::css( 'nalaz-oznaka' ) ); ?>">
		<?php echo esc_html( $oznake[ $nalaz->vaznost ] ?? '' ); ?>
	</p>

	<h3 class="<?php echo esc_attr( Config::css( 'nalaz-naslov' ) ); ?>">
		<?php echo esc_html( $nalaz->naslov ); ?>
	</h3>

	<?php if ( '' !== $nalaz->objasnjenje ) : ?>
		<p class="<?php echo esc_attr( Config::css( 'nalaz-opis' ) ); ?>">
			<?php echo esc_html( $nalaz->objasnjenje ); ?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $nalaz->postupak ) : ?>
		<p class="<?php echo esc_attr( Config::css( 'nalaz-postupak' ) ); ?>">
			<strong><?php esc_html_e( 'Sto uciniti:', Config::TEXT_DOMAIN ); ?></strong>
			<?php echo esc_html( $nalaz->postupak ); ?>
		</p>
	<?php endif; ?>

	<?php // Doslovan tekst za kopiranje — cron redak stoji OVDJE, ne u dokumentaciji. ?>
	<?php if ( '' !== $nalaz->doslovno ) : ?>
		<p class="<?php echo esc_attr( Config::css( 'nalaz-doslovno-naslov' ) ); ?>">
			<?php echo esc_html( $nalaz->doslovno_naslov ); ?>
		</p>
		<textarea
			class="<?php echo esc_attr( Config::css( 'nalaz-doslovno' ) ); ?>"
			rows="2"
			readonly
			onclick="this.select();"
		><?php echo esc_textarea( $nalaz->doslovno ); ?></textarea>
		<p class="<?php echo esc_attr( Config::css( 'nalaz-sitno' ) ); ?>">
			<?php esc_html_e( 'Kliknite u okvir da se cijeli redak oznaci, pa ga kopirajte.', Config::TEXT_DOMAIN ); ?>
		</p>
	<?php endif; ?>

	<p class="<?php echo esc_attr( Config::css( 'nalaz-radnje' ) ); ?>">
		<?php if ( $nalaz->moze_rijesiti() ) : ?>
			<form method="post" class="<?php echo esc_attr( Config::css( 'nalaz-obrazac' ) ); ?>">
				<?php wp_nonce_field( Config::nonce( 'nalaz' ) ); ?>
				<input type="hidden" name="nalaz_posao" value="<?php echo esc_attr( $nalaz->posao ); ?>">
				<button class="button button-primary"><?php echo esc_html( $nalaz->gumb ); ?></button>
			</form>
		<?php endif; ?>

		<?php if ( '' !== $nalaz->ekran ) : ?>
			<a class="button" href="<?php echo esc_url( $nalaz->ekran ); ?>"><?php echo esc_html( $nalaz->ekran_natpis ); ?></a>
		<?php endif; ?>

		<?php if ( '' !== $nalaz->popis ) : ?>
			<a class="<?php echo esc_attr( Config::css( 'nalaz-popis' ) ); ?>" href="<?php echo esc_url( $nalaz->popis ); ?>">
				<?php echo esc_html( $nalaz->popis_natpis ); ?>
			</a>
		<?php endif; ?>
	</p>
</div>
