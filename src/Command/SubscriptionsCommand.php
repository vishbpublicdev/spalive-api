<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use Cake\Utility\Text;

use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;

require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client; 
use Twilio\Exceptions\TwilioException; 
require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;

use SpaLiveV1\Controller\MainController;
use SpaLiveV1\Controller\SubscriptionController;
use SpaLiveV1\Controller\PaymentsController;
use SpaLiveV1\Controller\GhlController;
class SubscriptionsCommand extends Command{
    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }
    
    public function execute(Arguments $args, ConsoleIo $io){

        $arr_arguments = $args->getArguments();

        $isDev = env('IS_DEV', false);
        
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments');
        $this->loadModel('SpaLiveV1.DataSubscriptionPendingPayments');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');
        $this->loadModel('SpaLiveV1.DataSubscriptionsPaymentsHistory');
        $this->loadModel('SpaLiveV1.DataSubscriptionsPaymentsError');
        if (empty($arr_arguments)) {
            //$this->notify_cancellation_prev();
            $this->cancel_subscription();    
        }
        
        $_fields = ['DataSubscriptions.customer_id','DataSubscriptions.total','DataSubscriptions.status','DataSubscriptions.payment_method','DataSubscriptions.subscription_type', 'DataSubscriptions.data_object_id','DataSubscriptions.id','DataSubscriptions.user_id','DataSubscriptions.created','User.name','User.lname','User.email','User.phone','User.state','User.city','User.street','User.zip','User.suite','State.name', 'State.abv','DataSubscriptions.main_service','DataSubscriptions.addons_services', 'DataSubscriptions.payment_details', 'DataSubscriptions.other_school', 'DataSubscriptions.monthly'];
        $_where = ['DataSubscriptions.status ' => 'ACTIVE','DataSubscriptions.deleted' => 0,'User.deleted' => 0, 'User.active' => 1];


        if (!empty($arr_arguments) && count($arr_arguments) == 1) {
            $_where = ['DataSubscriptions.status IN' => array('ACTIVE'),'DataSubscriptions.deleted' => 0,'User.deleted' => 0, 'User.active' => 1,'User.uid' => $arr_arguments[0]];
        } else if (count($arr_arguments) > 2) {
            $_where = ['DataSubscriptions.status IN' => array('HOLD'),'DataSubscriptions.deleted' => 0,'User.deleted' => 0, 'User.active' => 1,'User.uid' => $arr_arguments[0]];
        }



        $_fields['last_payment'] = "(SELECT DATE_FORMAT(Payment.created, '%Y-%m-%d') created FROM data_subscription_payments Payment WHERE Payment.subscription_id = DataSubscriptions.id AND Payment.status IN ('DONE','REFUNDED') AND Payment.deleted = 0 AND Payment.payment_type = 'FULL'   ORDER BY Payment.id DESC LIMIT 1)";
        $arr_subscriptions = $this->DataSubscriptions->find()->select($_fields)->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSubscriptions.user_id'],
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = User.state']
        ])->where($_where)->all();      
        
        foreach($arr_subscriptions as $row) {
            $this->log(__LINE__ ." subs ". json_encode($row) );
            $id =0;
            $forcepay = $this->resubscription($arr_arguments, $row->user_id ,$row->subscription_type);
            $should_pay = $this->validateSubscriptionPayment($row['created']->i18nFormat('yyyy-MM-dd'),$row['last_payment'],$row['status'],$forcepay);       
            
            if(isset($row['last_payment'])){
                $dateValue = strtotime($row['last_payment']);                     
                $yr   = date("Y", $dateValue); 
                $mon  = date("m", $dateValue); 
                $date = date("d", $dateValue); 
                if($mon == 12){
                    $mon = 1;
                }else{
                    $mon = intval($mon); 
                    $mon++;
                }
                $str_new_date = $yr."-".(string)$mon."-".$date;                    
                $new_date = date('Y-m-d',strtotime($str_new_date));
                if($new_date == date('Y-m-d')){
                    $c_entity = $this->DataSubscriptionsPaymentsHistory->newEntity([                
                        'date_payment' => date('Y-m-d'),
                        'user_id' => $row->user_id,
                        'start_subscription' => $row['created']->i18nFormat('yyyy-MM-dd'),
                        'last_payment' => $row['last_payment'],
                        'status' => $row['status'],
                        'forcepay' => $forcepay,
                        'shouldpay' =>  $should_pay,
                        'arr_arguments' => json_encode($arr_arguments),
                        'row' => $row,                               
                    ]);
                    if(!$c_entity->hasErrors()) {
                        $this->DataSubscriptionsPaymentsHistory->save($c_entity);
                    }
                    $id = $c_entity->id;                     
                }else if ($should_pay){
                    $c_entity = $this->DataSubscriptionsPaymentsHistory->newEntity([                
                        'date_payment' => date('Y-m-d'),
                        'user_id' => $row->user_id,
                        'start_subscription' => $row['created']->i18nFormat('yyyy-MM-dd'),
                        'last_payment' => $row['last_payment'],
                        'status' => $row['status'],
                        'forcepay' => $forcepay,
                        'shouldpay' =>  $should_pay,
                        'arr_arguments' => json_encode($arr_arguments),
                        'row' => $row,                               
                    ]);
                    if(!$c_entity->hasErrors()) {
                        $this->DataSubscriptionsPaymentsHistory->save($c_entity);
                    }
                    $id = $c_entity->id;
                } 
            }else if ($should_pay){
                $c_entity = $this->DataSubscriptionsPaymentsHistory->newEntity([                
                    'date_payment' => date('Y-m-d'),
                    'user_id' => $row->user_id,
                    'start_subscription' => $row['created']->i18nFormat('yyyy-MM-dd'),
                    'last_payment' => $row['last_payment'],
                    'status' => $row['status'],
                    'forcepay' => $forcepay,
                    'shouldpay' =>  $should_pay,
                    'arr_arguments' => json_encode($arr_arguments),
                    'row' => $row,                               
                ]);
                if(!$c_entity->hasErrors()) {
                    $this->DataSubscriptionsPaymentsHistory->save($c_entity);
                }
                $id = $c_entity->id;
            }       
                
            if (!$should_pay){
                if($row['status'] == 'HOLD' && !empty($arr_arguments)){
                    $this->DataSubscriptions->updateAll(
                        ['status' => 'ACTIVE'],
                        ['id' => $row->id]
                    );
                }
                continue;
            }    
            
            $paid = false;
            
            $str_desc = str_replace("SUBSCRIPTION", "", $row->subscription_type) . ' Subscription Fee';
            $arr_payment_methods = array();

            $ent_payment_methods = $this->DataSubscriptionMethodPayments->find()->where(['user_id' => $row->user_id, 'deleted' => 0])->order(['DataSubscriptionMethodPayments.preferred' => 'DESC','DataSubscriptionMethodPayments.id' => 'DESC'])->toArray();

            if (!empty($ent_payment_methods)) {
                foreach($ent_payment_methods as $rowp) {
                    $arr_payment_methods[] = $rowp->payment_id;
                }
                $paid = $this->execPayment($row->id,$row->user_id,$row->total,$row->customer_id,$arr_payment_methods,$str_desc,$row);

            } else if (!empty($row->payment_method)) {

                $arr_payment_methods = array($row->payment_method);
                $paid = $this->execPayment($row->id,$row->user_id,$row->total,$row->customer_id,$arr_payment_methods,$str_desc,$row);

            } else {

                $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    

                $checkout_session = $stripe->checkout->sessions->retrieve(
                  $row->data_object_id,
                  []
                );

                $setup_intent = $stripe->setupIntents->retrieve(
                  $checkout_session->setup_intent,
                  []
                );

                if (!empty($setup_intent)) {
                    $payment_method = $setup_intent->payment_method;

                    $arr_payment_methods = array($payment_method);
                    $paid = $this->execPayment($row->id,$row->user_id,$row->total,$row->customer_id,$arr_payment_methods,$str_desc,$row);
                    if ($paid) {
                        $this->DataSubscriptions->updateAll(
                            ['payment_method' => $payment_method],
                            ['id' => $row->id]
                        );
                    }

                }
            
            }

            $this->DataSubscriptionPayments->updateAll(
                ['deleted' => '1'],
                ['subscription_id' => $row->id, 'user_id' => $row->user_id, 'status' => 'PENDING']
            );

            $this->DataSubscriptionMethodPayments->updateAll(
                    ['error' => 0],
                    ['user_id' => $row->user_id]
                );

            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $row->user_id])->first();

            if (!$paid) {

                //TRYING WITH OTHER PAYMENTS METHODS IN STRIPE

                $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
                
                $oldCustomer = $stripe->customers->all([
                    "email" => $ent_user->email,
                    "limit" => 1,
                ]);

                if (count($oldCustomer) > 0) {
                    $customer = $oldCustomer->data[0];  
                    $payment_methods = $stripe->customers->allPaymentMethods(
                        $customer->id,
                        ['type' => 'card']
                    );
                    
                    $arr_payment_methods = array();
                    foreach($payment_methods as $method) {
                        $arr_payment_methods[] = $method->id;
                    }   
                    $paid = $this->execPayment($row->id,$row->user_id,$row->total,$customer->id,$arr_payment_methods,$str_desc,$row);
                    if($paid){
                        $this->DataSubscriptions->updateAll(
                            ['customer_id' => $customer->id],
                            ['id' => $row->id]
                        );
                    }
                }
            } 
            // PAYMENT FAILED
            if ($paid) {
                $address = $row['User']['street'].', '.$row['User']['city'].', '.$row['State']['abv'].' '.$row['User']['zip'];
                $name = $row['User']['name'] . ' ' . $row['User']['lname'];
                if(empty($row['last_payment'])){
                    $this->send_trial_hold_email($ent_user->email);
                }else{
                    
                    if(strpos($row['subscription_type'], 'md') !== false){
                        $this->send_receipt($ent_user->email, $row->subscription_type,$row->total,$address,$name,$row->id);
                    }
                }
            }else{
                $Main = new MainController();
                $this->DataSubscriptions->updateAll(
                        ['status' => 'HOLD'],
                        ['id' => $row->id]
                    );

                $this->DataSubscriptionMethodPayments->updateAll(
                    ['error' => 1],
                    ['user_id' => $row->user_id]
                );
                    //$ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $row->user_id])->first();
                    //All records for Marie
                    $md_id = $this->get_doctor($row['user_id'], $row['subscription_type']);
                $c_entity = $this->DataSubscriptionPayments->newEntity([
                    'uid'   => Text::uuid(),
                    'subscription_id'  => $row->id,
                    'user_id'  => $row->user_id,
                    'total'  => $row->total,
                    'payment_id'  => '',
                    'charge_id'  => '',
                    'receipt_id'  => '',
                    'created'   => date('Y-m-d H:i:s'),
                    'error' => 'Payment declined.',
                    'status' => 'PENDING',
                    'deleted' => 0,
                    'md_id' => $md_id,
                    'payment_type' => 'FULL',
                    'payment_description' => $row['main_service'] . ', ' . $row['addons_services'],
                    'main_service' => $row['main_service'],
                    'addons_services' => $row['addons_services'],
                    'payment_details' => $row['payment_details'],
                    'state' => $row['User']['state'],
                ]);

                if(!$c_entity->hasErrors()) {
                    $this->DataSubscriptionPayments->save($c_entity);
                }
                $this->sendErrorEmail("francisco@advantedigital.com,".$ent_user->email,$row->total);
                $Main->sendEmalSubscriptionHold($ent_user->email);
            }
            if($id !=0){
                $this->DataSubscriptionsPaymentsHistory->updateAll(
                    ['result' => ($paid == true ? 'paid successful': 'error on paid') ],
                    ['id' => $id]
                );
            }
        }
         // subscription start        
        $result3 = $this->DataSubscriptions->getConnection()->execute("
            select id, user_id, event, payload, subscription_type, status, created,
            DATEDIFF(now(), created) datediff,
            (select count(*) from data_subscription_cancelled dsc where dsc.subscription_id = ds.id ) cancel  ,
            (select count(*) from data_subscription_payments dsp where dsp.subscription_id = ds.id ) paid  ,
            (select concat(id, ' ',name, ' ', lname,' ', email, ' ', active) from sys_users u where u.id = user_id ) st,
            (select steps  from sys_users u where u.id = user_id ) step,
            (select deleted from sys_users u where u.id = user_id ) del
            from data_subscriptions ds 
            where agreement_id >0 and deleted = 0 and status IN ( 'ACTIVE')             
            and (select count(*) from data_subscription_payments dsp where dsp.subscription_id = ds.id )  = 0
            and (select deleted from sys_users u where u.id = user_id )  = 0
            and (select count(*) from data_subscription_cancelled dsc where dsc.subscription_id = ds.id ) = 0
            and (select steps  from sys_users u where u.id = user_id ) = 'HOME'
            and DATEDIFF(now(), created) > 30
            and (select active from sys_users u where u.id = user_id )  = 1
            order by created desc;
        ")->fetchAll('assoc');

        // Comparar los resultados de las consultas
        $str_subs = ""; $str_subs .= date('Y-m-d H:m'). "\n";
        if (!empty($result3)) {            
            // Almacenar los resultados de la primera base de datos en un array
            foreach ($result3 as $row) {
                $str_subs .=          " " . json_encode($row) . "\n";
            }                    
        } else {
            $this->log("Error en las consultas: ");
        }


        $myfile = fopen("/var/www/html/apispalive/logs/db_subs.txt", "w") or die("Unable to open file!");            
        fwrite($myfile, $str_subs);
        fclose($myfile);
            // subscription end
    }

    private function validateSubscriptionPayment($str_subscription_date,$str_last_payment_date = '',$subscription_status = 'ACTIVE',$forcepay = false) {
        $str_now = date('Y-m-d');
        
        $date_formatter = new \DateTime("-1 month");
        $monthago = $date_formatter->format("Y-m-d");
        
        //$forcepay = false;

        $currentDate = date('Y-m-d', strtotime($str_now));
        $currentDateDay = date('d', strtotime($str_now));
        $currentMonthLastDate = date('Y-m-t', strtotime($str_now));
        $isTodayLastDay = $currentDate == $currentMonthLastDate ? true : false;

        $subscriptionDate = date('Y-m-d', strtotime($str_subscription_date));
        $subscriptionDateDay = date('d', strtotime($str_subscription_date));
        $subscriptionLastDate = date('Y-m-t', strtotime($str_subscription_date));
        $isSubscriptionLastDay = $subscriptionDate == $subscriptionLastDate ? true : false;

        if (!empty($str_last_payment_date)) {    
            $lastPaymentDate = date('Y-m-d', strtotime($str_last_payment_date));
            $lastPaymentDateDay = date('d', strtotime($str_last_payment_date));
            $lastPaymentLastDate = date('Y-m-t', strtotime($str_last_payment_date));
            $isPaymentLastDay = $lastPaymentDate == $lastPaymentLastDate ? true : false;
        }

        if (empty($str_last_payment_date)) {
            $diff_days = strtotime($str_now) - strtotime($str_subscription_date); 
        } else {
            $diff_days = strtotime($str_now) - strtotime($str_last_payment_date); 
        }

        $diff_days = $diff_days / 60 / 60 / 24;
        if ($subscription_status == 'ACTIVE') {
            if (!empty($str_last_payment_date)) {   
                if ($lastPaymentDateDay == $subscriptionDateDay || ($isPaymentLastDay && $subscriptionDateDay > $lastPaymentDateDay)) {
                    if ($isTodayLastDay && ($subscriptionDateDay > $currentDateDay) && $diff_days >= 27) return true;
                    if ($subscriptionDateDay == $currentDateDay && $diff_days >= 27) return true;
                } else {
                    if ($isTodayLastDay && ($lastPaymentDateDay > $currentDateDay) && $diff_days >= 27) return true;
                    if ($lastPaymentDateDay == $currentDateDay && $diff_days >= 27) return true;
                }
            } else {
                if ($isTodayLastDay && ($subscriptionDateDay > $currentDateDay) && $diff_days >= 27) return true;
                if ($subscriptionDateDay == $currentDateDay && $diff_days >= 27) return true;
                if ($forcepay) return true;
            }
        }
        if ($subscription_status == 'HOLD') {
            if ($diff_days >= 27) return true;
        }

        return false;
    }

    /**
     * When the subscription charge is $0, skip Stripe and record a DONE payment so last_payment advances
     * and the subscription is not moved to HOLD as a failed charge.
     */
    private function recordZeroDollarSubscriptionPayment($subscription_id, $user_id, $row, $amountCents = null)
    {
        if ($amountCents === null) {
            $amountCents = (int) $row['total'];
        } else {
            $amountCents = (int) $amountCents;
        }
        if ($amountCents < 0) {
            $amountCents = 0;
        }

        $md_id = $this->get_doctor($user_id, $row['subscription_type']);
        $c_entity = $this->DataSubscriptionPayments->newEntity([
            'uid' => Text::uuid(),
            'subscription_id' => $subscription_id,
            'user_id' => $user_id,
            'total' => $amountCents,
            'payment_id' => '',
            'charge_id' => '',
            'receipt_id' => '',
            'created' => date('Y-m-d H:i:s'),
            'error' => '',
            'status' => 'DONE',
            'deleted' => 0,
            'md_id' => $md_id,
            'payment_type' => 'FULL',
            'payment_description' => $row['main_service'] . ', ' . $row['addons_services'],
            'main_service' => $row['main_service'],
            'addons_services' => $row['addons_services'],
            'payment_details' => $row['payment_details'],
            'state' => $row['User']['state'],
        ]);

        if ($c_entity->hasErrors()) {
            return false;
        }

        $this->DataSubscriptionPayments->save($c_entity);
        $this->DataSubscriptions->updateAll(
            ['status' => 'ACTIVE'],
            ['id' => $subscription_id]
        );
        $this->processPendingPayments($subscription_id, $user_id, $amountCents);

        return true;
    }

    private function execPayment($subscription_id,$user_id,$total,$customer_id,$payment_methods,$str_desc,$row) {

        if($row['monthly'] == '1'){
            if(empty($row['last_payment'])){
                $this->DataSubscriptions->updateAll(
                        ['status' => 'HOLD'],
                        ['id' => $subscription_id]
                );
                $this->email_relations($row);
                return true;
            }
        }else if($row['monthly'] == '3'){
            $cancel = $this->cancel_new_subs($subscription_id, $row['monthly']);
            if(!$cancel){
                return $this->execPayThreeMonth($subscription_id,$user_id,$customer_id,$payment_methods,$str_desc,$row);
            }
            return true;
        } else if($row['monthly'] == '12'){
            $cancel = $this->cancel_new_subs($subscription_id, $row['monthly']);
            if($cancel){
                return true;
            }
        }

        if ((int) $total <= 0) {
            return $this->recordZeroDollarSubscriptionPayment($subscription_id, $user_id, $row, (int) $total);
        }

        foreach($payment_methods as $payment_method) {
            \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
            $stripe_result = '';
            $error = '';

            try {
              $stripe_result = \Stripe\PaymentIntent::create([
                'amount' => $total,
                'currency' => 'usd',
                'customer' => $customer_id,
                'payment_method' => $payment_method,
                'off_session' => true,
                'confirm' => true,
                'description' => $str_desc
              ]);
            } catch(Stripe_CardError $e) {
                $error = $e->getMessage();
              } catch (Stripe_InvalidRequestError $e) {
                // Invalid parameters were supplied to Stripe's API
                $error = $e->getMessage();
              } catch (Stripe_AuthenticationError $e) {
                // Authentication with Stripe's API failed
                $error = $e->getMessage();
              } catch (Stripe_ApiConnectionError $e) {
                // Network communication with Stripe failed
                $error = $e->getMessage();
              } catch (Stripe_Error $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                $error = $e->getMessage();
              } catch (Exception $e) {
                // Something else happened, completely unrelated to Stripe
                $error = $e->getMessage();
              } catch(\Stripe\Exception\CardException $e) {
               // Since it's a decline, \Stripe\Exception\CardException will be caught
                  $error = $e->getMessage();
              } catch (\Stripe\Exception\RateLimitException $e) {
                // Too many requests made to the API too quickly
                  $error = $e->getMessage();
              } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Invalid parameters were supplied to Stripe's API
                  $error = $e->getMessage();
              } catch (\Stripe\Exception\AuthenticationException $e) {
                // Authentication with Stripe's API failed
                // (maybe you changed API keys recently)
                  $error = $e->getMessage();
              } catch (\Stripe\Exception\ApiErrorException $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                $error = $e->getMessage();
              }

            $receipt_url = '';
            $id_charge = '';
            $payment_id = '';
            if (isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $payment_id = $stripe_result->id;
            }    
            //$ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user_id])->first();
            //All records for Marie
            $md_id = $this->get_doctor($user_id, $row['subscription_type']);
            $c_entity = $this->DataSubscriptionPayments->newEntity([
                'uid'   => Text::uuid(),
                'subscription_id'  => $subscription_id,
                'user_id'  => $user_id,
                'total'  => $total,
                'payment_id'  => $payment_id,
                'charge_id'  => $id_charge,
                'receipt_id'  => $receipt_url,
                'created'   => date('Y-m-d H:i:s'),
                'error' => $error,
                'status' => 'DONE',
                'deleted' => 0,
                'md_id' => $md_id,
                'payment_type' => 'FULL',
                'payment_description' => $row['main_service'] . ', ' . $row['addons_services'],
                'main_service' => $row['main_service'],
                'addons_services' => $row['addons_services'],
                'payment_details' => $row['payment_details'],
                'state' => $row['User']['state'],
            ]);
            if (empty($error)) {
                if(!$c_entity->hasErrors()) $this->DataSubscriptionPayments->save($c_entity);

                $this->DataSubscriptions->updateAll(
                    ['status' => 'ACTIVE'],
                    ['id' => $subscription_id]
                );

                // Process pending payments after successful payment
                $this->processPendingPayments($subscription_id, $user_id, $total);

                $this->pay_salesrep_comissions($subscription_id);
                return true;
                break;
            }else{
                /*$c_entity = $this->DataSubscriptionsPaymentsError->newEntity([                                    
                    'subscription_id' => $subscription_id, 
                    'user_id' => $user_id, 
                    'error' => json_encode($error), 
                    'date' => date('Y-m-d H:i:s') , 
                    'stripe_result' => json_encode($stripe_result), 
                    'customer_id' => $customer_id, 
                    'payment_method'=> $payment_method,                                                                 
                ]);
                if(!$c_entity->hasErrors()) {
                    $this->DataSubscriptionsPaymentsError->save($c_entity);
                }*/
                $this->log(__LINE__ ." ". json_encode(['subscription_id'  => $subscription_id,'user_id'  => $user_id,'total'  => $total,'payment_id'  => $payment_id,'charge_id'  => $id_charge,'receipt_id'  => $receipt_url,'created'   => date('Y-m-d H:i:s'),'error' => $error,'status' => 'DONE','deleted' => 0]) );
                $this->log(__LINE__ ." error ". json_encode($error) );$this->log(__LINE__ ." stripe_result ". json_encode($stripe_result) );
            } 
        }

        return false;
    }

    private function sendErrorEmail($email,$amount) {
        return;
        $total = number_format($amount / 100,2);
        $body = '
            <!doctype html> <html> <head> <meta name="viewport" content="width=device-width"> <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"> <title>MySpaLive Message</title> <style>@media only screen and (max-width: 620px){table[class=body] h1{font-size: 28px !important; margin-bottom: 10px !important;}table[class=body] p, table[class=body] ul, table[class=body] ol, table[class=body] td, table[class=body] span, table[class=body] a{font-size: 16px !important;}table[class=body] .wrapper, table[class=body] .article{padding: 10px !important;}table[class=body] .content{padding: 0 !important;}table[class=body] .container{padding: 0 !important; width: 100% !important;}table[class=body] .main{border-left-width: 0 !important; border-radius: 0 !important; border-right-width: 0 !important;}table[class=body] .btn table{width: 100% !important;}table[class=body] .btn a{width: 100% !important;}table[class=body] .img-responsive{height: auto !important; max-width: 100% !important; width: auto !important;}}@media all{.ExternalClass{width: 100%;}.ExternalClass, .ExternalClass p, .ExternalClass span, .ExternalClass font, .ExternalClass td, .ExternalClass div{line-height: 100%;}.apple-link a{color: inherit !important; font-family: inherit !important; font-size: inherit !important; font-weight: inherit !important; line-height: inherit !important; text-decoration: none !important;}#MessageViewBody a{color: inherit; text-decoration: none; font-size: inherit; font-family: inherit; font-weight: inherit; line-height: inherit;}.btn-primary table td:hover{background-color: #34495e !important;}.btn-primary a:hover{background-color: #34495e !important; border-color: #34495e !important;}}</style> </head> <body class="" style="background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;"> <span class="preheader" style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">MySpaLive Message.</span> <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background-color: #f6f6f6;"> <tr> <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td><td class="container" style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 580px; padding: 10px; width: 580px;"> <div class="content" style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;"> <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 3px;"> <tr> <td class="wrapper" style="font-family: sans-serif; font-size: 14px; box-sizing: border-box; padding: 20px;"> <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;"><br><tr> <div style="color: #655489; font-size: 20px; text-align: center;"><b>Action Required: Please Complete Payment</b></div><br><div style="text-align: justify; color:#666666;">We\'ve encountered an issue while attempting to automatically charge your credit card on file. To prevent service disruption for non-payment, please log in to your MySpaLive account and make a payment for the due amount.<br><br>It is important to keep your credit card profile up to date and, if your card issuer requires it, promptly provide authorization for any charges. </div><br><br><br><br><td><strong style="text-align: center; color: #ed4f32; font-size: 16px;">Amount due:</strong></td><td style="float:right;"><strong style="color: #ed4f32; font-size: 16px;">$ ' . $total . '</strong></td></tr></table> <br><br></td></tr></table> <div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;"> <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;"> <tr> <td class="content-block" style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;"> <span class="apple-link" style="color: #999999; font-size: 12px; text-align: center;">Visit us at <a href="https://blog.myspalive.com/">MySpaLive</a></span> </td></table> </div></div></td><td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td></tr></table> </body> </html>';


        
        $data = array(
            'from'    => 'MySpaLive <info@mg.myspalive.com>',
            // 'to'    => 'khanzab@gmail.com',
            'to'    => $email,
            'subject' => "We couldn't process your subscription payment.",
            'html'    => $body,
        );

        $mailgunKey = $this->getMailgunKey();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        $result = curl_exec($curl);
        curl_close($curl);
    }

    private function rcpt_subscriptions($type,$amount,$address,$name, $subscription_id){
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        
        // $type = "SUBSCRIPTIONMSL";
        //$type = "SUBSCRIPTIONMD";
        // $realTotal = $total = $amount;
        // $address = 'Alamo 100';
        // $user_name    = 'Luis Valdez';


        
        $subscription_type = $type == 'SUBSCRIPTIONMSL' ? 'MySpaLive Subscription' : 'Medical Director Subscription';
        $date = date('Y-m-d H:i:s');
        $html_detail  = '';
        $shippinFoot  = "";
        $discountFoot = '';
        $logo = '';
        $paidSpa = '';
        $vendor = '';

        $logoZach = 'Zach.png';
        $adreesZach = '1000 E 5th Street Suite 100, Tyler, TX 75701';
        $vendorZach = 'Zach Cannon, MD';
        $paidZach = 'paidZach.jpg';

        $adreessSpa = '2450 East Prosper Trail, Suite 20, Prosper, TX 75078';
        $logoSpa = 'myspalive-logo1.png';
        $vendorSpa = 'MySpaLive , LLC';
        $paidSpa = 'paidMyspaLive.png';

        $html_detail = "
                <tr>
                    <td colspan=\"2\">
                        &nbsp;<br>
                        <table style=\"margin-left: 30px;\">
                            <tbody>
                                <tr>
                                    <td colspan=\"2\" style=\"text-align: left; width: 290px;\">Subscription:&nbsp;{$subscription_type} Fee<br>&nbsp;</td>
                                    <td colspan=\"2\" style=\"text-align: left; width: 290px;\">$" . number_format($amount / 100,2) . "<br>&nbsp;</td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
            ";

        if($type == 'SUBSCRIPTIONMD'){

            $logo = $logoZach;
            $adreess = $adreesZach;
            $vendor = $vendorZach;
            $paid = $paidZach;
        
        }else if($type == 'SUBSCRIPTIONMSL'){
            $this->loadModel('SpaLiveV1.DataTreatment');
            $this->loadModel('SpaLiveV1.DataTreatmentDetail');

            $logo = $logoSpa;
            $adreess = $adreessSpa;
            $vendor = $vendorSpa;
            $paid = $paidSpa;


        }else{
            return;
        }

        $url_panel = 'https://blog.myspalive.com/';
       
        $html_content = "
        
            <div style=\"padding: 8px 1px 8px 1px; width: 100%\">
                <div style=\"width: 100%; display: inline-flex;\">
                    
                    <img style=\"margin-left: 0px;\" height=\"90\" src=\"{$url_panel}{$logo}\">
                    
                    <div style=\"float: right; margin-left: 300px;\">
                        <p style=\"line-height:22px;\">
                            Date: {$date}
                        </p>
                    </div>
                </div>
                <div style=\"padding: 0px 16px 0px 16px; margin-top: 24px;\">
                    <p style=\"line-height:20px;\">
                        {$vendor}
                        <br>
                        Address: {$adreess}
                    </p>
                </div>
            </div> 
            <div style=\"margin-top:4px; padding-left: 16px; width: 100%\">
                <p style=\"line-height:20px;\">
                    To: {$name}
                    <br>
                    Address: {$address}
                </p>
            </div> 
            <div style=\"margin-top:52px; padding: 0px 16px 16px 16px;\">
                <table width=\"100%\">
                    <thead>
                        <tr>
                            <th style=\"text-align: left; width: 500px; line-height:30px;\">PRODUCT/SERVICE<br>&nbsp;</th>
                            <th style=\"text-align: right;  line-height:30px;\">COST<br>&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$html_detail}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan=\"2\" style=\"line-height:60px;\">&nbsp;<br>&nbsp;</td>
                        </tr>
                        {$shippinFoot}
                        {$discountFoot}
                        <tr>
                            <td style=\"text-align: right;width: 500px;\">&nbsp;<br>Total paid:&nbsp;&nbsp;&nbsp;</td>
                            <td style=\"text-align: right;\">&nbsp;<br>\$" .  number_format($amount / 100,2) ."</td>


                        </tr>
                        <tr>
                            <td style=\"text-align: right;\">&nbsp;<br>Balance:&nbsp;&nbsp;&nbsp;</td>
                            <td style=\"text-align: right;\"><br>$0</td>
                        </tr>
                    </tfoot>
                </table>

            </div> 
                <div style=\"width: 100%; display: inline-flex; text-align : center;\">
                    <br><br><br><br>
                    <img height=\"180\" src=\"
                    data:image/png;base64,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
                    \">
                </div>
            ";

        $filename = TMP . 'subscription_receipts' . DS . 'invoice_' . ($subscription_id+1745) . '.pdf';
        $html2pdf = new HTML2PDF('P','A4','en', true, 'UTF-8', array(10, 10, 10, 10));
        $html2pdf->WriteHTML($html_content);
        $html2pdf->Output($filename, 'F'); //,'D'
        return $filename;

    }

    private function send_receipt($str_email, $type,$amount,$address,$name, $subscription_id){

        /*$filename = $this->rcpt_subscriptions($type,$amount,$address,$name, $subscription_id);

        if(empty($filename)){
            return;
        }*/

        $html = "
            <html>
                <body>
                    <p>Hi $name</p>
                    <p>This is a confirmation that your credit card has been charged $218.95 for the renewal of your MySpaLive subscription. Thi cludes continued access to the app and coverage by the medical director.</p>
                    <p>If you believe this charge was made in error or would like to cancel your subscription, please text or call us at (972) 900-3944 and we'll be happy to assiste you.</p>
                    <p>Thank you for being a part of MySpaLive!</p>
                    <p>Best regards.</p>
                    <p>The MySpaLive Team</p>
                </body>
            </html>
        ";
        
        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $str_email,
            'subject' => 'Your MySpaLive Subscription Has Been Renewed - $218.95 Charged',
            'html'    => "You have received a receipt from MySpaLive.",
            //'attachment[1]' => curl_file_create($filename, 'application/pdf', 'SpaLiveMD_Receipt.pdf'),
        );

        $mailgunKey = $this->getMailgunKey();

        $curl = curl_init();
        
        curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);

        $result = curl_exec($curl);
        curl_close($curl);

        unlink($filename);
    }

    private function cancel_subscription(){
        $Main = new MainController();
        $subscriptionController = new SubscriptionController();
        $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');
        $today = date('Ymd');
        $_fields = ['User.id','User.email','User.name','User.lname','User.phone','DataSubscription.subscription_type','DataSubscriptionCancelled.subscription_id','DataSubscriptionCancelled.id','DataSubscriptionCancelled.services_unsubscribe'];
        
        $ent_sub_cancelled = $this->DataSubscriptionCancelled->find()->select($_fields)->join([
            'DataSubscription' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DataSubscription.id = DataSubscriptionCancelled.subscription_id'],
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSubscription.user_id'],
        ])->where([
            'DataSubscription.status IN' => ['ACTIVE', 'HOLD'],
            'DataSubscriptionCancelled.deleted' => 0,
            "DATE_FORMAT(DataSubscriptionCancelled.date_payment, '%Y%m%d') <= $today"
        ])->toArray();


        $array_str_subscriptions = [
            'SUBSCRIPTIONMSL' => 'MySpaLive Subscription',
            'SUBSCRIPTIONMD' => 'Medical Director Subscription' 
        ];
        $this->loadModel('SpaLiveV1.SysUsers');
        foreach($ent_sub_cancelled as $row){
            $this->log(__LINE__ . " sub_cancelled ".$row);

            $ent_subscription = $this->DataSubscriptions->find()
                        ->where(['id' => $row['subscription_id']])
                        ->first();                    
            $current_subscription   = $ent_subscription['subscription_type'];
            $subscription_type      = stripos($current_subscription, 'MD') !== false 
                                        ? 'MD'
                                        : 'MSL';

            $services_unsubscribe = $row['services_unsubscribe'];
            if(!empty($services_unsubscribe)){                
                $arr_services_unsubscribe = explode(',',$services_unsubscribe);
                if(!empty($arr_services_unsubscribe)){                                         
                    $downgrade_subscription = $subscriptionController->get_downgraded_subscription(
                        $subscription_type,
                        $current_subscription,
                        $arr_services_unsubscribe
                    );

                    $main_service = $ent_subscription['main_service'];
                    $addons_services = $ent_subscription['addons_services'];
                    $payment_details = json_decode($ent_subscription['payment_details'], true);
                    // if downgrade_subscription is false it means that all the services are cancelled                    
                    if(!$downgrade_subscription){
                        // assigned a new type for subscription in order to allow the user to resubscribe 
                        $arr_new_subscription = [$arr_services_unsubscribe[count($arr_services_unsubscribe) -1 ]];
                        $new_subscription     = $subscriptionController->get_subscription_by_array(
                            $subscription_type,
                            $arr_new_subscription
                        );

                        $new_price = $subscriptionController->get_total_subscription(
                            $subscription_type,
                            count($arr_new_subscription)
                        );
                        $subtotal = $new_price;

                        $promo_code = $ent_subscription['promo_code'];
                        if(!empty($promo_code)){
                            $new_price = $subscriptionController->validateCode($promo_code, $new_price, $subscription_type);
                        }
                        
                        // Update payment_details to reflect only the remaining service
                        $new_payment_details = [];
                        foreach($arr_new_subscription as $service) {
                            if(isset($payment_details[$service])) {
                                $new_payment_details[$service] = $payment_details[$service];
                            }
                        }
                        
                        $this->DataSubscriptions->updateAll(
                            [
                                'subtotal'          => $subtotal,
                                'total'             => $new_price,
                                'subscription_type' => $new_subscription,
                                'payment_details'   => json_encode($new_payment_details),
                            ],
                            ['id' => $row['subscription_id']]
                        );
                    }else{
                        // update price, subtotal and subscription_type and the delete the cancellation.
                        // use continue in order to prevent the order actions that occur further this foreach.
                        $new_services = $subscriptionController->get_services_subscription($subscription_type, $downgrade_subscription);
                        if(!in_array($main_service, $new_services)){
                            $main_service = $new_services[0];
                        }

                        if(strpos($addons_services, $main_service) !== false){
                            $arr_addons_services = explode(',',$addons_services);
                            $arr_new_addons_services = [];
                            foreach($arr_addons_services as $addon){
                                if($addon != $main_service){
                                    $arr_new_addons_services[] = $addon;
                                }
                            }
                            $addons_services = implode(',',$arr_new_addons_services);
                        }

                        $new_price = $subscriptionController->get_total_subscription(
                            $subscription_type,
                            count($new_services)
                        );
                        $subtotal = $new_price;

                        $promo_code = $ent_subscription['promo_code'];
                        if(!empty($promo_code)){
                            $new_price = $subscriptionController->validateCode($promo_code, $new_price, $subscription_type);
                        }
                        
                        // Update payment_details to remove cancelled services
                        $new_payment_details = [];
                        foreach($new_services as $service) {
                            if(isset($payment_details[$service])) {
                                $new_payment_details[$service] = $payment_details[$service];
                            }
                        }
                        
                        $this->DataSubscriptions->updateAll(
                            [
                                'subtotal'          => $subtotal,
                                'total'             => $new_price,
                                'subscription_type' => $downgrade_subscription,
                                'main_service'      => $main_service,
                                'addons_services'   => $addons_services,
                                'payment_details'   => json_encode($new_payment_details),
                            ],
                            ['id' => $row['subscription_id']]
                        );
                        continue;
                    }               
                }                                
            }

            $this->DataSubscriptions->updateAll(
                ['status' => 'CANCELLED'],
                ['id' => $row['subscription_id']]
            );

            if(!env('IS_DEV', false)){
                $Ghl = new GhlController();
                $array_ghl = array(
                    'email' => $row['User']['email'],
                    'name' => $row['User']['name'],
                    'lname' => $row['User']['lname'],
                    'phone' => $row['User']['phone'],
                    'costo' => 0,
                    'column' => 'Completely unsubscribed',
                );
                $contactId = $Ghl->updateOpportunityTags($array_ghl);
                $tag = $Ghl->addTag($contactId, $array_ghl['email'],$array_ghl['phone'],'Unsubscribed');
            }

            $this->DataSubscriptionCancelled->updateAll(
                ['deleted' => 1],
                ['id' => $row['id']]
            );

            $this->SysUsers->updateAll(
                ['steps' => 'MATERIALS'],
                ['id' => $row['User']['id']]
            );
            

            $email_title = $subscription_type == 'MD'   ? 'Medical Director Subscription'
                                                        : 'MySpaLive Subscription';


            $Main->sendUnsubscriptionEmail('EMAIL_SUBSCRIPTION_CANCELLATION', $row['User']['email'], $email_title);

        }
        
    }

    private function resubscription($arr_arguments, $user_id ,$subscription_type){
        if(!empty($arr_arguments[0])){
            $this->loadModel('SpaLiveV1.DataSubscriptions');
            $str_query = "SELECT * FROM data_subscriptions DS WHERE DS.user_id = $user_id AND DS.subscription_type = '$subscription_type' AND DS.deleted = 0 ORDER BY DS.id DESC LIMIT 2";
            $query_result = $this->DataSubscriptions->getConnection()->execute($str_query)->fetchAll('assoc');
            if(isset($query_result[0]['status']) && isset($query_result[1]['status'])){
                if($query_result[0]['status'] == 'ACTIVE' && $query_result[1]['status'] == 'CANCELLED'){
                    return true;
                }
            }
        }
        return false;
    }

    private function execPayThreeMonth($subscription_id,$user_id,$customer_id,$payment_methods,$str_desc,$row) {
        $total = 0;
        if(!empty($row['addons_services'])){
            $addon = explode(',',$row['addons_services']);
            $detail = json_decode($row['payment_details'],true);
            foreach ($addon as $key => $value) {
                $total += $detail[$value];
            }

            if ((int) $total <= 0) {
                return $this->recordZeroDollarSubscriptionPayment($subscription_id, $user_id, $row, (int) $total);
            }

            foreach($payment_methods as $payment_method) {
                \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
                $stripe_result = '';
                $error = '';
    
                try {
                  $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => $total,
                    'currency' => 'usd',
                    'customer' => $customer_id,
                    'payment_method' => $payment_method,
                    'off_session' => true,
                    'confirm' => true,
                    'description' => $str_desc
                  ]);
                } catch(Stripe_CardError $e) {
                    $error = $e->getMessage();
                  } catch (Stripe_InvalidRequestError $e) {
                    // Invalid parameters were supplied to Stripe's API
                    $error = $e->getMessage();
                  } catch (Stripe_AuthenticationError $e) {
                    // Authentication with Stripe's API failed
                    $error = $e->getMessage();
                  } catch (Stripe_ApiConnectionError $e) {
                    // Network communication with Stripe failed
                    $error = $e->getMessage();
                  } catch (Stripe_Error $e) {
                    // Display a very generic error to the user, and maybe send
                    // yourself an email
                    $error = $e->getMessage();
                  } catch (Exception $e) {
                    // Something else happened, completely unrelated to Stripe
                    $error = $e->getMessage();
                  } catch(\Stripe\Exception\CardException $e) {
                   // Since it's a decline, \Stripe\Exception\CardException will be caught
                      $error = $e->getMessage();
                  } catch (\Stripe\Exception\RateLimitException $e) {
                    // Too many requests made to the API too quickly
                      $error = $e->getMessage();
                  } catch (\Stripe\Exception\InvalidRequestException $e) {
                    // Invalid parameters were supplied to Stripe's API
                      $error = $e->getMessage();
                  } catch (\Stripe\Exception\AuthenticationException $e) {
                    // Authentication with Stripe's API failed
                    // (maybe you changed API keys recently)
                      $error = $e->getMessage();
                  } catch (\Stripe\Exception\ApiErrorException $e) {
                    // Display a very generic error to the user, and maybe send
                    // yourself an email
                      $error = $e->getMessage();
                  }
    
                $receipt_url = '';
                $id_charge = '';
                $payment_id = '';
                if (isset($stripe_result->charges->data[0]->receipt_url)) {
                    $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                    $id_charge = $stripe_result->charges->data[0]->id;
                    $payment_id = $stripe_result->id;
                }    
                //$ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user_id])->first();
                //All records for Marie
                $md_id = $this->get_doctor($user_id, $row['subscription_type']);
                $c_entity = $this->DataSubscriptionPayments->newEntity([
                    'uid'   => Text::uuid(),
                    'subscription_id'  => $subscription_id,
                    'user_id'  => $user_id,
                    'total'  => $total,
                    'payment_id'  => $payment_id,
                    'charge_id'  => $id_charge,
                    'receipt_id'  => $receipt_url,
                    'created'   => date('Y-m-d H:i:s'),
                    'error' => $error,
                    'status' => 'DONE',
                    'deleted' => 0,
                    'md_id' => $md_id,
                    'payment_type' => 'FULL',
                    'payment_description' => $row['main_service'] . ', ' . $row['addons_services'],
                    'main_service' => $row['main_service'],
                    'addons_services' => $row['addons_services'],
                    'payment_details' => $row['payment_details'],
                    'state' => $row['User']['state'],
                ]);
                if (empty($error)) {
                    if(!$c_entity->hasErrors()) $this->DataSubscriptionPayments->save($c_entity);
    
                    $this->DataSubscriptions->updateAll(
                        ['status' => 'ACTIVE'],
                        ['id' => $subscription_id]
                    );
    
                    // Process pending payments after successful payment
                    $this->processPendingPayments($subscription_id, $user_id, $total);
    
                    $this->pay_salesrep_comissions($subscription_id);
                    return true;
                    break;
                }else{
                    $c_entity = $this->DataSubscriptionsPaymentsError->newEntity([                                    
                        'subscription_id' => $subscription_id, 
                        'user_id' => $user_id, 
                        'error' => json_encode($error), 
                        'date' => date('Y-m-d H:i:s') , 
                        'stripe_result' => json_encode($stripe_result), 
                        'customer_id' => $customer_id, 
                        'payment_method'=> $payment_method,                                                                 
                    ]);
                    if(!$c_entity->hasErrors()) {
                        $this->DataSubscriptionsPaymentsError->save($c_entity);
                    }
                    $this->log(__LINE__ ." ". json_encode(['subscription_id'  => $subscription_id,'user_id'  => $user_id,'total'  => $total,'payment_id'  => $payment_id,'charge_id'  => $id_charge,'receipt_id'  => $receipt_url,'created'   => date('Y-m-d H:i:s'),'error' => $error,'status' => 'DONE','deleted' => 0]) );
                    $this->log(__LINE__ ." error ". json_encode($error) );$this->log(__LINE__ ." stripe_result ". json_encode($stripe_result) );
                } 
            }
        }else{
            $total = $row['total'];

            $md_id = $this->get_doctor($user_id, $row['subscription_type']);

            $c_entity = $this->DataSubscriptionPayments->newEntity([
                'uid'   => Text::uuid(),
                'subscription_id'  => $subscription_id,
                'user_id'  => $user_id,
                'total'  => 0,
                'payment_id'  => 'payment_zero_loan',
                'charge_id'  => 'charge_zero_loan',
                'receipt_id'  => 'receipt_zero_loan',
                'created'   => date('Y-m-d H:i:s'),
                'error' => '',
                'status' => 'DONE',
                'deleted' => 0,
                'md_id' => $md_id,
                'payment_type' => 'FULL',
                'payment_description' => $row['main_service'] . ', ' . $row['addons_services'],
                'main_service' => $row['main_service'],
                'addons_services' => $row['addons_services'],
                'payment_details' => $row['payment_details'],
                'state' => $row['User']['state'],
            ]);

            if(!$c_entity->hasErrors()){
                $this->DataSubscriptionPayments->save($c_entity);
    
                $this->DataSubscriptions->updateAll(
                    ['status' => 'ACTIVE'],
                    ['id' => $subscription_id]
                );
            }
            return true;
        }

        return false;
    }

    private function cancel_new_subs($subscription_id, $monthly) {
        $payments = $this->DataSubscriptionPayments->find()->where(['subscription_id' => $subscription_id, 'status' => 'DONE', 'payment_type' => 'FULL'])->count();

        if($monthly == '3'){
            if($payments >= 3){
                $this->DataSubscriptions->updateAll(
                    ['status' => 'CANCELLED'],
                    ['id' => $subscription_id]
                );
                return true;
            }
        } else if($monthly == '12'){
            if($payments >= 12){
                $this->DataSubscriptions->updateAll(
                    ['status' => 'CANCELLED'],
                    ['id' => $subscription_id]
                );

                return true;
            }
        }

        return false;
    }
    // Unsubscription
    /*private function notify_cancellation_prev() {
        $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');

        $today = date('Y-m-d');
        $_fields = ['User.email','DataSubscription.subscription_type'];
        
        $ent_sub_cancelled = $this->DataSubscriptionCancelled->find()->select($_fields)->join([
            'DataSubscription' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DataSubscription.id = DataSubscriptionCancelled.subscription_id'],
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSubscription.user_id'],
        ])->where([
            'DataSubscriptionCancelled.deleted' => 0,
            "DATE_FORMAT(DATE_SUB(DataSubscriptionCancelled.date_payment, INTERVAL 1 MONTH), '%Y-%m-%d') = '$today'"
        ])->toArray();

        $array_str_subscriptions = [
            'SUBSCRIPTIONMSL' => 'MySpaLive Subscription','SUBSCRIPTIONMD' => 'Medical Director Subscription'
        ];
        foreach ($ent_sub_cancelled as $row) {
            $this->sendUnsubscriptionEmail('EMAIL_SUBSCRIPTION_CANCELLATION_REQUEST', $row['User']['email'], $array_str_subscriptions[$row['DataSubscription']['subscription_type']]);
        }
        
    }*/

    /*private function sendUnsubscriptionEmail($notif_key, $str_email, $subscription) {

        $this->loadModel('SpaLiveV1.CatNotifications');
        $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => $notif_key])->first();
        
        if (!empty($ent_notification)) {
           
        
            $constants = [
                '[CNT/Subscription]'   => $subscription,
            ];

            $msg_mail = $ent_notification['body'];

            foreach($constants as $key => $value){
                $msg_mail = str_replace($key, $value, $msg_mail);
            }

            $conf_subject = $ent_notification['subject'];
            $conf_body = $msg_mail;

    
            $str_message = '
                <!doctype html>
                    <html>
                        <head>
                        <meta name="viewport" content="width=device-width">
                        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                        <title>MySpaLive Message</title>
                        <style>
                        @media only screen and (max-width: 620px) {
                            table[class=body] h1 {
                            font-size: 28px !important;
                            margin-bottom: 10px !important;
                            }
                            table[class=body] p,
                                table[class=body] ul,
                                table[class=body] ol,
                                table[class=body] td,
                                table[class=body] span,
                                table[class=body] a {
                            font-size: 16px !important;
                            }
                            table[class=body] .wrapper,
                                table[class=body] .article {
                            padding: 10px !important;
                            }
                            table[class=body] .content {
                            padding: 0 !important;
                            }
                            table[class=body] .container {
                            padding: 0 !important;
                            width: 100% !important;
                            }
                            table[class=body] .main {
                            border-left-width: 0 !important;
                            border-radius: 0 !important;
                            border-right-width: 0 !important;
                            }
                            table[class=body] .btn table {
                            width: 100% !important;
                            }
                            table[class=body] .btn a {
                            width: 100% !important;
                            }
                            table[class=body] .img-responsive {
                            height: auto !important;
                            max-width: 100% !important;
                            width: auto !important;
                            }
                        }
    
                        /* -------------------------------------
                            PRESERVE THESE STYLES IN THE HEAD
                        ------------------------------------- 
                        @media all {
                            .ExternalClass {
                            width: 100%;
                            }
                            .ExternalClass,
                                .ExternalClass p,
                                .ExternalClass span,
                                .ExternalClass font,
                                .ExternalClass td,
                                .ExternalClass div {
                            line-height: 100%;
                            }
                            .apple-link a {
                            color: inherit !important;
                            font-family: inherit !important;
                            font-size: inherit !important;
                            font-weight: inherit !important;
                            line-height: inherit !important;
                            text-decoration: none !important;
                            }
                            #MessageViewBody a {
                            color: inherit;
                            text-decoration: none;
                            font-size: inherit;
                            font-family: inherit;
                            font-weight: inherit;
                            line-height: inherit;
                            }
                            .btn-primary table td:hover {
                            background-color: #34495e !important;
                            }
                            .btn-primary a:hover {
                            background-color: #34495e !important;
                            border-color: #34495e !important;
                            }
                        }
                        </style>
                        </head>
                        <body class="" style="background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
                        <span class="preheader" style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">MySpaLive Message.</span>
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background-color: #f6f6f6;">
                            <tr>
                            <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                            <td class="container" style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 580px; padding: 10px; width: 580px;">
                            <center>
                            <img src="' . $this->URL_PANEL . 'img/logo-spa-paid.png" width="250px"/>
                            </center>  
                                <div class="content" style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;">                              
                                <!-- START CENTERED WHITE CONTAINER -->
                                <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 3px;">
                                    <!-- START MAIN CONTENT AREA -->
                                    <tr>
                                    <td class="wrapper" style="font-family: sans-serif; font-size: 14px; text-align: center; box-sizing: border-box; padding: 20px;">
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                        <tr>
                                            <td style="font-family: sans-serif; font-size: 14px;">
                                            <p style="font-family: sans-serif; font-size: 14px; font-weight: normal; margin: 0; Margin-bottom: 15px;">
                                            </p>'
                                            . $msg_mail .
                                            '
                                            <br>
                                        </tr>
                                        </table>
                                    </td>
                                    </tr>
                                <!-- END MAIN CONTENT AREA -->
                                </table>
    
                                <!-- START FOOTER -->
                                <div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                    <tr>
                                        <td class="content-block" style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
                                        <span class="apple-link" style="color: #999999; font-size: 12px; text-align: center;">Visit us at <a href="https://blog.myspalive.com/">MySpaLive</a></span>
                                        </td>
                                    </table>
                                </div>
                                <!-- END FOOTER -->
    
                                <!-- END CENTERED WHITE CONTAINER -->
                                </div>
                            </td>
                            <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                            </tr>
                        </table>
                        </body>
                    </html>
    
            ';

            $data = array(
                'from'      => 'MySpaLive <noreply@mg.myspalive.com>',
                'to'        => $str_email,
                'subject'   => $conf_subject,
                'html'      => $str_message,
            );

            $mailgunKey = $this->getMailgunKey();
    
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_POST, true); 
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    
            $result = curl_exec($curl);
            curl_close($curl);
            $this->success();
        }
    }*/


    private function pay_salesrep_comissions($subscription_id) {
        if ($subscription_id == 0) return;
        $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
        $Pay = new PaymentsController();

        $ent_pay = $this->DataSalesRepresentativePayments->find()->where(['DataSalesRepresentativePayments.deleted' => 1, 'DataSalesRepresentativePayments.payment_uid' => '', 'DataSalesRepresentativePayments.payload' => '','DataSalesRepresentativePayments.subscription_id' => $subscription_id])->first();
        
        if ($ent_pay) $Pay->payment_invitations($ent_pay->user_id, $ent_pay->payment_id, $ent_pay->id, $ent_pay->uid);
        
    }

    private function get_doctor($injector_id, $type){

        $posicion = strpos($type, 'msl');

        if($posicion !== false){
            return 0;
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $assigned = $this->SysUsers->getConnection()->query( "SELECT U.md_id, U.created FROM sys_users U WHERE U.deleted = 0  and type in ('injector') and U.id = {$injector_id} order by id desc limit 1 ")->fetchAll('assoc');

        if(empty($assigned)){
            return 0;
        }

        return $assigned[0]['md_id'];
    }

    private function send_trial_hold_email($str_email){

        $subject = 'Your Free Month Has Ended—Keep Your Coverage Active';

        $body = "
            <h3>Your Free Month Has Ended—Time to Resubscribe</h3>
            <p>Your free month of Medical Director Coverage and MySpaLive app access has ended. To continue treating clients legally under MySpaLive, please resubscribe in the app under the Subscriptions tab.</p>
            
            <p>🔹 Medical Director Coverage: $179/month</p>
            <p>🔹 MySpaLive App Subscription: $39/month</p>
            
            <p>Keeping your subscriptions active ensures you remain fully covered, compliant, and able to continue treating clients without interruption.</p>
            
            <p>📲 Log in to the MySpaLive app and resubscribe today to stay covered!</p>
            
            <p>If you have any questions, feel free to reach out.</p>
            
            <p>The MySpaLive Team</p>
        ";

        $data = array(
            'from'      => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'        => $str_email,
            'subject'   => $subject,
            'html'      => $body,
        );

        $mailgunKey = $this->getMailgunKey();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

        $result = curl_exec($curl);
        curl_close($curl);
    }

    private function email_relations($row){

        $isDev = env('IS_DEV', false);


        $subject = "Trial has ended for " . $row['User']['name'] . " " . $row['User']['lname'];

        $body = "Subscription: Neurotoxins trial<br>Name:" . $row['User']['name'] . " " . $row['User']['lname'] . "<br>Email: " . $row['User']['email'] . "<br>Phone: " . $row['User']['phone'];

        $data = array(
            'from'      => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'        => $isDev ? 'john@advantedigital.com' : 'patientrelations@myspalive.com',
            'subject'   => $subject,
            'html'      => $body,
        );

        $mailgunKey = $this->getMailgunKey();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

        $result = curl_exec($curl);
        curl_close($curl);
    }

    /**
     * Process pending payments after successful subscription payment
     * @param int $subscription_id
     * @param int $user_id
     * @param int $paid_amount Amount that was successfully paid
     */
    private function processPendingPayments($subscription_id, $user_id, $paid_amount) {
        // Get pending payments for this subscription
        return;
        
        $pending_payments = $this->DataSubscriptionPendingPayments->find()
        ->where([
            'subscription_id' => $subscription_id,
            'deleted' => 0
        ])
        ->order(['created' => 'ASC'])
        ->all();
        
        if (count($pending_payments) == 0) {
            return;
        }

        foreach($pending_payments as $pending_payment){
            $subscription = $this->DataSubscriptions->find()->where(['id' => $subscription_id])->first();

            $payment_details = json_decode($subscription->payment_details, true);

            $service = $pending_payments->service;

            $new_amount = $pending_payments->amount;

            $pending_amount = $pending_payments->amount_pending;

            $payment_details[$service] = $new_amount;

            $total = $subscription->total;

            $new_total = $total + $pending_amount;

            $this->DataSubscriptions->updateAll(
                ['total' => $new_total, 'payment_details' => json_encode($payment_details)],
                ['id' => $subscription_id]
            );

            $this->DataSubscriptionPendingPayments->updateAll(
                ['deleted' => 1],
                ['id' => $pending_payments->id]
            );
        }
    }

}