<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;

class TokenCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
        $this->loadModel('SpaLiveV1.AppTokens');
        sleep(900);
        $token = $args->getArguments()[0];        
        $this->AppTokens->getConnection()->execute("UPDATE app_tokens SET deleted = 1 WHERE id = {$token}");
    }

}