<?php
/** @var array $_ */
?>
<div class="body-login-container update">
	<h2><?php p($l->t('Login failed')); ?></h2>
	<p><?php p($_['message'] ?? ''); ?></p>
	<p><a href="<?php print_unescaped($_['login_url'] ?? \OC::$server->getURLGenerator()->linkToDefaultPageUrl()); ?>">
		<?php p($l->t('Back to login')); ?>
	</a></p>
</div>
