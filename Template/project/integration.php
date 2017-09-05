<h3>dailydigest</h3>
<div class="panel">

    <?= $this->form->label('Send daily digest (yes\no)', 'send_digest') ?>
    <?= $this->form->text('send_digest', $values) ?>
    
    <?= $this->form->label('Send weekly digest (yes\no)', 'send_weekly_digest') ?>
    <?= $this->form->text('send_weekly_digest', $values) ?>
    
    <p class="form-help"><a href="https://github.com/krijnenr/kanboard_plugin_digest" target="_blank"><?= t('Help on Digest plugin integration') ?></a></p>

    <div class="form-actions">
        <input type="submit" value="<?= t('Save') ?>" class="btn btn-blue"/>
    </div>
</div>
