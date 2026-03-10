<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

use SpaLiveV1\Controller\GhlController;
use SpaLiveV1\Controller\PaymentsController;

class GhlCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){

        $arr_arguments = $args->getArguments();
        // $Ghl = new GhlController();

        // $Ghl->update_users_downloaded();
        // $Ghl->update_users_basic();
        // $Ghl->update_users_advanced();


        $Payment = new PaymentsController();

        $Payment->send_email_nec_bulk($arr_arguments[0], $arr_arguments[1]);

    }
}