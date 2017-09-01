<h3>dailydigest</h3>
<div class="panel">
    <?= $this->form->label('Send daily digest (yes\no)', 'send_digest') ?>
    <?= $this->form->text('send_digest', $values) ?>
    
    <p class="form-help"><a href="https://kanboard.net/plugin/dailydigest" target="_blank"><?= t('Help on My integration') ?></a></p>

    <div class="form-actions">
        <input type="submit" value="<?= t('Save') ?>" class="btn btn-blue"/>
    </div>
</div>
