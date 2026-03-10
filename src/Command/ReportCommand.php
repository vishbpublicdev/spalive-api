<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use SpaLiveV1\Controller\MainController;
use SpaLiveV1\Controller\SubscriptionController;

class ReportCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
		$Main = new MainController();
    $Subscription = new SubscriptionController();
    $Subscription->send_email_unsubscribed_injector();
		$Main->general_report();
    }


}