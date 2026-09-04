<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

/**
 * Changelog ----------------------------------------------------------------
 *
 * 1.1.47 - The List view scales: a search box, paged output (20 a page) and bulk
 *          Enable / Disable / Delete with the standard select-all. The Templates
 *          query stays unbounded on purpose — a Template's rank depends on every
 *          other Template, so the ordering cannot be computed a page at a time —
 *          but only one page is ever RENDERED, which is what actually falls over
 *          on a large site, and a search narrows the set in SQL before ranking.
 *          Bulk ids are re-checked against the Template post type, so a forged
 *          payload cannot trash arbitrary posts.
 *
 * 1.1.46 - A Header/Body/Footer preset now says which Templates use it. Presets
 *          are shared by design, and nothing on the preset's own screens said so,
 *          which made deleting one a silent way to blank the chrome in several
 *          places at once. The preset list tables gain a "Used by" column and the
 *          preset editor names the Templates that depend on it, so the cost of a
 *          delete is visible before it is made. Built once per request from a
 *          single pass over the Templates, so a list of twenty presets costs what
 *          one does.
 *
 * 1.1.45 - Author and page-template conditions. A Template can now target the
 *          posts of specific authors, an author's archive, or any singular
 *          content using a given page template — the three conditions Divi has
 *          that we did not. Page-template qualifiers are template FILENAMES, so
 *          sub_type is no longer run through sanitize_key() (which silently ate
 *          the dot in 'page-landing.php' and left a rule that matched nothing);
 *          it is validated against the theme's declared templates instead, which
 *          is a stronger gate than character-stripping — a traversal attempt like
 *          '../../evil.php' is simply not on the list and the rule is dropped.
 *
 * 1.1.44 - Card thumbnails — a real render of the Template, not a name. Each
 *          card frames the site through the existing admin-gated, nonce'd preview
 *          URL and scales it down, so the picture is always current and needs no
 *          screenshot service, headless browser or third-party library: nothing
 *          new has to be installed on the host. The frame is previewed against a
 *          URL the Template's own conditions actually match, so a Template for
 *          single posts is shown on a single post rather than on the home page.
 *          Each thumbnail costs a front-end page load, so nothing loads until its
 *          card nears the viewport, and a per-user "Previews on/off" switch sits
 *          in the screen header. Embedded frames drop the preview badge and the
 *          admin bar, and are inert to the pointer so the card keeps its clicks.
 *
 * 1.1.43 - A Tree view of the site's own template hierarchy, beside the Canvas
 *          and the List. Every node is built from what THIS site registers — its
 *          post types, its public taxonomies, its special views — and each
 *          Template hangs off the node its most specific Use On rule targets, so
 *          depth reads as precedence: anything deeper overrides what is above it.
 *          Its real value is the negative space: a node with nothing attached is
 *          a visible coverage gap ("you have no Search results template"), which
 *          a card grid can never show. Each gap carries an "Add a Template here"
 *          link that opens a new Template with that condition already filled in;
 *          the prefill is validated against the rule vocabulary, so a hand-typed
 *          key cannot inject a rule shape the resolver does not know.
 *
 * 1.1.41 - Templates can be switched off, and ties can be settled by hand. An
 *          Enabled toggle sits on each card (and in a Status & priority box on
 *          the edit screen), so a Template can be parked during a redesign
 *          without deleting it or losing its designs. Priority is a tie-break
 *          only: when two Templates match a request at the SAME specificity the
 *          higher Priority wins instead of the newest — it can never let a broad
 *          Template outrank a more specific one. The status flag is stored
 *          INVERTED (`tb_disabled`) so that an absent value always reads as ON,
 *          which is what every Template written before this has. Both travel
 *          with export/import, and a theme re-seed leaves them alone.
 *
 * 1.1.40 - Conditions can be combined with AND, not only OR. A Template now
 *          stores a `relation` alongside its rules; 'or' (any Include rule
 *          matches) stays the default and is what every Template written
 *          before this got, while 'and' requires every Include rule to match —
 *          so "posts in Category X" + "with Tag Y" finally narrows instead of
 *          widening. Exceptions are deliberately left OR: an exclusion fires
 *          as soon as any one of them matches. The relation is carried through
 *          export / import / theme seeding, and the resolver reads it inline
 *          so it keeps its no-dependency, front-end-safe shape.
 *
 * 1.1.39 - Conditions are edited as rows instead of two walls of checkboxes.
 *          Step 2 is now a repeatable list — Include/Exclude, the rule type,
 *          its qualifier, and its values — where one row maps to exactly one
 *          resolver rule, so the two duplicated form halves are gone. Values
 *          are picked through a searchable, capped AJAX picker rather than
 *          pre-rendering every post on the page. Taxonomy rules now offer
 *          EVERY public taxonomy (the old form was hard-wired to category and
 *          product_cat), which the resolver already supported.
 *
 * 1.1.38 - Canvas view: one card per Template with three actionable Header /
 *          Body / Footer slots, assignable in place. Cards are ordered by real
 *          precedence, and a Canvas/List switch is remembered per user.
 */

$manifest['name']        = __( 'Theme Builder', 'fw' );
$manifest['slug']        = 'unysonplus-theme-builder';
$manifest['description'] = __(
	'Build global Headers, Bodies and Footers with the page builder, bundle them into a Template, and assign each Template to parts of the site with conditional rules (Use On / Exclude From) — the UnysonPlus take on Divi\'s Theme Builder. The Theme Settings header/footer remains the fallback when no Template applies. Absorbs and replaces the former Header & Footer Builder extension.',
	'fw'
);

$manifest['version']    = '1.1.50';
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
