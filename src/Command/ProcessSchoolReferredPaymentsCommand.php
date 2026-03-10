<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use Cake\Utility\Text;
use Cake\I18n\FrozenTime;

require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;

class ProcessSchoolReferredPaymentsCommand extends Command
{
    public function execute(Arguments $args, ConsoleIo $io)
    {
        $io->out('Starting ProcessSchoolReferredPaymentsCommand...');

        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.DataSchoolReferredPayments');

        // Find all unpaid referred payments
        $unpaidPayments = $this->DataSchoolReferredPayments->find()
            ->select([
                'DataSchoolReferredPayments.id', 
                'DataSchoolReferredPayments.payment_id', 
                'DataSchoolReferredPayments.amount', 
                'DataSchoolReferredPayments.user_id', 
                'DataSchoolReferredPayments.status', 
                'DataSchoolReferredPayments.deleted', 
                'DataSchoolReferredPayments.created',
                'DataSubscriptionPayments.status',
                'DataSubscriptionPayments.total',
                'DataSubscriptionPayments.charge_id',
                'SysUsers.stripe_account',
            ])
            ->join([
                'DataSubscriptionPayments' => [
                    'table' => 'data_subscription_payments',
                    'type' => 'INNER',
                    'conditions' => 'DataSchoolReferredPayments.payment_id = DataSubscriptionPayments.id'
                ]
            ])
            ->join([
                'SysUsers' => [
                    'table' => 'sys_users',
                    'type' => 'INNER',
                    'conditions' => 'DataSchoolReferredPayments.user_id = SysUsers.id'
                ]
            ])
            ->where([
                'DataSchoolReferredPayments.status' => 'NOT PAID',
                'DataSchoolReferredPayments.deleted' => 0
            ])
            ->all();

        if (count($unpaidPayments) === 0) {
            $io->out('No unpaid referred payments found.');
            return;
        }

        $count = 0;
        foreach ($unpaidPayments as $payment) {
            $paymentId = $payment->id;

            // Check if funds are sufficient and payment is done
            // Criteria:
            // 1. Subscription payment status must be 'DONE'
            // 2. Subscription payment total (funds) >= Referral amount (commission)
            
            $fundsAvailable = $payment['DataSubscriptionPayments']['total'];
            $commissionAmount = $payment->amount;
            $status = $payment->status;

            if ($status === 'DONE' && $fundsAvailable >= $commissionAmount) {
                try {
                    $transfer = \Stripe\Transfer::create([
                        'amount' => $commissionAmount,
                        'currency' => 'USD',
                        'description' => 'SCHOOL REF PAYMENT',
                        'destination' => $payment['SysUsers']['stripe_account'],
                        'source_transaction' => $payment['DataSubscriptionPayments']['charge_id'] //ch_3PDpEyD0WNkFIbmK1CJR1LJp
                    ]);
                } catch (\Stripe\Exception\CardException $e) {
                    $io->err("Referral Payment ID {$paymentId}: Stripe card error - " . $e->getMessage());
                } catch (\Stripe\Exception\RateLimitException $e) {
                    $io->err("Referral Payment ID {$paymentId}: Stripe rate limit error - " . $e->getMessage());
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    $io->err("Referral Payment ID {$paymentId}: Stripe invalid request error - " . $e->getMessage());
                } catch (\Stripe\Exception\AuthenticationException $e) {
                    $io->err("Referral Payment ID {$paymentId}: Stripe authentication error - " . $e->getMessage());
                } catch (\Stripe\Exception\ApiConnectionException $e) {
                    $io->err("Referral Payment ID {$paymentId}: Stripe API connection error - " . $e->getMessage());
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    $io->err("Referral Payment ID {$paymentId}: Stripe API error - " . $e->getMessage());
                } catch (\Exception $e) {
                    $io->err("Referral Payment ID {$paymentId}: General error - " . $e->getMessage());
                }

                if ($transfer) {
                    $this->DataSchoolReferredPayments->updateAll(
                        ['status' => 'PAID', 'payload' => json_encode($transfer)],
                        ['id' => $paymentId]
                    );
                    $io->out("Referral Payment ID {$paymentId}: Successfully processed (Amount: {$commissionAmount}, Funds: {$fundsAvailable}).");
                    $count++;
                } else {
                    $io->err("Referral Payment ID {$paymentId}: Failed to process payment.");
                }
                // Update status to PAID
                
            } else {
                $io->out("Referral Payment ID {$paymentId}: Skipped. Status: {$status}, Funds: {$fundsAvailable}, Required: {$commissionAmount}.");
            }
        }

        $io->out("Finished. Processed {$count} payments.");
    }
}

