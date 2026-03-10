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

class PendingpaysubsCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
        $isDev = env('IS_DEV', false);
        
        // Load required models
        $this->loadModel('SpaLiveV1.DataSubscriptionPendingPayments');
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.SysUsers');
        
        // Get pending payments that are due today or overdue
        $today = date('Y-m-d');
        $pending_payments = $this->DataSubscriptionPendingPayments->find()
            ->where([
                'due_date <=' => $today . ' 23:59:59',
                'deleted' => 0
            ])
            ->all();
            
        if (count($pending_payments) == 0) {
            return;
        }
        
        foreach($pending_payments as $pending_payment) {
            
            // Process the payment logic here
            // You can call your existing processPendingPayments method or implement new logic
            $this->processPendingPayment($pending_payment, $io);
        }
    }

    /**
     * Process a single pending payment
     * @param object $pending_payment
     * @param ConsoleIo $io
     */
    private function processPendingPayment($pending_payment) {
        try {
            $subscription = $this->DataSubscriptions->find()->where(['id' => $pending_payment->subscription_id])->first();
            
            if (!$subscription) {
                return false;
            }

            // Get user data
            $user = $this->SysUsers->find()->where(['id' => $subscription->user_id])->first();
            if (!$user) {
                return false;
            }

            // Get customer ID and payment methods from subscription
            $customer_id = $subscription->customer_id;
            $payment_method = $subscription->payment_method;
            
            if (empty($customer_id) || empty($payment_method)) {
                // Try to get payment methods from Stripe customer
                $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
                
                $oldCustomer = $stripe->customers->all([
                    "email" => $user->email,
                    "limit" => 1,
                ]);

                if (count($oldCustomer) > 0) {
                    $customer = $oldCustomer->data[0];
                    $customer_id = $customer->id;
                    
                    $payment_methods = $stripe->customers->allPaymentMethods(
                        $customer->id,
                        ['type' => 'card']
                    );
                    
                    if (count($payment_methods) > 0) {
                        $payment_method = $payment_methods->data[0]->id;
                    }
                }
            }

            if (empty($customer_id) || empty($payment_method)) {
                return false;
            }

            // Process payment with Stripe
            $amount = $pending_payment->amount_pending;
            $description = 'Pending payment for service: ' . $pending_payment->service;
            
            \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
            $stripe_result = '';
            $error = '';

            try {
                $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => $amount,
                    'currency' => 'usd',
                    'customer' => $customer_id,
                    'payment_method' => $payment_method,
                    'off_session' => true,
                    'confirm' => true,
                    'description' => $description,
                    'error_on_requires_action' => true,
                ]);
            } catch(Stripe_CardError $e) {
                $error = $e->getMessage();
            } catch (Stripe_InvalidRequestError $e) {
                $error = $e->getMessage();
            } catch (Stripe_AuthenticationError $e) {
                $error = $e->getMessage();
            } catch (Stripe_ApiConnectionError $e) {
                $error = $e->getMessage();
            } catch (Stripe_Error $e) {
                $error = $e->getMessage();
            } catch (Exception $e) {
                $error = $e->getMessage();
            } catch(\Stripe\Exception\CardException $e) {
                $error = $e->getMessage();
            } catch (\Stripe\Exception\RateLimitException $e) {
                $error = $e->getMessage();
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                $error = $e->getMessage();
            } catch (\Stripe\Exception\AuthenticationException $e) {
                $error = $e->getMessage();
            } catch (\Stripe\Exception\ApiErrorException $e) {
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

            if (empty($error) && $stripe_result->status == 'succeeded') {
                // Payment successful - update subscription and mark pending payment as processed
                $payment_details = json_decode($subscription->payment_details, true);
                $service = $pending_payment->service;
                $new_amount = $pending_payment->amount;
                $pending_amount = $pending_payment->amount_pending;

                $payment_details[$service] = $new_amount;
                $total = $subscription->total;
                $new_total = $total + $new_amount;

                // Update subscription
                $this->DataSubscriptions->updateAll(
                    ['total' => $new_total, 'payment_details' => json_encode($payment_details)],
                    ['id' => $pending_payment->subscription_id]
                );

                // Mark pending payment as deleted
                $this->DataSubscriptionPendingPayments->updateAll(
                    ['deleted' => 1],
                    ['id' => $pending_payment->id]
                );

                // Create payment record
                $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
                $c_entity = $this->DataSubscriptionPayments->newEntity([
                    'uid' => \Cake\Utility\Text::uuid(),
                    'subscription_id' => $pending_payment->subscription_id,
                    'user_id' => $subscription->user_id,
                    'total' => $amount,
                    'payment_id' => $payment_id,
                    'charge_id' => $id_charge,
                    'receipt_id' => $receipt_url,
                    'created' => date('Y-m-d H:i:s'),
                    'error' => $error,
                    'status' => 'DONE',
                    'deleted' => 0,
                    'payment_type' => 'PARTIAL',
                    'payment_description' => $description,
                    'main_service' => $subscription->main_service,
                    'addons_services' => $subscription->addons_services,
                    'payment_details' => json_encode([$service => $amount]),
                    'state' => $user->state ?? '',
                ]);
                
                if (!$c_entity->hasErrors()) {
                    $this->DataSubscriptionPayments->save($c_entity);
                }

                return true;
            } else {
                // Payment failed - remove service from subscription
                $this->handlePaymentFailure($pending_payment, $subscription, $error);
                return false;
            }
            
        } catch (Exception $e) {
            $this->log(__LINE__ . " Exception in processPendingPayment: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle payment failure by removing service from subscription
     * @param object $pending_payment
     * @param object $subscription
     * @param string $error
     */
    private function handlePaymentFailure($pending_payment, $subscription, $error) {
        try {
            $service = $pending_payment->service;
            $amount_to_remove = $pending_payment->amount;
            
            // Get current payment details
            $payment_details = json_decode($subscription->payment_details, true);
            if (!$payment_details) {
                $payment_details = [];
            }
            
            // Remove service from payment_details
            if (isset($payment_details[$service])) {
                unset($payment_details[$service]);
            }
            
            // Update main_service and addons_services
            $main_service = $subscription->main_service;
            $addons_services = $subscription->addons_services;
            
            // Check if service is in main_service
            if ($main_service === $service) {
                $main_service = '';
            }
            
            // Check if service is in addons_services
            if (!empty($addons_services)) {
                $addons_array = explode(',', $addons_services);
                $addons_array = array_map('trim', $addons_array);
                $addons_array = array_filter($addons_array); // Remove empty values
                
                // Remove the service from addons
                $addons_array = array_diff($addons_array, [$service]);
                $addons_services = implode(',', $addons_array);
            }
            
            // Update subscription
            $this->DataSubscriptions->updateAll([
                'payment_details' => json_encode($payment_details),
                'main_service' => $main_service,
                'addons_services' => $addons_services
            ], [
                'id' => $pending_payment->subscription_id
            ]);
            
            // Mark pending payment as deleted (failed)
            $this->DataSubscriptionPendingPayments->updateAll(
                ['deleted' => 1],
                ['id' => $pending_payment->id]
            );

            // sub cancelled
            $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');
            $array_save = array(
                'subscription_id' => $pending_payment->subscription_id,
                'date_cancelled' => date('Y-m-d H:i:s'),
                'date_payment' => date('Y-m-d H:i:s'),
                'created' => date('Y-m-d H:i:s'),
                'deleted' => 0,
                'modified' => date('Y-m-d H:i:s'),
                'reason' => 'Payment failed',
                'services_unsubscribe' => $service,
            );
            $c_entity = $this->DataSubscriptionCancelled->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                $this->DataSubscriptionCancelled->save($c_entity);
            }
            
            // Log the failure
            $this->log(__LINE__ . " Payment failed for pending payment ID: " . $pending_payment->id . 
                      " Service: " . $service . 
                      " Amount: " . $amount_to_remove . 
                      " Error: " . json_encode($error));
                      
        } catch (Exception $e) {
            $this->log(__LINE__ . " Exception in handlePaymentFailure: " . $e->getMessage());
        }
    }

    /**
     * Process pending payments after successful subscription payment
     * @param int $subscription_id
     * @param int $user_id
     * @param int $paid_amount Amount that was successfully paid
     */
    private function processPendingPayments($subscription_id, $user_id, $paid_amount) {
        // Get pending payments for this subscription
        
        $pending_payments = $this->DataSubscriptionPendingPayments->find()
        ->where([
            'subscription_id' => $subscription_id,
            'deleted' => 0
        ])
        ->order(['due_date' => 'ASC']) // Changed from 'created' to 'due_date'
        ->all();
        
        if (count($pending_payments) == 0) {
            return;
        }

        foreach($pending_payments as $pending_payment){
            $subscription = $this->DataSubscriptions->find()->where(['id' => $subscription_id])->first();

            $payment_details = json_decode($subscription->payment_details, true);

            $service = $pending_payment->service; // Fixed: was $pending_payments->service

            $new_amount = $pending_payment->amount; // Fixed: was $pending_payments->amount

            $pending_amount = $pending_payment->amount_pending; // Fixed: was $pending_payments->amount_pending

            $payment_details[$service] = $new_amount;

            $total = $subscription->total;

            $new_total = $total + $pending_amount;

            $this->DataSubscriptions->updateAll(
                ['total' => $new_total, 'payment_details' => json_encode($payment_details)],
                ['id' => $subscription_id]
            );

            $this->DataSubscriptionPendingPayments->updateAll(
                ['deleted' => 1],
                ['id' => $pending_payment->id] // Fixed: was $pending_payments->id
            );
        }
    }
}