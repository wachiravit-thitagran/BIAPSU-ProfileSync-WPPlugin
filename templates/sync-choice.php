<?php
/**
 * Choice page template.
 *
 * Provided by Frontend::render():
 *
 * @var string $action_url admin-post.php URL.
 * @var string $nonce      Nonce value for this user/action.
 * @var string $error      Error message (when a previous sync attempt failed).
 *
 * @package BIAPSU\ProfileSync
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="biapsu-card" role="region" aria-label="<?php esc_attr_e( 'Profile sync', 'biapsu-profilesync' ); ?>">
	<h2 class="biapsu-card__title"><?php esc_html_e( 'ซิงค์ข้อมูลโปรไฟล์ของท่าน', 'biapsu-profilesync' ); ?></h2>

	<p class="biapsu-card__lead">
		<?php esc_html_e( 'ท่านเคยลงทะเบียนแพลตฟอร์มพุทธธรรมแล้ว ต้องการซิงค์ข้อมูลจากแพลตฟอร์มหรือไม่', 'biapsu-profilesync' ); ?>
	</p>

	<p class="biapsu-card__note">
		<?php esc_html_e( 'หากเลือกซิงค์ ระบบจะนำชื่อ-นามสกุล และข้อมูลโปรไฟล์อื่น ๆ จากแพลตฟอร์มพุทธธรรมมาใช้กับบัญชีนี้โดยอัตโนมัติ', 'biapsu-profilesync' ); ?>
	</p>

	<?php if ( '' !== $error ) : ?>
		<div class="biapsu-alert biapsu-alert--error" role="alert">
			<strong><?php esc_html_e( 'ซิงค์ข้อมูลไม่สำเร็จ:', 'biapsu-profilesync' ); ?></strong>
			<span><?php echo esc_html( $error ); ?></span>
		</div>
	<?php endif; ?>

	<div class="biapsu-actions">
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="biapsu-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( \BIAPSU\ProfileSync\Sync_Controller::ACTION ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<input type="hidden" name="decision" value="sync" />
			<button type="submit" class="biapsu-btn biapsu-btn--primary">
				<?php esc_html_e( 'ซิงค์ข้อมูลจากแพลตฟอร์ม', 'biapsu-profilesync' ); ?>
			</button>
		</form>

		<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="biapsu-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( \BIAPSU\ProfileSync\Sync_Controller::ACTION ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<input type="hidden" name="decision" value="skip" />
			<button type="submit" class="biapsu-btn biapsu-btn--ghost">
				<?php esc_html_e( 'ไม่ซิงค์ ใช้ข้อมูลเดิม', 'biapsu-profilesync' ); ?>
			</button>
		</form>
	</div>
</div>
