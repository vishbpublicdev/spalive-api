<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;

use App\Controller\AppPluginController;
use Cake\Utility\Text;

/**
 * API: Create legacy subscription records from a Port2Pay purchase.
 *
 * Call with: action=Port2PaySubscription____create_subscription
 *
 * Required params:
 * - token
 * - payment_ref (string) : external/Port2Pay reference (stored in payment_id/charge_id/receipt_id)
 * - msl_amount (int)     : cents to record for SUBSCRIPTIONMSL
 * - md_amount (int)      : cents to record for SUBSCRIPTIONMD
 *
 * Optional params:
 * - monthly (1|3|12) default 1
 * - other_school (0|1) default 0
 * - main_service (default NEUROTOXINS)
 * - addons_services (comma separated, default '')
 * - payment_details_json (JSON string map service=>amount). If empty, derived from main_service + amounts.
 * - create_product_purchase (0|1) default 0
 * - product_id (int) default 48
 * - product_amount (int) cents default 0
 *
 * @property \SpaLiveV1\Model\Table\DataSubscriptionsTable $DataSubscriptions
 * @property \SpaLiveV1\Model\Table\DataSubscriptionPaymentsTable $DataSubscriptionPayments
 * @property \SpaLiveV1\Model\Table\SysUsersTable $SysUsers
 * @property \SpaLiveV1\Model\Table\SysUserAdminTable $SysUserAdmin
 * @property \SpaLiveV1\Model\Table\DataPurchasesTable $DataPurchases
 * @property \SpaLiveV1\Model\Table\DataPurchasesDetailTable $DataPurchasesDetail
 * @property \SpaLiveV1\Model\Table\CatProductsTable $CatProducts
 * @property \SpaLiveV1\Controller\Component\AppTokenComponent $AppToken
 *
 * @method void loadModel(string $modelClass = null, string $modelType = null)
 */
