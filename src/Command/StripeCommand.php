<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client; 
use Twilio\Exceptions\TwilioException; 

class StripeCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
        $isDev = env('IS_DEV', false);
        $arr_arguments = $args->getArguments();
         
         if(isset($arr_arguments[0])){
             $from = $arr_arguments[0];
         }  else{
            $from = date('Y-m-01');        
         }
         if(isset($arr_arguments[1])){            
            $to = $arr_arguments[1];
        }  else{
            $to = date('Y-m-t');   
        }                 
         
         $this->loadModel('SpaLiveV1.DataStripeRequestReport');
         $id_r =0; 
         $array_rr = array(			
             'date' => date('Y-m-d H:i:s'),
             'start' => $from,
             'end' => $to,
             'status' => 'request_user',
             'deleted' => 0,
             'user' => 0,			
         );
         $c_entity = $this->DataStripeRequestReport->newEntity($array_rr);
         if(!$c_entity->hasErrors()) {
             $id_report = $this->DataStripeRequestReport->save($c_entity);
             $id_r = $id_report->id;
         }
        $this->stripe_transfer($from,$to, $id_r);
        //$this->stripe_transfer();        
    }

    function stripe_transfer($from,$to,$id_r){        
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
        // $this->log(__LINE__ . ' stripe_transfer' . json_encode([$from,$to,$id_r]));
        //date now 
        $now = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime($now . ' -1 day'));
        $yesterday = date('Y-m-d', strtotime('2023-11-01'));
        $load_more = true;
        $firstDay = "$from 00:00:00";
        $lastDay  = "$to 23:59:59";
        // if (count($transfers->data) >= 100) {
        $arr_transfers = [];
        $arr_stripe_conditions = ['limit' => 100];

        //if ($firstDay && $lastDay) {
            $arr_stripe_conditions['created'] = [
                'gte' => strtotime($firstDay),
                'lte' => strtotime($lastDay),
            ];
            $arr_stripe_conditions['expand'] = ['data.balance_transaction'];
        //}
        $this->log(__LINE__ . ' arr_stripe_conditions' . json_encode($arr_stripe_conditions));
		// transfer
        $this->loadModel('SpaLiveV1.DataStripeRequestReport');
        $this->loadModel('SpaLiveV1.DataStripeTransfer');
		$this->DataStripeRequestReport->updateAll(
            ['status' => 'start_download_transfer'],
            ['id' => $id_r]
        );

        $transfers = $stripe->transfers->all($arr_stripe_conditions);
        $last_obj = null;
        $cont =0;
        while ($load_more) {
            $transfers = $stripe->transfers->all($arr_stripe_conditions);
			$this->log(__LINE__ . ' load more transfer' . json_encode($load_more));
            foreach ($transfers->data as $key => $tr) {
                $arr_transfers[] = $tr;
                $last_obj = $tr->id;
                if($tr->description=='CI COMMISSION PAYMENT'){
                    $cont ++;
                    //$this->log(__LINE__ . ' load more transfer' . json_encode($tr));
                }
                //find 
                $exists = $this->DataStripeTransfer->find()->where(['id_tr' => $tr->id])->first();
                $fee = 0;
                if (isset($tr->balance_transaction ) && isset($tr->balance_transaction->fee) ){
                    
                    $fee = $tr->balance_transaction->fee;
                }
                if(empty($exists)){
                    $array_data = array(
                    'id_tr' => $tr->id,
                    'date' => date('Y-m-d H:i:s',$tr->created),
                    'description' => $tr->description,
                    'status' => '',
                    'amount' => $tr->amount,
                    'amount_reversed' => $tr->amount_reversed,
                    'fee' => $fee,
                    'destination_stripe_account' => $tr->destination,
                    'transfer_group' => $tr->transfer_group,
                    'payload' => $tr,
                    'type' => 'transfer'
                );
                    $c_entity = $this->DataStripeTransfer->newEntity($array_data);
                    if(!$c_entity->hasErrors()) {
                        $this->DataStripeTransfer->save($c_entity);                    
                    } 
                }else{
                    $array_data = array(
                        'id' => $exists->id,
                        'id_tr' => $tr->id,
                        'date' => date('Y-m-d H:i:s',$tr->created),
                        'description' => $tr->description,
                        'status' => '',
                        'amount' => $tr->amount,
                        'amount_reversed' => $tr->amount_reversed,
                        'fee' => $fee,
                        'destination_stripe_account' => $tr->destination,
                        'transfer_group' => $tr->transfer_group,
                        'payload' => $tr,
                        'type' => 'transfer' );
                        $c_entity = $this->DataStripeTransfer->newEntity($array_data);
                        if(!$c_entity->hasErrors()) {
                            $this->DataStripeTransfer->save($c_entity);                    
                        } 
                }
            }
            
            if (count($transfers->data) < 100) {
                $load_more = false;
                $this->DataStripeRequestReport->updateAll(
                    ['status' => 'end_download_transfer'],
                    ['id' => $id_r]
                );
                }
            else $arr_stripe_conditions['starting_after'] = $last_obj;        
        }  
        
		//payments
		$arr_stripe_conditions =[];
		$arr_stripe_conditions = ['limit' => 100];

        //if ($firstDay && $lastDay) {
            $arr_stripe_conditions['created'] = [
                'gte' => strtotime($firstDay),
                'lte' => strtotime($lastDay),
            ];
            $arr_stripe_conditions['expand'] = ['data.charges.data.balance_transaction'];
		$paymentIntents = $stripe->paymentIntents->all($arr_stripe_conditions);
        $last_obj = null;
        $load_more = true;
        $this->DataStripeRequestReport->updateAll(
            ['status' => 'start_download_payments'],
            ['id' => $id_r]
        );
        while ($load_more) {
            $paymentIntents = $stripe->paymentIntents->all($arr_stripe_conditions);
             $this->log(__LINE__ . ' load more payments' . json_encode($load_more));
            foreach ($paymentIntents->data as $key => $tr) {            
                $arr_paymentIntents[] = $tr;
                $last_obj = $tr->id;
                $charges = $tr->charges;
                $amount_refunded = 0;
                $fee =0;
                if (isset($charges['data'])){                     
                    $data = $charges['data'];
                    if(isset($data[0])){
                        if(isset($data[0]->amount_refunded)){
                        $amount_refunded = $data[0]->amount_refunded;    
                        }
                        if (isset($data[0]->balance_transaction ) && isset($data[0]->balance_transaction->fee) ){
                            
                            $fee = $data[0]->balance_transaction->fee;
                        }
                    }
                }
                
                
                //find 
                $exists = $this->DataStripeTransfer->find()->where(['id_tr' => $tr->id])->first();
                if(empty($exists)){
                    $array_data = array(
                    'id_tr' => $tr->id,
                    'date' => date('Y-m-d H:i:s',$tr->created),
                    'description' => $tr->description,
                    'status' => $tr->status,
                    'amount' => $tr->amount,
                    'amount_reversed' => $amount_refunded,
                    'fee' => $fee,
                    'destination_stripe_account' => $tr->destination,
                    'transfer_group' => $tr->transfer_group,
                    'payload' => $tr,
                    'type' => 'payment' );
                    $c_entity = $this->DataStripeTransfer->newEntity($array_data);
                    if(!$c_entity->hasErrors()) {
                        $ent_saved = $this->DataStripeTransfer->save($c_entity);                    
                    } 
                }else{
                    $array_data = array(
                        'id' => $exists->id,
                        'id_tr' => $tr->id,
                        'date' => date('Y-m-d H:i:s',$tr->created),
                        'description' => $tr->description,
                        'status' => $tr->status,
                        'amount' => $tr->amount,
                        'amount_reversed' => $amount_refunded,
                        'fee' =>  $fee,
                        'destination_stripe_account' => $tr->destination,
                        'transfer_group' => $tr->transfer_group,
                        'payload' => $tr,
                        'type' => 'payment' );
                        $c_entity = $this->DataStripeTransfer->newEntity($array_data);
                        if(!$c_entity->hasErrors()) {
                            $ent_saved = $this->DataStripeTransfer->save($c_entity);                    
                        } 
                }
            }
            
            if (count($paymentIntents->data) < 100) {
                $load_more = false;
                $this->DataStripeRequestReport->updateAll(
                    ['status' => 'end_download_payments'],
                    ['id' => $id_r]
                );
            }
            else $arr_stripe_conditions['starting_after'] = $last_obj;        
        }
        $this->DataStripeRequestReport->updateAll(
            ['status' => 'end_download_stripe'],
            ['id' => $id_r]
        ); 

        //refunds       
		$arr_stripe_conditions =[];
		$arr_stripe_conditions = ['limit' => 100];

        //if ($firstDay && $lastDay) {
            $arr_stripe_conditions['created'] = [
                'gte' => strtotime($firstDay),
                'lte' => strtotime($lastDay),
            ];
            $arr_stripe_conditions['expand'] = ['data.balance_transaction'];
		$paymentIntents = $stripe->refunds->all($arr_stripe_conditions);
        $last_obj = null;
        $load_more = true;
        $this->DataStripeRequestReport->updateAll(
            ['status' => 'start_download_refunds'],
            ['id' => $id_r]
        );
        while ($load_more) {
            $paymentIntents = $stripe->refunds->all($arr_stripe_conditions);
             $this->log(__LINE__ . ' load more refunds' . json_encode($load_more));
            foreach ($paymentIntents->data as $key => $tr) {            
                $arr_paymentIntents[] = $tr;
                $last_obj = $tr->id;
                $charges = $tr->charges;
                $amount_refunded = 0;
                $fee =0;  //$this->log(__LINE__ . ' ' . json_encode($tr));
                if (isset($charges['data'])){                     
                    $data = $charges['data'];
                    if(isset($data[0])){
                        if(isset($data[0]->amount_refunded)){
                        $amount_refunded = $data[0]->amount_refunded;    
                        }
                        if (isset($data[0]->balance_transaction ) && isset($data[0]->balance_transaction->fee) ){
                            
                            $fee = $data[0]->balance_transaction->fee;
                        }
                    }
                }
                
                
                //find 
                $exists = $this->DataStripeTransfer->find()->where(['id_tr' => $tr->id])->first();
                if(empty($exists)){
                    $array_data = array(
                    'id_tr' => $tr->id,
                    'date' => date('Y-m-d H:i:s',$tr->created),
                    'description' => $tr->balance_transaction->description,
                    'status' => $tr->status,
                    'amount' => $tr->amount,
                    'amount_reversed' => $amount_refunded,
                    'fee' => $fee,
                    'destination_stripe_account' => $tr->destination_details,
                    'transfer_group' => $tr->payment_intent,
                    'payload' => $tr,
                    'type' => 'refund' );
                    $c_entity = $this->DataStripeTransfer->newEntity($array_data);
                    if(!$c_entity->hasErrors()) {
                        $ent_saved = $this->DataStripeTransfer->save($c_entity);                    
                    } 
                }else{
                    $array_data = array(
                        'id' => $exists->id,
                        'id_tr' => $tr->id,
                        'date' => date('Y-m-d H:i:s',$tr->created),
                        'description' => $tr->balance_transaction->description,
                        'status' => $tr->status,
                        'amount' => $tr->amount,
                        'amount_reversed' => $amount_refunded,
                        'fee' =>  $fee,
                        'destination_stripe_account' => $tr->destination_details,
                        'transfer_group' => $tr->payment_intent,
                        'payload' => $tr,
                        'type' => 'refund' );
                        $c_entity = $this->DataStripeTransfer->newEntity($array_data);
                        if(!$c_entity->hasErrors()) {
                            $ent_saved = $this->DataStripeTransfer->save($c_entity);                    
                        } 
                }
            }
            
            if (count($paymentIntents->data) < 100) {
                $load_more = false;
                $this->DataStripeRequestReport->updateAll(
                    ['status' => 'end_download_refunds'],
                    ['id' => $id_r]
                );
            }
            else $arr_stripe_conditions['starting_after'] = $last_obj;        
        }


        //dispute       
		$arr_stripe_conditions =[];
		$arr_stripe_conditions = ['limit' => 100];

        //if ($firstDay && $lastDay) {
            $arr_stripe_conditions['created'] = [
                'gte' => strtotime($firstDay),
                'lte' => strtotime($lastDay),
            ];
            $arr_stripe_conditions['expand'] = ['data.balance_transaction'];
		$paymentIntents = $stripe->disputes->all($arr_stripe_conditions);
        $last_obj = null;
        $load_more = true;
        $this->DataStripeRequestReport->updateAll(
            ['status' => 'start_download_disputes'],
            ['id' => $id_r]
        );
        while ($load_more) {
            $paymentIntents = $stripe->disputes->all($arr_stripe_conditions);
             $this->log(__LINE__ . ' load more disputes' . json_encode($load_more));
            foreach ($paymentIntents->data as $key => $tr) {            
                $arr_paymentIntents[] = $tr;
                $last_obj = $tr->id;
                $charges = $tr->charges;
                $amount_refunded = 0;
                $fee =0;  $this->log(__LINE__ . ' ' . json_encode($tr));
                if (isset($charges['data'])){                     
                    $data = $charges['data'];
                    if(isset($data[0])){
                        if(isset($data[0]->amount_refunded)){
                        $amount_refunded = $data[0]->amount_refunded;    
                        }
                        if (isset($data[0]->balance_transaction ) && isset($data[0]->balance_transaction->fee) ){
                            
                            $fee = $data[0]->balance_transaction->fee;
                        }
                    }
                }
                
                
                //find 
                $exists = $this->DataStripeTransfer->find()->where(['id_tr' => $tr->id])->first();
                if(empty($exists)){
                    $array_data = array(                    
                    'id_tr' => $tr->id,
                    'date' => date('Y-m-d H:i:s',$tr->created),
                    'description' => $tr->reason,
                    'status' => $tr->status,
                    'amount' => $tr->amount,
                    'amount_reversed' => 0,
                    'fee' => 0,
                    'destination_stripe_account' => $tr->payment_intent,
                    'transfer_group' => $tr->payment_intent,
                    'payload' => $tr,
                    'type' => 'dispute' );
                    $c_entity = $this->DataStripeTransfer->newEntity($array_data);
                    if(!$c_entity->hasErrors()) {
                        $ent_saved = $this->DataStripeTransfer->save($c_entity);                    
                    } 
                }else{
                    $array_data = array(
                        'id' => $exists->id,
                        'id_tr' => $tr->id,
                        'date' => date('Y-m-d H:i:s',$tr->created),
                        'description' => $tr->reason,
                        'status' => $tr->status,
                        'amount' => $tr->amount,
                        'amount_reversed' => 0,
                        'fee' => 0,
                        'destination_stripe_account' => $tr->payment_intent,
                        'transfer_group' => $tr->payment_intent,
                        'payload' => $tr,
                        'type' => 'dispute' );
                        $c_entity = $this->DataStripeTransfer->newEntity($array_data);
                        if(!$c_entity->hasErrors()) {
                            $ent_saved = $this->DataStripeTransfer->save($c_entity);                    
                        } 
                }
            }
            
            if (count($paymentIntents->data) < 100) {
                $load_more = false;
                $this->DataStripeRequestReport->updateAll(
                    ['status' => 'end_download_stripe'],
                    ['id' => $id_r]
                );
            }
            else $arr_stripe_conditions['starting_after'] = $last_obj;        
        }
    }
        
                     
    

    function stripe_payments(){
                      
    }

}