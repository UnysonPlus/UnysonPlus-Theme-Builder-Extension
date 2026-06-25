<?php if ( ! defined( 'ABSPATH' ) ) {
	die( 'Forbidden' );
}

/**
 * Theme Builder — theme-independent full-page document (the "Divi-style" takeover).
 *
 * Loaded via template_include (see hooks.php) when the active theme is NOT the
 * unysonplus-theme family AND a matching Template assigns a Body. The active
 * theme's own template (and its get_header()/get_footer()) is bypassed entirely —
 * the plugin renders the whole page itself: header preset + body region + footer
 * preset, wrapped in wp_head()/wp_footer() so every script/style still loads
 * (the preset shortcode assets are enqueued for the head by
 * _action_fw_tb_enqueue_preset_assets()).
 *
 * "Inherit" header/footer (id 0) render NOTHING here — under a foreign theme there
 * is no native chrome to inherit (that is what the unysonplus-theme path is for).
 * Header/footer-ONLY templates (no body) never reach this file; they keep the
 * foreign theme's page and swap only its <header>/<footer> (surgical swap).
 */

$fw_tb_header_id = class_exists( 'FW_Theme_Builder_Resolver' ) ? (int) FW_Theme_Builder_Resolver::header_id() : 0;
$fw_tb_body_id   = class_exists( 'FW_Theme_Builder_Resolver' ) ? (int) FW_Theme_Builder_Resolver::body_id()   : 0;
$fw_tb_footer_id = class_exists( 'FW_Theme_Builder_Resolver' ) ? (int) FW_Theme_Builder_Resolver::footer_id() : 0;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'fw-tb-standalone' ); ?>>
<?php if ( function_exists( 'wp_body_open' ) ) { wp_body_open(); } ?>

<?php if ( $fw_tb_header_id > 0 && function_exists( 'fw_ext_hfbuilder_render' ) ) : ?>
<header class="fw-tb-header" role="banner"><?php echo fw_ext_hfbuilder_render( $fw_tb_header_id, 'header' ); // phpcs:ignore WordPress.Security.EscapeOutput — builder HTML ?></header>
<?php endif; ?>

<main id="fw-tb-content" class="fw-tb-standalone__content">
<?php
if ( $fw_tb_body_id > 0 && function_exists( 'fw_ext_theme_builder_print_body_region' ) ) {
	fw_ext_theme_builder_print_body_region( $fw_tb_body_id );
}
?>
</main>

<?php if ( $fw_tb_footer_id > 0 && function_exists( 'fw_ext_hfbuilder_render' ) ) : ?>
<footer class="fw-tb-footer" role="contentinfo"><?php echo fw_ext_hfbuilder_render( $fw_tb_footer_id, 'footer' ); // phpcs:ignore WordPress.Security.EscapeOutput — builder HTML ?></footer>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
