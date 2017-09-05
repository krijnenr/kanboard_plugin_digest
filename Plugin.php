<?php

namespace Kanboard\Plugin\Digest;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Digest\Command\TaskSendDigest;
use Kanboard\Core\Translator;

class Plugin extends Base
{
    public function initialize()
    {
    	$this->template->hook->attach('template:project:integrations', 'Digest:project/integration');
    	 
    	$this->cli->add(new TaskSendDigest($this->container));
    }
   
    public function getPluginName() {
        return 'Digest';
    }
    
   public function onStartup()
   {
        Translator::load($this->languageModel->getCurrentLanguage(), __DIR__.'/Locale');
   }
    
    public function getPluginDescription() {
        return t('Send daily digest mail to project members');
    }

    public function getPluginAuthor() {
        return 'Robbert Krijnen';
    }

    public function getPluginVersion() {
        return '0.1.0.';
    }
    
    public function getCompatibleVersion()
    {
    	/* developmend based on 1.45, should work with earlier versions (1.35?) */
    	return '>=1.0.45';
    }
    
}
