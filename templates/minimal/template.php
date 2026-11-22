<?php
/**
 * Template "Minimalista": linhas finas, sem balões coloridos, botões de texto.
 *
 * Variável disponível: $saic (id, mode, position, title, placeholder, welcome,
 * launcher_label, disclaimer, suggestions[], height, color, class).
 *
 * Para customizar: copie esta pasta para wp-content/themes/SEU-TEMA/ai-smart-chat/minimal/
 *
 * @package AI_Smart_Chat
 */

defined( 'ABSPATH' ) || exit;

$saic_floating = ( 'floating' === $saic['mode'] );
$saic_panel_id = $saic['id'] . '-panel';
?>
<?php if ( $saic_floating ) : ?>
	<button type="button" class="saic-launcher" data-saic-launcher aria-expanded="false" aria-controls="<?php echo esc_attr( $saic_panel_id ); ?>">
		<span class="saic-launcher__label"><?php echo esc_html( $saic['launcher_label'] ); ?></span>
		<span aria-hidden="true">?</span>
	</button>
<?php endif; ?>

<div class="saic-panel" id="<?php echo esc_attr( $saic_panel_id ); ?>" data-saic-panel<?php echo $saic_floating ? ' hidden role="dialog" aria-label="' . esc_attr( $saic['title'] ) . '"' : ''; ?>>

	<header class="saic-header">
		<h3 class="saic-header__title"><?php echo esc_html( $saic['title'] ); ?></h3>
		<button type="button" class="saic-text-btn" data-saic-reset><?php esc_html_e( 'Clear', 'ai-smart-chat' ); ?></button>
		<?php if ( $saic_floating ) : ?>
			<button type="button" class="saic-text-btn" data-saic-close aria-label="<?php esc_attr_e( 'Close chat', 'ai-smart-chat' ); ?>">&times;</button>
		<?php endif; ?>
	</header>

	<div class="saic-messages" data-saic-messages role="log" aria-live="polite"></div>

	<?php if ( $saic['suggestions'] ) : ?>
		<div class="saic-suggestions" data-saic-suggestions>
			<?php foreach ( $saic['suggestions'] as $saic_s ) : ?>
				<button type="button" class="saic-chip" data-saic-suggestion="<?php echo esc_attr( $saic_s ); ?>"><?php echo esc_html( $saic_s ); ?></button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<form class="saic-form" data-saic-form>
		<label class="screen-reader-text" for="<?php echo esc_attr( $saic['id'] ); ?>-input" style="position:absolute;left:-9999px"><?php echo esc_html( $saic['placeholder'] ); ?></label>
		<textarea class="saic-input" id="<?php echo esc_attr( $saic['id'] ); ?>-input" data-saic-input rows="1" placeholder="<?php echo esc_attr( $saic['placeholder'] ); ?>" autocomplete="off"></textarea>
		<button type="submit" class="saic-send saic-send--text" data-saic-send><?php esc_html_e( 'Send', 'ai-smart-chat' ); ?></button>
	</form>

	<?php if ( $saic['disclaimer'] ) : ?>
		<p class="saic-disclaimer"><?php echo esc_html( $saic['disclaimer'] ); ?></p>
	<?php endif; ?>
</div>
