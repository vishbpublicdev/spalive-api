<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Utility\Text;

/**
 * Command to manually create a subscription for any user.
 *
 * Usage:
 *   bin/cake add_manual_subscription <user_id_or_uid> <subscription_type> [--type=msl|md] [--service=NEUROTOXINS|IV_THERAPY|FILLERS] [--amount=17900] [--monthly=1|3|12]
 *
 * Examples:
 *   bin/cake add_manual_subscription 123 SUBSCRIPTIONMSL
 *   bin/cake add_manual_subscription abc-uid-here SUBSCRIPTIONMD --service=IV_THERAPY --amount=17900 --monthly=1
 */
class AddManualSubscriptionCommand extends Command
{
    /** @var array Valid subscription types */
    private const SUBSCRIPTION_TYPES = [
        'SUBSCRIPTIONMSL',
        'SUBSCRIPTIONMD',
        'SUBSCRIPTIONMSLIVT',
        'SUBSCRIPTIONMDIVT',
        'SUBSCRIPTIONMSLFILLERS',
        'SUBSCRIPTIONMDFILLERS',
    ];

    /** @var array Valid main services */
    private const MAIN_SERVICES = [
        'NEUROTOXINS',
        'IV THERAPY',
        'IV_THERAPY',
        'FILLERS',
    ];

