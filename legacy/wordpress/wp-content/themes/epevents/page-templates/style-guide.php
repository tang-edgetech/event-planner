<?php
/**
 * Template Name: Style guide
 * Shows the design system (Branding colours, type, buttons, form fields, cards, badges) on one page
 * so the client can sign off the look. Everything follows Appearance → Branding.
 */
defined( 'ABSPATH' ) || exit;
get_header();
$swatches = array(
	'Primary'        => '--ep-accent',
	'Secondary'      => '--ep-secondary',
	'Page'           => '--ep-page',
	'Cards'          => '--ep-surface',
	'Text'           => '--ep-text',
	'Secondary text' => '--ep-muted',
	'Borders'        => '--ep-border',
	'Footer'         => '--ep-footer-bg',
);
?>
<main id="main" class="ep-styleguide">
	<section class="ep-section ep-pad-md ep-bg-color ep-banner ep-banner--auto is-dark is-center" style="--sec-bg:var(--ep-accent)">
		<div class="ep-container">
			<div class="ep-banner__content">
				<p class="ep-banner__eyebrow">Design system</p>
				<h1 class="ep-banner__title"><?php echo esc_html( get_bloginfo( 'name' ) ); ?> style guide</h1>
				<p class="ep-banner__desc">Colours, fonts and shapes come from Appearance → Branding, so this page always shows the current look.</p>
			</div>
		</div>
	</section>

	<section class="ep-section ep-pad-md">
		<div class="ep-container">
			<header class="ep-section__head is-left"><p class="ep-eyebrow">01</p><h2 class="ep-section__title">Colours</h2></header>
			<div class="ep-sg-swatches">
				<?php foreach ( $swatches as $label => $var ) : ?>
					<div class="ep-sg-swatch"><span style="background:var(<?php echo esc_attr( $var ); ?>)"></span><strong><?php echo esc_html( $label ); ?></strong><code data-var="<?php echo esc_attr( $var ); ?>"></code></div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="ep-section ep-pad-md" style="background:var(--ep-surface)">
		<div class="ep-container">
			<header class="ep-section__head is-left"><p class="ep-eyebrow">02</p><h2 class="ep-section__title">Typography</h2></header>
			<div class="ep-sg-type">
				<h1>Heading 1 · Scientific Summit 2026</h1>
				<h2>Heading 2 · Programme overview</h2>
				<h3>Heading 3 · Session details</h3>
				<p class="ep-section__intro">Intro text · A short lead paragraph that introduces a section in a calm, readable size.</p>
				<p>Body text · Registration is open to healthcare professionals practising in Malaysia. Please complete the form with the details as they appear on your NRIC. A confirmation email will be sent after you submit.</p>
				<p class="ep-eyebrow">Small label</p>
			</div>
		</div>
	</section>

	<section class="ep-section ep-pad-md">
		<div class="ep-container">
			<header class="ep-section__head is-left"><p class="ep-eyebrow">03</p><h2 class="ep-section__title">Buttons and badges</h2></header>
			<div class="ep-buttons" style="margin-top:0">
				<a class="ep-btn ep-btn--solid" href="#">Register now</a>
				<a class="ep-btn ep-btn--outline" href="#">Join virtually</a>
				<a class="ep-btn ep-btn--solid ep-btn--sm" href="#">Small button</a>
				<button type="button" class="ep-link">Text link →</button>
			</div>
			<div class="ep-buttons">
				<span class="ep-status ep-status--open">Registration open</span>
				<span class="ep-status ep-status--waitlist">Waiting list open</span>
				<span class="ep-status ep-status--full">Application full</span>
				<span class="ep-status ep-status--closed">Registration closed</span>
			</div>
		</div>
	</section>

	<section class="ep-section ep-pad-md ep-bg-color is-dark" style="--sec-bg:var(--ep-accent)">
		<div class="ep-container">
			<header class="ep-section__head is-left"><p class="ep-eyebrow">04</p><h2 class="ep-section__title">On a dark background</h2><div class="ep-section__intro">Sections switch to light text automatically on dark colours and images.</div></header>
			<div class="ep-buttons" style="margin-top:0">
				<a class="ep-btn ep-btn--solid" href="#">Register now</a>
				<a class="ep-btn ep-btn--outline" href="#">Join virtually</a>
			</div>
		</div>
	</section>

	<section class="ep-section ep-pad-md">
		<div class="ep-container">
			<header class="ep-section__head is-left"><p class="ep-eyebrow">05</p><h2 class="ep-section__title">Form fields</h2></header>
			<div class="ep-event-card epc-form-wrap" style="max-width:760px">
				<div class="epc-grid">
					<div class="epc-field epc-w-25"><label>Salutation <span class="epc-req">*</span></label><select><option>Dr.</option></select></div>
					<div class="epc-field epc-w-33"><label>Full name <span class="epc-req">*</span></label><input type="text" value="Lim Mei Ling"></div>
					<div class="epc-field epc-w-33 is-invalid"><label>NRIC <span class="epc-req">*</span></label><input type="text" value="900101-99-1234"><span class="epc-error">Please enter a valid MyKad number.</span></div>
					<div class="epc-field epc-w-50"><label>Email <span class="epc-req">*</span></label><input type="email" placeholder="name@hospital.my"><span class="epc-help">We will send your confirmation here.</span></div>
					<div class="epc-field epc-w-50"><label>Mobile <span class="epc-req">*</span></label><input type="tel" placeholder="012-345 6789"></div>
					<div class="epc-field epc-w-100"><fieldset><legend>Attendance</legend><div class="epc-choices"><label class="epc-choice"><input type="radio" name="sg" checked> Physical</label><label class="epc-choice"><input type="radio" name="sg"> Virtual</label></div></fieldset></div>
				</div>
				<div class="epc-actions"><button type="button" class="epc-submit">Submit registration</button></div>
			</div>
		</div>
	</section>

	<section class="ep-section ep-pad-md" style="background:var(--ep-surface)">
		<div class="ep-container">
			<header class="ep-section__head is-left"><p class="ep-eyebrow">06</p><h2 class="ep-section__title">Cards and programme</h2></header>
			<div class="ep-cards ep-cols-3">
				<?php foreach ( array( 'Hotel information', 'Getting there', 'Dress code' ) as $t ) : ?>
					<article class="ep-card"><span class="ep-card__placeholder"></span><div class="ep-card__body"><p class="ep-card__meta">Logistics</p><h3 class="ep-card__title"><?php echo esc_html( $t ); ?></h3><p>Short supporting text for this card.</p><a class="ep-link" href="#">Read more →</a></div></article>
				<?php endforeach; ?>
			</div>
			<ol class="ep-agenda" style="margin-top:40px">
				<li class="ep-agenda__item"><div class="ep-agenda__time">8:30am</div><div class="ep-agenda__body"><strong>Registration and breakfast</strong><p>Foyer, level 3</p></div></li>
				<li class="ep-agenda__item"><div class="ep-agenda__time">9:15am</div><div class="ep-agenda__body"><strong>Opening address</strong><p>Programme chair</p></div></li>
			</ol>
		</div>
	</section>
</main>
<script>
document.querySelectorAll('.ep-sg-swatch code').forEach(function (c) {
	c.textContent = getComputedStyle(document.documentElement).getPropertyValue(c.dataset.var).trim();
});
</script>
<?php
get_footer();