class Port2PaySubscriptionController extends AppPluginController
{
    public function create_subscription()
    {
        $token = get('token', '');
        if (empty($token)) {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $user = $this->AppToken->validateToken($token, true);
        if ($user === false) {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }
        $this->set('session', true);

        $paymentRef = (string)get('payment_ref', '');
        if ($paymentRef === '') {
            $this->message('payment_ref is required.');
            return;
        }

        $mslAmount = (int)get('msl_amount', -1);
        $mdAmount = (int)get('md_amount', -1);
        if ($mslAmount < 0 || $mdAmount < 0) {
            $this->message('msl_amount and md_amount are required (in cents).');
            return;
        }

        $monthly = (string)get('monthly', '1');
        if (!in_array($monthly, ['1', '3', '12'], true)) {
            $this->message('monthly must be 1, 3, or 12.');
            return;
        }

        $otherSchool = (int)get('other_school', 0);
        $mainService = (string)get('main_service', 'NEUROTOXINS');
        $addonsServices = (string)get('addons_services', '');

        $detailsJsonParam = get('payment_details_json', '');
        $paymentDetails = null;
        if (is_string($detailsJsonParam) && $detailsJsonParam !== '') {
            $decoded = json_decode($detailsJsonParam, true);
            if (is_array($decoded)) {
                $paymentDetails = $decoded;
            }
        }
        if ($paymentDetails === null) {
            // Keep it simple: record the split totals under the main service.
            $paymentDetails = [$mainService => ($mslAmount + $mdAmount)];
        }

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');

        $userEntity = $this->SysUsers->find()
            ->where(['SysUsers.id' => (int)$user['user_id'], 'SysUsers.deleted' => 0])
            ->first();

        if (empty($userEntity)) {
            $this->message('User not found.');
            return;
        }

        $mdId = (int)($userEntity->md_id ?? 0);
        if ($mdId === 0) {
            $assigned = $this->SysUserAdmin->getAssignedDoctorInjector((int)$userEntity->id);
            $mdId = (int)($assigned ?? 0);
            if ($mdId > 0) {
                $this->SysUsers->updateAll(['md_id' => $mdId], ['id' => (int)$userEntity->id]);
            }
        }

        $now = date('Y-m-d H:i:s');

        // Create legacy subscription rows (MSL + MD).
        $mslUid = Text::uuid();
        $mslEntity = $this->DataSubscriptions->newEntity([
            'user_id' => (int)$userEntity->id,
            'uid' => $mslUid,
            'event' => 'port2pay_subscription',
            'payload' => '',
            'request_id' => '',
            'data_object_id' => '',
            'customer_id' => '',
            'payment_method' => 'PORT2PAY',
            'subscription_type' => 'SUBSCRIPTIONMSL',
            'promo_code' => '',
            'subtotal' => $mslAmount,
            'total' => $mslAmount,
            'status' => 'ACTIVE',
            'deleted' => 0,
            'created' => $now,
            'agreement_id' => 0,
            'comments' => '',
            'main_service' => $mainService,
            'addons_services' => $addonsServices,
            'payment_details' => json_encode($paymentDetails),
            'state' => (int)($userEntity->state ?? 0),
            'other_school' => $otherSchool,
            'monthly' => $monthly,
        ]);

        if ($mslEntity->hasErrors()) {
            $this->message('MSL subscription validation failed: ' . json_encode($mslEntity->getErrors()));
            return;
        }
        $mslSaved = $this->DataSubscriptions->save($mslEntity);
        if (!$mslSaved) {
            $this->message('Failed to save MSL subscription.');
            return;
        }

        $mdUid = Text::uuid();
        $mdEntity = $this->DataSubscriptions->newEntity([
            'user_id' => (int)$userEntity->id,
            'uid' => $mdUid,
            'event' => 'port2pay_subscription',
            'payload' => '',
            'request_id' => '',
            'data_object_id' => '',
            'customer_id' => '',
            'payment_method' => 'PORT2PAY',
            'subscription_type' => 'SUBSCRIPTIONMD',
            'promo_code' => '',
            'subtotal' => $mdAmount,
            'total' => $mdAmount,
            'status' => 'ACTIVE',
            'deleted' => 0,
            'created' => $now,
            'agreement_id' => 0,
            'comments' => '',
            'main_service' => $mainService,
            'addons_services' => $addonsServices,
            'payment_details' => json_encode($paymentDetails),
            'state' => (int)($userEntity->state ?? 0),
            'other_school' => $otherSchool,
            'monthly' => $monthly,
        ]);

        if ($mdEntity->hasErrors()) {
            $this->message('MD subscription validation failed: ' . json_encode($mdEntity->getErrors()));
            return;
        }
        $mdSaved = $this->DataSubscriptions->save($mdEntity);
        if (!$mdSaved) {
            $this->message('Failed to save MD subscription.');
            return;
        }

        // Register payments in data_subscription_payments (sales reports depend on this).
        $mslPayEntity = $this->DataSubscriptionPayments->newEntity([
            'uid' => Text::uuid(),
            'user_id' => (int)$userEntity->id,
            'subscription_id' => (int)$mslSaved->id,
            'total' => $mslAmount,
            'payment_id' => $paymentRef,
            'charge_id' => $paymentRef,
            'receipt_id' => $paymentRef,
            'error' => '',
            'status' => 'DONE',
            'notes' => 'Port2Pay',
            'created' => $now,
            'deleted' => 0,
            'payment_type' => 'FULL',
            'payment_description' => 'SUBSCRIPTIONMSL',
            'main_service' => $mainService,
            'addons_services' => $addonsServices,
            'payment_details' => json_encode($paymentDetails),
            'state' => (int)($userEntity->state ?? 0),
            'md_id' => 0,
        ]);
        if (!$mslPayEntity->hasErrors()) {
            $this->DataSubscriptionPayments->save($mslPayEntity);
        }

        $mdPayEntity = $this->DataSubscriptionPayments->newEntity([
            'uid' => Text::uuid(),
            'user_id' => (int)$userEntity->id,
            'subscription_id' => (int)$mdSaved->id,
            'total' => $mdAmount,
            'payment_id' => $paymentRef,
            'charge_id' => $paymentRef,
            'receipt_id' => $paymentRef,
            'error' => '',
            'status' => 'DONE',
            'notes' => 'Port2Pay',
            'created' => $now,
            'deleted' => 0,
            'payment_type' => 'FULL',
            'payment_description' => 'SUBSCRIPTIONMD',
            'main_service' => $mainService,
            'addons_services' => $addonsServices,
            'payment_details' => json_encode($paymentDetails),
            'state' => (int)($userEntity->state ?? 0),
            'md_id' => $mdId,
        ]);
        if (!$mdPayEntity->hasErrors()) {
            $this->DataSubscriptionPayments->save($mdPayEntity);
        }

        // Optional: create product purchase records (tox party, etc.).
        $createProductPurchase = (int)get('create_product_purchase', 0) === 1;
        if ($createProductPurchase) {
            $this->loadModel('SpaLiveV1.DataPurchases');
            $this->loadModel('SpaLiveV1.DataPurchasesDetail');
            $this->loadModel('SpaLiveV1.CatProducts');

            $productId = (int)get('product_id', 48);
            $productAmount = (int)get('product_amount', 0);

            $purchaseUid = Text::uuid();
            $purchaseEntity = $this->DataPurchases->newEntity([
                'uid' => $purchaseUid,
                'user_id' => (int)$userEntity->id,
                'status' => 'NEW',
                'name' => trim((string)$userEntity->name . ' ' . (string)$userEntity->lname),
                'address' => '',
                'suite' => '',
                'city' => '',
                'state' => '',
                'zip' => 0,
                'tracking' => '',
                'delivery_company' => '',
                'created' => $now,
                'shipping_date' => date('Y-m-d'),
                'shipping_cost' => 0,
                'amount' => $productAmount,
            ]);

            if (!$purchaseEntity->hasErrors() && $this->DataPurchases->save($purchaseEntity)) {
                $product = $this->CatProducts->find()
                    ->where(['CatProducts.id' => $productId])
                    ->first();

                if (!empty($product)) {
                    $detailEntity = $this->DataPurchasesDetail->newEntity([
                        'product_id' => (int)$product->id,
                        'price' => (int)$product->unit_price,
                        'qty' => 1,
                        'product_detail_question' => (string)$product->details_text,
                        'product_detail' => '',
                        'purchase_id' => (int)$purchaseEntity->id,
                    ]);

                    if (!$detailEntity->hasErrors()) {
                        $this->DataPurchasesDetail->save($detailEntity);
                    }
                }

                // Register payment in legacy payments table(s) via existing PaymentsController helper.
                $Payments = new PaymentsController();
                $Payments->createPaymentRegister(
                    'PURCHASE',
                    (int)$userEntity->id,
                    0,
                    $purchaseUid,
                    $paymentRef,
                    $paymentRef,
                    $paymentRef,
                    $productAmount,
                    $productAmount
                );

                $this->DataPurchases->updateAll(
                    [
                        'payment' => $paymentRef,
                        'payment_intent' => $paymentRef,
                        'receipt_url' => $paymentRef,
                    ],
                    ['uid' => $purchaseUid]
                );
            }
        }

        // Ensure user can access the app.
        $this->SysUsers->updateAll(
            [
                'active' => 1,
                'login_status' => 'READY',
                'steps' => 'HOME',
            ],
            ['id' => (int)$userEntity->id]
        );

        $this->set('msl_subscription_id', (int)$mslSaved->id);
        $this->set('md_subscription_id', (int)$mdSaved->id);
        $this->set('msl_uid', (string)$mslSaved->uid);
        $this->set('md_uid', (string)$mdSaved->uid);
        $this->success();
    }
}

