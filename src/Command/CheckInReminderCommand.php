<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use Cake\Utility\Text;
require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client; 
use Twilio\Exceptions\TwilioException; 
require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;

use SpaLiveV1\Controller\MainController;
use SpaLiveV1\Controller\OtherservicesController;
use SpaLiveV1\Controller\PaymentsController;

class CheckInReminderCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
 
        $isDev = env('IS_DEV', false);                
        $Other = new OtherservicesController();
        $Other->reminder_checkin_nine();
    }
}