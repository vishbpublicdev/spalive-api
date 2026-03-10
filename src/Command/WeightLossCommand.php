<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

use SpaLiveV1\Controller\OtherservicesController;

class WeightLossCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){

        $WL = new OtherservicesController();

        $WL->notify_check_ins();
    }
}