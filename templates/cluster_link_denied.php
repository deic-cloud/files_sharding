<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div class="body-login-container update">
	<h2><?php p($l->t('File not available')); ?></h2>
	<p><?php
	if (($_['name'] ?? '') !== '') {
		p($l->t('"%1$s" belongs to %2$s and has not been shared with you, or it has been moved or deleted.', [$_['name'], $_['owner']]));
	} elseif (($_['owner'] ?? '') === '') {
		p($l->t('This file has not been shared with you, or it has been moved or deleted.'));
	} else {
		p($l->t('This file belongs to %s and has not been shared with you, or it has been moved or deleted.', [$_['owner']]));
	}
	?></p>
	<p><?php p($l->t('Ask the owner to share it, or the folder it is in, with you.')); ?></p>
	<p><a href="<?php print_unescaped($_['home']); ?>"><?php p($l->t('Go to your files')); ?></a></p>
</div>
