<?php
/**
 * Template "Moderno": painel escuro com efeito de vidro, avatar com gradiente e sugestões no topo.
 *
 * Variável disponível: $saic (id, mode, position, title, placeholder, welcome,
 * launcher_label, disclaimer, suggestions[], height, color, class).
 *
 * Para customizar: copie esta pasta para wp-content/themes/SEU-TEMA/ai-smart-chat/modern/
 *
 * @package AI_Smart_Chat
 */

defined( 'ABSPATH' ) || exit;

$saic_floating = ( 'floating' === $saic['mode'] );
$saic_panel_id = $saic['id'] . '-panel';
?>
<?php if ( $saic_floating ) : ?>
	<button type="button" class="saic-launcher" data-saic-launcher aria-expanded="false" aria-controls="<?php echo esc_attr( $saic_panel_id ); ?>">
		<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
		<span class="saic-launcher__label"><?php echo esc_html( $saic['launcher_label'] ); ?></span>
	</button>
<?php endif; ?>

<div class="saic-panel" id="<?php echo esc_attr( $saic_panel_id ); ?>" data-saic-panel<?php echo $saic_floating ? ' hidden role="dialog" aria-label="' . esc_attr( $saic['title'] ) . '"' : ''; ?>>

	<header class="saic-header">
		<span class="saic-avatar" aria-hidden="true">
			<svg viewBox="0 0 24 24"><path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/></svg>
		</span>
		<div class="saic-header__text">
			<h3 class="saic-header__title"><?php echo esc_html( $saic['title'] ); ?></h3>
			<span class="saic-header__sub"><?php esc_html_e( 'Answers based on the site content', 'ai-smart-chat' ); ?></span>
		</div>
		<button type="button" class="saic-icon-btn" data-saic-reset aria-label="<?php esc_attr_e( 'New conversation', 'ai-smart-chat' ); ?>" title="<?php esc_attr_e( 'New conversation', 'ai-smart-chat' ); ?>">
			<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
		</button>
		<?php if ( $saic_floating ) : ?>
			<button type="button" class="saic-icon-btn" data-saic-close aria-label="<?php esc_attr_e( 'Close chat', 'ai-smart-chat' ); ?>">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
			</button>
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
		<button type="submit" class="saic-send" data-saic-send aria-label="<?php esc_attr_e( 'Send', 'ai-smart-chat' ); ?>">
			<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>
		</button>
	</form>

	<?php if ( $saic['disclaimer'] ) : ?>
		<p class="saic-disclaimer"><?php echo esc_html( $saic['disclaimer'] ); ?></p>
	<?php endif; ?>
</div>