    /** @var array Default amounts in cents (MSL/MD Neurotoxins, IV Therapy) */
    private const DEFAULT_AMOUNTS = [
        'SUBSCRIPTIONMSL' => 9900,
        'SUBSCRIPTIONMD' => 17900,
        'SUBSCRIPTIONMSLIVT' => 9900,
        'SUBSCRIPTIONMDIVT' => 17900,
        'SUBSCRIPTIONMSLFILLERS' => 7500,
        'SUBSCRIPTIONMDFILLERS' => 7500,
    ];

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Manually create a subscription for any user.')
            ->addArgument('user', [
                'help' => 'User ID (numeric) or UID (e.g. abc-123-def)',
                'required' => true,
            ])
            ->addArgument('subscription_type', [
                'help' => 'Subscription type: SUBSCRIPTIONMSL, SUBSCRIPTIONMD, SUBSCRIPTIONMSLIVT, SUBSCRIPTIONMDIVT, SUBSCRIPTIONMSLFILLERS, SUBSCRIPTIONMDFILLERS',
                'required' => true,
                'choices' => self::SUBSCRIPTION_TYPES,
            ])
            ->addOption('service', [
                'short' => 's',
                'help' => 'Main service: NEUROTOXINS, IV_THERAPY, FILLERS (default derived from subscription_type)',
                'default' => null,
            ])
            ->addOption('amount', [
                'short' => 'a',
                'help' => 'Amount in cents (e.g. 17900 = $179.00). Default based on subscription type.',
                'default' => null,
            ])
            ->addOption('monthly', [
                'short' => 'm',
                'help' => 'Billing period: 1, 3, or 12 months',
                'default' => '1',
            ])
            ->addOption('add-payment', [
                'boolean' => true,
                'help' => 'Also create a DONE payment record (recommended for manual subscriptions)',
                'default' => true,
            ]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $userInput = $args->getArgument('user');
        $subscriptionType = strtoupper($args->getArgument('subscription_type'));
        $serviceOption = $args->getOption('service');
        $amountOption = $args->getOption('amount');
        $monthly = $args->getOption('monthly');
        $addPayment = $args->getOption('add-payment');

        if (!in_array($subscriptionType, self::SUBSCRIPTION_TYPES, true)) {
            $io->err("Invalid subscription_type. Use: " . implode(', ', self::SUBSCRIPTION_TYPES));
            return self::CODE_ERROR;
        }

        if (!in_array((string)$monthly, ['1', '3', '12'], true)) {
            $io->err('monthly must be 1, 3, or 12');
            return self::CODE_ERROR;
        }

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');

        /** @var \Cake\ORM\Table $usersTable */
        $usersTable = $this->SysUsers;
        $user = null;

        if (is_numeric($userInput)) {
            $user = $usersTable->find()->where(['SysUsers.id' => (int)$userInput])->first();
        } else {
            $user = $usersTable->find()->where(['SysUsers.uid' => $userInput])->first();
        }

        if (!$user) {
            $io->err("User not found: {$userInput}");
            return self::CODE_ERROR;
        }

        $userId = $user->id;
        $userState = $user->state ?? 0;

        $mainService = $this->resolveMainService($subscriptionType, $serviceOption);

        $amount = $amountOption !== null
            ? (int)$amountOption
            : (self::DEFAULT_AMOUNTS[$subscriptionType] ?? 17900);

        $paymentDetails = json_encode([$mainService => $amount]);

        $subEntity = $this->DataSubscriptions->newEntity([
            'uid' => Text::uuid(),
            'event' => 'manual_subscription',
            'payload' => '',
            'user_id' => $userId,
            'request_id' => '',
            'data_object_id' => '',
            'customer_id' => '',
            'payment_method' => 'MANUAL',
            'subscription_type' => $subscriptionType,
            'promo_code' => '',
            'subtotal' => $amount,
            'total' => $amount,
            'status' => 'ACTIVE',
            'deleted' => 0,
            'agreement_id' => 0,
            'main_service' => $mainService,
            'addons_services' => '',
            'payment_details' => $paymentDetails,
            'state' => $userState,
            'monthly' => (string)$monthly,
            'other_school' => 0,
        ]);

        if ($subEntity->hasErrors()) {
            $io->err('Validation errors: ' . json_encode($subEntity->getErrors(), JSON_PRETTY_PRINT));
            return self::CODE_ERROR;
        }

        $saved = $this->DataSubscriptions->save($subEntity);

        if (!$saved) {
            $io->err('Failed to save subscription.');
            return self::CODE_ERROR;
        }

        $io->out("Subscription created successfully. ID: {$saved->id}");

        if ($addPayment) {
            $mdId = 0;
            if (strpos($subscriptionType, 'MD') !== false) {
                $assigned = $this->SysUserAdmin->getAssignedDoctorInjector($userId);
                $mdId = $assigned ?? 0;
            }

            $payEntity = $this->DataSubscriptionPayments->newEntity([
                'uid' => Text::uuid(),
                'user_id' => $userId,
                'subscription_id' => $saved->id,
                'total' => $amount,
                'payment_id' => '',
                'charge_id' => '',
                'receipt_id' => '',
                'error' => '',
                'status' => 'DONE',
                'notes' => 'Manual subscription',
                'deleted' => 0,
                'payment_type' => 'FULL',
                'payment_description' => $subscriptionType,
                'main_service' => $mainService,
                'addons_services' => '',
                'payment_details' => $paymentDetails,
                'state' => $userState,
                'md_id' => $mdId,
            ]);

            if (!$payEntity->hasErrors() && $this->DataSubscriptionPayments->save($payEntity)) {
                $io->out('Payment record created (DONE).');
            } else {
                $io->warning('Subscription created but payment record failed. You may add it manually.');
            }
        }

        return self::CODE_SUCCESS;
    }

    private function resolveMainService(string $subscriptionType, ?string $serviceOption): string
    {
        if ($serviceOption !== null) {
            $s = strtoupper(str_replace('_', ' ', $serviceOption));
            if ($s === 'IV THERAPY' || $s === 'IVTHERAPY') {
                return 'IV THERAPY';
            }
            if (in_array($s, ['NEUROTOXINS', 'FILLERS'], true)) {
                return $s;
            }
        }

        if (strpos($subscriptionType, 'FILLERS') !== false) {
            return 'FILLERS';
        }
        if (strpos($subscriptionType, 'IVT') !== false) {
            return 'IV THERAPY';
        }
        return 'NEUROTOXINS';
    }
}
