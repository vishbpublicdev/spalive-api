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
use SpaLiveV1\Controller\TrainerController;
class AutopayCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
 
        $isDev = env('IS_DEV', false);
        
        $this->loadModel('SpaLiveV1.DataPayment');

        $oneMonthAgo = date('Y-m-d H:i:s', strtotime('-1 month'));

        $_where = ['DataPayment.is_visible' => 1, 'DataPayment.prod' => 1];
        $_where['OR'] = [['Froom.is_test IN (NULL,0)'],['Too.is_test IN (NULL,0)']];

        $_where[] = ['DataPayment.comission_payed' => 0, 'Too.stripe_account_confirm' => 1];
        $_where['DataPayment.created >='] = $oneMonthAgo;


        $_fields = ['DataPayment.service_uid','DataPayment.refund_id','DataPayment.id','DataPayment.comission_payed','DataPayment.prod','DataPayment.uid','DataPayment.type','DataPayment.intent','DataPayment.payment','DataPayment.discount_credits','DataPayment.id_to','DataPayment.id_from','DataPayment.receipt','DataPayment.promo_code','DataPayment.subtotal','DataPayment.total','DataPayment.created','Froom.email','Froom.name','Froom.lname','Froom.uid','Too.email','Too.name','Too.lname','Too.stripe_account_confirm','Too.stripe_account','Too.uid','Froom.type','Too.type', 'Froom.stripe_account_confirm','Froom.stripe_account'];
        
        $ent_payment = $this->DataPayment->find()->select($_fields)
        ->join([
            'Froom' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'Froom.id = DataPayment.id_from'],
            'Too' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'Too.id = DataPayment.id_to'],
        ])
        ->where($_where)->all();



        $Main = new MainController();
        $Pay = new PaymentsController();

        foreach($ent_payment as $row) {
            // $Main->exec_refund_payment($row->id,'sales_representative');
            $Main->exec_refund_payment($row->id,$row->type);
        }

        $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');

        
        
        $ent_pays = $this->DataSalesRepresentativePayments->find()->where([
            'DataSalesRepresentativePayments.deleted' => 1, 
            'DataSalesRepresentativePayments.payment_uid' => '', 
            'DataSalesRepresentativePayments.payload' => '', 
            'DataSalesRepresentativePayments.subscription_id' => 0, 
            'DataSalesRepresentativePayments.description IN' => array('PAY INVITATION', 'SALES TEAM BASIC', 'SALES TEAM', 'SALES TEAM MD SUB', 'SALES TEAM ADVANCED', 'SALES TEAM FILLERS', 'SALES TEAM LEVEL 3', 'SALES TEAM OTHER COURSE'),
            'DataSalesRepresentativePayments.created >=' => $oneMonthAgo
        ])->all();

        
        foreach($ent_pays as $row2) {

            $Pay->payment_invitations($row2->user_id, $row2->payment_id, $row2->id, $row2->uid);
        }

        $ent_pays_sales = $this->DataSalesRepresentativePayments->find()->where(['DataSalesRepresentativePayments.deleted' => 1, 'DataSalesRepresentativePayments.payment_uid' => '', 'DataSalesRepresentativePayments.payload' => '', 'DataSalesRepresentativePayments.description' => 'SALES TEAM'])->all();

        foreach($ent_pays_sales as $row3) {
            $Pay->payment_invitations($row3->user_id, $row3->payment_id, $row3->id, $row3->uid);
        }

        $ent_pays_schools = $this->DataSalesRepresentativePayments->find()->where(['DataSalesRepresentativePayments.deleted' => 1, 'DataSalesRepresentativePayments.payment_uid' => '', 'DataSalesRepresentativePayments.payload' => '', 'DataSalesRepresentativePayments.description' => 'SALES TEAM MD SUB'])->all();

        foreach($ent_pays_sales as $row3) {
            $Pay->payment_invitations($row3->user_id, $row3->payment_id, $row3->id, $row3->uid);
        }

        $Other = new OtherservicesController();
        $Other->reminder_checkin_nine();
        $Other->checkin_after_48hrs();

        // $Trainer = new TrainerController();
        // $Trainer->delete_trainee_24_after_course_passed();
    }
}