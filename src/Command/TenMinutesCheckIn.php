<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

use SpaLiveV1\Controller\OtherservicesController;

class TenMinutesCheckInCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){

        $WL = new OtherservicesController();

        $WL->reminder_gage_check_in();
    }
}