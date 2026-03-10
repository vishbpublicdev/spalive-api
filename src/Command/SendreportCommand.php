<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use SpaLiveV1\Controller\MainController;

class SendreportCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
		$Main = new MainController();

		$Main->send_general_report();
    }


}