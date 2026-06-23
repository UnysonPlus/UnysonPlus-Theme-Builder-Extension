<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Theme Builder', 'fw' );
$manifest['slug']        = 'unysonplus-theme-builder';
$manifest['description'] = __(
	'Build global Headers, Bodies and Footers with the page builder, bundle them into a Template, and assign each Template to parts of the site with conditional rules (Use On / Exclude From) — the UnysonPlus take on Divi\'s Theme Builder. The Theme Settings header/footer remains the fallback when no Template applies. Absorbs and replaces the former Header & Footer Builder extension.',
	'fw'
);

$manifest['version']    = '1.1.5';
$manifest['display']    = true;
$manifest['standalone'] = true;
$manifest['thumbnail']  = 'thumbnail.svg';

// Repository Info — repo root IS this folder.
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Theme-Builder-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-Theme-Builder-Extension';
$manifest['github_branch'] = 'master';

// Needs the shortcodes extension (and, at runtime, the page-builder child
// extension — guarded with function_exists, exactly like the snippets extension).
$manifest['requirements'] = array(
	'extensions' => array(
		'shortcodes' => array(),
	),
);

$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
